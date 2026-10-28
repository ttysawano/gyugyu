<?php
declare(strict_types=1);
function receptionPage(string $id,string $method): void {
    if ($method!=='GET') throw new Gyugyu\Problem('許可されていないメソッドです。',405);
    $row=(new Gyugyu\Schedules(Gyugyu\Database::connect()))->get($id);$action=$row['status']==='open'?'lock':($row['status']==='locked'?'reopen':'');
    headerPage('受付終了・再開');
    echo '<p><a href="'.url('event.php?id='.$id).'">案件に戻る</a></p><section id="reception-manager" data-api="'.h(url('api/index.php?route=/instances/'.$id.'/reception')).'" data-csrf="'.h($_SESSION['csrf']).'" data-action="'.h($action).'" data-version="'.h($row['version']).'" data-schema="'.h($row['schema_revision']).'" data-answers="'.h($row['answers_revision']).'"><h2>'.h($row['title']).'</h2><p>現在の状態: '.h($row['status']).'</p><p>有効候補日'.count($row['days']).'日／有効参加者'.count($row['participants']).'人。読込み時点の保存済み内容を確認しています。</p><p>受付終了は回答・参加者・所属・候補・グループ・調整名の変更を停止します。閲覧・表示条件の変更・集計・出力は可能です。再開は明示操作で行い、説明文の日付や締切から自動変更しません。回答と履歴は保持します。日時確定は今回の操作には含みません。</p><noscript>受付変更にはJavaScriptを有効にしてください。</noscript><p id="reception-status" role="status">'.(($_GET['saved'] ?? '')==='1'?'受付状態を保存しました。':'').'</p>';
    echo '<form><fieldset disabled><legend>'.($action==='lock'?'受付を終了します':($action==='reopen'?'受付を再開します':'この状態では変更できません')).'</legend><label>確認のため案件名を入力<input name="confirmation" required autocomplete="off"></label><button type="submit">'.($action==='lock'?'受付を終了する':'受付を再開する').'</button> <button type="reset">取消</button></fieldset></form><button type="button" id="reception-reload">最新版を読み直す</button></section><script type="module" src="'.url('assets/reception.mjs').'"></script></main></body></html>';
}
