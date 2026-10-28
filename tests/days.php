<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules,Days,Participants,Summary,Export};
$db=Database::connect(':memory:');Database::migrate($db);$s=new Schedules($db);$d=new Days($db);$p=new Participants($db);
$row=$s->create(['title'=>'日付','dates'=>['2026-11-03']]);$id=$row['id'];$day=$row['days'][0];
$rev=fn()=>['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision']];
function dateCommit(Days $service,string $id,string $action,array $data): array {
 $preview=$service->change($id,$action,$data,true);
 return $service->change($id,$action,$data+['confirmed_operation'=>$preview['operation'],'expected_answers_revision'=>$preview['answers_revision'],'expected_answer_count'=>$preview['answer_count'],'expected_visible_answers'=>$preview['visible_answers'],'confirmation'=>$preview['local_date']]);
}
$payload=$rev()+['display_name'=>'回答','submission_key'=>str_repeat('a',32),'answers'=>[['slot_id'=>$day['slots'][6]['id'],'state'=>'yes'],['slot_id'=>$day['slots'][7]['id'],'state'=>'no']]];
$person=$p->submit($id,null,$payload);
$db->prepare('UPDATE day_slots SET is_candidate=0 WHERE id=?')->execute([$day['slots'][7]['id']]);
$before=$s->get($id)['days'][0];$answers=$p->get($id,$person['id'])['answers'];
$req=$rev()+['day_id'=>$day['id']];$plan=$d->change($id,'archive',$req,true);
check($plan['answer_count']===2&&$plan['visible_answers']===1,'date preview distinguishes retained and visible answers');
$confirm=$req+['confirmed_operation'=>'archive','expected_answers_revision'=>$plan['answers_revision'],'expected_answer_count'=>2,'expected_visible_answers'=>1,'confirmation'=>$day['local_date']];
rejects(fn()=>$d->change($id,'archive',array_replace($confirm,['confirmation'=>'wrong'])),422);
$person=$p->submit($id,$person['id'],array_replace($payload,['expected_participant_version'=>1,'answers'=>[$payload['answers'][0]]]));
rejects(fn()=>$d->change($id,'archive',$confirm),409);
dateCommit($d,$id,'archive',$req);check($s->get($id)['days']===[],'last date can be archived without deleting history');
check(Summary::calculate($s->get($id))['N']===1 && Summary::calculate($s->get($id))['slots']===[],'no-day aggregate keeps target people');
$complete=json_decode((new Export($db))->generate($id,[])['content'],true);check(count($complete['days'])===1 && $complete['days'][0]['archived_at']!==null && count($complete['participants'][0]['answers'])===2,'JSON keeps archived date history');
rejects(fn()=>$s->candidates($id,$rev()+['masks'=>[['day_id'=>$day['id'],'candidates'=>array_fill(0,48,true)]]],true),422);
$edited=$p->submit($id,$person['id'],$rev()+['expected_participant_version'=>2,'display_name'=>'回答','answers'=>[]]);check($edited['answers']===$answers,'submission with no active days retains archived history');
$req=$rev()+['local_date'=>'2026-11-03','initial_window'=>['start'=>'22:00','end'=>'06:00','end_next_day'=>true]];
$plan=$d->change($id,'add',$req,true);check($plan['operation']==='restore','same date re-add is explicit restoration');
rejects(fn()=>$d->change($id,'add',$req+['confirmed_operation'=>'new']),422);
dateCommit($d,$id,'add',$req);$after=$s->get($id)['days'][0];check($after===$before,'restore preserves day/slot IDs, timestamps and candidate mask');
check($p->get($id,$person['id'])['answers']===$answers,'restore preserves exact answers');
check($d->change($id,'add',$rev()+['local_date'=>'2026-11-03'],true)['operation']==='existing','active duplicate preview is existing/unchanged');
rejects(fn()=>dateCommit($d,$id,'add',$rev()+['local_date'=>'2026-11-03']),409);
$new=dateCommit($d,$id,'add',$rev()+['local_date'=>'2026-11-02','initial_window'=>['start'=>'22:00','end'=>'06:00','end_next_day'=>true]]);
$days=$s->get($id)['days'];check(array_column($days,'local_date')===['2026-11-02','2026-11-03'],'new earlier date sorted ascending');
check($new['operation']==='new' && count($days[0]['slots'])===48 && array_sum(array_column($days[0]['slots'],'is_candidate'))===16,'new day is 48 slots with explicit overnight window');
check($days[0]['slots'][47]['ends_at_utc']==='2026-11-02T21:00:00Z','new day JST to UTC end boundary');
foreach (['2026-02-30',"2026-11-02\0bad",[], '9999-01-01'] as $bad) rejects(fn()=>$d->change($id,'add',$rev()+['local_date'=>$bad],true),422);
$other=$s->create(['title'=>'別','dates'=>['2026-11-04']]);rejects(fn()=>$d->change($id,'archive',$rev()+['day_id'=>$other['days'][0]['id']],true),404);
$inactive=$p->submit($other['id'],null,['expected_version'=>1,'expected_schema_revision'=>1,'display_name'=>'非表示','submission_key'=>str_repeat('b',32),'answers'=>[['slot_id'=>$other['days'][0]['slots'][6]['id'],'state'=>'no']]]);
$db->prepare('UPDATE participants SET is_active=0 WHERE id=?')->execute([$inactive['id']]);
$foreignReq=['expected_version'=>1,'expected_schema_revision'=>1,'day_id'=>$other['days'][0]['id']];
$impact=$d->change($other['id'],'archive',$foreignReq,true);check($impact['answer_count']===1&&$impact['visible_answers']===0,'inactive answers counted as retained history only');
dateCommit($d,$other['id'],'archive',$foreignReq);dateCommit($d,$other['id'],'restore',array_replace($foreignReq,['expected_schema_revision'=>2]));
check($p->get($other['id'],$inactive['id'])['answers']===$inactive['answers'],'archive/restore retains inactive participant answers');
$stale=$rev()+['local_date'=>'2026-11-05'];dateCommit($d,$id,'add',$stale);rejects(fn()=>$d->change($id,'add',$stale,true),409);
$db->exec("CREATE TRIGGER fail_day BEFORE INSERT ON audit_log WHEN NEW.action LIKE 'day-%' BEGIN SELECT RAISE(ABORT,'rollback'); END");
$before=$d->listing($id);try{dateCommit($d,$id,'add',$rev()+['local_date'=>'2026-11-06']);throw new RuntimeException('rollback missing');}catch(PDOException $e){}
check($d->listing($id)===$before,'day+48 slots+revision+audit rollback atomically');$db->exec('DROP TRIGGER fail_day');
$db->prepare("UPDATE instances SET status='locked' WHERE id=?")->execute([$id]);rejects(fn()=>$d->change($id,'archive',$rev()+['day_id'=>$day['id']],true),409);
$db->prepare("UPDATE instances SET status='deleted',deleted_at='fixture' WHERE id=?")->execute([$id]);rejects(fn()=>$d->listing($id),404);
check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'date foreign keys remain valid');check($db->query("SELECT COUNT(*) FROM audit_log WHERE actor!='shared-basic'")->fetchColumn()===0,'date audit shared-basic');

// Lifetime capacity counts archived records; restoration never consumes capacity.
$dates=[];$start=new DateTimeImmutable('2028-01-01');
for($i=0;$i<366;$i++) $dates[]=$start->modify("+$i days")->format('Y-m-d');
$cap=$s->create(['title'=>'上限','dates'=>$dates]);$cid=$cap['id'];
$capRev=fn()=>['expected_version'=>1,'expected_schema_revision'=>$s->get($cid)['schema_revision']];
rejects(fn()=>$d->change($cid,'add',$capRev()+['local_date'=>'2030-01-01'],true),422);
for($i=0;$i<2;$i++) {
 dateCommit($d,$cid,'archive',$capRev()+['day_id'=>$cap['days'][$i]['id']]);
 rejects(fn()=>dateCommit($d,$cid,'add',$capRev()+['local_date'=>'2030-01-01']),422);
 dateCommit($d,$cid,'add',$capRev()+['local_date'=>$dates[$i]]);
}
check(count($d->listing($cid)['days'])===366,'archive/add cycles cannot grow lifetime dates');
$db->prepare("UPDATE instance_days SET archived_at='fixture' WHERE instance_id=?")->execute([$cid]);
rejects(fn()=>$d->change($cid,'add',$capRev()+['local_date'=>'2030-01-01'],true),422);
$capBefore=$d->listing($cid);
rejects(fn()=>$d->change($cid,'add',$capRev()+['local_date'=>$dates[0],'dates'=>[$dates[0],'2030-01-01']]),422);
check($d->listing($cid)===$capBefore,'unsupported mixed add/restore batch is rejected without partial restoration');
dateCommit($d,$cid,'restore',$capRev()+['day_id'=>$cap['days'][0]['id']]);
check($s->get($cid)['days'][0]===$cap['days'][0],'restore succeeds at lifetime capacity with every date previously archived');
$backup=tempnam(sys_get_temp_dir(),'gyugyu-days-');unlink($backup);
try {
 $db->exec('VACUUM INTO '.$db->quote($backup));$restored=Database::connect($backup);Database::migrate($restored);
 check((new Days($restored))->listing($cid)===$d->listing($cid),'backup/restore keeps active and archived dates at lifetime cap');
 check((new Participants($restored))->get($other['id'],$inactive['id'])['answers']===$inactive['answers'],'backup/restore keeps archived participant answer history');
 $restored=null;
} finally {foreach ([$backup,$backup.'-wal',$backup.'-shm'] as $file) if(is_file($file))unlink($file);}
