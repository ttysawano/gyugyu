<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Days
{
    public function __construct(private PDO $db) {}
    private function rows(string $id): array
    {
        $q=$this->db->prepare('SELECT d.*, (SELECT COUNT(*) FROM day_slots s WHERE s.day_id=d.id AND s.is_candidate=1) AS candidate_count, (SELECT COUNT(*) FROM answers a JOIN day_slots s ON s.id=a.slot_id WHERE s.day_id=d.id) AS answer_count, (SELECT COUNT(*) FROM answers a JOIN day_slots s ON s.id=a.slot_id JOIN participants p ON p.id=a.participant_id WHERE s.day_id=d.id AND s.is_candidate=1 AND p.is_active=1) AS visible_answers FROM instance_days d WHERE d.instance_id=? ORDER BY d.local_date');
        $q->execute([$id]);return $q->fetchAll();
    }
    public function listing(string $id): array
    {
        $this->db->exec('SAVEPOINT days_read');
        try {$row=(new Schedules($this->db))->get($id);$result=['days'=>$this->rows($id),'version'=>$row['version'],'schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision'],'status'=>$row['status']];$this->db->exec('RELEASE SAVEPOINT days_read');return $result;}
        catch (\Throwable $e) {$this->db->exec('ROLLBACK TO SAVEPOINT days_read');$this->db->exec('RELEASE SAVEPOINT days_read');throw $e;}
    }
    public function change(string $id,string $action,array $data,bool $preview=false): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=(new Schedules($this->db))->get($id);
            if ($row['status']!=='open') throw new Problem('受付中の案件のみ日付を変更できます。',409);
            foreach (['version','schema_revision'] as $key) if (!is_int($data['expected_'.$key] ?? null)||$data['expected_'.$key]!==$row[$key]) throw new Problem('案件または日付構成が更新されました。下書きを控えて最新版を確認してください。',409);
            if (!in_array($action,['add','archive','restore'],true)) throw new Problem('日付操作が不正です。',422);
            $allowed=['action','expected_version','expected_schema_revision','expected_answers_revision','expected_answer_count','expected_visible_answers','confirmed_operation','confirmation',...($action==='add'?['local_date','initial_window']:['day_id'])];
            foreach (array_keys($data) as $key) if (!in_array($key,$allowed,true)) throw new Problem('この日付操作では使えない入力です。',422);
            if (isset($data['action']) && $data['action']!==$action) throw new Problem('日付操作が一致しません。',422);
            $days=$this->rows($id);$target=null;$window=null;
            if ($action==='add') {
                if (!is_string($data['local_date'] ?? null)) throw new Problem('日付の形式が不正です。',422);
                $date=Schedules::preview(['title'=>'date','dates'=>[$data['local_date']]])['dates'][0];
                foreach ($days as $day) if ($day['local_date']===$date) $target=$day;
                if (!$target) $window=Schedules::preview(['title'=>'date','dates'=>[$date],'initial_window'=>$data['initial_window'] ?? ['start'=>'09:00','end'=>'18:00']]);
            } else {
                if (!is_string($data['day_id'] ?? null)) throw new Problem('対象日IDが不正です。',422);
                foreach ($days as $day) if ($day['id']===$data['day_id']) $target=$day;
                if (!$target) throw new Problem('この案件の対象日が見つかりません。',404);
                if (($action==='restore')!==($target['archived_at']!==null)) throw new Problem('日付の状態が変わりました。',409);
                $date=$target['local_date'];
            }
            $operation=$action==='archive'?'archive':($target?($target['archived_at']===null?'existing':'restore'):'new');
            if ($operation==='new' && count($days)>=366) throw new Problem('保存できる日付はアーカイブ済みを含め累計366日までです。同じ日付の履歴復元は可能です。',422);
            $plan=['operation'=>$operation,'day_id'=>$target['id'] ?? null,'local_date'=>$date,'candidate_count'=>$target['candidate_count'] ?? $window['candidate_slots_per_day'],'answer_count'=>$target['answer_count'] ?? 0,'visible_answers'=>$target['visible_answers'] ?? 0,'version'=>$row['version'],'schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision']];
            if ($preview) {$this->db->exec('COMMIT');return $plan;}
            if ($operation==='existing') throw new Problem('この日付は既に有効です。変更はありません。',409);
            if (($data['confirmed_operation'] ?? null)!==$operation) throw new Problem('新規追加・履歴復元・アーカイブの内容をプレビューで確認してください。',422);
            if (!is_int($data['expected_answers_revision'] ?? null)||$data['expected_answers_revision']!==$row['answers_revision']) throw new Problem('回答または参加者が更新されました。影響を再確認してください。',409);
            if (($data['expected_answer_count'] ?? null)!==$plan['answer_count']||($data['expected_visible_answers'] ?? null)!==$plan['visible_answers']) throw new Problem('確認した回答件数が一致しません。再プレビューしてください。',409);
            if ($operation==='archive' && ($data['confirmation'] ?? null)!==$date) throw new Problem('確認のため対象日をYYYY-MM-DDで入力してください。',422);
            $now=gmdate('Y-m-d\TH:i:s\Z');
            if ($operation==='new') {
                $dayId=bin2hex(random_bytes(16));$this->db->prepare('INSERT INTO instance_days(id,instance_id,local_date,sort_index) VALUES (?,?,?,0)')->execute([$dayId,$id,$date]);
                $start=new \DateTimeImmutable($date.' 06:00:00',new \DateTimeZone('Asia/Tokyo'));
                $q=$this->db->prepare('INSERT INTO day_slots VALUES (?,?,?,?,?,?)');
                for ($i=0;$i<48;$i++) {
                    $utc=fn(int $n)=>$start->modify('+'.($n*30).' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');$minute=360+$i*30;
                    $q->execute([bin2hex(random_bytes(16)),$dayId,$i,$utc($i),$utc($i+1),(int)($minute>=$window['start_minute']&&$minute<$window['end_minute'])]);
                }
                $plan['day_id']=$dayId;
            } else $this->db->prepare('UPDATE instance_days SET archived_at=? WHERE id=? AND instance_id=?')->execute([$operation==='archive'?$now:null,$target['id'],$id]);
            foreach ($this->rows($id) as $i=>$day) $this->db->prepare('UPDATE instance_days SET sort_index=? WHERE id=?')->execute([$i,$day['id']]);
            $this->db->prepare('UPDATE instances SET schema_revision=schema_revision+1,updated_at=? WHERE id=?')->execute([$now,$id]);
            $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?,?,?,?,?)')->execute([$id,'shared-basic','day-'.$operation,json_encode($plan,JSON_THROW_ON_ERROR),$now]);
            $this->db->exec('COMMIT');return array_replace($plan,['saved'=>true,'schema_revision'=>$row['schema_revision']+1]);
        } catch (\Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
    }
}
