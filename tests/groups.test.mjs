import test from 'node:test';
import assert from 'node:assert/strict';
import {matches} from '../public/assets/groups.mjs';
test('group OR and empty modes',()=>{
 assert.equal(matches('include',[],['a']),false);
 assert.equal(matches('exclude',[],[]),true);
 assert.equal(matches('all',['a'],[]),true);
 const people=[['a','b'],['b'],[]];
 assert.equal(people.filter(groups=>matches('include',['a','b'],groups)).length,2);
 assert.equal(people.filter(groups=>matches('exclude',['a'],groups)).length,2);
 assert.equal(matches('include',['__proto__'],['constructor']),false);
});
