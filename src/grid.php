<?php
declare(strict_types=1);
function candidateGrid(array $row): void {
    echo '<section id="candidate-grid" data-version="'.h($row['version']).'" data-revision="'.h($row['schema_revision']).'" data-api="'.h(url('api/index.php?route=/instances/'.$row['id'])).'" data-csrf="'.h($_SESSION['csrf']).'" data-density="16"><h2>日別の候補時間帯</h2><p>● 候補 · — 候補外。回答・参加者・集計はまだ実装していません。</p><noscript>候補帯の編集にはJavaScriptを有効にしてください。</noscript>';
    echo '<label>30分セルの幅<select id="grid-density"><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option></select></label>';
    if ($row['status'] === 'open') echo '<button type="button" id="candidate-edit" hidden>候補帯を編集</button>';
    echo '<p id="candidate-status" role="status" aria-live="polite">閲覧中</p><div id="candidate-tools" hidden><fieldset id="candidate-inputs"><legend>候補帯を編集中</legend><p>パレットを選び、セルをドラッグ、またはキーボードで選択してください。タッチでの横移動は表の時間見出しをスワイプしてください。</p><label>パレット<select id="candidate-palette"><option value="true">候補にする</option><option value="false">候補外にする</option></select></label><button type="button" id="candidate-undo">Undo</button> <button type="button" id="candidate-redo">Redo</button><fieldset><legend>一括操作</legend><label>対象日<select id="candidate-scope"><option value="all">全日</option><option value="selected">選択した日</option></select></label><div id="candidate-days">';
    foreach ($row['days'] as $day) echo '<label><input type="checkbox" value="'.h($day['id']).'"> '.h($day['local_date']).'</label>';
    echo '</div><div class="times"><label>一括開始<input id="candidate-start" type="time" value="09:00" step="1800"></label><label>一括終了<input id="candidate-end" type="time" value="18:00" step="1800"></label></div><label><input id="candidate-next" type="checkbox"> 終了は翌日</label><label>操作<select id="candidate-operation"><option value="replace">置換</option><option value="add">追加</option><option value="remove">除外</option></select></label><button type="button" id="candidate-bulk">一括プレビュー</button></fieldset></fieldset><p id="candidate-preview" aria-live="polite"></p><button type="button" id="candidate-save">候補帯を保存</button> <button type="button" id="candidate-cancel">候補編集を取消</button> <button type="button" id="candidate-reload">最新版を読み直す</button></div>';
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
            echo '<td><button type="button" class="candidate-cell" data-slot="'.h($slot['id']).'" data-ordinal="'.h($slot['ordinal']).'" data-label="'.h($label).'" aria-label="'.h($label).' '.($active?'候補':'候補外').'" aria-pressed="'.($active?'true':'false').'" title="'.h($label).'" disabled>'.($active?'●':'—').'</button></td>';
        }
        echo '</tr></tbody></table></div></div>';
    }
    echo '</section><script type="module" src="'.url('assets/candidates.mjs').'"></script>';
}
