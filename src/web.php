<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/group-page.php';
require_once __DIR__ . '/day-page.php';
require_once __DIR__ . '/participant-page.php';
require_once __DIR__ . '/reception-page.php';
use Gyugyu\{Security,Schedules,Database,Problem};
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function jstTime(string $utc): string { return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s') . ' JST'; }
function url(string $path = ''): string { return Security::base() . '/' . $path; }
function fields(?array $row = null): void {
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">';
    if ($row) echo '<input type="hidden" name="expected_version" value="'.h($row['version']).'">';
}
function headerPage(string $title): void {
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · gyugyu</title><link rel="icon" href="'.url('assets/gyugyu-cow.svg').'"><link rel="stylesheet" href="'.url('assets/gyugyu.css').'"></head><body><header><a href="'.url().'"><img src="'.url('assets/gyugyu-cow.svg').'" width="32" height="32" alt="gyugyu"> gyugyu</a></header><main><h1>'.h($title).'</h1>';
}
function metadata(array $v): void {
    echo '<label>スケジュール調整名<input name="title" required maxlength="140" value="'.h($v['title'] ?? '').'"></label><label>説明（任意・複数行）<textarea name="description" maxlength="2000" rows="5">'.h($v['description'] ?? '').'</textarea></label>';
}
function runWeb(): void {
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    Security::authorize(); Security::session();
    $service = new Schedules(Database::connect());
    $page = basename($_SERVER['SCRIPT_NAME']);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method,['GET','POST'],true)) throw new Problem('許可されていないメソッドです。',405);
    $id = $_GET['id'] ?? '';
    if (!is_string($id)) throw new Problem('IDが不正です。',400);
    if ($page === 'reception.php') { receptionPage($id,$method); return; }
    if ($page === 'participants.php') { participantPage($id,$method); return; }
    if ($page === 'days.php') { dayPage($id,$method); return; }
    if ($page === 'groups.php') { groupPage($service,$id,$method); return; }
    $error = null; $preview = null;
    if ($method === 'POST') {
        try {
            Security::mutation($_POST);
            $action = $_POST['action'] ?? '';
            if (!is_string($action)) throw new Problem('操作が不正です。',400);
            if ($page === 'create.php' && $action === 'preview') $preview = Schedules::preview($_POST);
            elseif ($page === 'create.php' && $action === 'create') {
                $row = $service->create($_POST); header('Location: '.url('event.php?id='.$row['id']),true,303); return;
            } elseif (($page === 'event.php' && $action === 'edit') || ($page === 'delete.php' && $action === 'delete') || ($page === 'index.php' && $action === 'restore')) {
                $service->change($id,$action,$_POST); header('Location: '.($action === 'edit' ? url('event.php?id='.$id) : url()),true,303); return;
            } else throw new Problem('操作が不正です。',400);
        } catch (Problem $e) {
            if (!in_array($e->getCode(),[409,422],true)) throw $e;
            http_response_code($e->getCode()); $error = $e->getMessage();
        }
    }
    if ($page === 'create.php') {
        headerPage('新しいスケジュール調整');
        if ($error) echo '<p role="alert">'.h($error).'</p>';
        if ($preview) {
            echo '<section><h2>作成プレビュー</h2><p>'.count($preview['dates']).'日・各日'.$preview['candidate_slots_per_day'].'候補スロット（全'.(count($preview['dates'])*$preview['candidate_slots_per_day']).'枠）</p>';
            if ($preview['duplicate_count']) echo '<p>重複した日付を'.$preview['duplicate_count'].'件まとめました。</p>';
            echo '<ul>'; foreach ($preview['dates'] as $date) echo '<li>'.h($date).' '.h($_POST['start']).'〜'.(!empty($_POST['end_next_day'])?'翌日':'').h($_POST['end']).'</li>'; echo '</ul></section>';
        }
        echo '<form method="post">'; fields(); metadata($_POST);
        echo '<label>候補日（YYYY-MM-DDを1行1つ、最大366行）<textarea name="dates" rows="5" required placeholder="2026-11-02&#10;2026-11-04">'.h($_POST['dates'] ?? '').'</textarea></label><div class="times"><label>開始<input type="time" name="start" step="1800" required value="'.h($_POST['start'] ?? '09:00').'"></label><label>終了<input type="time" name="end" step="1800" required value="'.h($_POST['end'] ?? '18:00').'"></label></div><label><input type="checkbox" name="end_next_day" value="1"'.(!empty($_POST['end_next_day'])?' checked':'').'> 終了は翌日</label><p>日本時間。当日06:00〜翌日06:00の範囲内で指定してください。</p><button name="action" value="preview">プレビュー</button> <button name="action" value="create">作成</button> <a href="'.url().'">取消</a></form>';
    } elseif ($page === 'event.php' || $page === 'delete.php') {
        $row = $service->get($id); headerPage($row['title']);
        if ($error) echo '<p role="alert">'.h($error).'</p>';
        if ($page === 'delete.php') {
            echo '<p>この案件を一覧から隠します。候補データは保持され、削除済み一覧から復元できます。</p><form method="post">'; fields($error ? ['version'=>$_POST['expected_version'] ?? 0] : $row);
            echo '<label>確認のため案件名を再入力<input name="confirmation" required autocomplete="off"></label><button name="action" value="delete">論理削除する</button> <a href="'.url('event.php?id='.$id).'">取消</a></form>';
        } else {
            if ($row['description'] !== '') echo '<div class="description">'.h($row['description']).'</div>';
            echo '<p>状態: '.h($row['status']).' · 日本時間 · 更新: '.h(jstTime($row['updated_at'])).'</p>';
            candidateGrid($row);
            echo '<details id="metadata-editor"'.($error?' open':'').'><summary>調整名・説明を編集</summary><form method="post"><fieldset'.($row['status']==='open'?'':' disabled').'><legend>受付中のみ編集できます</legend>';
            // Preserve the submitted version after a conflict; never silently retry a stale edit.
            fields($error ? ['version'=>$_POST['expected_version'] ?? 0] : $row); metadata($error ? $_POST : $row);
            echo '<button name="action" value="edit">保存</button> <a href="'.url('event.php?id='.$id).'">取消</a><p>競合時は入力を控え、再読み込みしてから変更内容を確認してください。</p></fieldset></form></details><p><a href="'.url('delete.php?id='.$id).'">削除</a> · <a href="'.url().'">一覧へ戻る</a></p>';
        }
    } else {
        $deleted = ($_GET['deleted'] ?? '') === '1'; headerPage($deleted ? '削除済みスケジュール' : 'スケジュール一覧');
        if ($error) echo '<p role="alert">'.h($error).'</p>';
        echo '<nav><a class="button" href="'.url('create.php').'">＋ 新しいスケジュール調整</a> <a href="'.url($deleted?'':'?deleted=1').'">'.($deleted?'通常の一覧へ':'削除済みを表示／復元').'</a></nav>';
        $rows = $service->listing($deleted);
        if (!$rows) echo '<p>スケジュールはありません。</p>';
        foreach ($rows as $row) {
            echo '<article><h2>'.($deleted ? h($row['title']) : '<a href="'.url('event.php?id='.$row['id']).'">'.h($row['title']).'</a>').'</h2><p>'.h($row['first_date']).'〜'.h($row['last_date']).'（'.h($row['day_count']).'日） · 参加者'.h($row['participant_count']).'人／提出済'.h($row['submitted_count']).'人 · 状態: '.h($row['status']).' · 更新: '.h(jstTime($row['updated_at'])).'</p>';
            if ($deleted) { echo '<form method="post" action="'.url('?id='.$row['id'].'&deleted=1').'">'; fields($row); echo '<button name="action" value="restore">復元</button></form>'; }
            else echo '<a href="'.url('event.php?id='.$row['id']).'">開く</a> · <a href="'.url('delete.php?id='.$row['id']).'">削除</a>';
            echo '</article>';
        }
        echo '<p>共通認証で操作します。監査ログから操作者個人を特定することはできません。</p>';
    }
    echo '</main></body></html>';
}
try { runWeb(); } catch (Throwable $e) {
    $status = $e instanceof Problem ? $e->getCode() : 500; http_response_code($status);
    if ($status === 401) header('WWW-Authenticate: Basic realm="gyugyu"');
    if ($status === 500) error_log('gyugyu request failed: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    headerPage('エラー'); echo '<p role="alert">'.h($status === 500 ? '処理に失敗しました。設定とサーバーログを確認してください。' : $e->getMessage()).'</p></main></body></html>';
}
