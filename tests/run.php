<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use Gyugyu\{Database,Schedules,Problem};
$count = 0;
function check(bool $ok, string $message): void { global $count; $count++; if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, int $code): void { try { $fn(); } catch (Problem $e) { check($e->getCode() === $code, $e->getMessage()); return; } throw new RuntimeException('Expected rejection '.$code); }
$db = Database::connect(':memory:'); Database::migrate($db); Database::migrate($db);
check($db->query('SELECT COUNT(*) FROM migrations')->fetchColumn() === 1, 'idempotent migration');
$s = new Schedules($db);
$data = ['title'=>' 実験調整 ','description'=>"一行\r\n<script>alert(1)</script>\r三行",'dates'=>['2026-11-04','2026-11-02','2026-11-04'],'initial_window'=>['start'=>'09:00','end'=>'18:00']];
$p = Schedules::preview($data); check($p['duplicate_count'] === 1 && $p['dates'][0] === '2026-11-02', 'deduplication and sort');
$a = $s->create($data); $b = $s->create($data);
check($a['id'] !== $b['id'] && count($s->listing()) === 2, 'independent instances with duplicate names');
check($a['title'] === '実験調整' && $a['description'] === "一行\n<script>alert(1)</script>\n三行", 'normalization');
check(!isset($s->listing()[0]['description']), 'no portal description');
check(count($a['days']) === 2 && count($a['days'][0]['slots']) === 48, '48 slots per day');
check(array_sum(array_column($a['days'][0]['slots'],'is_candidate')) === 18, '09-18 mask');
check($a['days'][0]['slots'][0]['starts_at_utc'] === '2026-11-01T21:00:00Z', 'JST UTC conversion');
check($a['days'][0]['slots'][47]['ends_at_utc'] === '2026-11-02T21:00:00Z', 'next day boundary');
foreach ([['title'=>' '],['title'=>str_repeat('あ',141)],['description'=>str_repeat('あ',2001)],['dates'=>['2026-02-30']],['dates'=>["2026-11-02\0bad"]],['dates'=>['1899-12-31']],['dates'=>['9999-01-01']],['dates'=>['2026-1-02']],['dates'=>array_fill(0,367,'2026-11-02')],['dates'=>[]],['dates'=>[[]]],['initial_window'=>['start'=>'09:15','end'=>'18:00']],['initial_window'=>['start'=>'18:00','end'=>'09:00']],['timezone'=>'UTC']] as $bad) rejects(fn()=>$s->create(array_replace($data,$bad)),422);
check(count($s->listing())===2, 'invalid creation does not persist');
$night = $s->create(array_replace($data,['initial_window'=>['start'=>'22:00','end'=>'06:00','end_next_day'=>true]]));
check(array_sum(array_column($night['days'][0]['slots'],'is_candidate'))===16,'overnight window');
$all = Schedules::preview(array_replace($data,['initial_window'=>['start'=>'06:00','end'=>'06:00','end_next_day'=>true]]));
check($all['candidate_slots_per_day']==48,'full 24h');
$edited = $s->change($a['id'],'edit',['expected_version'=>'1','description'=>'']);
check($edited['version']===2 && $edited['description']==='' && $edited['days']===$a['days'],'metadata preserves IDs and slots');
rejects(fn()=>$s->change($a['id'],'edit',['expected_version'=>1,'title'=>'stale']),409);
rejects(fn()=>$s->change($a['id'],'delete',['expected_version'=>2,'confirmation'=>'wrong']),422);
rejects(fn()=>$s->change($a['id'],'delete',['expected_version'=>1,'confirmation'=>$a['title']]),409);
$d = $s->change($a['id'],'delete',['expected_version'=>2,'confirmation'=>$a['title']]);
check($d['deleted_at']!==null && $d['status']==='deleted','soft delete');
rejects(fn()=>$s->get($a['id']),404);
check(count($s->listing(true))===1,'explicit deleted list');
rejects(fn()=>$s->change($a['id'],'restore',['expected_version'=>2]),409);
$r = $s->change($a['id'],'restore',['expected_version'=>3]);
check($r['status']==='open' && $r['days']===$a['days'],'restore intact');
check($s->get($b['id'])===$b,'cross-instance isolation');
$db->prepare("UPDATE instances SET status='locked' WHERE id=?")->execute([$b['id']]);
rejects(fn()=>$s->change($b['id'],'edit',['expected_version'=>1,'title'=>'no']),409);
$s->change($b['id'],'delete',['expected_version'=>1,'confirmation'=>$b['title']]);
check($s->change($b['id'],'restore',['expected_version'=>2])['status']==='locked','restore pre-delete status');
check($db->query("SELECT COUNT(*) FROM audit_log WHERE actor!='shared-basic'")->fetchColumn()===0,'shared actor');
// A mid-creation database error rolls back instance, days, slots and audit together.
$db->exec("CREATE TRIGGER fail_slot BEFORE INSERT ON day_slots BEGIN SELECT RAISE(ABORT,'test'); END");
$before = $db->query('SELECT COUNT(*) FROM instances')->fetchColumn();
try { $s->create($data); throw new RuntimeException('expected failure'); } catch (PDOException $e) {}
check($db->query('SELECT COUNT(*) FROM instances')->fetchColumn()===$before,'atomic rollback');
$db->exec('DROP TRIGGER fail_slot');
check($db->query('PRAGMA integrity_check')->fetchColumn()==='ok','integrity');
check($db->query('PRAGMA foreign_key_check')->fetchAll()===[],'foreign keys');
foreach (['1900-01-01','2000-02-29','9998-12-31'] as $date) {
    $edge = $s->create(array_replace($data,['dates'=>[$date]]));
    check(count($edge['days'][0]['slots'])===48,'boundary date slots');
}
check($db->query("SELECT COUNT(*) FROM audit_log WHERE action='edit'")->fetchColumn()===1,'rejected edits leave no audit entry');
echo "PASS: $count assertions\n";
