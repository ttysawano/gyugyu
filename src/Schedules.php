<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Problem extends \RuntimeException {}
final class Schedules
{
    public function __construct(private PDO $db) {}
    private static function text(array $data, string $key, int $limit, bool $required = false): string
    {
        $v = array_key_exists($key, $data) ? $data[$key] : '';
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) throw new Problem('入力形式が不正です。', 422);
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        if ($key === 'title') $v = trim($v);
        if (($required && $v === '') || mb_strlen($v) > $limit || str_contains($v, "\0")) throw new Problem('調整名は1〜140文字、説明は2000文字以内です。', 422);
        return $v;
    }
    public static function preview(array $data): array
    {
        $title = self::text($data, 'title', 140, true);
        $description = self::text($data, 'description', 2000);
        if (($data['timezone'] ?? 'Asia/Tokyo') !== 'Asia/Tokyo') throw new Problem('タイムゾーンはAsia/Tokyoです。', 422);
        $dates = $data['dates'] ?? [];
        if (is_string($dates)) $dates = preg_split('/\R/u', trim($dates));
        if (!is_array($dates) || count($dates) < 1 || count($dates) > 366) throw new Problem('候補日は1〜366行で指定してください。', 422);
        $unique = [];
        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', trim($date))) throw new Problem('候補日が不正です。', 422);
            $date = trim($date);
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Tokyo'));
            if (!$d || $d->format('Y-m-d') !== $date || $date < '1900-01-01' || $date > '9998-12-31') throw new Problem('候補日は有効なYYYY-MM-DDで入力してください。', 422);
            $unique[$date] = true;
        }
        $sorted = array_keys($unique); sort($sorted);
        $window = $data['initial_window'] ?? ['start' => $data['start'] ?? '09:00', 'end' => $data['end'] ?? '18:00', 'end_next_day' => $data['end_next_day'] ?? false];
        if (!is_array($window)) throw new Problem('時間帯が不正です。', 422);
        $minutes = [];
        foreach (['start', 'end'] as $key) {
            $s = $window[$key] ?? null;
            if (!is_string($s) || !preg_match('/^(?:[01][0-9]|2[0-3]):(?:00|30)$/D', $s)) throw new Problem('時刻は30分刻みです。', 422);
            [$h,$m] = array_map('intval', explode(':', $s));
            $minutes[$key] = $h * 60 + $m;
        }
        $next = $window['end_next_day'] ?? false;
        if (!in_array($next, [true,false,'1','0'], true)) throw new Problem('翌日指定が不正です。', 422);
        if ($next === true || $next === '1') $minutes['end'] += 1440;
        if ($minutes['start'] < 360 || $minutes['end'] > 1800 || $minutes['end'] <= $minutes['start']) throw new Problem('当日06:00〜翌日06:00内の正の時間帯を指定してください。', 422);
        return compact('title','description') + ['dates'=>$sorted,'duplicate_count'=>count($dates)-count($sorted),'start_minute'=>$minutes['start'],'end_minute'=>$minutes['end'],'candidate_slots_per_day'=>($minutes['end']-$minutes['start'])/30];
    }
    public function listing(bool $deleted = false): array
    {
        $q = $this->db->prepare('SELECT i.id,i.title,i.status,i.version,i.updated_at,(SELECT COUNT(*) FROM participants p WHERE p.instance_id=i.id AND p.is_active=1) AS participant_count,(SELECT COUNT(*) FROM participants p WHERE p.instance_id=i.id AND p.is_active=1 AND p.submitted_at IS NOT NULL) AS submitted_count,COUNT(d.id) AS day_count,MIN(d.local_date) AS first_date,MAX(d.local_date) AS last_date FROM instances i LEFT JOIN instance_days d ON d.instance_id=i.id AND d.archived_at IS NULL WHERE i.deleted_at IS ' . ($deleted ? 'NOT NULL' : 'NULL') . ' GROUP BY i.id ORDER BY i.updated_at DESC,i.id');
        $q->execute(); return $q->fetchAll();
    }
    public function get(string $id, bool $deleted = false): array
    {
        // PDO 8.3 does not reliably report transactions started with SQL BEGIN IMMEDIATE.
        // A savepoint gives a consistent read both standalone and inside existing writes.
        $this->db->exec('SAVEPOINT schedule_read');
        try {
            $row=$this->readSnapshot($id,$deleted);
            $this->db->exec('RELEASE SAVEPOINT schedule_read');
            return $row;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK TO SAVEPOINT schedule_read');
            $this->db->exec('RELEASE SAVEPOINT schedule_read');
            throw $e;
        }
    }

    private function readSnapshot(string $id,bool $deleted): array
    {
        $q = $this->db->prepare('SELECT * FROM instances WHERE id=? AND deleted_at IS ' . ($deleted ? 'NOT NULL' : 'NULL'));
        $q->execute([$id]); $row = $q->fetch();
        if (!$row) throw new Problem('案件が見つかりません。', 404);
        $q = $this->db->prepare('SELECT * FROM instance_days WHERE instance_id=? AND archived_at IS NULL ORDER BY sort_index'); $q->execute([$id]);
        $row['days'] = $q->fetchAll();
        $q = $this->db->prepare('SELECT * FROM day_slots WHERE day_id=? ORDER BY ordinal');
        foreach ($row['days'] as &$day) { $q->execute([$day['id']]); $day['slots'] = $q->fetchAll(); }
        $q=$this->db->prepare('SELECT id FROM participants WHERE instance_id=? AND is_active=1 ORDER BY created_at,id'); $q->execute([$id]);
        $participants=new Participants($this->db);
        $row['participants']=array_map(fn($pid)=>$participants->get($id,$pid),$q->fetchAll(PDO::FETCH_COLUMN));
        $row['groups']=(new Groups($this->db))->listing($id);
        return $row;
    }
    private function transaction(callable $work): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try { $result = $work(); $this->db->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    private function audit(string $id, string $action, array $summary): void
    {
        $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?, ?, ?, ?, ?)')->execute([$id,'shared-basic',$action,json_encode($summary, JSON_THROW_ON_ERROR),gmdate('Y-m-d\TH:i:s\Z')]);
    }
    public function create(array $data): array
    {
        $p = self::preview($data);
        return $this->transaction(function () use ($p) {
            $id = bin2hex(random_bytes(16)); $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare('INSERT INTO instances(id,title,description,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([$id,$p['title'],$p['description'],$now,$now]);
            foreach ($p['dates'] as $index => $date) {
                $day = bin2hex(random_bytes(16));
                $this->db->prepare('INSERT INTO instance_days(id,instance_id,local_date,sort_index) VALUES (?,?,?,?)')->execute([$day,$id,$date,$index]);
                $start = new \DateTimeImmutable($date . ' 06:00:00', new \DateTimeZone('Asia/Tokyo'));
                $q = $this->db->prepare('INSERT INTO day_slots VALUES (?,?,?,?,?,?)');
                for ($i=0; $i<48; $i++) {
                    $utc = fn(int $n) => $start->modify('+' . ($n*30) . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                    $minute = 360 + $i*30;
                    $q->execute([bin2hex(random_bytes(16)),$day,$i,$utc($i),$utc($i+1),(int)($minute >= $p['start_minute'] && $minute < $p['end_minute'])]);
                }
            }
            $this->audit($id,'create',['title'=>$p['title'],'description'=>$p['description'],'dates'=>$p['dates']]);
            return $this->get($id);
        });
    }
    public function candidates(string $id, array $data, bool $preview = false): array
    {
        return $this->transaction(function () use ($id, $data, $preview) {
            $row = $this->get($id);
            if ($row['status'] !== 'open') throw new Problem('受付中の案件のみ候補帯を編集できます。',409);
            foreach (['version','schema_revision'] as $key) {
                if (!is_int($data['expected_'.$key] ?? null) || $data['expected_'.$key] !== $row[$key]) {
                    throw new Problem('案件または候補帯が更新されました。下書きを控え、最新版と比較してください。',409);
                }
            }
            $masks = $data['masks'] ?? null;
            if (!is_array($masks) || !array_is_list($masks) || count($masks) < 1 || count($masks) > count($row['days'])) throw new Problem('対象日のマスクが不正です。',422);
            $days = array_column($row['days'],null,'id'); $seen = []; $changes = []; $removed = 0;
            foreach ($masks as $mask) {
                if (!is_array($mask) || !is_string($mask['day_id'] ?? null)) throw new Problem('対象日が不正です。',422);
                $dayId = $mask['day_id']; $values = $mask['candidates'] ?? null;
                if (!isset($days[$dayId]) || isset($seen[$dayId]) || $days[$dayId]['archived_at'] !== null) throw new Problem('対象日が不正です。',422);
                $seen[$dayId] = true;
                if (!is_array($values) || !array_is_list($values) || count($values) !== 48) throw new Problem('各日の候補マスクは48個の真偽値で指定してください。',422);
                foreach ($values as $i => $value) {
                    if (!is_bool($value)) throw new Problem('候補マスクは真偽値で指定してください。',422);
                    $slot = $days[$dayId]['slots'][$i];
                    if ((bool)$slot['is_candidate'] !== $value) {
                        $changes[] = ['slot_id'=>$slot['id'],'candidate'=>$value];
                        if (!$value) $removed++;
                    }
                }
            }
            $q=$this->db->prepare('SELECT a.slot_id,COUNT(*) AS count FROM answers a JOIN participants p ON p.id=a.participant_id AND p.is_active=1 JOIN day_slots s ON s.id=a.slot_id JOIN instance_days d ON d.id=s.day_id WHERE d.instance_id=? GROUP BY a.slot_id');
            $q->execute([$id]); $counts=array_column($q->fetchAll(),'count','slot_id'); $affected=0;
            foreach ($changes as $change) if (!$change['candidate']) $affected+=(int)($counts[$change['slot_id']] ?? 0);
            if ($preview) return ['changed_cells'=>count($changes),'excluded_cells'=>$removed,'affected_answers'=>$affected,'answers_revision'=>$row['answers_revision'],'answer_counts'=>(object)$counts];
            if (!is_int($data['expected_answers_revision'] ?? null) || $data['expected_answers_revision']!==$row['answers_revision']) throw new Problem('回答が更新されました。候補帯の影響を再プレビューしてください。',409);
            if ($removed && ($data['confirm_exclusion'] ?? null) !== true) throw new Problem('候補から外れるセルを確認してください。',422);
            if ($changes) {
                // Update only the mask: slot IDs, UTC bounds and referencing history survive.
                $q = $this->db->prepare('UPDATE day_slots SET is_candidate=? WHERE id=?');
                foreach ($changes as $change) $q->execute([(int)$change['candidate'],$change['slot_id']]);
                $this->db->prepare('UPDATE instances SET schema_revision=schema_revision+1,updated_at=? WHERE id=?')->execute([gmdate('Y-m-d\TH:i:s\Z'),$id]);
                $this->audit($id,'candidate-mask',['previous_schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision'],'affected_answers'=>$affected,'changes'=>$changes]);
            }
            return $this->get($id);
        });
    }
    public function reception(string $id,array $data): array
    {
        foreach (array_keys($data) as $key) if (!in_array($key,['action','confirmation','expected_version','expected_schema_revision','expected_answers_revision'],true)) throw new Problem('受付状態の変更に使えない入力です。',422);
        return $this->transaction(function () use ($id,$data) {
            $row=$this->get($id);$action=$data['action'] ?? null;
            if (!in_array($action,['lock','reopen'],true)) throw new Problem('受付終了または再開を指定してください。',422);
            if ($row['status']!==($action==='lock'?'open':'locked')) throw new Problem('受付状態が変わりました。最新版を確認してください。',409);
            foreach (['version','schema_revision','answers_revision'] as $key) if (!is_int($data['expected_'.$key] ?? null)||$data['expected_'.$key]!==$row[$key]) throw new Problem('案件・候補・参加者・回答が更新されました。受付変更の内容を再確認してください。',409);
            if (($data['confirmation'] ?? null)!==$row['title']) throw new Problem('確認のため案件名を正確に入力してください。',422);
            $status=$action==='lock'?'locked':'open';
            $this->db->prepare('UPDATE instances SET status=?,version=version+1,updated_at=? WHERE id=?')->execute([$status,gmdate('Y-m-d\TH:i:s\Z'),$id]);
            $this->audit($id,'reception-'.$action,['previous_status'=>$row['status'],'status'=>$status,'previous_version'=>$row['version'],'schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision']]);
            return $this->get($id);
        });
    }
    public function change(string $id, string $action, array $data): array
    {
        return $this->transaction(function () use ($id,$action,$data) {
            $row = $this->get($id, $action === 'restore');
            $version = $data['expected_version'] ?? null;
            if (!(is_int($version) || (is_string($version) && preg_match('/^[0-9]+$/D', $version))) || (int)$version !== $row['version']) throw new Problem('他の利用者が更新しました。再読み込みして変更を確認してください。',409);
            $title = $row['title']; $description = $row['description']; $status = $row['status']; $deleted = $row['deleted_at']; $before = $row['status_before_delete'];
            if ($action === 'edit') {
                if ($status !== 'open') throw new Problem('受付中の案件のみ編集できます。',409);
                if (array_key_exists('title',$data)) $title = self::text($data,'title',140,true);
                if (array_key_exists('description',$data)) $description = self::text($data,'description',2000);
            } elseif ($action === 'delete') {
                if (($data['confirmation'] ?? null) !== $title) throw new Problem('確認のため案件名を正確に再入力してください。',422);
                $deleted = gmdate('Y-m-d\TH:i:s\Z'); $before = $status; $status = 'deleted';
            } elseif ($action === 'restore') {
                $status = $before; $deleted = null; $before = null;
            } else throw new Problem('操作が不正です。',400);
            $this->db->prepare('UPDATE instances SET title=?,description=?,status=?,deleted_at=?,status_before_delete=?,version=version+1,updated_at=? WHERE id=?')->execute([$title,$description,$status,$deleted,$before,gmdate('Y-m-d\TH:i:s\Z'),$id]);
            $this->audit($id,$action,['title'=>$title,'description'=>$description,'previous_version'=>$row['version']]);
            return $this->get($id,$action === 'delete');
        });
    }
}
