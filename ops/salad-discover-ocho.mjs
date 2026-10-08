// Read-only diagnosis: determine which Salad project actually owns prl-low-4070-ocho.
// Do not print raw responses, credentials or workload environment variables.
const api='https://api.salad.com/api/public/organizations/hache';
const target='prl-low-4070-ocho';
export function items(value){return Array.isArray(value?.items)?value.items:Array.isArray(value?.container_groups)?value.container_groups:[];}
async function get(key,url,label) {
 const response=await fetch(url,{headers:{Accept:'application/json','Salad-Api-Key':key},signal:AbortSignal.timeout(20000)});
 if(!response.ok)throw new Error(label+': HTTP '+response.status+' (response suppressed)');
 return response.json();
}
export async function main() {
 const key=process.env.SALAD_API_KEY;
 if(!key)throw new Error('No Salad secret configured');
 const projects=items(await get(key,api+'/projects','projects listing'));
 console.log('PROJECTS_FOUND:',projects.length);
 const found=[];
 for(const p of projects.slice(0,40)) {
   const project=String(p.name??'');
   if(!/^[a-zA-Z0-9-]{1,80}$/.test(project))continue;
   let groups;
   try{groups=items(await get(key,api+'/projects/'+encodeURIComponent(project)+'/containers','group listing')); }
   catch(e){console.log('SKIPPED_PROJECT_LOOKUP:',project,e.message);continue;}
   const hits=groups.filter(g=>g.name===target);
   for(const g of hits){
     found.push({project,group:g.name,priority:g.priority,replicas:g.replicas,
       state:g.current_state?.status??'unknown',pending_change:g.pending_change,
       gpu_class_count:g.container?.resources?.gpu_classes?.length??0});
   }
 }
 console.log('TARGET_MATCHES:',JSON.stringify(found));
 if(found.length!==1)throw new Error('Cannot uniquely locate target in hache organization');
 console.log('READ_ONLY_DIAGNOSTIC_OK: no GPU modification was issued');
}
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';
if(process.argv[1]&&resolve(process.argv[1])===fileURLToPath(import.meta.url))
 main().catch(e=>{console.error('SALAD_DISCOVERY_FAILED:',e.message);process.exitCode=1;});
