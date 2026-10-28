<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules,Participants,Groups,Days,Summary,Export};
$db=Database::connect(':memory:');Database::migrate($db);$s=new Schedules($db);$p=new Participants($db);$g=new Groups($db);$d=new Days($db);
$r=$s->create(['title'=>'復帰','dates'=>['2026-11-01','2026-11-02']]);$id=$r['id'];
$rev=fn()=>['expected_version'=>$s->get($id)['version'],'expected_schema_revision'=>$s->get($id)['schema_revision'],'expected_answers_revision'=>$s->get($id)['answers_revision']];
$a=$g->change($id,null,'create',$rev()+['name'=>'有効']);$b=$g->change($id,null,'create',$rev()+['name'=>'履歴']);
$one=$p->submit($id,null,$rev()+['display_name'=>'constructor','submission_key'=>str_repeat('a',32),'group_ids'=>[$a['id'],$b['id']],'answers'=>[['slot_id'=>$r['days'][0]['slots'][6]['id'],'state'=>'yes'],['slot_id'=>$r['days'][1]['slots'][6]['id'],'state'=>'no']]]);
$two=$p->submit($id,null,$rev()+['display_name'=>'constructor','submission_key'=>str_repeat('b',32),'answers'=>[]]);
$db->prepare("UPDATE participants SET submitted_at='2020-01-01T00:00:00Z' WHERE id=?")->execute([$one['id']]);
$p->deactivate($id,$one['id'],$rev()+['expected_participant_version'=>1,'confirmation'=>'constructor','is_active'=>false]);
$g->change($id,$b['id'],'archive',$rev()+['expected_group_version'=>1,'confirmation'=>'履歴']);
dateCommit($d,$id,'archive',['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision'],'day_id'=>$r['days'][0]['id']]);
$db->prepare('UPDATE day_slots SET is_candidate=0 WHERE id=?')->execute([$r['days'][1]['slots'][6]['id']]);
$listing=$p->inactive($id);check(count($listing['participants'])===1&&$listing['participants'][0]['answer_count']===2,'inactive roster includes retained archived/candidate-outside answers');
check(count($listing['participants'][0]['groups'])===2&&$listing['participants'][0]['groups'][1]['archived_at']!==null,'inactive roster distinguishes archived memberships');
$restore=$rev()+['expected_participant_version'=>2,'confirmation'=>'constructor'];
$answers=$db->query('SELECT * FROM answers ORDER BY participant_id,slot_id')->fetchAll();$members=$db->query('SELECT * FROM participant_groups ORDER BY participant_id,group_id')->fetchAll();
foreach(['expected_version','expected_schema_revision','expected_answers_revision','expected_participant_version'] as $key){rejects(fn()=>$p->restore($id,$one['id'],array_replace($restore,[$key=>0])),409);rejects(fn()=>$p->restore($id,$one['id'],array_replace($restore,[$key=>(string)$restore[$key]])),409);}
rejects(fn()=>$p->restore($id,$one['id'],array_replace($restore,['confirmation'=>'wrong'])),422);
foreach(['answers','group_ids','display_name','instance_id','is_active'] as $key)rejects(fn()=>$p->restore($id,$one['id'],$restore+[$key=>'injected']),422);
$other=$s->create(['title'=>'別','dates'=>['2026-11-01']]);rejects(fn()=>$p->restore($other['id'],$one['id'],$restore),404);
check($p->inactive($id)===$listing,'rejected restore leaves roster and all versions unchanged');
$mask=['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision'],'masks'=>[['day_id'=>$r['days'][1]['id'],'candidates'=>array_fill(0,48,false)]]];$preview=$s->candidates($id,$mask,true);
$dayReq=['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision'],'day_id'=>$r['days'][1]['id']];$dayPreview=$d->change($id,'archive',$dayReq,true);
$db->exec("CREATE TRIGGER fail_restore BEFORE INSERT ON audit_log WHEN NEW.action='participant-restore' BEGIN SELECT RAISE(ABORT,'fixture'); END");
try{$p->restore($id,$one['id'],$restore);throw new RuntimeException('missing rollback');}catch(PDOException $e){}
check($p->inactive($id)===$listing,'audit failure rolls back activation and all revisions');$db->exec('DROP TRIGGER fail_restore');
$result=$p->restore($id,$one['id'],$restore);check($result['id']===$one['id']&&$result['version']===3&&$result['is_active']===1,'restore preserves ID and increments participant version');
check($result['submitted_at']==='2020-01-01T00:00:00Z'&&$result['group_ids']===[$a['id']],'restore keeps submission timestamp and only exposes active memberships');
check($db->query('SELECT * FROM answers ORDER BY participant_id,slot_id')->fetchAll()===$answers&&$db->query('SELECT * FROM participant_groups ORDER BY participant_id,group_id')->fetchAll()===$members,'all answer timestamps and archived memberships unchanged');
check($s->get($id)['schema_revision']===$restore['expected_schema_revision']&&$s->get($id)['answers_revision']===$restore['expected_answers_revision']+1,'restore increments answers revision only, not schema');
rejects(fn()=>$p->restore($id,$one['id'],$restore),409);
rejects(fn()=>$s->candidates($id,$mask+['expected_answers_revision'=>$preview['answers_revision'],'confirm_exclusion'=>true]),409);
rejects(fn()=>$d->change($id,'archive',$dayReq+['confirmed_operation'=>'archive','confirmation'=>'2026-11-02','expected_answers_revision'=>$dayPreview['answers_revision'],'expected_answer_count'=>$dayPreview['answer_count'],'expected_visible_answers'=>$dayPreview['visible_answers']]),409);
check(Summary::calculate($s->get($id))['N']===2&&Summary::calculate($s->get($id),'include',[$a['id']])['participant_ids']===[$one['id']],'same-name restored people counted once in same mask');
$wide=(new Export($db))->generate($id,['format'=>'wide']);check(substr_count($wide['content'],$one['id'])===1&&substr_count($wide['content'],$two['id'])===1,'CSV includes each restored stable ID once');
$portal=array_values(array_filter($s->listing(),fn($row)=>$row['id']===$id))[0];check($portal['participant_count']===2&&$portal['submitted_count']===2,'portal includes restored participant as already submitted');
// Re-hide and change other answers after reading the confirmation snapshot.
$p->deactivate($id,$one['id'],$rev()+['expected_participant_version'=>3,'confirmation'=>'constructor','is_active'=>false]);$stale=$rev()+['expected_participant_version'=>4,'confirmation'=>'constructor'];
$p->submit($id,$two['id'],$rev()+['expected_participant_version'=>1,'display_name'=>'constructor','answers'=>[]]);rejects(fn()=>$p->restore($id,$one['id'],$stale),409);
$schemaStale=$rev()+['expected_participant_version'=>4,'confirmation'=>'constructor'];
$g->change($id,$a['id'],'edit',$rev()+['expected_group_version'=>1,'name'=>'有効改名']);rejects(fn()=>$p->restore($id,$one['id'],$schemaStale),409);
foreach(['locked','confirmed'] as $status){$db->prepare('UPDATE instances SET status=? WHERE id=?')->execute([$status,$id]);rejects(fn()=>$p->restore($id,$one['id'],$rev()+['expected_participant_version'=>4,'confirmation'=>'constructor']),409);}
$db->prepare("UPDATE instances SET status='open' WHERE id=?")->execute([$id]);
dateCommit($d,$id,'archive',['expected_version'=>1,'expected_schema_revision'=>$s->get($id)['schema_revision'],'day_id'=>$r['days'][1]['id']]);
$p->restore($id,$one['id'],$rev()+['expected_participant_version'=>4,'confirmation'=>'constructor']);check($s->get($id)['days']===[]&&Summary::calculate($s->get($id))['N']===2,'zero-day restore keeps roster and target count');
$p->deactivate($id,$two['id'],$rev()+['expected_participant_version'=>2,'confirmation'=>'constructor','is_active'=>false]);$p->restore($id,$two['id'],$rev()+['expected_participant_version'=>3,'confirmation'=>'constructor']);
check($p->get($id,$two['id'])['answers']===[]&&$p->get($id,$two['id'])['submitted_at']!==null,'all unknown stays submitted without fabricating answers');
$backup=tempnam(sys_get_temp_dir(),'gyugyu-restore-');unlink($backup);
try {$db->exec('VACUUM INTO '.$db->quote($backup));$copy=Database::connect($backup);Database::migrate($copy);
 check((new Participants($copy))->get($id,$one['id'])===$p->get($id,$one['id']),'restored state and submitted timestamp survive physical backup');
 check($copy->query('SELECT * FROM participant_groups ORDER BY participant_id,group_id')->fetchAll()===$members,'archived memberships survive restoration backup');$copy=null;
} finally {foreach([$backup,$backup.'-wal',$backup.'-shm'] as $file) if(is_file($file))unlink($file);}
$db->prepare("UPDATE instances SET status='deleted',deleted_at='fixture' WHERE id=?")->execute([$id]);rejects(fn()=>$p->inactive($id),404);rejects(fn()=>$p->restore($id,$one['id'],$restore),404);
check($db->query("SELECT COUNT(*) FROM audit_log WHERE action='participant-restore' AND actor='shared-basic'")->fetchColumn()===3,'successful restores only audited as shared-basic');
check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'restore retains relational integrity');
