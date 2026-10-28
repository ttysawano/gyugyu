<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Groups
{
    public function __construct(private PDO $db) {}
    public function listing(string $instance, bool $archived=false): array
    {
        $q=$this->db->prepare('SELECT g.*, (SELECT COUNT(*) FROM participant_groups pg JOIN participants p ON p.id=pg.participant_id WHERE pg.group_id=g.id AND p.is_active=1) AS member_count FROM instance_groups g WHERE instance_id=? AND archived_at IS '.($archived?'NOT NULL':'NULL').' ORDER BY sort_index,id');
        $q->execute([$instance]); return $q->fetchAll();
    }
    private static function text(array $data, string $key, int $max, bool $required=false): string
    {
        $value=array_key_exists($key,$data)?$data[$key]:'';
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) throw new Problem('グループの入力形式が不正です。',422);
        $value=str_replace(["\r\n","\r"],"\n",$value);
        if ($key==='name') $value=preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u','',$value);
        if (($required && $value==='') || mb_strlen($value)>$max) throw new Problem('グループ名は1〜60文字、説明は500文字以内です。',422);
        return $value;
    }
    private static function expected(mixed $value, int $current): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D',$value))) && (int)$value===$current;
    }
    public function change(string $instance, ?string $id, string $action, array $data): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT * FROM instances WHERE id=? AND deleted_at IS NULL'); $q->execute([$instance]); $schedule=$q->fetch();
            if (!$schedule) throw new Problem('案件が見つかりません。',404);
            if ($schedule['status']!=='open') throw new Problem('受付中の案件のみグループを変更できます。',409);
            foreach (['version','schema_revision'] as $key) if (!self::expected($data['expected_'.$key] ?? null,$schedule[$key])) throw new Problem('案件またはグループ構成が更新されました。入力を控えて再読み込みしてください。',409);
            $row=null;
            if ($id!==null) {
                $q=$this->db->prepare('SELECT * FROM instance_groups WHERE instance_id=? AND id=?'); $q->execute([$instance,$id]); $row=$q->fetch();
                if (!$row) throw new Problem('グループが見つかりません。',404);
                if (!self::expected($data['expected_group_version'] ?? null,$row['version'])) throw new Problem('グループが更新されました。',409);
            }
            if (!in_array($action,['create','edit','archive','restore'],true) || (($action==='create')!==($id===null))) throw new Problem('グループ操作が不正です。',400);
            if ($row && (($action==='restore')!==($row['archived_at']!==null))) throw new Problem('グループの状態が変わりました。',409);
            $name=in_array($action,['create','edit'],true)?self::text($data,'name',60,true):$row['name'];
            $description=in_array($action,['create','edit'],true)?self::text($data,'description',500):$row['description'];
            $nameKey=mb_convert_case($name,MB_CASE_FOLD,'UTF-8');
            if (in_array($action,['create','restore'],true) && count($this->listing($instance))>=9) throw new Problem('有効グループは9件までです。',422);
            if ($action!=='archive') {
                $q=$this->db->prepare('SELECT 1 FROM instance_groups WHERE instance_id=? AND name_key=? AND archived_at IS NULL AND id!=?'); $q->execute([$instance,$nameKey,$id ?? '']);
                if ($q->fetchColumn()) throw new Problem('同名の有効グループがあります。',422);
            }
            $memberCount=0;
            if ($action==='archive') {
                if (!self::expected($data['expected_answers_revision'] ?? null,$schedule['answers_revision'])) throw new Problem('参加者・所属が更新されました。影響人数を再確認してください。',409);
                if (($data['confirmation'] ?? null)!==$name) throw new Problem('確認のためグループ名を正確に入力してください。',422);
                $q=$this->db->prepare('SELECT COUNT(*) FROM participant_groups pg JOIN participants p ON p.id=pg.participant_id WHERE pg.group_id=? AND p.is_active=1'); $q->execute([$id]); $memberCount=(int)$q->fetchColumn();
            }
            $now=gmdate('Y-m-d\TH:i:s\Z');
            if ($action==='create') {
                $id=bin2hex(random_bytes(16));
                $q=$this->db->prepare('SELECT COALESCE(MAX(sort_index),-1)+1 FROM instance_groups WHERE instance_id=?'); $q->execute([$instance]); $sort=$q->fetchColumn();
                $this->db->prepare('INSERT INTO instance_groups(id,instance_id,name,name_key,description,sort_index) VALUES (?,?,?,?,?,?)')->execute([$id,$instance,$name,$nameKey,$description,$sort]);
            } else {
                $this->db->prepare('UPDATE instance_groups SET name=?,name_key=?,description=?,archived_at=?,version=version+1 WHERE id=?')->execute([$name,$nameKey,$description,$action==='archive'?$now:null,$id]);
            }
            $this->db->prepare('UPDATE instances SET schema_revision=schema_revision+1,updated_at=? WHERE id=?')->execute([$now,$instance]);
            $summary=['group_id'=>$id,'name'=>$name,'description'=>$description,'previous_schema_revision'=>$schedule['schema_revision'],'affected_participants'=>$memberCount];
            $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?,?,?,?,?)')->execute([$instance,'shared-basic','group-'.$action,json_encode($summary,JSON_THROW_ON_ERROR),$now]);
            $result=['id'=>$id,'schema_revision'=>$schedule['schema_revision']+1]; $this->db->exec('COMMIT'); return $result;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
}
