<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules,Participants,Groups,Days,Export,Summary};
$db=Database::connect(':memory:');Database::migrate($db);$s=new Schedules($db);$p=new Participants($db);$g=new Groups($db);$d=new Days($db);
$r=$s->create(['title'=>'受付','description'=>'締切: 2000年1月1日','dates'=>['2026-11-01','2026-11-02']]);$id=$r['id'];
$rev=fn()=>['expected_version'=>$s->get($id)['version'],'expected_schema_revision'=>$s->get($id)['schema_revision'],'expected_answers_revision'=>$s->get($id)['answers_revision']];
$beforeAnswer=$rev()+['action'=>'lock','confirmation'=>'受付'];
$person=$p->submit($id,null,$rev()+['display_name'=>'有効','submission_key'=>str_repeat('a',32),'answers'=>[['slot_id'=>$r['days'][0]['slots'][6]['id'],'state'=>'yes']]]);
rejects(fn()=>$s->reception($id,$beforeAnswer),409);
$hidden=$p->submit($id,null,$rev()+['display_name'=>'非表示','submission_key'=>str_repeat('b',32),'answers'=>[]]);$p->deactivate($id,$hidden['id'],$rev()+['expected_participant_version'=>1,'confirmation'=>'非表示','is_active'=>false]);
$beforeStructure=$rev()+['action'=>'lock','confirmation'=>'受付'];$group=$g->change($id,null,'create',$rev()+['name'=>'有効群']);rejects(fn()=>$s->reception($id,$beforeStructure),409);
$archived=$g->change($id,null,'create',$rev()+['name'=>'履歴群']);$g->change($id,$archived['id'],'archive',$rev()+['expected_group_version'=>1,'confirmation'=>'履歴群']);
dateCommit($d,$id,'archive',['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision'],'day_id'=>$r['days'][1]['id']]);
$lock=$rev()+['action'=>'lock','confirmation'=>'受付'];
foreach(['expected_version','expected_schema_revision','expected_answers_revision'] as $key) {rejects(fn()=>$s->reception($id,array_replace($lock,[$key=>0])),409);rejects(fn()=>$s->reception($id,array_replace($lock,[$key=>(string)$lock[$key]])),409);}
rejects(fn()=>$s->reception($id,array_replace($lock,['confirmation'=>'wrong'])),422);rejects(fn()=>$s->reception($id,array_replace($lock,['action'=>'confirmed'])),422);rejects(fn()=>$s->reception($id,$lock+['participant_id'=>$person['id']]),422);rejects(fn()=>$s->reception(str_repeat('f',32),$lock),404);
$before=$s->get($id);$db->exec("CREATE TRIGGER fail_reception BEFORE INSERT ON audit_log WHEN NEW.action LIKE 'reception-%' BEGIN SELECT RAISE(ABORT,'fixture'); END");
try{$s->reception($id,$lock);throw new RuntimeException('missing rollback');}catch(PDOException $e){}check($s->get($id)===$before,'state audit failure rolls back status/version');$db->exec('DROP TRIGGER fail_reception');
$tables=['participants','answers','participant_groups','instance_groups','instance_days','day_slots'];$snapshot=[];foreach($tables as $table)$snapshot[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
$locked=$s->reception($id,$lock);check($locked['status']==='locked'&&$locked['version']===2&&$locked['schema_revision']===$before['schema_revision']&&$locked['answers_revision']===$before['answers_revision'],'lock changes instance version only');
rejects(fn()=>$s->reception($id,$lock),409);rejects(fn()=>$s->reception($id,$rev()+['action'=>'lock','confirmation'=>'受付']),409);
$expected=$rev();$masks=['masks'=>[['day_id'=>$r['days'][0]['id'],'candidates'=>array_fill(0,48,true)]]];
$blocked=[
 fn()=>$s->change($id,'edit',$expected+['title'=>'changed']),
 fn()=>$p->submit($id,null,$expected+['display_name'=>'新規','submission_key'=>str_repeat('c',32),'answers'=>[]]),
 fn()=>$p->submit($id,$person['id'],$expected+['expected_participant_version'=>1,'display_name'=>'有効','answers'=>[]]),
 fn()=>$p->deactivate($id,$person['id'],$expected+['expected_participant_version'=>1,'confirmation'=>'有効','is_active'=>false]),
 fn()=>$p->restore($id,$hidden['id'],$expected+['expected_participant_version'=>2,'confirmation'=>'非表示']),
 fn()=>$g->change($id,null,'create',$expected+['name'=>'new']),
 fn()=>$g->change($id,$group['id'],'edit',$expected+['expected_group_version'=>1,'name'=>'changed']),
 fn()=>$g->change($id,$group['id'],'archive',$expected+['expected_group_version'=>1,'confirmation'=>'有効群']),
 fn()=>$g->change($id,$archived['id'],'restore',$expected+['expected_group_version'=>2]),
 fn()=>$d->change($id,'add',$expected+['local_date'=>'2026-11-03'],true),
 fn()=>$d->change($id,'archive',$expected+['day_id'=>$r['days'][0]['id']],true),
 fn()=>$d->change($id,'restore',$expected+['day_id'=>$r['days'][1]['id']],true),
 fn()=>$s->candidates($id,$expected+$masks,true),fn()=>$s->candidates($id,$expected+$masks),
];foreach($blocked as $work)rejects($work,409);
check(Summary::calculate($locked)===Summary::calculate($before),'locked summary and mask data unchanged');
foreach(['json','wide','long','summary'] as $format)check(strlen((new Export($db))->generate($id,['format'=>$format])['content'])>0,'locked export readable '.$format);
foreach($tables as $table)check($db->query('SELECT * FROM '.$table)->fetchAll()===$snapshot[$table],'lock leaves '.$table.' unchanged');
$s->change($id,'delete',['expected_version'=>2,'confirmation'=>'受付']);rejects(fn()=>$s->reception($id,$expected+['action'=>'reopen','confirmation'=>'受付']),404);
$back=$s->change($id,'restore',['expected_version'=>3]);check($back['status']==='locked','deleted locked instance restores to locked');
$reopen=$rev()+['action'=>'reopen','confirmation'=>'受付'];$opened=$s->reception($id,$reopen);check($opened['status']==='open'&&$opened['version']===5,'explicit reopen advances version');rejects(fn()=>$s->reception($id,$reopen),409);
rejects(fn()=>$p->submit($id,$person['id'],$lock+['display_name'=>'有効','expected_participant_version'=>1,'answers'=>[]]),409);
$edited=$p->submit($id,$person['id'],$rev()+['display_name'=>'再開後','expected_participant_version'=>1,'answers'=>[]]);check($edited['display_name']==='再開後','fresh submission succeeds after reopening');
// No participant/day prerequisites; confirmed is deliberately outside this slice.
$empty=$s->create(['title'=>'空','dates'=>['2026-11-01']]);dateCommit($d,$empty['id'],'archive',['expected_version'=>1,'expected_schema_revision'=>1,'day_id'=>$empty['days'][0]['id']]);
$req=['expected_version'=>1,'expected_schema_revision'=>2,'expected_answers_revision'=>$empty['answers_revision'],'action'=>'lock','confirmation'=>'空'];$emptyLocked=$s->reception($empty['id'],$req);check($emptyLocked['days']===[]&&$emptyLocked['participants']===[],'zero-day zero-participant lock works');
$s->reception($empty['id'],array_replace($req,['expected_version'=>2,'action'=>'reopen']));
$db->prepare("UPDATE instances SET status='confirmed' WHERE id=?")->execute([$empty['id']]);foreach(['lock','reopen'] as $action)rejects(fn()=>$s->reception($empty['id'],array_replace($req,['expected_version'=>3,'action'=>$action])),409);
check($db->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'reception-%' AND actor='shared-basic'")->fetchColumn()===4,'successful state transitions audited shared-basic');
