export function exportUrl(api,format,scope,mode,groups) {
    const url=new URL(api,typeof location==='undefined'?'https://example.invalid':location.href);
    url.searchParams.set('route',url.searchParams.get('route')+'/export');url.searchParams.set('format',format);
    url.searchParams.set('scope',format==='json'?'all':scope);
    if(format!=='json'&&scope==='mask'){url.searchParams.set('mode',mode);for(const id of groups)url.searchParams.append('group_ids[]',id);}
    return url.href;
}
function initialize(root){
    const $=id=>root.querySelector('#'+id),metadata=document.querySelector('#metadata-editor');let downloading=false;
    const busy=()=>!!root.dataset.answerEditing||!!root.dataset.candidateEditing||!!metadata?.open;
    const status=text=>$('export-status').textContent=text;
    function render(){ if(metadata)metadata.hidden=downloading||!!root.dataset.answerEditing||!!root.dataset.candidateEditing; $('export-controls').disabled=busy()||downloading;$('export-scope').disabled=$('export-format').value==='json'; }
    $('export-download').hidden=false;$('export-format').addEventListener('change',render);
    for(const event of ['answer-mode','candidate-mode','participants-rendered'])root.addEventListener(event,render);metadata?.addEventListener('toggle',render);
    $('export-download').addEventListener('click',async()=>{
        if(busy()||downloading)return;
        const url=exportUrl(root.dataset.api,$('export-format').value,$('export-scope').value,$('group-mode').value,[...root.querySelectorAll('#group-filter input:checked')].map(input=>input.value));
        downloading=true;root.dataset.exporting='1';root.dispatchEvent(new CustomEvent('export-mode'));render();status('保存済みデータを生成中…');
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),30000);
        try{
            const response=await fetch(url,{signal:controller.signal,cache:'no-store'});
            if(!response.ok){const problem=await response.json();throw Error(problem.error||'出力できませんでした。');}
            const match=/^attachment; filename="(gyugyu-[a-f0-9]{32}-[0-9TZ]+-(?:all|mask)-(?:json|wide|long|summary)\.(?:json|csv))"$/.exec(response.headers.get('Content-Disposition')||'');
            if(!match)throw Error('出力ファイル名が不正です。');
            const blob=await response.blob(),href=URL.createObjectURL(blob),link=document.createElement('a');link.href=href;link.download=match[1];document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(href),60000);
            status('ダウンロードを開始しました。保存済みデータのみを出力しました。');
        }catch(error){status(error.name==='AbortError'||error.name==='TypeError'?'通信に失敗しました。入力は変更していません。':error.message+' 最新版を確認してください。');}
        finally{clearTimeout(timeout);downloading=false;root.dataset.exporting='';root.dispatchEvent(new CustomEvent('export-mode'));render();}
    });render();
}
if(typeof document!=='undefined'){const root=document.querySelector('#candidate-grid');if(root)initialize(root);}
