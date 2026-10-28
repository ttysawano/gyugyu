<?php
declare(strict_types=1);
function confirmationDisplay(?array $decision): void {
    echo '<section class="confirmation-record"><h2>確定日時</h2>';
    if (!$decision) {echo '<p>確定情報が未登録の旧データです。日時を推測しません。</p></section>';return;}
    $mask=$decision['mask'];$names=array_map(fn($p)=>$p['name'].' #'.substr($p['id'],0,6),$mask['participants']);
    $groups=array_filter($mask['groups'],fn($g)=>in_array($g['id'],$mask['group_ids'],true));
    echo '<p><strong>'.h(jstTime($decision['starts_at_utc'])).' 〜 '.h(jstTime($decision['ends_at_utc'])).'</strong></p><p>決定日時: '.h(jstTime($decision['confirmed_at'])).'</p><p>決定時の対象: '.h(['all'=>'全員','include'=>'選択グループを含む','exclude'=>'選択グループを除外'][$mask['mode']]).' / '.h(implode('、',array_column($groups,'name'))?:'グループ選択なし').' / '.h($mask['N']).'人</p><p>'.h(implode('、',$names)?:'対象者なし').'</p><p>決定時の条件を保存しています。現在の表示フィルターを変えても確定内容は変わりません。</p></section>';
}
function confirmationPage(string $id,string $method): void {
    if ($method!=='GET') throw new Gyugyu\Problem('許可されていないメソッドです。',405);
    $row=(new Gyugyu\Schedules(Gyugyu\Database::connect()))->get($id);$cancel=$row['status']==='confirmed';$mode=$_GET['mode'] ?? 'all';$selected=$_GET['group_ids'] ?? [];
    if (!is_string($mode)||!is_array($selected)) throw new Gyugyu\Problem('対象条件が不正です。',422);Gyugyu\Summary::calculate($row,$mode,$selected);if (!$row['groups']) $mode='all';
    headerPage($cancel?'日時確定を取り消す':'30分候補枠の日時確定');
    echo '<p><a href="'.url('event.php?id='.$id).'">案件に戻る</a></p><section id="confirmation-manager" data-api="'.h(url('api/index.php?route=/instances/'.$id.'/confirmation')).'" data-action="'.($cancel?'cancel':'confirm').'" data-version="'.h($row['version']).'" data-schema="'.h($row['schema_revision']).'" data-answers="'.h($row['answers_revision']).'" data-csrf="'.h($_SESSION['csrf']).'"><h2>'.h($row['title']).'</h2><p>状態: '.h($row['status']).'</p>';
    if ($cancel) {confirmationDisplay($row['confirmation']);echo '<p>確定を取り消すと受付中(open)に戻ります。以前の決定は監査履歴に保持します。</p>';}
    else echo '<p>候補枠を自分で選び、対象条件をプレビューして確認してください。順位や全員○から自動選択しません。対象0人・未回答・×がある場合も確認して確定できます。確定後は回答・構成を変更できません。</p>';
    echo '<noscript>確定操作にはJavaScriptを有効にしてください。</noscript><p id="confirmation-status" role="status">'.(($_GET['saved'] ?? '')==='1'?'確定操作を保存しました。':'').'</p><form>';
    if (!$cancel) {
        echo '<fieldset id="decision-selection" disabled><legend>確定する日時と対象</legend><label>30分候補枠<select name="slot_id"><option value="">選択してください（自動選択しません）</option>';
        foreach ($row['days'] as $day) foreach ($day['slots'] as $slot) if ($slot['is_candidate']) echo '<option value="'.h($slot['id']).'">'.h(jstTime($slot['starts_at_utc']).' 〜 '.jstTime($slot['ends_at_utc'])).'</option>';
        echo '</select></label><div'.(!$row['groups']?' hidden':'').'><label>対象条件<select name="mode">';foreach (['all'=>'全員','include'=>'選択グループを含む（OR）','exclude'=>'選択グループを除外'] as $key=>$label)echo '<option value="'.$key.'"'.($mode===$key?' selected':'').'>'.$label.'</option>';echo '</select></label>';
        foreach($row['groups'] as $g)echo '<label><input type="checkbox" name="group_ids" value="'.h($g['id']).'"'.(in_array($g['id'],$selected,true)?' checked':'').'> '.h($g['name']).'</label>';
        echo '</div><button type="button" id="decision-preview">日時・対象をプレビュー</button></fieldset><div id="decision-preview-result" aria-live="polite"></div>';
    }
    echo '<fieldset id="decision-confirm" disabled><legend>明示確認</legend><label>案件名<input name="confirmation" required autocomplete="off"></label><label><input name="confirm_selection" type="checkbox" required> '.($cancel?'表示された確定を取り消して受付中に戻す':'プレビューの日時・対象条件を確定する').'</label><button type="submit" id="decision-save">'.($cancel?'確定を取り消す':'この日時・対象で確定').'</button></fieldset><button type="reset">取消（入力を破棄）</button></form><button type="button" id="decision-reload">最新版を読み直す</button></section><script type="module" src="'.url('assets/confirmation.mjs').'"></script></main></body></html>';
}
