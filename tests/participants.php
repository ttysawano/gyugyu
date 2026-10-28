<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules,Participants};
$db=Database::connect(':memory:'); Database::migrate($db); $s=new Schedules($db); $p=new Participants($db);
$row=$s->create(['title'=>'回答試験','dates'=>['2026-11-02','2026-11-03']]);
$other=$s->create(['title'=>'別案件','dates'=>['2026-11-02']]);
$slot=$row['days'][0]['slots'][6]['id']; $second=$row['days'][1]['slots'][7]['id'];
$payload=['expected_version'=>1,'expected_schema_revision'=>1,'display_name'=>' 山田 ','group_ids'=>[],'submission_key'=>str_repeat('a',32),'answers'=>[['slot_id'=>$slot,'state'=>'yes'],['slot_id'=>$second,'state'=>'no']]];
$a=$p->submit($row['id'],null,$payload);
check($a['display_name']==='山田' && count($a['answers'])===2 && $a['version']===1 && $a['submitted_at']!==null,'atomic registration and submission');
rejects(fn()=>$p->submit($row['id'],null,$payload),409);
$b=$p->submit($row['id'],null,array_replace($payload,['submission_key'=>str_repeat('b',32),'answers'=>[]]));
check($b['id']!==$a['id'] && $b['answers']===[] && $b['submitted_at']!==null,'duplicate name and all-unknown submission');
$list=$s->listing()[0];
$found=array_values(array_filter($s->listing(),fn($r)=>$r['id']===$row['id']))[0];
check($found['participant_count']===2 && $found['submitted_count']===2,'submitted count is not yes count');
foreach ([['display_name'=>' '],['display_name'=>'　'],['display_name'=>str_repeat('あ',81)],['display_name'=>[]],['display_name'=>"bad\0name"],['group_ids'=>['foreign']],['answers'=>[['slot_id'=>$slot,'state'=>'unknown']]],['answers'=>[['slot_id'=>$other['days'][0]['slots'][6]['id'],'state'=>'yes']]],['answers'=>[['slot_id'=>$row['days'][0]['slots'][0]['id'],'state'=>'yes']]],['answers'=>[$payload['answers'][0],$payload['answers'][0]]]] as $bad) rejects(fn()=>$p->submit($row['id'],$a['id'],array_replace($payload,['expected_participant_version'=>1],$bad)),422);
rejects(fn()=>$p->submit($other['id'],$a['id'],$payload+['expected_participant_version'=>1]),404);
rejects(fn()=>$p->submit($row['id'],$a['id'],array_replace($payload,['expected_participant_version'=>0])),409);
$mask=['expected_version'=>1,'expected_schema_revision'=>1,'masks'=>[['day_id'=>$row['days'][0]['id'],'candidates'=>array_map(fn($slot)=>(bool)$slot['is_candidate'],$row['days'][0]['slots'])]],'confirm_exclusion'=>true];
$mask['masks'][0]['candidates'][6]=false;
$preview=$s->candidates($row['id'],$mask,true);
check($preview['affected_answers']===1,'candidate preview counts actual answer rows');
// Another participant submits between preview and mask commit: old confirmation must fail.
$p->submit($row['id'],$b['id'],array_replace($payload,['expected_participant_version'=>1]));
rejects(fn()=>$s->candidates($row['id'],$mask+['expected_answers_revision'=>$preview['answers_revision']]),409);
$preview=$s->candidates($row['id'],$mask,true); check($preview['affected_answers']===2,'preview recounts concurrent submission');
$s->candidates($row['id'],$mask+['expected_answers_revision'=>$preview['answers_revision']]);
rejects(fn()=>$p->submit($row['id'],$a['id'],$payload+['expected_participant_version'=>1]),409);
$edited=array_replace($payload,['expected_participant_version'=>1,'expected_schema_revision'=>2,'display_name'=>'改名','answers'=>[]]);
$result=$p->submit($row['id'],$a['id'],$edited);
check($result['id']===$a['id'] && $result['display_name']==='改名' && $result['version']===2,'stable participant id and revision');
check($result['answers']===[['slot_id'=>$slot,'state'=>'yes']],'omitted active candidate cleared; disabled history retained');
rejects(fn()=>$p->submit($row['id'],$a['id'],array_replace($edited,['expected_participant_version'=>2,'answers'=>[['slot_id'=>$slot,'state'=>'no']]])),422);
$mask['masks'][0]['candidates'][6]=true; $mask['expected_schema_revision']=2;
$preview=$s->candidates($row['id'],$mask,true); $restored=$s->candidates($row['id'],$mask+['expected_answers_revision'=>$preview['answers_revision']]);
check($p->get($row['id'],$a['id'])['answers']===[['slot_id'=>$slot,'state'=>'yes']],'reenabling restores real stored answer');
// Name, answers, versions and submitted timestamp all roll back if audit fails.
$db->exec("CREATE TRIGGER fail_submission BEFORE INSERT ON audit_log WHEN NEW.action LIKE 'participant-%' BEGIN SELECT RAISE(ABORT,'rollback'); END");
$before=$p->get($row['id'],$a['id']); $scheduleBefore=$s->get($row['id']);
try { $p->submit($row['id'],$a['id'],array_replace($edited,['expected_participant_version'=>2,'expected_schema_revision'=>3])); throw new RuntimeException('expected audit failure'); } catch (PDOException $e) {}
check($p->get($row['id'],$a['id'])===$before && $s->get($row['id'])===$scheduleBefore,'submission atomic rollback');
try { $p->submit($row['id'],null,array_replace($payload,['submission_key'=>str_repeat('c',32),'expected_schema_revision'=>3])); throw new RuntimeException('expected audit failure'); } catch (PDOException $e) {}
check(count($s->get($row['id'])['participants'])===2,'failed registration leaves no participant');
$db->exec('DROP TRIGGER fail_submission');
try { $db->prepare('INSERT INTO answers VALUES (?,?,?,?)')->execute([$a['id'],$other['days'][0]['slots'][6]['id'],'yes','now']); throw new RuntimeException('cross-instance trigger did not fire'); } catch(PDOException $e) {check(true,'DB cross-instance protection');}
$hide=['expected_version'=>1,'expected_schema_revision'=>3,'expected_participant_version'=>2,'confirmation'=>'wrong','is_active'=>false];
rejects(fn()=>$p->deactivate($row['id'],$a['id'],$hide),422);
$hide['confirmation']='改名'; $hidden=$p->deactivate($row['id'],$a['id'],$hide);
check($hidden['is_active']===0 && $hidden['answers']===$before['answers'],'hide retains real answers');
check(count($s->get($row['id'])['participants'])===1,'inactive participant hidden in normal detail');
rejects(fn()=>$p->deactivate($row['id'],$a['id'],$hide),409);
$db->prepare('UPDATE participants SET is_active=1 WHERE id=?')->execute([$a['id']]);
check($p->get($row['id'],$a['id'])['answers']===$before['answers'],'restoration fixture retains answers; restoration UI not implemented');
foreach (['locked','confirmed'] as $state) {
 $db->prepare('UPDATE instances SET status=? WHERE id=?')->execute([$state,$row['id']]);
 rejects(fn()=>$p->submit($row['id'],$b['id'],array_replace($payload,['expected_schema_revision'=>3,'expected_participant_version'=>2])),409);
}
$db->prepare("UPDATE instances SET status='open' WHERE id=?")->execute([$row['id']]);
$s->change($row['id'],'delete',['expected_version'=>1,'confirmation'=>'回答試験']);
rejects(fn()=>$p->submit($row['id'],$b['id'],array_replace($payload,['expected_schema_revision'=>3,'expected_participant_version'=>2])),404);
$s->change($row['id'],'restore',['expected_version'=>2]);
check($p->get($row['id'],$a['id'])['answers']===$before['answers'],'schedule delete/restore retains answers');
check($db->query("SELECT COUNT(*) FROM audit_log WHERE actor!='shared-basic'")->fetchColumn()===0,'shared audit actor');
check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'real answer foreign keys');

// Upgrade a pre-participant database in place, preserving existing schedule IDs/data.
$upgrade=Database::connect(':memory:');
$upgrade->exec(file_get_contents(dirname(__DIR__).'/database/migrations/001_initial.sql'));
$upgrade->exec("CREATE TABLE migrations(name TEXT PRIMARY KEY); INSERT INTO migrations VALUES ('001_initial.sql')");
$upgrade->exec("INSERT INTO instances(id,title,created_at,updated_at) VALUES ('existing','旧案件','before','before')");
Database::migrate($upgrade); Database::migrate($upgrade);
$old=(new Schedules($upgrade))->get('existing');
check($old['title']==='旧案件' && $old['answers_revision']===1 && $old['participants']===[],'migration preserves preexisting schedules');
$valid=$p->submit($other['id'],null,array_replace($payload,['display_name'=>str_repeat('😀',80),'submission_key'=>str_repeat('d',32),'answers'=>[]]));
check(mb_strlen($valid['display_name'])===80,'80 Unicode codepoints accepted');
rejects(fn()=>$p->submit($other['id'],null,array_replace($payload,['display_name'=>str_repeat('😀',81),'submission_key'=>str_repeat('e',32),'answers'=>[]])),422);
