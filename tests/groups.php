<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules,Participants,Groups};
$db=Database::connect(':memory:');Database::migrate($db);$s=new Schedules($db);$g=new Groups($db);$p=new Participants($db);
$row=$s->create(['title'=>'グループ','dates'=>['2026-11-02']]);$id=$row['id'];
$revision=fn()=>['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision'],'expected_answers_revision'=>$s->get($id)['answers_revision']];
foreach (['', '　', str_repeat('名',61),"bad\0",[],null] as $name) rejects(fn()=>$g->change($id,null,'create',$revision()+['name'=>$name]),422);
rejects(fn()=>$g->change($id,null,'create',$revision()+['name'=>'ok','description'=>str_repeat('説',501)]),422);
$a=$g->change($id,null,'create',$revision()+['name'=>' Team ']);$b=$g->change($id,null,'create',$revision()+['name'=>'<script>']);
rejects(fn()=>$g->change($id,null,'create',$revision()+['name'=>'team']),422);
$slot=$row['days'][0]['slots'][6]['id'];
$payload=$revision()+['display_name'=>'同名','submission_key'=>str_repeat('f',32),'group_ids'=>[$a['id'],$b['id']],'answers'=>[['slot_id'=>$slot,'state'=>'yes']]];
$person=$p->submit($id,null,$payload);check(count($person['group_ids'])===2,'multiple memberships');
$before=$person;$stale=$revision();
$person=$p->submit($id,$person['id'],$revision()+['expected_participant_version'=>1,'display_name'=>'同名','answers'=>$person['answers'],'group_ids'=>[$a['id']]]);
rejects(fn()=>$g->change($id,$a['id'],'archive',$stale+['expected_group_version'=>1,'confirmation'=>'Team']),409);
check($person['answers']===$before['answers'],'membership edit preserves submitted answers');
$archive=$revision()+['expected_group_version'=>1,'confirmation'=>'Team'];$g->change($id,$a['id'],'archive',$archive);
check($p->get($id,$person['id'])['group_ids']===[],'archived group excluded');
check($db->query('SELECT COUNT(*) FROM participant_groups')->fetchColumn()===1,'archived membership retained');
$person=$p->submit($id,$person['id'],$revision()+['expected_participant_version'=>2,'display_name'=>'同名','answers'=>$person['answers'],'group_ids'=>[]]);
$g->change($id,$a['id'],'restore',$revision()+['expected_group_version'=>2]);
check($p->get($id,$person['id'])['group_ids']===[$a['id']],'restore retains archived membership across submissions');
check($p->get($id,$person['id'])['answers']===$before['answers'],'group archive restore preserves answers');
$other=$s->create(['title'=>'別','dates'=>['2026-11-02']]);$foreign=$g->change($other['id'],null,'create',['expected_version'=>1,'expected_schema_revision'=>1,'name'=>'別']);
rejects(fn()=>$p->submit($id,$person['id'],$revision()+['expected_participant_version'=>3,'display_name'=>'変更','answers'=>[],'group_ids'=>[$foreign['id']]]),422);
check($p->get($id,$person['id'])['answers']===$before['answers'],'invalid membership atomic rollback');
try {$db->prepare('INSERT INTO participant_groups VALUES (?,?,?)')->execute([$person['id'],$foreign['id'],$id]);throw new RuntimeException('foreign membership accepted');}catch(PDOException $e){check(true,'DB composite foreign key');}
$db->exec("CREATE TRIGGER fail_group_audit BEFORE INSERT ON audit_log BEGIN SELECT RAISE(ABORT,'rollback'); END");
$before=$s->get($id);
try {$g->change($id,null,'create',$revision()+['name'=>'rollback']);throw new RuntimeException('audit did not fail');}catch(PDOException $e){}
try {$p->submit($id,$person['id'],$revision()+['expected_participant_version'=>3,'display_name'=>'changed','answers'=>[],'group_ids'=>[$b['id']]]);throw new RuntimeException('audit did not fail');}catch(PDOException $e){}
check($s->get($id)===$before,'membership and group mutations roll back with audit');$db->exec('DROP TRIGGER fail_group_audit');
$stale=$revision();$g->change($id,$b['id'],'edit',$stale+['expected_group_version'=>1,'name'=>'改名','description'=>'説明']);
rejects(fn()=>$g->change($id,$b['id'],'edit',$stale+['expected_group_version'=>1,'name'=>'競合']),409);
for($i=2;$i<9;$i++)$g->change($id,null,'create',$revision()+['name'=>'G'.$i]);
rejects(fn()=>$g->change($id,null,'create',$revision()+['name'=>'10']),422);
try {$db->prepare('INSERT INTO instance_groups VALUES (?,?,?,?,?,1,NULL,10)')->execute(['extra',$id,'extra','extra','']);throw new RuntimeException('DB limit missing');}catch(PDOException $e){check(true,'DB insert limit');}
$g->change($id,$a['id'],'archive',$revision()+['expected_group_version'=>3,'confirmation'=>'Team']);
$g->change($id,null,'create',$revision()+['name'=>'replacement']);
rejects(fn()=>$g->change($id,$a['id'],'restore',$revision()+['expected_group_version'=>4]),422);
try {$db->prepare('UPDATE instance_groups SET archived_at=NULL WHERE id=?')->execute([$a['id']]);throw new RuntimeException('DB restore limit missing');}catch(PDOException $e){check(true,'DB restore limit');}
check(count($g->listing($id))===9,'9 groups remain');
check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'membership foreign keys valid');
check($db->query("SELECT COUNT(*) FROM audit_log WHERE actor!='shared-basic'")->fetchColumn()===0,'group shared audit');
// Upgrade an actual v002 database with persisted participants and answers.
$oldDb=Database::connect(':memory:');Database::migrate($oldDb);$oldS=new Schedules($oldDb);$oldP=new Participants($oldDb);
$oldRow=$oldS->create(['title'=>'旧回答DB','dates'=>['2026-11-02']]);
$oldPerson=$oldP->submit($oldRow['id'],null,['expected_version'=>1,'expected_schema_revision'=>1,'display_name'=>'保持','submission_key'=>str_repeat('1',32),'answers'=>[['slot_id'=>$oldRow['days'][0]['slots'][6]['id'],'state'=>'no']]]);
$oldDb->exec("DROP TABLE participant_groups; DROP TABLE instance_groups; DROP INDEX participant_instance_identity; DELETE FROM migrations WHERE name='003_groups.sql'");
Database::migrate($oldDb);Database::migrate($oldDb);
check($oldP->get($oldRow['id'],$oldPerson['id'])===$oldPerson,'v002 upgrade retains participant identity and answers');
