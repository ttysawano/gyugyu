import {Draft,copy} from './candidates.mjs';
export const symbols={yes:'○',maybe:'△',no:'×',unknown:'—'};
export function submission(values,slots) {
    const answers=[];
    for(const [day,list] of Object.entries(values)) list.forEach((state,i)=>{
        if(slots[day][i].candidate && state!=='unknown') answers.push({slot_id:slots[day][i].id,state});
    });
    return answers;
}
function initialize(root) {
    const $=id=>root.querySelector('#'+id), panels=[...root.querySelectorAll('.day-panel')];
    if(!panels.length)return;
    const metadata=document.querySelector('#metadata-editor');
    let slots={},active=null,draft=null,groupsBefore=[],nameBefore='',saving=false,stroke=null,inspected=null;
    let version=Number(root.dataset.version),revision=Number(root.dataset.revision);
    const status=text=>{$('answer-status').textContent=text;};
    function readSlots() { slots=Object.fromEntries(panels.map(panel=>[panel.dataset.day,[...panel.querySelectorAll('.candidate-cell')].map(cell=>({id:cell.dataset.slot,candidate:cell.getAttribute('aria-pressed')==='true',label:cell.dataset.label}))])); }
    readSlots();
    $('answer-inputs').append($('membership-template').content.cloneNode(true));
    const groupInputs=[...root.querySelectorAll('#participant-groups input')];
    $('participant-groups').hidden=!groupInputs.length;
    const selectedGroups=()=>groupInputs.filter(input=>input.checked).map(input=>input.value);

    const rows=id=>[...root.querySelectorAll('.participant-row')].filter(row=>row.dataset.participant===id);
    const dirty=()=>!!draft&&(draft.delta.changed>0||$('participant-name').value!==nameBefore||JSON.stringify(selectedGroups())!==JSON.stringify(groupsBefore)||active.id==='draft');
    function inspect(cell) {
        if(!cell) return;
        inspected=cell; $('answer-inspect').textContent=cell.getAttribute('aria-label')+'（日本時間）';
    }
    function labels() {
        const people=new Map([...root.querySelectorAll('.participant-row')].map(row=>[row.dataset.participant,row.dataset.name]));
        const counts=new Map(); for(const name of people.values()) counts.set(name,(counts.get(name)||0)+1);
        for(const row of root.querySelectorAll('.participant-row')) row.querySelector('.participant-edit').textContent=row.dataset.participant==='draft'?'新規（未保存）':row.dataset.name+(counts.get(row.dataset.name)>1?' #'+row.dataset.participant.slice(0,6):'');
    }
    function render() {
        $('answer-tools').hidden=!active;
        $('participant-add').disabled=!!active||!!root.dataset.candidateEditing||!!root.dataset.exporting||!!metadata?.open;
        $('answer-inputs').disabled=saving;
        for(const id of ['answer-save','answer-cancel','answer-reload','participant-hide']) $(id).disabled=saving;
        $('participant-hide').hidden=!active||active.id==='draft';
        $('answer-undo').disabled=saving||!draft?.past.length; $('answer-redo').disabled=saving||!draft?.future.length;
        if(metadata) metadata.hidden=!!active||!!root.dataset.candidateEditing||!!root.dataset.exporting;
        for(const row of root.querySelectorAll('.participant-row')) {
            const selected=active?.id===row.dataset.participant,day=row.closest('[data-day]').dataset.day;
            row.classList.toggle('answer-editing',selected);
            row.querySelector('.participant-edit').disabled=!!active||!!root.dataset.candidateEditing||!!root.dataset.exporting||!!metadata?.open||root.dataset.status!=='open';
            [...row.querySelectorAll('.answer-cell')].forEach((cell,i)=>{
                const value=selected?draft.value[day][i]:cell.dataset.state;
                cell.textContent=symbols[value]; cell.dataset.display=value;
                cell.dataset.candidate=String(slots[day][i].candidate);
                cell.disabled=saving;
                cell.setAttribute('aria-label',cell.dataset.label+' '+symbols[value]+(value==='unknown'?' 未入力':'')+(slots[day][i].candidate?'':' 候補外（履歴保持）'));
                cell.setAttribute('aria-disabled',String(selected&&!slots[day][i].candidate));
            });
        }
        if(inspected?.isConnected) inspect(inspected);
        labels(); root.dispatchEvent(new CustomEvent('participants-rendered'));
    }
    function setMode() { root.dataset.answerEditing=active?'1':''; root.dispatchEvent(new CustomEvent('answer-mode')); render(); }
    function newRows() {
        for(const panel of panels) {
            const row=document.createElement('tr'); row.className='participant-row'; row.dataset.participant='draft'; row.dataset.version='0'; row.dataset.name='';
            const th=document.createElement('th'); th.scope='row'; const button=document.createElement('button'); button.type='button'; button.className='participant-edit'; th.append(button); row.append(th);
            for(const slot of slots[panel.dataset.day]) {
                const td=document.createElement('td'),cell=document.createElement('button'); cell.type='button'; cell.className='answer-cell';
                Object.assign(cell.dataset,{slot:slot.id,state:'unknown',label:slot.label}); td.append(cell); row.append(td);
            }
            panel.querySelector('tbody').insertBefore(row,panel.querySelector('.summary-row'));
        }
    }
    function begin(id) {
        if(active||saving||root.dataset.exporting||root.dataset.candidateEditing||metadata?.open||root.dataset.status!=='open') return;
        if(id==='draft') newRows();
        const selected=rows(id),first=selected[0];
        active={id,version:Number(first.dataset.version),key:[...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('')};
        groupsBefore=JSON.parse(first.dataset.groups||'[]');
        groupInputs.forEach(input=>input.checked=groupsBefore.includes(input.value));
        groupsBefore=selectedGroups();
        nameBefore=first.dataset.name; $('participant-name').value=nameBefore;
        draft=new Draft(Object.fromEntries(selected.map(row=>[row.closest('[data-day]').dataset.day,[...row.querySelectorAll('.answer-cell')].map(cell=>cell.dataset.state)])));
        status('回答を編集中（未保存）'); setMode(); $('participant-name').focus();
    }
    $('participant-add').hidden=root.dataset.status!=='open';
    $('participant-add').addEventListener('click',()=>begin('draft'));
    $('participant-name').addEventListener('input',()=>$('participant-name').setCustomValidity(''));
    metadata?.addEventListener('toggle',render);
    root.addEventListener('candidate-mode',render);
    root.addEventListener('export-mode',render);
    root.addEventListener('candidate-saved',event=>{
        revision=event.detail.schema_revision; version=event.detail.version; readSlots(); render();
    });
    root.addEventListener('click',event=>{
        const edit=event.target.closest('.participant-edit'); if(edit) begin(edit.closest('[data-participant]').dataset.participant);
        const cell=event.target.closest('.answer-cell'); if(!cell) return;
        inspect(cell);
        if(event.detail===0&&editable(cell)) {
            const next=copy(draft.value),day=cell.closest('[data-day]').dataset.day,i=index(cell);
            next[day][i]=$('answer-palette').value; draft.replace(next); render();
        }
    });
    for(const type of ['focusin','pointerover']) root.addEventListener(type,event=>inspect(event.target.closest('.answer-cell')));
    const index=cell=>[...cell.closest('tr').querySelectorAll('.answer-cell')].indexOf(cell);
    const editable=cell=>!!cell&&!!active&&!saving&&cell.closest('[data-participant]').dataset.participant===active.id&&cell.dataset.candidate==='true';
    function paint(cell) {
        if(!stroke) return;
        if(!editable(cell)) {stroke.last=null;return;}
        const day=cell.closest('[data-day]').dataset.day,i=index(cell),from=stroke.last?.day===day?stroke.last.i:i;
        for(let n=Math.min(from,i);n<=Math.max(from,i);n++) if(slots[day][n].candidate) draft.value[day][n]=stroke.value;
        stroke.last={day,i}; render();
    }
    function finish(cancel=false) {
        if(!stroke) return;
        const {before,source,id}=stroke; stroke=null;
        if(cancel) draft.value=before; else draft.record(before);
        if(source.hasPointerCapture(id)) source.releasePointerCapture(id);
        status(cancel?'ドラッグを中断しました。下書きを保持しています。':'回答を編集中（未保存）'); render();
    }
    root.addEventListener('pointerdown',event=>{
        const cell=event.target.closest('.answer-cell'); if(!editable(cell)||stroke||event.button!==0||!event.isPrimary) return;
        event.preventDefault(); cell.focus(); stroke={before:copy(draft.value),source:cell,id:event.pointerId,value:$('answer-palette').value,last:null}; cell.setPointerCapture(event.pointerId); paint(cell);
    });
    root.addEventListener('pointermove',event=>{if(stroke?.id===event.pointerId) paint(document.elementFromPoint(event.clientX,event.clientY)?.closest('.answer-cell'));});
    window.addEventListener('pointerup',event=>{if(stroke?.id===event.pointerId) finish();});
    window.addEventListener('pointercancel',event=>{if(stroke?.id===event.pointerId) finish(true);});
    root.addEventListener('lostpointercapture',()=>finish(true)); window.addEventListener('blur',()=>finish(true));
    document.addEventListener('visibilitychange',()=>{if(document.hidden) finish(true);});
    $('answer-undo').addEventListener('click',()=>{finish();draft.undo();render();});
    $('answer-redo').addEventListener('click',()=>{finish();draft.redo();render();});
    function discard() { if(active?.id==='draft') rows('draft').forEach(row=>row.remove()); active=null;draft=null;setMode(); }
    $('answer-cancel').addEventListener('click',()=>{finish(true);if(dirty()&&!confirm('未保存の氏名・所属・回答を破棄しますか？'))return;discard();status('回答編集を取り消しました。');});
    $('answer-reload').addEventListener('click',()=>{if(dirty()&&!confirm('下書きを破棄して最新版を読み直しますか？'))return;discard();location.reload();});
    window.addEventListener('beforeunload',event=>{if(dirty()||saving){event.preventDefault();event.returnValue='';}});
    async function send(hide=false) {
        if(saving||!active) return; finish();
        if(!hide) {
            const length=Array.from($('participant-name').value.trim()).length;
            $('participant-name').setCustomValidity(length>=1&&length<=80?'':'氏名は1〜80文字で入力してください。');
            if(!$('participant-name').reportValidity()) return;
        }
        let confirmation;
        if(hide) {confirmation=prompt('未保存の変更を破棄して参加者を非表示にします。回答は保持します。確認のため保存済みの氏名を入力してください。', '');if(confirmation===null)return;}
        saving=true;status('回答を保存中…');render();
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
        try {
            const fresh=active.id==='draft';
            const path=root.dataset.api+'/participants'+(fresh?'':'/'+active.id+(hide?'':'/submission'));
            const payload={expected_version:version,expected_schema_revision:revision,expected_participant_version:active.version,submission_key:active.key,display_name:$('participant-name').value,group_ids:selectedGroups(),answers:submission(draft.value,slots),confirmation,is_active:false};
            const response=await fetch(path,{method:hide?'PATCH':fresh?'POST':'PUT',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(payload),signal:controller.signal});
            if(!response.ok) {
                const problem=await response.json();
                let comparison='';
                if(response.status===409 && !fresh) {
                    try {
                        const latestResponse=await fetch(root.dataset.api,{signal:controller.signal});
                        if(latestResponse.ok) {
                            const latest=await latestResponse.json(),person=latest.participants.find(p=>p.id===active.id);
                            if(person) {
                                const values=Object.fromEntries(person.answers.map(a=>[a.slot_id,a.state]));let count=0;
                                for(const [day,list] of Object.entries(draft.value)) list.forEach((value,i)=>{if(value!==(values[slots[day][i].id]||'unknown'))count++;});
                                comparison=` 最新版との差：氏名${person.display_name===$('participant-name').value?'は同じ':'に差あり'}、回答${count}セル。`;
                            } else comparison=' 最新版では参加者が表示対象外です。';
                        }
                    } catch { /* Comparison must never replace the draft or expected revision. */ }
                }
                throw Error((response.status===409?'競合しました。':'保存できませんでした。')+(problem.error||'')+comparison+' 下書きは保持しています。必要なら控えて最新版を読み直してください。');
            }
            const participant=await response.json();
            if(hide) rows(active.id).forEach(row=>row.remove());
            else {
                const values=Object.fromEntries(participant.answers.map(answer=>[answer.slot_id,answer.state]));
                for(const row of rows(active.id)) {
                    row.dataset.participant=participant.id;row.dataset.version=participant.version;row.dataset.name=participant.display_name;row.dataset.groups=JSON.stringify(participant.group_ids);
                    for(const cell of row.querySelectorAll('.answer-cell')) cell.dataset.state=values[cell.dataset.slot]||'unknown';
                }
            }
            active=null;draft=null;status(hide?'参加者を非表示にしました。回答は保持しています。':'回答の保存完了');setMode();
        } catch(error) {status(error.name==='AbortError'||error.name==='TypeError'?'通信に失敗しました。下書きを保持しています。保存結果が不明な場合は最新版を確認してください。':error.message);}
        finally {clearTimeout(timeout);saving=false;render();}
    }
    $('answer-save').addEventListener('click',()=>send()); $('participant-hide').addEventListener('click',()=>send(true));
    render();
}
if(typeof document!=='undefined') {const root=document.querySelector('#candidate-grid');if(root)initialize(root);}
