<?php
declare(strict_types=1);
function participantPage(string $id,string $method): void {
    if ($method!=='GET') throw new Gyugyu\Problem('許可されていないメソッドです。',405);
    $data=(new Gyugyu\Participants(Gyugyu\Database::connect()))->inactive($id);headerPage('非表示参加者の復帰');
    echo '<p><a href="'.url('event.php?id='.$id).'">案件に戻る</a></p><section id="participant-restoration" data-api="'.h(url('api/index.php?route=/instances/'.$id.'/participants/')).'" data-csrf="'.h($_SESSION['csrf']).'" data-version="'.h($data['version']).'" data-schema="'.h($data['schema_revision']).'" data-answers="'.h($data['answers_revision']).'" data-status="'.h($data['status']).'"><p>共有アカウントで任意の非表示参加者を復帰できます。ID・全回答・所属・提出日時を保持します。候補外やアーカイブ済み日の回答は引き続き履歴として保持し、アーカイブ済みグループ自体は復元しません。</p><noscript>参加者の復帰にはJavaScriptを有効にしてください。</noscript><p id="restore-status" role="status">'.(($_GET['restored'] ?? '')==='1'?'参加者を復帰しました。':'').'</p>';
    if (!$data['participants']) echo '<p>非表示の参加者はいません。</p>';
    foreach ($data['participants'] as $p) {
        echo '<form data-participant="'.h($p['id']).'" data-version="'.h($p['version']).'"><fieldset disabled><legend>'.h($p['display_name']).' #'.h(substr($p['id'],0,6)).'</legend><p>参加者ID: '.h($p['id']).'<br>提出日時: '.h(jstTime($p['submitted_at'])).'<br>保存済み回答: '.h($p['answer_count']).'件（候補外・アーカイブ済み日を含む）</p><p>所属: ';
        $labels=array_map(fn($g)=>$g['name'].($g['archived_at']!==null?'（アーカイブ済み・現在の対象外）':''),$p['groups']);echo h($labels?implode('、',$labels):'未所属');
        echo '</p><label>確認のため氏名を入力<input name="confirmation" required autocomplete="off"></label><button type="submit">この参加者を復帰</button> <button type="reset">取消</button></fieldset></form>';
    }
    echo '<button type="button" id="restore-reload">最新版を読み直す</button></section><script type="module" src="'.url('assets/participant-restore.mjs').'"></script></main></body></html>';
}
