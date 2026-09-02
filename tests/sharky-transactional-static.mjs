import fs from 'node:fs';

function expect(ok,msg){if(!ok){console.error(msg);process.exit(1)}}
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const migration=read('database/migrations/20260902_sharky_orchestrator.sql');
const actions=read('config/sharky-business-actions.php');
const executor=read('config/sharky-orchestrator-db.php');
const adapter=read('config/sharky-whatsapp-adapter.php');
const batch=read('config/sharky-whatsapp-batching.php');
const verify=read('config/sharky-identity-verification.php');
const page=read('public/sharky-verificar.php');
const lab=read('public/api/whatsapp-orchestrator-lab.php');
const login=read('api/login.php');

for(const table of ['sharky_message_receipts','sharky_referrals','sharky_conversation_state','sharky_identity_challenges','sharky_action_audit'])expect(migration.includes(table),`Falta tabla ${table}`);
expect(actions.includes("estado_administrativo') === 'BAJA'")||actions.includes("estado_administrativo'] === 'BAJA'"),'Ausencias deben bloquear alumno de baja.');
expect(actions.includes("SELECT id FROM alumnos WHERE whatsapp=:w LIMIT 1"),'Registro debe revalidar WhatsApp duplicado dentro de transacción.');
expect(actions.includes('FOR UPDATE'),'Registro/ausencia deben revalidar bajo bloqueo.');
expect(actions.includes("'PENDIENTE'"),'Registro conversacional debe quedar PENDIENTE.');
expect(actions.includes(':birth'),'Registro debe persistir fecha de nacimiento validada.');
expect(executor.includes("requires_revalidation")&&executor.includes('IDENTITY_MISMATCH'),'Executor debe exigir revalidación e identidad.');
expect(executor.includes('ACTION_IN_PROGRESS')&&executor.includes('ALREADY_COMPLETED'),'Executor debe ser idempotente.');
expect(adapter.includes("source_type")||adapter.includes("referral"),'Adapter debe conservar referral.');
expect(adapter.includes("'type'=>'interactive'")&&adapter.includes("'type'=>'list'"),'Adapter debe renderizar botones/listas reales.');
expect(batch.includes('hache_sharky_orchestrator_batch_enqueue_and_wait'),'Debe agrupar ráfagas antes de responder.');
expect(verify.includes('token_hash')&&verify.includes("status='VERIFIED'"),'Verificación debe usar token hash de un solo uso.');
expect(page.includes("auth_csrf_validate")&&page.includes("['ALUMNO']")===false,'Página usa sesión de alumno y CSRF sin aceptar identidad por texto libre.');
expect(page.includes("($me['rol']??'')!=='ALUMNO'"),'Verificación web debe restringirse a rol ALUMNO.');
expect(login.includes('sharky_verification_token')&&login.includes('/sharky-verificar.php'),'Login debe regresar al alumno a la verificación pendiente.');
expect(lab.includes("SHARKY_ORCHESTRATOR_LAB_ENABLED")&&lab.includes("!=='1'"),'Webhook laboratorio debe permanecer apagado por defecto.');
expect(!migration.includes('whatsapp VARCHAR'),'Persistencia del orquestador no debe almacenar teléfono crudo.');

console.log('SHARKY_TRANSACTIONAL_STATIC_OK');
