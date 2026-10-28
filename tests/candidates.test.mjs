import test from 'node:test';
import assert from 'node:assert/strict';
import {Draft, bulkMask, windowSlots, difference} from '../public/assets/candidates.mjs';
const empty=()=>({a:Array(48).fill(false),b:Array(48).fill(false)});
test('30-minute boundaries and explicit overnight end',()=>{
    assert.deepEqual(windowSlots('06:00','06:00',true),[0,48]);
    assert.deepEqual(windowSlots('22:00','03:00',true),[32,42]);
    for(const [a,b,n] of [['05:30','18:00',false],['09:15','18:00',false],['09:00','09:00',false],['22:00','07:00',true],['','18:00',false]]) assert.throws(()=>windowSlots(a,b,n));
});
test('bulk selected days, replace/add/remove and zero selections',()=>{
    const base=empty(); const added=bulkMask(base,['a'],6,24,'add');
    assert.equal(added.a.filter(Boolean).length,18); assert.equal(added.b.filter(Boolean).length,0);
    const removed=bulkMask(added,['a'],8,10,'remove');
    assert.equal(removed.a.filter(Boolean).length,16);
    assert.equal(bulkMask(removed,['a','b'],0,48,'replace').b.filter(Boolean).length,48);
    assert.deepEqual(base,empty()); assert.throws(()=>bulkMask(base,[],0,1,'add'));
    assert.throws(()=>bulkMask(base,['foreign'],0,1,'add'));
});
test('one history entry per gesture and redo cleared after new action',()=>{
    const draft=new Draft(empty()),before=structuredClone(draft.value);
    draft.value.a[0]=true; draft.value.a[1]=true; draft.record(before);
    assert.equal(draft.past.length,1); assert.equal(draft.delta.changed,2);
    draft.undo(); assert.equal(draft.delta.changed,0); draft.redo(); assert.equal(draft.delta.changed,2);
    draft.undo(); draft.replace(bulkMask(draft.value,['b'],0,1,'add')); assert.equal(draft.future.length,0);
    draft.replace(structuredClone(draft.value)); assert.equal(draft.past.length,1);
});
test('preview counts changed days and exclusions separately',()=>{
    const base=bulkMask(empty(),['a','b'],6,24,'replace');
    const next=bulkMask(base,['a'],8,10,'remove');
    assert.deepEqual(difference(base,next),{changed:2,removed:2,days:1});
});
