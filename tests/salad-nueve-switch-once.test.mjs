import {test} from 'node:test';
import assert from 'node:assert/strict';
import {findGroup} from '../ops/salad-nueve-switch-once.mjs';
const expected='prl-low-4070-experimental-nueve';
const group=()=>({name:'prl-low-4070-experimental-nueve-copy',display_name:expected,priority:'low',replicas:1,pending_change:false});
test('resolve exact display name without touching another group',()=>{
 const other={...group(),name:'prl-low-4070-experimental-duplicate',display_name:'prl-low-4070-ocho'};
 assert.equal(findGroup({items:[other,group()]}),'prl-low-4070-experimental-nueve-copy');
});
test('reject absent and ambiguous matches',()=>{
 assert.throws(()=>findGroup({items:[]}),/uniquely/);
 assert.throws(()=>findGroup({items:[group(),group()]}),/uniquely/);
});
test('reject non-Low, multiple replicas, pending changes and unsafe slug',()=>{
 for(const edit of [g=>g.priority='medium',g=>g.replicas=2,
 g=>g.pending_change=true,g=>g.name='other']) {
  const g=group();edit(g);assert.throws(()=>findGroup({items:[g]}));
 }
});
