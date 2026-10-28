export function decisionSelection(snapshot,slot,mode,groups){return {...snapshot,slot_id:slot,mode,group_ids:[...groups]};}
function initialize(root){
 const form=root.querySelector('form'),status=root.querySelector('#confirmation-status'),selection=root.querySelector('#decision-selection'),confirmFields=root.querySelector('#decision-confirm'),reload=root.querySelector('#decision-reload');const cancel=root.dataset.action==='cancel';
 const snapshot={expected_version:Number(root.dataset.version),expected_schema_revision:Number(root.dataset.schema),expected_answers_revision:Number(root.dataset.answers)};let request=cancel?{...snapshot}:null,busy=false,dirty=false;
 function render(){if(selection)selection.disabled=busy;confirmFields.disabled=busy||!request;reload.disabled=busy;form.querySelector('[type=reset]').disabled=busy;}
 form.addEventListener('input',event=>{dirty=true;if(selection?.contains(event.target)){request=null;root.querySelector('#decision-preview-result').textContent='選択が変わりました。再プレビューしてください。';form.elements.confirm_selection.checked=false;}render();});
 async function send(preview){
  if(busy||!preview&&!request)return;
  const payload=preview?decisionSelection(snapshot,form.elements.slot_id.value,form.elements.mode.value,[...form.querySelectorAll('[name=group_ids]:checked')].map(x=>x.value)):{...request,confirmation:form.elements.confirmation.value,confirm_selection:form.elements.confirm_selection.checked};
  busy=true;dirty=true;render();status.textContent=preview?'内容を確認中…':'保存中…';const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
  try{const response=await fetch(root.dataset.api+(preview?'/preview':cancel?'/cancel':''),{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(payload),signal:controller.signal});const result=await response.json();if(!response.ok)throw Error((response.status===409?'競合しました。':'')+(result.error||'処理に失敗しました。'));
   if(preview){request=payload;const d=result.decision,m=d.mask;const jst=x=>new Intl.DateTimeFormat('ja-JP',{timeZone:'Asia/Tokyo',dateStyle:'medium',timeStyle:'short'}).format(new Date(x));const labels=m.groups.filter(g=>m.group_ids.includes(g.id)).map(g=>g.name).join('、')||'グループ選択なし';root.querySelector('#decision-preview-result').textContent=`${jst(d.starts_at_utc)} 〜 ${jst(d.ends_at_utc)} JST / ${{all:'全員',include:'選択グループを含む（OR）',exclude:'選択グループを除外'}[m.mode]} / ${labels} / 対象${m.N}人 / ○${m.counts.yes} △${m.counts.maybe} ×${m.counts.no} 未入力${m.counts.unknown}。${m.participants.map(p=>p.name+' #'+p.id.slice(0,6)).join('、')||'対象者なし'}`;status.textContent='日時・対象条件を確認して確定してください。';}
   else{dirty=false;const url=new URL(location.href);url.searchParams.set('saved','1');location.assign(url.href);}
  }catch(error){status.textContent=(error.name==='AbortError'||error.name==='TypeError'?'通信に失敗しました。結果不明なら別画面で確認してください。':error.message)+' 選択・確認入力・元の版数を保持しています。';}
  finally{clearTimeout(timer);busy=false;render();}
 }
 root.querySelector('#decision-preview')?.addEventListener('click',()=>send(true));form.addEventListener('submit',event=>{event.preventDefault();send(false);});
 form.addEventListener('reset',event=>{if(busy||dirty&&!confirm('未保存の選択・確認入力を破棄しますか？')){event.preventDefault();return;}dirty=false;request=cancel?{...snapshot}:null;if(selection)root.querySelector('#decision-preview-result').textContent='';status.textContent='入力を取り消しました。';render();});
 reload.addEventListener('click',()=>{if(dirty&&!confirm('入力を破棄して最新版を読み直しますか？'))return;dirty=false;location.reload();});window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});render();
}
if(typeof document!=='undefined'){const root=document.querySelector('#confirmation-manager');if(root)initialize(root);}
