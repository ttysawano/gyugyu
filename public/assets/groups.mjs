export function matches(mode, selected, memberships) {
    const included=memberships.some(id=>selected.includes(id));
    return mode==='include'?included:mode==='exclude'?!included:true;
}
function initialize(root) {
    const filter=root.querySelector('#group-filter'),mode=root.querySelector('#group-mode');
    const inputs=[...filter.querySelectorAll('input')],ids=inputs.map(input=>input.value);
    const key='gyugyu-mask-'+root.dataset.instance,metadata=document.querySelector('#metadata-editor');
    let state={mode:'all',selected:[]};
    try {const saved=JSON.parse(sessionStorage.getItem(key));if(saved&&['all','include','exclude'].includes(saved.mode)&&Array.isArray(saved.selected))state={mode:saved.mode,selected:saved.selected.filter(id=>ids.includes(id))};} catch {}
    if(!ids.length)state={mode:'all',selected:[]};
    const busy=()=>!!root.dataset.answerEditing||!!root.dataset.candidateEditing||!!metadata?.open;
    function render() {
        mode.value=state.mode;inputs.forEach(input=>input.checked=state.selected.includes(input.value));filter.disabled=busy();
        const people=new Set(),visible=new Set();
        for(const row of root.querySelectorAll('.participant-row')) {
            const id=row.dataset.participant;
            row.hidden=id!=='draft'&&!matches(state.mode,state.selected,JSON.parse(row.dataset.groups||'[]'));
            if(id!=='draft'){people.add(id);if(!row.hidden)visible.add(id);}
        }
        root.querySelector('#group-status').textContent=`対象${visible.size}人／全${people.size}人`+(state.mode==='include'&&!state.selected.length?'（グループ未選択）':'')+'。時間別の未入力数・集計は未実装です。';
        root.querySelector('#group-manage').setAttribute('aria-disabled',String(busy()));
    }
    filter.addEventListener('change',()=>{
        if(!busy()){state={mode:mode.value,selected:inputs.filter(input=>input.checked).map(input=>input.value)};try{sessionStorage.setItem(key,JSON.stringify(state));}catch{}}
        render();
    });
    root.querySelector('#group-manage').addEventListener('click',event=>{if(busy()){event.preventDefault();root.querySelector('#group-status').textContent='編集中です。保存または取消後にグループ管理を開いてください。';}});
    for(const event of ['answer-mode','candidate-mode','participants-rendered']) root.addEventListener(event,render);
    metadata?.addEventListener('toggle',render);render();
}
if(typeof document!=='undefined'){const root=document.querySelector('#candidate-grid');if(root)initialize(root);}

function manageForms() {
    const status=document.querySelector('#group-save-status');if(!status)return;
    let dirty=false,saving=false;
    const controls=[...document.querySelectorAll('form input,form textarea,form button')],initial=controls.map(control=>control.disabled);
    document.addEventListener('input',event=>{
        const form=event.target.closest('form');if(!form)return;
        dirty=true;controls.forEach((control,i)=>control.disabled=initial[i]||control.form!==form);
    });
    document.addEventListener('reset',event=>{
        if(saving||dirty&&!confirm('未保存のグループ入力を破棄しますか？')){event.preventDefault();return;}
        dirty=false;controls.forEach((control,i)=>control.disabled=initial[i]);status.textContent='編集を取り消しました。';
    });
    window.addEventListener('beforeunload',event=>{if(dirty||saving){event.preventDefault();event.returnValue='';}});
    for(const form of document.querySelectorAll('form[data-action]'))form.addEventListener('submit',async event=>{
        event.preventDefault();if(saving)return;
        const data=new URLSearchParams(new FormData(form));data.set('action',form.dataset.action);
        saving=true;status.textContent='保存中…';
        const buttons=controls,disabled=buttons.map(button=>button.disabled);
        buttons.forEach(button=>button.disabled=true);
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
        try {
            const response=await fetch(location.href,{method:'POST',body:data,signal:controller.signal});
            if(response.ok&&response.redirected){dirty=false;saving=false;location.assign(response.url);return;}
            const html=new DOMParser().parseFromString(await response.text(),'text/html');
            status.textContent=(html.querySelector('[role="alert"]')?.textContent||'保存できませんでした。')+' 下書きは保持しています。';
        } catch {status.textContent='通信に失敗しました。下書きを保持しています。結果不明の場合は別画面で最新版を確認してください。';}
        finally {clearTimeout(timeout);saving=false;buttons.forEach((button,i)=>button.disabled=disabled[i]);}
    });
}
if(typeof document!=='undefined')manageForms();
