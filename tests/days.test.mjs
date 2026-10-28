import test from 'node:test';import assert from 'node:assert/strict';import {confirmationPayload} from '../public/assets/days.mjs';
test('date confirmation retains original versions and preview answer counters',()=>{
 const request={action:'archive',day_id:'d',expected_version:2,expected_schema_revision:5};
 const plan={operation:'archive',answers_revision:8,answer_count:4,visible_answers:2};
 assert.deepEqual(confirmationPayload(request,plan,'2026-11-03'),{...request,expected_answers_revision:8,expected_answer_count:4,expected_visible_answers:2,confirmed_operation:'archive',confirmation:'2026-11-03'});
 assert.equal(confirmationPayload({...request,action:'add'},{...plan,operation:'restore'},'').confirmed_operation,'restore');
});
