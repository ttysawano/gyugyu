import test from 'node:test';import assert from 'node:assert/strict';import {exportUrl} from '../public/assets/exports.mjs';
test('export captures mask once, preserves subpath, and forces complete JSON to all',()=>{
 const base='https://example.test/gyugyu/api/index.php?route=/instances/abcd';
 const url=new URL(exportUrl(base,'summary','mask','include',['a','b']));
 assert.equal(url.pathname,'/gyugyu/api/index.php');assert.equal(url.searchParams.get('route'),'/instances/abcd/export');
 assert.deepEqual(url.searchParams.getAll('group_ids[]'),['a','b']);assert.equal(url.searchParams.get('mode'),'include');
 const json=new URL(exportUrl(base,'json','mask','include',['foreign']));assert.equal(json.searchParams.get('scope'),'all');assert.equal(json.searchParams.has('group_ids[]'),false);assert.equal(json.searchParams.has('mode'),false);
 const all=new URL(exportUrl(base,'wide','all','include',['a']));assert.equal(all.searchParams.has('group_ids[]'),false);
});
