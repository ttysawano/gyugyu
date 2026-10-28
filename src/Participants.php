<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Participants
{
    public function __construct(private PDO $db) {}
    public function get(string $instance, string $id): array
    {
        $q=$this->db->prepare('SELECT id,instance_id,display_name,version,is_active,submitted_at,created_at,updated_at FROM participants WHERE instance_id=? AND id=?');
        $q->execute([$instance,$id]); $row=$q->fetch();
        if (!$row) throw new Problem('参加者が見つかりません。',404);
        $q=$this->db->prepare('SELECT slot_id,state FROM answers WHERE participant_id=? ORDER BY slot_id'); $q->execute([$id]);
        $row['answers']=$q->fetchAll();
        $q=$this->db->prepare('SELECT g.id FROM participant_groups pg JOIN instance_groups g ON g.id=pg.group_id WHERE pg.participant_id=? AND g.archived_at IS NULL ORDER BY g.sort_index,g.id'); $q->execute([$id]);
        $row['group_ids']=$q->fetchAll(PDO::FETCH_COLUMN); return $row;
    }
    public function inactive(string $instance): array
    {
        $this->db->exec('SAVEPOINT inactive_read');
        try {
            $schedule=(new Schedules($this->db))->get($instance);
            $q=$this->db->prepare('SELECT id,display_name,version,submitted_at,(SELECT COUNT(*) FROM answers a WHERE a.participant_id=p.id) AS answer_count FROM participants p WHERE instance_id=? AND is_active=0 ORDER BY created_at,id');$q->execute([$instance]);$people=$q->fetchAll();
            $groups=$this->db->prepare('SELECT g.id,g.name,g.archived_at FROM instance_groups g JOIN participant_groups pg ON pg.group_id=g.id WHERE pg.participant_id=? ORDER BY g.sort_index,g.id');
            foreach ($people as &$person) {$groups->execute([$person['id']]);$person['groups']=$groups->fetchAll();}unset($person);
            $result=['participants'=>$people,'version'=>$schedule['version'],'schema_revision'=>$schedule['schema_revision'],'answers_revision'=>$schedule['answers_revision'],'status'=>$schedule['status']];
            $this->db->exec('RELEASE SAVEPOINT inactive_read');return $result;
        } catch (\Throwable $e) {$this->db->exec('ROLLBACK TO SAVEPOINT inactive_read');$this->db->exec('RELEASE SAVEPOINT inactive_read');throw $e;}
    }
    public function restore(string $instance,string $id,array $data): array
    {
        $allowed=['expected_version','expected_schema_revision','expected_answers_revision','expected_participant_version','confirmation'];
        foreach (array_keys($data) as $key) if (!in_array($key,$allowed,true)) throw new Problem('復帰では氏名・回答・所属の書換えはできません。',422);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM instances WHERE id=? AND deleted_at IS NULL');$q->execute([$instance]);$schedule=$q->fetch();
            if (!$schedule) throw new Problem('案件が見つかりません。',404);
            $row=$this->get($instance,$id);
            if ($schedule['status']!=='open'||$row['is_active']) throw new Problem('案件または参加者の状態が変わりました。',409);
            foreach (['version','schema_revision','answers_revision'] as $key) if (!is_int($data['expected_'.$key] ?? null)||$data['expected_'.$key]!==$schedule[$key]) throw new Problem('案件・候補・所属・回答が更新されました。復帰内容を再確認してください。',409);
            if (!is_int($data['expected_participant_version'] ?? null)||$data['expected_participant_version']!==$row['version']) throw new Problem('参加者が更新されました。復帰内容を再確認してください。',409);
            if (($data['confirmation'] ?? null)!==$row['display_name']) throw new Problem('確認の氏名が一致しません。',422);
            $now=gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare('UPDATE participants SET is_active=1,version=version+1,updated_at=? WHERE id=? AND instance_id=?')->execute([$now,$id,$instance]);
            $this->db->prepare('UPDATE instances SET answers_revision=answers_revision+1,updated_at=? WHERE id=?')->execute([$now,$instance]);
            $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?,?,?,?,?)')->execute([$instance,'shared-basic','participant-restore',json_encode(['participant_id'=>$id,'previous_version'=>$row['version']],JSON_THROW_ON_ERROR),$now]);
            $result=$this->get($instance,$id);$this->db->exec('COMMIT');return $result;
        } catch (\Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
    }
    public function deactivate(string $instance, string $id, array $data): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM instances WHERE id=? AND deleted_at IS NULL'); $q->execute([$instance]); $schedule=$q->fetch();
            if (!$schedule) throw new Problem('案件が見つかりません。',404);
            $row=$this->get($instance,$id);
            if ($schedule['status']!=='open' || !$row['is_active'] || ($data['expected_version'] ?? null)!==$schedule['version'] || ($data['expected_schema_revision'] ?? null)!==$schedule['schema_revision'] || ($data['expected_participant_version'] ?? null)!==$row['version']) throw new Problem('案件または参加者が更新されました。',409);
            if (($data['confirmation'] ?? null)!==$row['display_name'] || ($data['is_active'] ?? null)!==false) throw new Problem('確認の氏名が一致しません。',422);
            $now=gmdate('Y-m-d\TH:i:s\Z');
            $this->db->prepare('UPDATE participants SET is_active=0,version=version+1,updated_at=? WHERE id=?')->execute([$now,$id]);
            $this->db->prepare('UPDATE instances SET answers_revision=answers_revision+1,updated_at=? WHERE id=?')->execute([$now,$instance]);
            $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?,?,?,?,?)')->execute([$instance,'shared-basic','participant-hide',json_encode(['participant_id'=>$id,'previous_version'=>$row['version']],JSON_THROW_ON_ERROR),$now]);
            $result=$this->get($instance,$id); $this->db->exec('COMMIT'); return $result;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function submit(string $instance, ?string $id, array $data): array
    {
        $name=$data['display_name'] ?? null;
        if (!is_string($name) || !mb_check_encoding($name,'UTF-8') || str_contains($name,"\0")) throw new Problem('氏名の形式が不正です。',422);
        $name=preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u','',$name);
        if ($name==='' || mb_strlen($name)>80) throw new Problem('氏名は1〜80文字で入力してください。',422);
        if (array_key_exists('group_ids',$data) && (!is_array($data['group_ids']) || !array_is_list($data['group_ids']) || count($data['group_ids'])>9)) throw new Problem('所属グループの形式が不正です。',422);
        $answers=$data['answers'] ?? null;
        if (!is_array($answers) || !array_is_list($answers) || count($answers)>366*48) throw new Problem('回答の形式が不正です。',422);
        // A public per-form request key prevents duplicate registration after a lost response.
        // It is not a credential and never grants editing permission.
        $key=$data['submission_key'] ?? null;
        if ($id===null && (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D',$key))) throw new Problem('登録リクエストの識別子が不正です。',422);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM instances WHERE id=? AND deleted_at IS NULL'); $q->execute([$instance]); $schedule=$q->fetch();
            if (!$schedule) throw new Problem('案件が見つかりません。',404);
            if ($schedule['status']!=='open') throw new Problem('受付中の案件のみ回答できます。',409);
            foreach (['version','schema_revision'] as $field) {
                if (!is_int($data['expected_'.$field] ?? null) || $data['expected_'.$field]!==$schedule[$field]) throw new Problem('案件または候補帯が更新されました。下書きを控えて再確認してください。',409);
            }
            $previous=null;
            if ($id!==null) {
                $previous=$this->get($instance,$id);
                if (!$previous['is_active'] || !is_int($data['expected_participant_version'] ?? null) || $data['expected_participant_version']!==$previous['version']) throw new Problem('参加者の回答が更新されました。下書きを控えて再確認してください。',409);
            } else {
                $q=$this->db->prepare('SELECT id FROM participants WHERE instance_id=? AND submission_key=?'); $q->execute([$instance,$key]);
                if ($q->fetchColumn()) throw new Problem('この登録は既に保存されています。一覧を再読み込みして確認してください。',409);
            }
            $groups=$data['group_ids'] ?? ($previous['group_ids'] ?? []); $seenGroups=[];
            $q=$this->db->prepare('SELECT id FROM instance_groups WHERE instance_id=? AND archived_at IS NULL'); $q->execute([$instance]); $validGroups=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
            foreach ($groups as $group) {
                if (!is_string($group) || !isset($validGroups[$group]) || isset($seenGroups[$group])) throw new Problem('この案件の有効グループを重複なく指定してください。',422);
                $seenGroups[$group]=true;
            }
            $q=$this->db->prepare('SELECT s.id FROM day_slots s JOIN instance_days d ON d.id=s.day_id WHERE d.instance_id=? AND d.archived_at IS NULL AND s.is_candidate=1'); $q->execute([$instance]);
            $valid=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true); $seen=[];
            foreach ($answers as $answer) {
                if (!is_array($answer) || !is_string($answer['slot_id'] ?? null) || !isset($valid[$answer['slot_id']]) || isset($seen[$answer['slot_id']]) || !in_array($answer['state'] ?? null,['yes','maybe','no'],true)) throw new Problem('回答にはこの案件の有効候補スロットと○△×を指定してください。',422);
                $seen[$answer['slot_id']]=true;
            }
            $now=gmdate('Y-m-d\TH:i:s\Z');
            if ($id===null) {
                $id=bin2hex(random_bytes(16));
                $this->db->prepare('INSERT INTO participants(id,instance_id,display_name,submitted_at,created_at,updated_at,submission_key) VALUES (?,?,?,?,?,?,?)')->execute([$id,$instance,$name,$now,$now,$now,$key]);
            } else {
                $this->db->prepare('UPDATE participants SET display_name=?,version=version+1,submitted_at=?,updated_at=? WHERE id=?')->execute([$name,$now,$now,$id]);
                $this->db->prepare('DELETE FROM answers WHERE participant_id=? AND slot_id IN (SELECT s.id FROM day_slots s JOIN instance_days d ON d.id=s.day_id WHERE d.instance_id=? AND d.archived_at IS NULL AND s.is_candidate=1)')->execute([$id,$instance]);
            }
            $this->db->prepare('DELETE FROM participant_groups WHERE participant_id=? AND group_id IN (SELECT id FROM instance_groups WHERE instance_id=? AND archived_at IS NULL)')->execute([$id,$instance]);
            $q=$this->db->prepare('INSERT INTO participant_groups(participant_id,group_id,instance_id) VALUES (?,?,?)');
            foreach ($groups as $group) $q->execute([$id,$group,$instance]);
            $q=$this->db->prepare('INSERT INTO answers(participant_id,slot_id,state,updated_at) VALUES (?,?,?,?)');
            foreach ($answers as $answer) $q->execute([$id,$answer['slot_id'],$answer['state'],$now]);
            $this->db->prepare('UPDATE instances SET answers_revision=answers_revision+1,updated_at=? WHERE id=?')->execute([$now,$instance]);
            $summary=['participant_id'=>$id,'display_name'=>$name,'previous_version'=>$previous['version'] ?? null,'group_ids'=>$groups,'answers'=>$answers];
            $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?,?,?,?,?)')->execute([$instance,'shared-basic',$previous?'participant-submit':'participant-create',json_encode($summary,JSON_THROW_ON_ERROR),$now]);
            $result=$this->get($instance,$id); $this->db->exec('COMMIT'); return $result;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
}
