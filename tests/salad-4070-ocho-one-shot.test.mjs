import {test} from 'node:test';
import assert from 'node:assert/strict';
import {identifySource} from '../ops/salad-4070-ocho-one-shot.mjs';
const oldId='aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
const newId='bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
const original=()=>({name:'prl-low-4070-ocho',replicas:1,priority:'low',pending_change:false,
 container:{resources:{gpu_classes:[oldId]}}});
const catalog={items:[{id:oldId,name:'RTX 4070 Ti Super (16 GB)'},{id:newId,name:'RTX 4090 (24 GB)'}]};
test('identifies 4070 Ti Super desktop without selecting any other group',()=>{
 assert.deepEqual(identifySource(original(),catalog),{name:'RTX 4070 Ti Super (16 GB)',already:false});
});
test('idempotent on target',()=>{
 const g=original();g.container.resources.gpu_classes=[newId];
 assert.equal(identifySource(g,catalog).already,true);
});
test('refuses wrong group, pending, medium, multi-replica, unknown GPU',()=>{
 for(const edit of [
  g=>{g.name='prl-low-4070-experimental';},g=>{g.pending_change=true;},
  g=>{g.priority='medium';},g=>{g.replicas=2;},
  g=>{g.container.resources.gpu_classes=['unknown'];},
 ]) {const g=original();edit(g);assert.throws(()=>identifySource(g,catalog));}
});
test('refuses non-4070 GPU classes and laptops',()=>{
 for(const name of ['RTX 5090 (32 GB)','RTX 4090 Laptop (16 GB)','RTX 4070 Laptop (8 GB)']){
  assert.throws(()=>identifySource(original(),{items:[{id:oldId,name}]}));
 }
});
