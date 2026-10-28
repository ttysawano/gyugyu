export function confirmationPayload(request,plan,confirmation){return {...request,expected_answers_revision:plan.answers_revision,expected_answer_count:plan.answer_count,expected_visible_answers:plan.visible_answers,confirmed_operation:plan.operation,confirmation};}
function initialize(root){
 const $=id=>root.querySelector('#'+id);let request=null,plan=null,busy=false,dirty=false;
 const expected={expected_version:Number(root.dataset.version),expected_schema_revision:Number(root.dataset.revision)};
 const status=text=>$('day-status').textContent=text;
 function render(){ $('day-inputs').disabled=busy||root.dataset.status!=='open';$('day-save').disabled=busy||!plan||plan.operation==='existing';$('day-cancel').disabled=busy||!dirty;$('day-reload').disabled=busy;$('day-confirm').disabled=busy;$('day-confirm-label').hidden=plan?.operation!=='archive'; }
 root.addEventListener('input',event=>{dirty=true;if(event.target.id!=='day-confirm'){plan=null;$('day-preview').textContent='入力が変わりました。再プレビューしてください。';}render();});
 async function send(save=false){
  if(busy||root.dataset.status!=='open'||save&&(!plan||plan.operation==='existing'))return;busy=true;dirty=true;render();status(save?'保存中…':'影響を確認中…');
  const payload=save?confirmationPayload(request,plan,$('day-confirm').value):request;
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
  try{
   const response=await fetch(root.dataset.api+(save?'':'/preview'),{method:save&&request.action==='archive'?'DELETE':'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(payload),signal:controller.signal});
   const data=await response.json();if(!response.ok)throw Error((response.status===409?'競合しました。':'')+(data.error||'処理に失敗しました。'));
   if(save){dirty=false;const url=new URL(location.href);url.searchParams.set('result',data.operation);location.assign(url.href);return;}
   plan=data;$('day-confirm').value='';$('day-preview').textContent=`${data.local_date}: ${data.operation==='new'?'新規作成':data.operation==='restore'?'履歴を復元（入力時間帯は適用しません）':data.operation==='existing'?'既存・変更なし':'アーカイブ'}。候補${data.candidate_count}枠、保存済み回答${data.answer_count}件（候補外・非表示参加者も含む）、${data.operation==='restore'?'復元後':'現在'}の表示対象回答${data.visible_answers}件。ID・候補マスク・回答履歴を保持します。`;status(data.operation==='existing'?'この日付は既に有効です。変更はありません。':'プレビューを確認して確定してください。');
  }catch(error){status((error.name==='AbortError'||error.name==='TypeError'?'通信に失敗しました。保存結果が不明な場合は別画面で確認してください。':error.message)+' 下書きと元の版数を保持しています。');}
  finally{clearTimeout(timeout);busy=false;render();}
 }
 $('day-add').addEventListener('click',()=>{request={...expected,action:'add',local_date:$('day-date').value,initial_window:{start:$('day-start').value,end:$('day-end').value,end_next_day:$('day-next').checked}};plan=null;send();});
 root.querySelectorAll('[data-action]').forEach(button=>button.addEventListener('click',()=>{if(dirty&&!confirm('現在の下書きを破棄して別の日付操作に切り替えますか？'))return;request={...expected,action:button.dataset.action,day_id:button.dataset.dayId};plan=null;send();}));
 $('day-save').addEventListener('click',()=>send(true));
 $('day-cancel').addEventListener('click',()=>{if(dirty&&!confirm('日付変更の下書きを破棄しますか？'))return;dirty=false;plan=null;request=null;$('day-preview').textContent='';$('day-confirm').value='';$('day-date').value='';$('day-start').value='09:00';$('day-end').value='18:00';$('day-next').checked=false;status('取り消しました。');render();});
 $('day-reload').addEventListener('click',()=>{if(dirty&&!confirm('下書きを破棄して最新版を読み直しますか？'))return;dirty=false;location.reload();});
 window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});
 if(root.dataset.status!=='open')status('受付中の案件のみ日付を変更できます。');render();
}
if(typeof document!=='undefined'){const root=document.querySelector('#day-manager');if(root)initialize(root);}
