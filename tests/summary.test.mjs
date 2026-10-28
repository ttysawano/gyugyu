import test from 'node:test';import assert from 'node:assert/strict';import {execFileSync} from 'node:child_process';
import {summarize} from '../public/assets/summary.mjs';
function fixture(states){return {groups:[{id:'a'},{id:'b'},{id:'archived',archived_at:'then'}],participants:states.map((state,i)=>({id:'p'+i,display_name:'<name>',is_active:1,group_ids:i%2?['a','b']:[],submitted_at:i%2?'now':null,answers:state==='unknown'?[]:[{slot_id:'s',state}]})),days:[{id:'d',slots:[{id:'s',starts_at_utc:'2026-11-02T00:00:00Z',is_candidate:1},{id:'outside',starts_at_utc:'2026-11-02T00:30:00Z',is_candidate:0}]},{id:'archived-day',archived_at:'then',slots:[{id:'old',starts_at_utc:'old',is_candidate:1}]}]};}
test('PHP and JS agree with independent counts for every four-person answer combination and masks',()=>{
 const cases=[];const states=['yes','maybe','no','unknown'];
 for(let code=0;code<256;code++){
  const values=Array.from({length:4},(_,i)=>states[(code>>(i*2))&3]);const row=fixture(values);
  row.participants.push({...row.participants[0],id:'inactive',is_active:0});
  for(const [mode,selected] of [['all',[]],['include',[]],['include',['a','b']],['exclude',[]],['exclude',['a']]]){
   const actual=summarize(row,mode,selected),indices=[0,1,2,3].filter(i=>mode==='all'||(mode==='include'?selected.length&&i%2:!selected.length||!(i%2)));
   const expected=Object.fromEntries(states.map(s=>[s,indices.filter(i=>values[i]===s).length]));const slot=actual.slots[0];
   assert.deepEqual(slot.counts,expected);assert.equal(Object.values(expected).reduce((a,b)=>a+b,0),actual.N);
   assert.equal(slot.score,indices.length?expected.yes-(indices.length+1)*expected.no:null);
   assert.equal(slot.provisional,indices.length>0&&expected.unknown>0);assert.equal(slot.all_yes,indices.length>0&&expected.yes===indices.length);
   assert.equal(actual.slots.length,2);assert.equal(actual.slots[1].score,null);assert.deepEqual(actual.ranking,indices.length?['s']:[]);
   cases.push({row,mode,selected,result:actual});
  }
 }
 const output=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',['tests/summary_bridge.php'],{input:JSON.stringify(cases),maxBuffer:16*1024*1024}));
 assert.deepEqual(output,cases.map(c=>c.result));
});
test('ranking follows fewer no, then more yes; ties ignore unknown and use time only',()=>{
 const row=fixture(['yes','no']);row.days=[{id:'d',slots:Array.from({length:4},(_,i)=>({id:'s'+i,starts_at_utc:'2026-11-02T0'+i+':00:00Z',is_candidate:1}))}];
 row.participants[0].answers=[{slot_id:'s0',state:'yes'},{slot_id:'s1',state:'yes'},{slot_id:'s2',state:'yes'}];
 row.participants[1].answers=[{slot_id:'s0',state:'no'},{slot_id:'s1',state:'maybe'}];
 const s=summarize(row);assert.deepEqual(s.ranking,['s1','s2','s3','s0']);assert.equal(s.slots[1].score,s.slots[2].score);
 assert.equal(s.slots[1].provisional,false);assert.equal(s.slots[2].provisional,true);
 row.groups=[];assert.equal(summarize(row,'include',[]).N,2);
 assert.throws(()=>summarize(row,'include',['foreign']));
 row.groups=[{id:'archived',archived_at:'then'}];assert.throws(()=>summarize(row,'include',['archived']));
});
test('PHP/JS rank multiple slots by independent no/yes ordering, with every T/U tie through N=6',()=>{
 const cases=[];
 for(let N=0;N<=6;N++){
  const combinations=[];
  for(let X=0;X<=N;X++)for(let Y=0;Y<=N-X;Y++)for(let T=0;T<=N-X-Y;T++)combinations.push({X,Y,T,U:N-X-Y-T});
  // Reverse the generation order so accidental insertion-order ranking cannot pass.
  combinations.reverse();
  const slots=combinations.map((counts,i)=>({id:'s'+i,starts_at_utc:new Date(Date.UTC(2026,10,2,0,i*30)).toISOString(),is_candidate:1}));
  const outside={id:'outside',starts_at_utc:'2026-11-01T00:00:00.000Z',is_candidate:0};
  const row={groups:[{id:'a'}],days:[{id:'day',slots:[outside,...slots]}],participants:Array.from({length:N},(_,person)=>({id:'p'+person,display_name:'p'+person,is_active:1,group_ids:[],answers:[{slot_id:'outside',state:'yes'},...combinations.flatMap((c,i)=>{
   const state=person<c.Y?'yes':person<c.Y+c.X?'no':person<c.Y+c.X+c.T?'maybe':null;
   return state?[{slot_id:slots[i].id,state}]:[];
  })]}))};
  const reference=N?combinations.map((counts,i)=>({...counts,id:slots[i].id,time:slots[i].starts_at_utc})).sort((a,b)=>a.X-b.X||b.Y-a.Y||a.time.localeCompare(b.time)).map(s=>s.id):[];
  for(const mode of ['all','include']){
   const actual=summarize(row,mode,[]),expected=mode==='all'?reference:[];
   assert.deepEqual(actual.ranking,expected);
   assert.equal(actual.slots[0].score,null);assert.equal(actual.slots[0].all_yes,false);
   if(mode==='include'||N===0)for(const slot of actual.slots){assert.equal(slot.score,null);assert.equal(slot.all_yes,false);}
   // Slots sharing X/Y remain tied even when T/U and provisional state differ.
   for(let i=0;i<combinations.length;i++)for(let j=i+1;j<combinations.length;j++){
    if(combinations[i].X===combinations[j].X&&combinations[i].Y===combinations[j].Y)assert.equal(actual.slots[i+1].score,actual.slots[j+1].score);
   }
   cases.push({row,mode,selected:[],result:actual});
  }
 }
 const php=JSON.parse(execFileSync(process.env.PHP_BINARY||'php',['tests/summary_bridge.php'],{input:JSON.stringify(cases),maxBuffer:16*1024*1024}));
 // JS is already checked against the independent reference; PHP must match both rankings and slot data.
 assert.deepEqual(php,cases.map(c=>c.result));
});
