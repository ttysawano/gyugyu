export function matches(mode,selected,memberships) {
    const included=memberships.some(id=>selected.includes(id));
    return mode==='include'?included:mode==='exclude'?!included:true;
}
export function summarize(row,mode='all',selected=[]) {
    const groups=row.groups.filter(g=>!g.archived_at).map(g=>g.id);
    if(!['all','include','exclude'].includes(mode)||!Array.isArray(selected)||selected.some(id=>!groups.includes(id)))throw Error('Invalid groups');
    if(!groups.length)mode='all';
    const people=[...new Map(row.participants.filter(p=>p.is_active&&matches(mode,selected,p.group_ids)).map(p=>[p.id,p])).values()];
    const N=people.length,slots=[];
    const values=new Map(people.map(p=>[p.id,new Map(p.answers.map(a=>[a.slot_id,a.state]))]));
    for(const day of row.days.filter(d=>!d.archived_at))for(const slot of day.slots){
        const members={yes:[],maybe:[],no:[],unknown:[]};
        for(const p of people)members[values.get(p.id).get(slot.id)||'unknown'].push({id:p.id,name:p.display_name});
        const counts=Object.fromEntries(Object.entries(members).map(([state,list])=>[state,list.length])),candidate=!!slot.is_candidate;
        slots.push({id:slot.id,day_id:day.id,starts_at_utc:slot.starts_at_utc,candidate,N,counts,members,score:candidate&&N>0?counts.yes-(N+1)*counts.no:null,provisional:candidate&&N>0&&counts.unknown>0,all_yes:candidate&&N>0&&counts.yes===N});
    }
    return {N,participant_ids:people.map(p=>p.id),slots,ranking:slots.filter(s=>s.score!==null).sort((a,b)=>b.score-a.score||a.starts_at_utc.localeCompare(b.starts_at_utc)).map(s=>s.id)};
}
export function summaryView(root) {
    const panels=[...root.querySelectorAll('.day-panel')];
    let days=panels.map(panel=>({id:panel.dataset.day,slots:[...panel.querySelectorAll('.candidate-cell')].map(cell=>({id:cell.dataset.slot,starts_at_utc:cell.dataset.utc,is_candidate:cell.getAttribute('aria-pressed')==='true'}))}));
    const labels=new Map([...root.querySelectorAll('.candidate-cell')].map(cell=>[cell.dataset.slot,cell.dataset.label]));
    let result=null,selected=null;
    const detail=root.querySelector('#summary-detail'),ranking=root.querySelector('#summary-ranking');
    const states={yes:'○',maybe:'△',no:'×',unknown:'未入力'};
    function inspect(id){
        selected=id;const slot=result?.slots.find(s=>s.id===id);if(!slot)return;
        detail.replaceChildren();const title=document.createElement('p');
        title.textContent=labels.get(id)+'（日本時間） '+(!slot.candidate?'候補外（集計・順位対象外）':`対象${slot.N}人／未入力${slot.counts.unknown}人 · スコア${slot.score??'—'}${slot.provisional?' · 暫定':''}${slot.all_yes?' · 全員○':''}`);detail.append(title);
        if(!slot.candidate)return;
        for(const [state,label] of Object.entries(states)){
            const p=document.createElement('p');p.textContent=label+': '+(slot.members[state].map(person=>person.name+' #'+person.id.slice(0,6)).join('、')||'—');detail.append(p);
        }
    }
    root.addEventListener('candidate-saved',event=>{days=event.detail.days;});
    for(const type of ['focusin','click','pointerover'])root.addEventListener(type,event=>{const cell=event.target.closest('.summary-cell');if(cell)inspect(cell.dataset.slot);});
    return (mode,groups)=>{
        const people=new Map();
        for(const row of root.querySelectorAll('.participant-row')){
            if(row.dataset.participant==='draft')continue;
            let person=people.get(row.dataset.participant);
            if(!person){person={id:row.dataset.participant,is_active:1,display_name:row.dataset.name,group_ids:JSON.parse(row.dataset.groups||'[]'),answers:[]};people.set(person.id,person);}
            for(const cell of row.querySelectorAll('.answer-cell'))if(cell.dataset.state!=='unknown')person.answers.push({slot_id:cell.dataset.slot,state:cell.dataset.state});
        }
        result=summarize({groups:[...root.querySelectorAll('#group-filter input')].map(input=>({id:input.value})),days,participants:[...people.values()]},mode,groups);
        const byId=new Map(result.slots.map(s=>[s.id,s]));
        for(const cell of root.querySelectorAll('.summary-cell')){
            const s=byId.get(cell.dataset.slot);cell.textContent=!s.candidate?'':s.N?s.counts[cell.dataset.state]:'—';
            cell.setAttribute('aria-label',labels.get(s.id)+' '+(!s.candidate?'候補外':`${states[cell.dataset.state]} ${s.N?s.counts[cell.dataset.state]:'—'}、対象${s.N}人、未入力${s.counts.unknown}人${s.provisional?'、暫定':''}`));cell.title=cell.getAttribute('aria-label');
        }
        ranking.replaceChildren();
        let lastScore=null,rank=0;
        for(const [index,id] of result.ranking.entries()){
            const slot=byId.get(id);if(slot.score!==lastScore)rank=index+1;lastScore=slot.score;
            const li=document.createElement('li'),button=document.createElement('button');button.type='button';button.textContent=`${rank}位 ${labels.get(id)} · ${slot.score}${slot.provisional?'（暫定）':''}`;button.addEventListener('click',()=>inspect(id));li.append(button);ranking.append(li);
        }
        root.querySelector('#summary-empty').hidden=!!result.ranking.length;
        root.querySelector('#summary-source').textContent=(root.dataset.answerEditing||root.dataset.candidateEditing)?'保存済みデータの集計です。編集中の下書きは含みません。':'表示中の保存済みデータから集計しています。他端末の変更は再読み込みで確認してください。';
        inspect(selected||result.ranking[0]||result.slots[0]?.id);
        return result;
    };
}
