import test from 'node:test';
import assert from 'node:assert/strict';
import {submission,symbols} from '../public/assets/answers.mjs';
import {Draft} from '../public/assets/candidates.mjs';
test('submission omits unknown and disabled history, preserving no as an answer',()=>{
 const slots={a:[{id:'yes',candidate:true},{id:'unknown',candidate:true},{id:'no',candidate:true},{id:'retained',candidate:false}]};
 assert.deepEqual(submission({a:['yes','unknown','no','maybe']},slots),[{slot_id:'yes',state:'yes'},{slot_id:'no',state:'no'}]);
 assert.notEqual(symbols.no,symbols.unknown);
});
test('answer undo/redo preserves explicit unknown distinct from no',()=>{
 const d=new Draft({a:['unknown','no']}); d.replace({a:['yes','unknown']}); d.undo(); assert.deepEqual(d.value.a,['unknown','no']);d.redo();assert.deepEqual(d.value.a,['yes','unknown']);
});
