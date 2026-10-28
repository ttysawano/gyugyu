<?php
declare(strict_types=1);
namespace Gyugyu;
use PDO;
final class Export
{
    public function __construct(private PDO $db) {}
    public static function csvText(string $value): string
    {
        // Quoting alone does not prevent spreadsheet formulas. Preserve raw JSON/DB values.
        return preg_match('/^[=+\-@＝＋－＠\s\p{Z}\p{Cc}\p{Cf}]/u',$value)?"'".$value:$value;
    }
    private static function jst(string $utc): string
    {
        return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d\TH:i:sP');
    }
    public function generate(string $id,array $options): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new Problem('案件IDが不正です。',422);
        foreach (array_keys($options) as $key) if (!in_array($key,['route','format','scope','mode','group_ids'],true)) throw new Problem('未対応の出力条件です。',422);
        $format=$options['format'] ?? 'json';$scope=$options['scope'] ?? 'all';$mode=$options['mode'] ?? 'all';$selected=$options['group_ids'] ?? [];
        if (!is_string($format)||!in_array($format,['json','wide','long','summary'],true)||!is_string($scope)||!in_array($scope,['all','mask'],true)||!is_string($mode)||!is_array($selected)) throw new Problem('出力条件が不正です。',422);
        if ($format==='json'&&($scope!=='all'||$mode!=='all'||$selected!==[])) throw new Problem('完全JSONは全データを出力します。',422);
        $this->db->exec('SAVEPOINT export_read');
        try {
            $row=(new Schedules($this->db))->get($id);
            // Validate even ignored mask inputs; never accept another instance's group IDs.
            // Complete JSON has already rejected any mask; avoid constructing unused slot summaries.
            if ($format!=='json') Summary::calculate($row,$mode,$selected);
            if ($scope==='all') {$mode='all';$selected=[];}
            if (!$row['groups']) $mode='all';
            $selected=array_values(array_unique($selected));sort($selected);
            $generated=gmdate('Y-m-d\TH:i:s\Z');
            if ($format==='json') $content=$this->complete($row,$generated);
            else $content=$this->csv($row,$format,$scope,$mode,$selected,$generated);
            $this->db->exec('RELEASE SAVEPOINT export_read');
            return ['content'=>$content,'type'=>$format==='json'?'application/json; charset=utf-8':'text/csv; charset=utf-8','filename'=>'gyugyu-'.$id.'-'.str_replace(['-',':'],'',$generated).'-'.$scope.'-'.$format.($format==='json'?'.json':'.csv')];
        } catch (\Throwable $e) {$this->db->exec('ROLLBACK TO SAVEPOINT export_read');$this->db->exec('RELEASE SAVEPOINT export_read');throw $e;}
    }
    private function complete(array $row,string $generated): string
    {
        $instance=array_intersect_key($row,array_flip(['id','title','description','timezone','status','version','schema_revision','answers_revision','created_at','updated_at','deleted_at','status_before_delete','confirmation']));
        $q=$this->db->prepare('SELECT id,instance_id,local_date,sort_index,archived_at FROM instance_days WHERE instance_id=? ORDER BY sort_index,id');$q->execute([$row['id']]);$days=$q->fetchAll();
        $q=$this->db->prepare('SELECT id,day_id,ordinal,starts_at_utc,ends_at_utc,is_candidate FROM day_slots WHERE day_id=? ORDER BY ordinal');
        foreach ($days as &$day) {
            $q->execute([$day['id']]);$day['slots']=$q->fetchAll();
            foreach ($day['slots'] as &$slot) {$slot['is_candidate']=(bool)$slot['is_candidate'];$slot['starts_at_jst']=self::jst($slot['starts_at_utc']);$slot['ends_at_jst']=self::jst($slot['ends_at_utc']);}unset($slot);
        }unset($day);
        $q=$this->db->prepare('SELECT id,instance_id,name,description,version,archived_at,sort_index FROM instance_groups WHERE instance_id=? ORDER BY sort_index,id');$q->execute([$row['id']]);$groups=$q->fetchAll();
        $q=$this->db->prepare('SELECT id,instance_id,display_name,version,is_active,submitted_at,created_at,updated_at FROM participants WHERE instance_id=? ORDER BY created_at,id');$q->execute([$row['id']]);$people=$q->fetchAll();
        $members=$this->db->prepare('SELECT group_id FROM participant_groups WHERE instance_id=? AND participant_id=? ORDER BY group_id');
        $answers=$this->db->prepare('SELECT slot_id,state,updated_at FROM answers WHERE participant_id=? ORDER BY slot_id');
        foreach ($people as &$person) {$person['is_active']=(bool)$person['is_active'];$members->execute([$row['id'],$person['id']]);$person['group_ids']=$members->fetchAll(PDO::FETCH_COLUMN);$answers->execute([$person['id']]);$person['answers']=$answers->fetchAll();}unset($person);
        return json_encode(['format_version'=>1,'generated_at'=>$generated,'scope'=>'all','unknown_representation'=>'missing_answer','instance'=>$instance,'days'=>$days,'groups'=>$groups,'participants'=>$people],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
    }
    private function csv(array $row,string $format,string $scope,string $mode,array $selected,string $generated): string
    {
        $summary=Summary::calculate($row,$mode,$selected);$totals=array_column($summary['slots'],null,'id');
        $people=array_values(array_filter($row['participants'],fn($p)=>in_array($p['id'],$summary['participant_ids'],true)));
        $slots=[];foreach ($row['days'] as $day) foreach ($day['slots'] as $slot) if ($slot['is_candidate']) $slots[]=$slot;
        $stream=fopen('php://temp','w+');
        try {
            fwrite($stream,"\xEF\xBB\xBF");
            $write=fn(array $values)=>fputcsv($stream,array_map(fn($value)=>is_string($value)?self::csvText($value):($value===null?'':(is_bool($value)?(int)$value:$value)),$values),',','"','',"\r\n");
            $header=['instance_id','title','description','timezone','status','version','schema_revision','answers_revision','generated_at','scope','mask_mode','selected_group_ids','confirmed_slot_id','confirmed_start_utc','confirmed_end_utc','confirmed_at','confirmed_mask_json'];
            $base=[$row['id'],$row['title'],$row['description'],$row['timezone'],$row['status'],$row['version'],$row['schema_revision'],$row['answers_revision'],$generated,$scope,$mode,json_encode($selected,JSON_THROW_ON_ERROR),$row['confirmation']['slot_id'] ?? null,$row['confirmation']['starts_at_utc'] ?? null,$row['confirmation']['ends_at_utc'] ?? null,$row['confirmation']['confirmed_at'] ?? null,$row['confirmation']?json_encode($row['confirmation']['mask'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null];
            $timeHeader=['slot_id','day_id','slot_start_utc','slot_end_utc','slot_start_jst','slot_end_jst'];
            $times=fn($slot)=>[$slot['id'],$slot['day_id'],$slot['starts_at_utc'],$slot['ends_at_utc'],self::jst($slot['starts_at_utc']),self::jst($slot['ends_at_utc'])];
            if ($format==='summary') {
                $write([...$header,...$timeHeader,'N','Y','T','X','U','score','provisional','all_yes']);
                foreach ($slots as $slot) {$s=$totals[$slot['id']];$write([...$base,...$times($slot),$s['N'],...array_map(fn($state)=>$s['N']?$s['counts'][$state]:'—',['yes','maybe','no','unknown']),$s['score'] ?? '—',$s['provisional'],$s['all_yes']]);}
            } else {
                $columns=$format==='wide'?array_map(fn($slot)=>self::jst($slot['starts_at_utc']).'/'.self::jst($slot['ends_at_utc']).' ['.$slot['id'].']',$slots):[...$timeHeader,'state'];
                $write([...$header,'participant_id','name','groups',...$columns]);
                $groupNames=array_column($row['groups'],'name','id');
                foreach ($people as $p) {
                    $groups=array_map(fn($id)=>['id'=>$id,'name'=>$groupNames[$id]],$p['group_ids']);
                    $prefix=[...$base,$p['id'],$p['display_name'],json_encode($groups,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)];$answers=array_column($p['answers'],'state','slot_id');
                    if ($format==='wide') $write([...$prefix,...array_map(fn($slot)=>['yes'=>'○','maybe'=>'△','no'=>'×','unknown'=>''][$answers[$slot['id']] ?? 'unknown'],$slots)]);
                    else foreach ($slots as $slot) $write([...$prefix,...$times($slot),$answers[$slot['id']] ?? 'unknown']);
                }
            }
            rewind($stream);return stream_get_contents($stream);
        } finally {fclose($stream);}
    }
}
