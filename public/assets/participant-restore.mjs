export function restorePayload(expected,version,confirmation){return {...expected,expected_participant_version:Number(version),confirmation};}
function initialize(root){
 const forms=[...root.querySelectorAll('form')],status=root.querySelector('#restore-status'),reload=root.querySelector('#restore-reload');let active=null,busy=false;
 const expected={expected_version:Number(root.dataset.version),expected_schema_revision:Number(root.dataset.schema),expected_answers_revision:Number(root.dataset.answers)};
 function render(){for(const form of forms)form.querySelector('fieldset').disabled=busy||root.dataset.status!=='open'||!!active&&active!==form;reload.disabled=busy;}
 for(const form of forms){
  form.addEventListener('input',()=>{active=form;render();});
  form.addEventListener('reset',event=>{if(busy||active&&!confirm('復帰確認の入力を破棄しますか？')){event.preventDefault();return;}active=null;status.textContent='取り消しました。';render();});
  form.addEventListener('submit',async event=>{
   event.preventDefault();if(busy||root.dataset.status!=='open'||active&&active!==form)return;
   const data=restorePayload(expected,form.dataset.version,form.elements.confirmation.value);active=form;busy=true;render();status.textContent='復帰を保存中…';
   const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
   try{
    const response=await fetch(root.dataset.api+form.dataset.participant+'/restore',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(data),signal:controller.signal});const result=await response.json();
    if(!response.ok)throw Error((response.status===409?'競合しました。':'')+(result.error||'復帰に失敗しました。'));
    active=null;const url=new URL(location.href);url.searchParams.set('restored','1');location.assign(url.href);
   }catch(error){status.textContent=(error.name==='AbortError'||error.name==='TypeError'?'通信に失敗しました。結果が不明なら別画面で確認してください。':error.message)+' 入力と元の版数を保持しています。';}
   finally{clearTimeout(timer);busy=false;render();}
  });
 }
 reload.addEventListener('click',()=>{if(active&&!confirm('入力を破棄して最新版を読み直しますか？'))return;active=null;location.reload();});
 window.addEventListener('beforeunload',event=>{if(active||busy){event.preventDefault();event.returnValue='';}});
 if(root.dataset.status!=='open')status.textContent='受付中の案件のみ参加者を復帰できます。';render();
}
if(typeof document!=='undefined'){const root=document.querySelector('#participant-restoration');if(root)initialize(root);}
