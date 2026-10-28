export function receptionPayload(snapshot,confirmation){return {...snapshot,confirmation};}
function initialize(root){
 const form=root.querySelector('form'),fields=form.querySelector('fieldset'),status=root.querySelector('#reception-status'),reload=root.querySelector('#reception-reload');let busy=false,dirty=false;
 const snapshot={action:root.dataset.action,expected_version:Number(root.dataset.version),expected_schema_revision:Number(root.dataset.schema),expected_answers_revision:Number(root.dataset.answers)};
 function render(){fields.disabled=busy||!['lock','reopen'].includes(snapshot.action);reload.disabled=busy;}
 form.addEventListener('input',()=>{dirty=true;});
 form.addEventListener('reset',event=>{if(busy||dirty&&!confirm('受付変更の確認入力を破棄しますか？')){event.preventDefault();return;}dirty=false;status.textContent='取り消しました。';});
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy||fields.disabled)return;const payload=receptionPayload(snapshot,form.elements.confirmation.value);busy=true;dirty=true;render();status.textContent='保存中…';
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
  try{const response=await fetch(root.dataset.api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(payload),signal:controller.signal});const result=await response.json();if(!response.ok)throw Error((response.status===409?'競合しました。':'')+(result.error||'保存に失敗しました。'));dirty=false;const url=new URL(location.href);url.searchParams.set('saved','1');location.assign(url.href);}
  catch(error){status.textContent=(error.name==='AbortError'||error.name==='TypeError'?'通信に失敗しました。結果が不明なら別画面で確認してください。':error.message)+' 意図した操作・入力・元の版数を保持しています。';}
  finally{clearTimeout(timer);busy=false;render();}
 });
 reload.addEventListener('click',()=>{if(dirty&&!confirm('入力を破棄して最新版を読み直しますか？'))return;dirty=false;location.reload();});
 window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});render();
}
if(typeof document!=='undefined'){const root=document.querySelector('#reception-manager');if(root)initialize(root);}
