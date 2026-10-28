<?php
declare(strict_types=1);
use Gyugyu\{Database,Schedules,Participants,Groups,Export};
foreach (['=1+1','+1','-1','@SUM(1)',"\t=1","\r+1","\n@x",'  =1',"\u{3000}=1","\u{FEFF}=1","\u{200B}@x","\x01=1",'＝1',' ordinary'] as $value) check(Export::csvText($value)==="'".$value,'formula/control prefix neutralized');
foreach (['日本語','山田, "太郎"',"説明\n次行","O'Reilly","'safe",'123'] as $value) check(Export::csvText($value)===$value,'ordinary text retained');
$db=Database::connect(':memory:');Database::migrate($db);$s=new Schedules($db);$p=new Participants($db);$g=new Groups($db);$export=new Export($db);
$row=$s->create(['title'=>'=SUM(1)','description'=>"\t@danger,\"quoted\"\n日本語",'dates'=>['2026-11-02','2026-11-03']]);$id=$row['id'];
$a=$g->change($id,null,'create',['expected_version'=>1,'expected_schema_revision'=>1,'name'=>'+group']);
$b=$g->change($id,null,'create',['expected_version'=>1,'expected_schema_revision'=>2,'name'=>'古い群']);
$slot=$row['days'][0]['slots'][6]['id'];$history=$row['days'][0]['slots'][7]['id'];$old=$row['days'][1]['slots'][6]['id'];
$payload=['display_name'=>'=HYPERLINK("x")','expected_version'=>1,'expected_schema_revision'=>3,'submission_key'=>str_repeat('f',32),'group_ids'=>[$a['id'],$b['id']],'answers'=>[['slot_id'=>$slot,'state'=>'yes'],['slot_id'=>$history,'state'=>'no'],['slot_id'=>$old,'state'=>'maybe'],['slot_id'=>$row['days'][0]['slots'][9]['id'],'state'=>'no']]];
$person=$p->submit($id,null,$payload);
$hidden=$p->submit($id,null,array_replace($payload,['submission_key'=>str_repeat('e',32),'group_ids'=>[]]));
$db->prepare('UPDATE participants SET is_active=0 WHERE id=?')->execute([$hidden['id']]);
$db->prepare('UPDATE day_slots SET is_candidate=0 WHERE id=?')->execute([$history]);
$db->prepare("UPDATE instance_days SET archived_at='fixture' WHERE id=?")->execute([$row['days'][1]['id']]);
$g->change($id,$b['id'],'archive',['expected_version'=>1,'expected_schema_revision'=>3,'expected_group_version'=>1,'expected_answers_revision'=>3,'confirmation'=>'古い群']);
$before=$db->query('SELECT * FROM participants ORDER BY id')->fetchAll();
$file=$export->generate($id,['format'=>'json']);$json=json_decode($file['content'],true,512,JSON_THROW_ON_ERROR);
check(!str_starts_with($file['content'],"\xEF\xBB\xBF"),'JSON without BOM');
check($json['instance']['title']==='=SUM(1)' && $json['instance']['description']===$row['description'],'JSON raw text/newlines retained');
check(count($json['days'])===2 && count($json['days'][0]['slots'])===48 && count($json['groups'])===2 && count($json['participants'])===2,'complete logical history included');
$byId=array_column($json['participants'],null,'id');check(count($byId[$person['id']]['group_ids'])===2 && count($byId[$person['id']]['answers'])===4,'archived membership and candidate/day answer history retained');
check($byId[$hidden['id']]['is_active']===false && is_int($json['instance']['version']) && is_bool($json['days'][0]['slots'][0]['is_candidate']),'JSON native types');
check(!str_contains($file['content'],'submission_key') && !str_contains($file['content'],'csrf') && !str_contains($file['content'],'name_key'),'internal keys excluded');
check($json['days'][0]['slots'][6]['starts_at_utc']==='2026-11-02T00:00:00Z' && $json['days'][0]['slots'][6]['starts_at_jst']==='2026-11-02T09:00:00+09:00','explicit UTC and JST');
check((bool)preg_match('/^gyugyu-[a-f0-9]{32}-[0-9TZ]+-all-json\.json$/D',$file['filename']),'safe filename no user title');
function csvRows(string $content): array {
 check(str_starts_with($content,"\xEF\xBB\xBF"),'CSV UTF8 BOM');$stream=fopen('php://temp','w+');fwrite($stream,substr($content,3));rewind($stream);$rows=[];while(($line=fgetcsv($stream,null,',','"',''))!==false)$rows[]=$line;fclose($stream);return $rows;
}
$wide=csvRows($export->generate($id,['format'=>'wide'])['content']);
check(count($wide)===2,'wide active participants only');$wideRow=array_combine($wide[0],$wide[1]);
check($wideRow['title']==="'=SUM(1)" && $wideRow['name']==="'=HYPERLINK(\"x\")" && $wideRow['description']==="'".$row['description'],'CSV fields protected after parsing quotes/newlines');
check(count($wide[0])===15+17 && $wide[1][15]==='○' && $wide[1][16]==='','wide candidate-only symbols, unknown blank');
$long=csvRows($export->generate($id,['format'=>'long','scope'=>'mask','mode'=>'include','group_ids'=>[$a['id']]])['content']);
check(count($long)===18,'long current active candidates');$longRow=array_combine($long[0],$long[1]);check($longRow['slot_id']===$slot && $longRow['state']==='yes' && $longRow['scope']==='mask','long typed states and scope');
$none=csvRows($export->generate($id,['format'=>'wide','scope'=>'mask','mode'=>'include'])['content']);check(count($none)===1,'include empty produces header-only participants');
$summary=csvRows($export->generate($id,['format'=>'summary','scope'=>'mask','mode'=>'include'])['content']);$zero=array_combine($summary[0],$summary[1]);check($zero['N']==='0' && $zero['Y']==='—' && $zero['score']==='—' && $zero['all_yes']==='0','N0 summary agrees with UI');
$summary=csvRows($export->generate($id,['format'=>'summary'])['content']);$counts=array_combine($summary[0],$summary[1]);check($counts['N']==='1' && $counts['Y']==='1' && $counts['U']==='0' && $counts['score']==='1','aggregate same target/count/score');
check(in_array('-2',array_column(array_map(fn($line)=>array_combine($summary[0],$line),array_slice($summary,1)),'score'),true),'negative numeric score is not string-escaped');
foreach ([['format'=>'json','scope'=>'mask'],['format'=>'json','mode'=>'include'],['format'=>'wide','group_ids'=>['foreign']],['format'=>'wide','slot_ids'=>[$slot]],['format'=>[]],['format'=>'wide','scope'=>[]],['format'=>'wide','mode'=>[]],['format'=>'wide','group_ids'=>['x'=>1]],['format'=>'wide','filename'=>"bad\r\nheader"]] as $bad) rejects(fn()=>$export->generate($id,$bad),422);
check($db->query('SELECT * FROM participants ORDER BY id')->fetchAll()===$before,'export never rewrites raw values');
foreach (['locked','confirmed'] as $status) {$db->prepare('UPDATE instances SET status=? WHERE id=?')->execute([$status,$id]);check(json_decode($export->generate($id,[])['content'],true)['instance']['status']===$status,'read-only lifecycle export allowed');}
$db->prepare("UPDATE instances SET status='deleted',deleted_at='fixture' WHERE id=?")->execute([$id]);rejects(fn()=>$export->generate($id,[]),404);
