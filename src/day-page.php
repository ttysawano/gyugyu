<?php
declare(strict_types=1);
function dayPage(string $id,string $method): void {
    if ($method!=='GET') throw new Gyugyu\Problem('許可されていないメソッドです。',405);
    $row=(new Gyugyu\Days(Gyugyu\Database::connect()))->listing($id);headerPage('候補日の管理');
    echo '<p><a href="'.url('event.php?id='.$id).'">案件に戻る</a></p><section id="day-manager" data-api="'.h(url('api/index.php?route=/instances/'.$id.'/days')).'" data-csrf="'.h($_SESSION['csrf']).'" data-version="'.h($row['version']).'" data-revision="'.h($row['schema_revision']).'" data-status="'.h($row['status']).'"><p>削除はアーカイブです。同じ日付の再追加は以前のID・候補帯・回答を復元します。復元時に入力時間帯を適用しません。保存上限はアーカイブ済みを含め累計366日です。同じ日付の復元は上限到達後も可能です。</p><noscript>日付変更にはJavaScriptを有効にしてください。</noscript>';
    $messages=['new'=>'新しい日付を作成しました。','restore'=>'以前の日付と履歴を復元しました。','archive'=>'日付をアーカイブしました。回答は保持しています。'];
    $result=$_GET['result'] ?? '';echo '<p id="day-status" role="status">'.h(is_string($result)?($messages[$result] ?? ''):'').'</p>';
    echo '<fieldset id="day-inputs" disabled><legend>日付の追加・再追加</legend><label>日付<input id="day-date" type="date" min="1900-01-01" max="9998-12-31"></label><div class="times"><label>新規時の開始<input id="day-start" type="time" step="1800" value="09:00"></label><label>新規時の終了<input id="day-end" type="time" step="1800" value="18:00"></label></div><label><input id="day-next" type="checkbox"> 終了は翌日</label><button id="day-add" type="button">追加内容をプレビュー</button><h2>保存済みの日付</h2>';
    foreach ($row['days'] as $day) {
        $archived=$day['archived_at']!==null;
        echo '<article data-day="'.h($day['id']).'"><h3>'.h($day['local_date']).' '.($archived?'アーカイブ済み':'有効').'</h3><p>候補'.h($day['candidate_count']).'枠／保存済み回答'.h($day['answer_count']).'件（候補外・非表示参加者を含む）</p><button type="button" data-action="'.($archived?'restore':'archive').'" data-day-id="'.h($day['id']).'">'.($archived?'履歴復元をプレビュー':'アーカイブの影響を確認').'</button></article>';
    }
    echo '</fieldset><div id="day-preview" aria-live="polite"></div><label id="day-confirm-label" hidden>アーカイブ確認：対象日をYYYY-MM-DDで入力<input id="day-confirm" autocomplete="off"></label><button type="button" id="day-save" disabled>プレビューの内容を確定</button> <button type="button" id="day-cancel" disabled>取消</button> <button type="button" id="day-reload">最新版を読み直す</button></section><script type="module" src="'.url('assets/days.mjs').'"></script></main></body></html>';
}
