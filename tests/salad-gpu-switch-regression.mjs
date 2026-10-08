import {test} from 'node:test';
import assert from 'node:assert/strict';
import {validate,planSwitch,verifyApplied,assertSameNonGpu,run} from '../ops/salad-gpu-switch.mjs';
const oldId='aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',newId='bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
const catalog={items:[
  {id:oldId,name:'RTX 4070 Ti Super (16 GB)'},
  {id:newId,name:'RTX 4090 (24 GB)'},
  {id:'cccccccc-cccc-4ccc-cccc-cccccccccccc',name:'RTX 4090 Laptop (16 GB)'}]};
const group=()=>({name:'prl-low-4070-experimental',priority:'low',replicas:1,pending_change:false,
  container:{image:'ghcr.io/example/miner',environment_variables:[{name:'SAFE_TEST',value:'test'}],
    resources:{gpu_classes:[oldId],cpu:2}}});
const opts=(mode='plan')=>({group:'prl-low-4070-experimental',expected:'RTX 4070 Ti Super (16 GB)',
  target:'RTX 4090 (24 GB)',mode,confirm:'prl-low-4070-experimental'});
test('patch changes GPU class alone and excludes Laptop',()=>{
  const p=planSwitch(group(),catalog,opts());
  assert.deepEqual(p.patch,{container:{resources:{gpu_classes:[newId]}}});
  assert.equal(p.oldClass,'RTX 4070 Ti Super (16 GB)');
});
test('idempotent and exact model matching',()=>{
  const g=group();g.container.resources.gpu_classes=[newId];
  assert.equal(planSwitch(g,catalog,{...opts(),expected:'RTX 4090 (24 GB)'}).already,true);
  assert.throws(()=>planSwitch(group(),catalog,{...opts(),target:'RTX 4090'}),/not uniquely/);
});
test('refuses unexpected group, priority, pending change, replica, GPU-list size',()=>{
  const mutations=[
    g=>{g.name='prl-low-other';},g=>{g.priority='medium';},
    g=>{g.pending_change=true;},g=>{delete g.pending_change;},
    g=>{g.replicas=2;},g=>{g.container.resources.gpu_classes=[oldId,newId];}];
  for(const mutation of mutations){const g=group();mutation(g);
    assert.throws(()=>planSwitch(g,catalog,opts()));}
  assert.throws(()=>validate({...opts(),group:'another-project'}),/prl-low/);
  assert.throws(()=>validate({...opts(),group:'prl-low-../x'}),/prl-low/);
});
test('refuses stale expected GPU, ambiguous catalog and missing confirmation',()=>{
  assert.throws(()=>planSwitch(group(),catalog,{...opts(),expected:'RTX 3090'}),/mismatch/);
  assert.throws(()=>planSwitch(group(),{items:[...catalog.items,catalog.items[1]]},opts()),/not uniquely/);
  assert.throws(()=>validate({...opts('apply'),confirm:'wrong'}),/confirmation/);
});
test('postcheck waits for pending false and refuses other differences',()=>{
  const p=planSwitch(group(),catalog,opts());const g=group();g.container.resources.gpu_classes=[newId];
  assert.equal(verifyApplied(group(),g,p),true);
  g.pending_change=true;assert.equal(verifyApplied(group(),g,p),false);
  g.pending_change=false;g.container.resources.cpu=3;
  assert.throws(()=>verifyApplied(group(),g,p),/Non-GPU/);
});
test('preview uses GET only, and apply uses one PATCH after second GET',async()=>{
  const prev=process.env.SALAD_API_KEY;process.env.SALAD_API_KEY='test-not-real';
  const calls=[];let poll=0;
  const mock=async (key,method,path,payload)=>{
    calls.push({method,path,payload});
    if(path.endsWith('/gpu-classes'))return catalog;
    if(method==='PATCH'){assert.deepEqual(payload,{container:{resources:{gpu_classes:[newId]}}});return group();}
    const g=group();
    if(calls.some(c=>c.method==='PATCH')){g.container.resources.gpu_classes=[newId];g.pending_change=poll++===0;}
    return g;
  };
  try{
    assert.equal(await run(opts(),mock,async()=>{}),'preview');
    assert.deepEqual(calls.map(c=>c.method),['GET','GET']);
    calls.length=0;
    assert.equal(await run(opts('apply'),mock,async()=>{}),'applied');
    assert.equal(calls.filter(c=>c.method==='PATCH').length,1);
    assert.equal(poll,2);
  }finally{if(prev===undefined)delete process.env.SALAD_API_KEY;else process.env.SALAD_API_KEY=prev;}
});

test('concurrent non-GPU edit aborts before any PATCH',async()=>{
  const prev=process.env.SALAD_API_KEY;
  process.env.SALAD_API_KEY='fake-key-not-real';
  let gets=0;let patches=0;
  try{
    await assert.rejects(()=>run(opts('apply'),async(key,method,path)=>{
      if(path.endsWith('/gpu-classes'))return catalog;
      if(method==='PATCH'){patches++;return group();}
      const item=group();
      gets++;
      if(gets===2)item.container.image='changed-concurrently';
      return item;
    },async()=>{}),/Non-GPU settings changed/);
    assert.equal(patches,0);
  }finally{
    if(prev===undefined)delete process.env.SALAD_API_KEY;
    else process.env.SALAD_API_KEY=prev;
  }
});
