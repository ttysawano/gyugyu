import test from 'node:test';import assert from 'node:assert/strict';import {restorePayload} from '../public/assets/participant-restore.mjs';
test('restore uses the original snapshot, stable participant version and exact name confirmation',()=>{
 const expected={expected_version:2,expected_schema_revision:5,expected_answers_revision:8};
 assert.deepEqual(restorePayload(expected,'4','constructor'),{...expected,expected_participant_version:4,confirmation:'constructor'});
 assert.deepEqual(expected,{expected_version:2,expected_schema_revision:5,expected_answers_revision:8});
});
