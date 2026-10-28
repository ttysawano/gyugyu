<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Confirmation
{
    public function __construct(private PDO $db) {}
    public function current(string $id): ?array
    {
        $q=$this->db->prepare('SELECT slot_id,starts_at_utc,ends_at_utc,confirmed_at,mask_json FROM instance_confirmations WHERE instance_id=?');$q->execute([$id]);$row=$q->fetch();if (!$row) return null;
        $row['mask']=json_decode($row['mask_json'],true,64,JSON_THROW_ON_ERROR);unset($row['mask_json']);return $row;
    }
    public function change(string $id,string $action,array $data,bool $preview=false): array
    {
        $allowed=['expected_version','expected_schema_revision','expected_answers_revision','confirmation','confirm_selection',...($action==='confirm'?['slot_id','mode','group_ids']:[])];
        foreach (array_keys($data) as $key) if (!in_array($key,$allowed,true)) throw new Problem('確定操作に使えない入力です。',422);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=(new Schedules($this->db))->get($id);
            if (!in_array($action,['confirm','cancel'],true)) throw new Problem('操作が不正です。',422);
            if ($action==='confirm'?!in_array($row['status'],['open','locked'],true):$row['status']!=='confirmed') throw new Problem('案件の状態が変わりました。',409);
            foreach (['version','schema_revision','answers_revision'] as $key) if (!is_int($data['expected_'.$key] ?? null)||$data['expected_'.$key]!==$row[$key]) throw new Problem('案件・候補・回答・所属が更新されました。確定内容を再確認してください。',409);
            $decision=$row['confirmation'];
            if ($action==='confirm') {
                $slotId=$data['slot_id'] ?? null;$mode=$data['mode'] ?? null;$selected=$data['group_ids'] ?? null;
                if (!is_string($slotId)||!is_string($mode)||!is_array($selected)) throw new Problem('候補枠と対象条件を指定してください。',422);
                $summary=Summary::calculate($row,$mode,$selected);$slot=null;
                foreach ($row['days'] as $day) foreach ($day['slots'] as $candidate) if ($candidate['id']===$slotId&&$candidate['is_candidate']) {$slot=$candidate;}
                if (!$slot) throw new Problem('この案件の有効日・有効候補から30分枠を選んでください。',422);
                if (!$row['groups']) $mode='all';$selected=$mode==='all'?[]:array_values(array_unique($selected));sort($selected);
                $total=array_column($summary['slots'],null,'id')[$slotId];
                $people=array_values(array_filter($row['participants'],fn($p)=>in_array($p['id'],$summary['participant_ids'],true)));
                $people=array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['display_name'],'group_ids'=>$p['group_ids'],'state'=>array_column($p['answers'],'state','slot_id')[$slotId] ?? 'unknown'],$people);
                $mask=['mode'=>$mode,'group_ids'=>$selected,'groups'=>array_map(fn($g)=>['id'=>$g['id'],'name'=>$g['name']],$row['groups']),'participant_ids'=>$summary['participant_ids'],'participants'=>$people,'N'=>$total['N'],'counts'=>$total['counts'],'score'=>$total['score'],'provisional'=>$total['provisional'],'version'=>$row['version'],'schema_revision'=>$row['schema_revision'],'answers_revision'=>$row['answers_revision']];
                $decision=['slot_id'=>$slotId,'starts_at_utc'=>$slot['starts_at_utc'],'ends_at_utc'=>$slot['ends_at_utc'],'confirmed_at'=>null,'mask'=>$mask];
            }
            if ($preview) {$this->db->exec('COMMIT');return ['action'=>$action,'decision'=>$decision];}
            if (($data['confirmation'] ?? null)!==$row['title']||($data['confirm_selection'] ?? null)!==true) throw new Problem('案件名と、表示された日時・対象条件または確定取消を確認してください。',422);
            $now=gmdate('Y-m-d\TH:i:s\Z');
            if ($action==='confirm') {
                $decision['confirmed_at']=$now;
                $this->db->prepare('INSERT INTO instance_confirmations VALUES (?,?,?,?,?,?)')->execute([$id,$decision['slot_id'],$decision['starts_at_utc'],$decision['ends_at_utc'],$now,json_encode($decision['mask'],JSON_THROW_ON_ERROR)]);
            } else $this->db->prepare('DELETE FROM instance_confirmations WHERE instance_id=?')->execute([$id]);
            $this->db->prepare('UPDATE instances SET status=?,version=version+1,updated_at=? WHERE id=?')->execute([$action==='confirm'?'confirmed':'open',$now,$id]);
            $this->db->prepare('INSERT INTO audit_log(instance_id,actor,action,summary_json,created_at) VALUES (?,?,?,?,?)')->execute([$id,'shared-basic','decision-'.$action,json_encode(['previous_status'=>$row['status'],'previous_version'=>$row['version'],'decision'=>$decision],JSON_THROW_ON_ERROR),$now]);
            $result=(new Schedules($this->db))->get($id);$this->db->exec('COMMIT');return $result;
        } catch (\Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
    }
}
