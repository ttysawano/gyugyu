<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules};
$db=Database::connect(':memory:'); Database::migrate($db); $s=new Schedules($db);
$row=$s->create(['title'=>'候補帯','dates'=>['2026-11-02','2026-11-03']]);
$other=$s->create(['title'=>'別案件','dates'=>['2026-11-02']]);
$mask=fn(array $day)=>['day_id'=>$day['id'],'candidates'=>array_map(fn($slot)=>(bool)$slot['is_candidate'],$day['slots'])];
$payload=['expected_version'=>1,'expected_schema_revision'=>1,'expected_answers_revision'=>1,'masks'=>array_map($mask,$row['days'])];
$before=$s->get($row['id']);
check($s->candidates($row['id'],$payload)===$before,'no-op does not advance revision');
$changed=$payload; $changed['masks'][0]['candidates'][6]=false; $changed['masks'][1]['candidates'][47]=true;
rejects(fn()=>$s->candidates($row['id'],$changed),422);
$changed['confirm_exclusion']=true;
foreach ([['expected_version'=>0],['expected_schema_revision'=>0],['expected_schema_revision'=>'1'],['expected_version'=>true]] as $bad) rejects(fn()=>$s->candidates($row['id'],array_replace($changed,$bad)),409);
foreach ([[],[$mask($other['days'][0])],[$mask($row['days'][0]),$mask($row['days'][0])],[['day_id'=>$row['days'][0]['id'],'candidates'=>array_fill(0,47,false)]],[['day_id'=>$row['days'][0]['id'],'candidates'=>array_fill(0,48,0)]],[['day_id'=>[],'candidates'=>[]]]] as $bad) rejects(fn()=>$s->candidates($row['id'],array_replace($changed,['masks'=>$bad])),422);
// Simulated referencing history, not a production answers feature.
$db->exec('CREATE TABLE retained_history(slot_id TEXT REFERENCES day_slots(id), value TEXT)');
$db->prepare('INSERT INTO retained_history VALUES (?,?)')->execute([$row['days'][0]['slots'][6]['id'],'historical answer']);
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_log WHEN NEW.action='candidate-mask' BEGIN SELECT RAISE(ABORT,'test rollback'); END");
try { $s->candidates($row['id'],$changed); throw new RuntimeException('Expected failure'); } catch (PDOException $e) {}
check($s->get($row['id'])===$before,'mask and revision rollback with failed audit');
$db->exec('DROP TRIGGER fail_audit');
$saved=$s->candidates($row['id'],$changed);
check($saved['schema_revision']===2 && $saved['version']===1,'schema revision only');
check($saved['days'][0]['slots'][6]['is_candidate']===0 && $saved['days'][1]['slots'][47]['is_candidate']===1,'atomic multi-day mask update');
foreach ($saved['days'] as $d=>$day) foreach ($day['slots'] as $i=>$slot) {
    unset($slot['is_candidate']); $old=$row['days'][$d]['slots'][$i]; unset($old['is_candidate']);
    check($slot===$old,'slot identity and UTC bounds retained');
}
check($s->get($other['id'])===$other,'cross instance remains unchanged');
rejects(fn()=>$s->candidates($row['id'],$changed),409);
$payload['expected_schema_revision']=2; $restored=$s->candidates($row['id'],$payload+['confirm_exclusion'=>true]);
check($restored['days']===$row['days'],'reenabling restores mask with same slots');
check($db->query('SELECT value FROM retained_history')->fetchColumn()==='historical answer','candidate removal and restoration preserve referenced history');
$audit=$db->query("SELECT * FROM audit_log WHERE action='candidate-mask' ORDER BY id LIMIT 1")->fetch();
check($audit['actor']==='shared-basic' && count(json_decode($audit['summary_json'],true)['changes'])===2,'audit describes exact changed slots without individual attribution');
$payload['expected_schema_revision']=3;
$s->change($row['id'],'edit',['expected_version'=>1,'description'=>'new']);
rejects(fn()=>$s->candidates($row['id'],$payload),409);
$payload['expected_version']=2;
foreach (['locked','confirmed'] as $state) {
    $db->prepare('UPDATE instances SET status=? WHERE id=?')->execute([$state,$row['id']]);
    rejects(fn()=>$s->candidates($row['id'],$payload),409);
}
$db->prepare("UPDATE instances SET status='open' WHERE id=?")->execute([$row['id']]);
$s->change($row['id'],'delete',['expected_version'=>2,'confirmation'=>'候補帯']);
rejects(fn()=>$s->candidates($row['id'],$payload),404);
check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'history foreign keys intact');
