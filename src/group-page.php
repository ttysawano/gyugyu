<?php
declare(strict_types=1);
function groupPage(Gyugyu\Schedules $s, string $id, string $method): void {
    $groups=new Gyugyu\Groups(Gyugyu\Database::connect()); $error=null;
    if ($method==='POST') {
        Gyugyu\Security::mutation($_POST);
        $action=$_POST['action'] ?? ''; $target=$_POST['group_id'] ?? '';
        if (!is_string($action)||!is_string($target)) throw new Gyugyu\Problem('操作が不正です。',400);
        try {
            $groups->change($id,$target===''?null:$target,$action,$_POST);
            header('Location: '.url('groups.php?id='.$id),true,303); return;
        } catch (Gyugyu\Problem $e) {
            if (!in_array($e->getCode(),[409,422],true)) throw $e;
            http_response_code($e->getCode()); $error=$e->getMessage();
        }
    }
    $row=$s->get($id); headerPage('グループ管理');
    echo '<p><a href="'.url('event.php?id='.$id).'">案件に戻る</a></p><p>有効グループは0〜9件。削除後も参加者・回答・所属履歴を保持します。</p>';
    if ($error) {
        echo '<p role="alert">'.h($error).' 入力を控え、最新版を読み直してください。</p>';
        // Keep a non-submitting copy even when the target no longer has an edit form.
        $archivedIds=array_column($groups->listing($id,true),'id');
        echo '<section id="group-failed-draft"><h2>保存できなかった下書き</h2>';
        if (in_array($_POST['group_id'] ?? '',$archivedIds,true)) echo '<p>対象グループは現在アーカイブ済みです。</p>';
        echo '<p>送信した入力と元の版数です。復元・上書きは行っていません。必要な内容を控えてください。</p><dl>';
        foreach (['action','group_id','expected_version','expected_schema_revision','expected_group_version','expected_answers_revision'] as $key) {
            $value=$_POST[$key] ?? '';
            echo '<dt>'.h($key).'</dt><dd>'.h(is_string($value)?$value:json_encode($value,JSON_UNESCAPED_UNICODE)).'</dd>';
        }
        echo '</dl>';
        foreach (['name'=>'送信したグループ名','description'=>'送信した説明','confirmation'=>'送信した確認名'] as $key=>$label) {
            if (!array_key_exists($key,$_POST)) continue;
            $value=$_POST[$key];
            echo '<label>'.h($label).'<textarea readonly>'.h(is_string($value)?$value:json_encode($value,JSON_UNESCAPED_UNICODE)).'</textarea></label>';
        }
        echo '</section>';
    }
    $form=function(?array $g,string $action) use($row,$error) {
        $failed=$error && ($_POST['group_id'] ?? '')===($g['id'] ?? '') && ($_POST['action'] ?? '')===$action;
        $v=$failed?$_POST:array_merge($g ?? [],['expected_version'=>$row['version'],'expected_schema_revision'=>$row['schema_revision'],'expected_answers_revision'=>$row['answers_revision'],'expected_group_version'=>$g['version'] ?? 0]);
        echo '<form method="post" data-action="'.h($action).'">'; fields();
        foreach (['expected_version','expected_schema_revision','expected_answers_revision','expected_group_version'] as $key) echo '<input type="hidden" name="'.h($key).'" value="'.h($v[$key] ?? 0).'">';
        echo '<input type="hidden" name="group_id" value="'.h($g['id'] ?? '').'">';
        if (in_array($action,['create','edit'],true)) echo '<label>グループ名<input name="name" required maxlength="60" value="'.h($v['name'] ?? '').'"></label><label>説明<textarea name="description" maxlength="500">'.h($v['description'] ?? '').'</textarea></label>';
        if ($action==='archive') echo '<p>所属する有効参加者'.h($g['member_count']).'人。回答は削除しません。</p><label>削除確認：グループ名を入力<input name="confirmation" required value="'.h($failed?($_POST['confirmation'] ?? ''):'').'"></label>';
        echo '<button name="action" value="'.h($action).'"'.($action==='create' && count($row['groups'])>=9?' disabled':'').'>'.['create'=>'追加','edit'=>'変更を保存','archive'=>'グループを削除','restore'=>'復元'][$action].'</button> <button type="reset">取消</button></form>';
    };
    if ($row['status']==='open') {
        echo '<section><h2>追加</h2>'; $form(null,'create'); echo '</section>';
        foreach ($groups->listing($id) as $g) { echo '<section data-group="'.h($g['id']).'"><h2>'.h($g['name']).'</h2>'; $form($g,'edit'); $form($g,'archive'); echo '</section>'; }
        echo '<section><h2>削除済み</h2>';
        foreach ($groups->listing($id,true) as $g) {echo '<h3>'.h($g['name']).'</h3>'; $form($g,'restore');} echo '</section>';
    } else echo '<p>受付中の案件のみ変更できます。</p>';
    echo '<p id="group-save-status" role="status"></p><script type="module" src="'.url('assets/groups.mjs').'"></script></main></body></html>';
}
