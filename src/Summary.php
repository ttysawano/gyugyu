<?php
declare(strict_types=1);
namespace Gyugyu;
final class Summary
{
    public static function calculate(array $row,string $mode='all',array $selected=[]): array
    {
        if (!in_array($mode,['all','include','exclude'],true) || !array_is_list($selected)) throw new Problem('集計条件が不正です。',422);
        $groups=array_column(array_filter($row['groups'],fn($g)=>empty($g['archived_at'])),'id');
        foreach ($selected as $id) if (!is_string($id)||!in_array($id,$groups,true)) throw new Problem('この案件の有効グループを指定してください。',422);
        if (!$groups) $mode='all';
        $people=[];
        foreach ($row['participants'] as $p) {
            if (!$p['is_active']) continue;
            $included=(bool)array_intersect($selected,$p['group_ids']);
            if ($mode==='include'&&!$included || $mode==='exclude'&&$included) continue;
            $people[$p['id']]=$p;
        }
        $n=count($people);$slots=[];$answers=[];
        foreach ($people as $p) $answers[$p['id']]=array_column($p['answers'],'state','slot_id');
        foreach ($row['days'] as $day) {
            if (!empty($day['archived_at'])) continue;
            foreach ($day['slots'] as $slot) {
                $members=['yes'=>[],'maybe'=>[],'no'=>[],'unknown'=>[]];
                foreach ($people as $p) {
                    $state=$answers[$p['id']][$slot['id']] ?? 'unknown';
                    $members[$state][]=['id'=>$p['id'],'name'=>$p['display_name']];
                }
                $counts=array_map('count',$members);$active=(bool)$slot['is_candidate'];
                $slots[]=['id'=>$slot['id'],'day_id'=>$day['id'],'starts_at_utc'=>$slot['starts_at_utc'],'candidate'=>$active,'N'=>$n,'counts'=>$counts,'members'=>$members,'score'=>$active&&$n>0?$counts['yes']-($n+1)*$counts['no']:null,'provisional'=>$active&&$n>0&&$counts['unknown']>0,'all_yes'=>$active&&$n>0&&$counts['yes']===$n];
            }
        }
        $ranking=array_values(array_filter($slots,fn($s)=>$s['score']!==null));
        usort($ranking,fn($a,$b)=>($b['score']<=>$a['score'])?:strcmp($a['starts_at_utc'],$b['starts_at_utc']));
        return ['N'=>$n,'participant_ids'=>array_keys($people),'slots'=>$slots,'ranking'=>array_column($ranking,'id')];
    }
}
