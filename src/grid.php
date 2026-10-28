<?php
declare(strict_types=1);
function candidateGrid(array $row): void {
    echo '<section id="candidate-grid" data-status="'.h($row['status']).'" data-version="'.h($row['version']).'" data-revision="'.h($row['schema_revision']).'" data-api="'.h(url('api/index.php?route=/instances/'.$row['id'])).'" data-csrf="'.h($_SESSION['csrf']).'" data-density="16"><h2>日別の候補時間帯</h2><p>● 候補 · — 候補外。全員が参加者の回答を編集できます。グループ・集計・出力は後続です。</p><noscript>候補帯の編集にはJavaScriptを有効にしてください。</noscript>';
    echo '<label>30分セルの幅<select id="grid-density"><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option></select></label>';
    if ($row['status'] === 'open') echo '<button type="button" id="candidate-edit" hidden>候補帯を編集</button>';
    echo '<p id="candidate-status" role="status" aria-live="polite">閲覧中</p><p id="candidate-inspect" role="status" aria-live="polite">セルにフォーカス、またはタップすると時刻と候補状態を確認できます。</p><div id="candidate-tools" hidden><fieldset id="candidate-inputs"><legend>候補帯を編集中</legend><p>パレットを選び、セルをドラッグ、またはキーボードで選択してください。タッチでの横移動は表の時間見出しをスワイプしてください。</p><label>パレット<select id="candidate-palette"><option value="true">候補にする</option><option value="false">候補外にする</option></select></label><button type="button" id="candidate-undo">Undo</button> <button type="button" id="candidate-redo">Redo</button><fieldset><legend>一括操作</legend><label>対象日<select id="candidate-scope"><option value="all">全日</option><option value="selected">選択した日</option></select></label><div id="candidate-days">';
    foreach ($row['days'] as $day) echo '<label><input type="checkbox" value="'.h($day['id']).'"> '.h($day['local_date']).'</label>';
    echo '</div><div class="times"><label>一括開始<input id="candidate-start" type="time" value="09:00" step="1800"></label><label>一括終了<input id="candidate-end" type="time" value="18:00" step="1800"></label></div><label><input id="candidate-next" type="checkbox"> 終了は翌日</label><label>操作<select id="candidate-operation"><option value="replace">置換</option><option value="add">追加</option><option value="remove">除外</option></select></label><button type="button" id="candidate-bulk">一括プレビュー</button></fieldset></fieldset><p id="candidate-preview" aria-live="polite"></p><button type="button" id="candidate-save">候補帯を保存</button> <button type="button" id="candidate-cancel">候補編集を取消</button> <button type="button" id="candidate-reload">最新版を読み直す</button></div>';
    echo '<div id="answer-controls"><button type="button" id="participant-add" hidden>参加者を登録</button><p id="answer-status" role="status" aria-live="polite">回答は閲覧中</p><div id="answer-tools" hidden><fieldset id="answer-inputs"><legend>参加者の回答を編集中</legend><label>参加者名<input id="participant-name" required></label><label>回答パレット<select id="answer-palette"><option value="yes">○ 参加可</option><option value="maybe">△ 未定</option><option value="no">× 参加不可</option><option value="unknown">クリア（未入力）</option></select></label><button type="button" id="answer-undo">回答Undo</button> <button type="button" id="answer-redo">回答Redo</button></fieldset><button type="button" id="answer-save">回答を保存</button> <button type="button" id="answer-cancel">回答編集を取消</button> <button type="button" id="participant-hide">参加者を非表示</button> <button type="button" id="answer-reload">回答の最新版を読み直す</button></div><p id="answer-inspect" role="status" aria-live="polite">回答セルへのフォーカス・タップで時刻と状態を確認できます。</p></div>';
    $names=array_count_values(array_column($row['participants'],'display_name'));
    foreach ($row['days'] as $day) {
        echo '<div class="day-panel" data-day="'.h($day['id']).'"><h3>'.h($day['local_date']).'</h3><div class="day-scroll" tabindex="0" role="region" aria-label="'.h($day['local_date']).' 候補帯（横スクロール）"><table class="day-grid"><caption>'.h($day['local_date']).' 当日06時〜翌06時（日本時間）</caption><colgroup><col class="name-column">';
        for ($i=0;$i<48;$i++) echo '<col class="slot-column">';
        echo '</colgroup><thead><tr><th scope="col">時間</th>';
        for ($i=6;$i<30;$i++) echo '<th scope="colgroup" colspan="2">'.($i === 24 ? '24' : sprintf('%02d',$i%24)).'</th>';
        echo '</tr></thead><tbody><tr><th scope="row">候補帯</th>';
        foreach ($day['slots'] as $slot) {
            $d = (new DateTimeImmutable($slot['starts_at_utc']))->setTimezone(new DateTimeZone('Asia/Tokyo'));
            $label = $d->format('Y-m-d H:i').'〜'.$d->modify('+30 minutes')->format('H:i');
            $active = (bool)$slot['is_candidate'];
            echo '<td><button type="button" class="candidate-cell" data-slot="'.h($slot['id']).'" data-ordinal="'.h($slot['ordinal']).'" data-label="'.h($label).'" aria-label="'.h($label).' '.($active?'候補':'候補外').'" aria-pressed="'.($active?'true':'false').'" title="'.h($label).'">'.($active?'●':'—').'</button></td>';
        }
        echo '</tr>';
        foreach ($row['participants'] as $participant) {
            $values=array_column($participant['answers'],'state','slot_id');
            $label=$participant['display_name'].($names[$participant['display_name']]>1?' #'.substr($participant['id'],0,6):'');
            echo '<tr class="participant-row" data-participant="'.h($participant['id']).'" data-version="'.h($participant['version']).'" data-name="'.h($participant['display_name']).'"><th scope="row"><button type="button" class="participant-edit"'.($row['status']==='open'?'':' disabled').'>'.h($label).'</button></th>';
            foreach ($day['slots'] as $slot) {
                $value=$values[$slot['id']] ?? 'unknown';
                $d=(new DateTimeImmutable($slot['starts_at_utc']))->setTimezone(new DateTimeZone('Asia/Tokyo'));
                $time=$d->format('Y-m-d H:i').'〜'.$d->modify('+30 minutes')->format('H:i');
                $symbol=['yes'=>'○','maybe'=>'△','no'=>'×','unknown'=>'—'][$value];
                echo '<td><button type="button" class="answer-cell" data-slot="'.h($slot['id']).'" data-state="'.h($value).'" data-label="'.h($time).'" data-candidate="'.($slot['is_candidate']?'true':'false').'" aria-label="'.h($time.' '.$symbol.($slot['is_candidate']?'':' 候補外')).'">'.$symbol.'</button></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
    echo '</section><script type="module" src="'.url('assets/candidates.mjs').'"></script><script type="module" src="'.url('assets/answers.mjs').'"></script>';
}
