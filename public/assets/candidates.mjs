export const copy = value => structuredClone(value);
export function difference(before, after) {
    let changed = 0, removed = 0, days = 0;
    for (const [id, values] of Object.entries(after)) {
        let dayChanged = false;
        values.forEach((value, i) => {
            if (before[id][i] !== value) { changed++; dayChanged = true; if (!value) removed++; }
        });
        if (dayChanged) days++;
    }
    return {changed, removed, days};
}
export function windowSlots(start, end, next) {
    const minutes = text => {
        if (!/^(?:[01][0-9]|2[0-3]):(?:00|30)$/.test(text)) throw Error('時刻は30分刻みで入力してください。');
        const [h,m] = text.split(':').map(Number); return h*60+m;
    };
    const a = minutes(start), b = minutes(end) + (next ? 1440 : 0);
    if (a < 360 || b > 1800 || b <= a) throw Error('当日06:00〜翌06:00内の正の時間帯を指定してください。');
    return [(a-360)/30, (b-360)/30];
}
export function bulkMask(before, ids, start, end, operation) {
    if (!ids.length || !['replace','add','remove'].includes(operation)) throw Error('対象日と操作を選択してください。');
    const result = copy(before);
    for (const id of ids) {
        if (!result[id]) throw Error('対象日が不正です。');
        result[id] = result[id].map((value,i) => operation === 'replace' ? i>=start && i<end : (i>=start && i<end ? operation === 'add' : value));
    }
    return result;
}
export class Draft {
    constructor(value) { this.base = copy(value); this.value = copy(value); this.past = []; this.future = []; }
    record(before) { if (difference(before,this.value).changed) { this.past.push(copy(before)); this.future = []; } }
    replace(value) { const before=this.value; this.value=copy(value); this.record(before); }
    undo() { if (this.past.length) { this.future.push(copy(this.value)); this.value=this.past.pop(); } }
    redo() { if (this.future.length) { this.past.push(copy(this.value)); this.value=this.future.pop(); } }
    get delta() { return difference(this.base,this.value); }
}

function initialize(root) {
    const $ = id => root.querySelector('#'+id);
    const cells = [...root.querySelectorAll('.candidate-cell')];
    const panels = [...root.querySelectorAll('.day-panel')];
    const fromDOM = () => Object.fromEntries(panels.map(panel => [panel.dataset.day,[...panel.querySelectorAll('.candidate-cell')].map(cell=>cell.getAttribute('aria-pressed')==='true')]));
    let draft = new Draft(fromDOM()), editing = false, saving = false, stroke = null;
    let version = Number(root.dataset.version), revision = Number(root.dataset.revision);
    const edit = $('candidate-edit'), metadata = document.querySelector('#metadata-editor');
    $('grid-density').addEventListener('change',e=>{ root.dataset.density=e.target.value; });
    let inspectedCell = null;
    function inspect(event) {
        const cell = event.target.closest('.candidate-cell');
        if (cell && root.contains(cell)) {
            inspectedCell = cell;
            $('candidate-inspect').textContent = cell.getAttribute('aria-label')+'（日本時間）';
        }
    }
    // Inspection never mutates masks, including locked/confirmed schedules.
    for (const event of ['focusin','click','pointerover']) root.addEventListener(event,inspect);
    if (!edit) return;
    edit.hidden = false;
    const status = message => { $('candidate-status').textContent = message; };
    const describe = (delta, affected = null) => `${delta.days}日・変更${delta.changed}セル・候補外になる${delta.removed}セル。${affected===null?'影響回答数はプレビューで確認します。':`隠れる回答${affected}件（削除せず保持）。`}`;
    const masks = value => Object.entries(value).map(([day_id,candidates])=>({day_id,candidates}));
    async function preview(value, signal) {
        const response=await fetch(root.dataset.api+'/candidate-slots/preview',{method:'POST',signal,headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({expected_version:version,expected_schema_revision:revision,masks:masks(value)})});
        if(!response.ok) throw Error(response.status===409?'競合しました。下書きを控えて最新版を読み直してください。':`プレビューに失敗しました（HTTP ${response.status}）。下書きは保持しています。`);
        return response.json();
    }
    function affectedAnswers(before,after,counts) {
        let count=0;
        for(const cell of cells) {
            const day=cell.closest('[data-day]').dataset.day,i=Number(cell.dataset.ordinal);
            if(before[day][i] && !after[day][i]) count+=counts[cell.dataset.slot] || 0;
        }
        return count;
    }
    function render() {
        $('candidate-tools').hidden = !editing;
        edit.hidden = editing;
        edit.disabled = !!metadata?.open || !!root.dataset.answerEditing || !!root.dataset.exporting;
        root.dataset.candidateEditing = editing ? '1' : '';
        root.dispatchEvent(new CustomEvent('candidate-mode',{detail:{editing}}));
        if (metadata) metadata.hidden = editing || !!root.dataset.answerEditing || !!root.dataset.exporting;
        $('candidate-inputs').disabled = saving;
        $('candidate-save').disabled = saving || !draft.delta.changed;
        $('candidate-cancel').disabled = saving;
        $('candidate-reload').disabled = saving;
        $('candidate-undo').disabled = saving || !draft.past.length;
        $('candidate-redo').disabled = saving || !draft.future.length;
        $('candidate-preview').textContent = describe(draft.delta);
        $('candidate-days').hidden = $('candidate-scope').value !== 'selected';
        for (const panel of panels) panel.querySelector('table').classList.toggle('editing',editing);
        for (const cell of cells) {
            const value = draft.value[cell.closest('[data-day]').dataset.day][Number(cell.dataset.ordinal)];
            cell.setAttribute('aria-pressed',String(value)); cell.textContent = value ? '●' : '—';
            cell.setAttribute('aria-label',cell.dataset.label+' '+(value?'候補':'候補外'));
            cell.disabled = saving;
        }
        if (inspectedCell) inspect({target:inspectedCell});
    }
    metadata?.addEventListener('toggle',render);
    root.addEventListener('answer-mode',render);
    root.addEventListener('export-mode',render);
    edit.addEventListener('click',()=>{ if(root.dataset.exporting||root.dataset.answerEditing||metadata?.open)return; editing=true; status('候補帯を編集中（未保存）'); render(); });
    $('candidate-scope').addEventListener('change',render);
    $('candidate-undo').addEventListener('click',()=>{ finish(); draft.undo(); status('Undo（未保存）'); render(); });
    $('candidate-redo').addEventListener('click',()=>{ finish(); draft.redo(); status('Redo（未保存）'); render(); });
    $('candidate-cancel').addEventListener('click',()=>{
        finish(true);
        if (draft.delta.changed && !confirm('未保存の候補帯変更を破棄しますか？')) return;
        draft=new Draft(draft.base); editing=false; status('取消しました。保存済みの候補帯を表示しています。'); render();
    });
    $('candidate-reload').addEventListener('click',()=>{
        if (draft.delta.changed && !confirm('下書きを破棄して最新版を読み直しますか？')) return;
        draft=new Draft(draft.base); window.location.reload();
    });
    $('candidate-bulk').addEventListener('click',async()=>{
        if(saving) return;
        finish();
        const controller=new AbortController(); const timeout=setTimeout(()=>controller.abort(),15000);
        try {
            const [start,end] = windowSlots($('candidate-start').value,$('candidate-end').value,$('candidate-next').checked);
            const ids = $('candidate-scope').value === 'all' ? Object.keys(draft.value) : [...$('candidate-days').querySelectorAll('input:checked')].map(input=>input.value);
            const next=bulkMask(draft.value,ids,start,end,$('candidate-operation').value);
            saving=true; render();
            const impact=await preview(next,controller.signal);
            const message=`対象${ids.length}日。${describe(difference(draft.value,next),affectedAnswers(draft.value,next,impact.answer_counts))}\n下書きへ適用しますか？（保存まではDBを変更しません）`;
            $('candidate-preview').textContent=message;
            if (!confirm(message)) return;
            draft.replace(next); status('一括操作を下書きへ適用しました（未保存）'); render();
        } catch (error) { status('プレビュー失敗。下書きを保持しています。'+error.message); }
        finally { clearTimeout(timeout); saving=false; render(); }
    });
    function paint(cell) {
        if (!stroke) return;
        if (!cell || !root.contains(cell)) { stroke.last = null; return; }
        const day=cell.closest('[data-day]').dataset.day, i=Number(cell.dataset.ordinal);
        const from=stroke.last?.day===day ? stroke.last.i : i;
        for(let n=Math.min(from,i);n<=Math.max(from,i);n++) draft.value[day][n]=stroke.value;
        stroke.last={day,i}; render();
    }
    function finish(cancel=false) {
        if (!stroke) return;
        const {before,source,pointerId}=stroke; stroke=null;
        if (cancel) draft.value=before; else draft.record(before);
        if (source.hasPointerCapture(pointerId)) source.releasePointerCapture(pointerId);
        status(cancel?'ドラッグを中断しました。直前の下書きを保持しています。':'候補帯を編集中（未保存）'); render();
    }
    root.addEventListener('pointerdown',event=>{
        const cell=event.target.closest('.candidate-cell');
        if (!cell || !editing || saving || stroke || event.button!==0 || !event.isPrimary) return;
        event.preventDefault(); cell.focus();
        stroke={before:copy(draft.value),value:$('candidate-palette').value==='true',pointerId:event.pointerId,source:cell,last:null};
        cell.setPointerCapture(event.pointerId); paint(cell);
    });
    root.addEventListener('pointermove',event=>{
        if (!stroke || event.pointerId!==stroke.pointerId) return;
        paint(document.elementFromPoint(event.clientX,event.clientY)?.closest('.candidate-cell'));
    });
    window.addEventListener('pointerup',event=>{ if(stroke?.pointerId===event.pointerId) finish(); });
    window.addEventListener('pointercancel',event=>{ if(stroke?.pointerId===event.pointerId) finish(true); });
    root.addEventListener('lostpointercapture',()=>finish(true));
    window.addEventListener('blur',()=>finish(true));
    document.addEventListener('visibilitychange',()=>{ if(document.hidden) finish(true); });
    root.addEventListener('click',event=>{
        const cell=event.target.closest('.candidate-cell');
        if (!cell || !editing || saving || event.detail!==0) return; // keyboard / assistive activation
        const next=copy(draft.value); next[cell.closest('[data-day]').dataset.day][Number(cell.dataset.ordinal)]=$('candidate-palette').value==='true';
        draft.replace(next); status('候補帯を編集中（未保存）'); render();
    });
    window.addEventListener('beforeunload',event=>{ if (draft.delta.changed || saving) { event.preventDefault(); event.returnValue=''; } });
    $('candidate-save').addEventListener('click',async()=>{
        if (saving || !editing) return;
        finish();
        if (!draft.delta.changed) return;
        const delta=draft.delta;
        saving=true; status('保存プレビュー中…'); render();
        const controller=new AbortController();
        const timeout=setTimeout(()=>controller.abort(),15000);
        try {
            const impact=await preview(draft.value,controller.signal);
            if (!confirm(describe(delta,impact.affected_answers)+'\nこの内容で保存しますか？')) { status('保存を取り消しました。下書きを保持しています。'); return; }
            status('保存中…');
            const response=await fetch(root.dataset.api+'/candidate-slots/bulk',{
                method:'PATCH', headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf}, signal:controller.signal,
                body:JSON.stringify({expected_version:version,expected_schema_revision:revision,expected_answers_revision:impact.answers_revision,confirm_exclusion:delta.removed>0,masks:Object.entries(draft.value).map(([day_id,candidates])=>({day_id,candidates}))})
            });
            if (!response.ok) {
                if(response.status===409) {
                    let comparison='';
                    try {
                        const latestResponse=await fetch(root.dataset.api,{signal:controller.signal});
                        if(latestResponse.ok) {
                            const latest=await latestResponse.json();
                            const masks=Object.fromEntries(latest.days.map(day=>[day.id,day.slots.map(slot=>!!slot.is_candidate)]));
                            comparison=` 最新版との差は${difference(masks,draft.value).changed}セル（最新版の候補版数${latest.schema_revision}）。`;
                        }
                    } catch { /* Retain draft and original revision even if comparison fails. */ }
                    throw Error('競合しました。下書きは保持しています。'+comparison+' 入力を控え、「最新版を読み直す」からやり直してください。');
                }
                throw Error(`保存できませんでした（HTTP ${response.status}）。下書きを保持しています。`);
            }
            const row=await response.json();
            version=row.version; revision=row.schema_revision;
            draft=new Draft(Object.fromEntries(row.days.map(day=>[day.id,day.slots.map(slot=>!!slot.is_candidate)])));
            editing=false; status('保存完了');
            root.dispatchEvent(new CustomEvent('candidate-saved',{detail:row}));
        } catch (error) {
            status(error.name==='TypeError'||error.name==='AbortError' ? '通信に失敗しました。下書きを保持しています。保存結果が不明な場合は最新版と確認してください。' : error.message);
        } finally { clearTimeout(timeout); saving=false; render(); }
    });
    render();
}
if (typeof document !== 'undefined') {
    const root=document.querySelector('#candidate-grid');
    if(root) initialize(root);
}
