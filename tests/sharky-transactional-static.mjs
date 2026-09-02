import fs from 'node:fs';

function expect(ok,msg){if(!ok){console.error(msg);process.exit(1)}}
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');

const migration=read('database/migrations/20260902_sharky_orchestrator.sql');
const actions=read('config/sharky-business-actions.php');
const executor=read('config/sharky-orchestrator-db.php');
const adapter=read('config/sharky-whatsapp-adapter.php');
const batch=read('config/sharky-whatsapp-batching.php');
const echoes=read('config/sharky-whatsapp-echoes.php');
const verify=read('config/sharky-identity-verification.php');
const page=read('public/sharky-verificar.php');
const lab=read('public/api/whatsapp-orchestrator-lab.php');
const login=read('api/login.php');
const router=read('api/whatsapp-webhook.php');

for(const table of ['sharky_message_receipts','sharky_referrals','sharky_conversation_state','sharky_identity_challenges','sharky_action_audit'])expect(migration.includes(table),`Falta tabla ${table}`);
expect(actions.includes("estado_administrativo') === 'BAJA'")||actions.includes("estado_administrativo'] === 'BAJA'"),'Ausencias deben bloquear alumno de baja.');
expect(actions.includes("SELECT id FROM alumnos WHERE whatsapp=:w LIMIT 1"),'Registro debe revalidar WhatsApp duplicado dentro de transacción.');
expect(actions.includes('FOR UPDATE'),'Registro/ausencia deben revalidar bajo bloqueo.');
expect(actions.includes("'PENDIENTE'"),'Registro conversacional debe quedar PENDIENTE.');
expect(actions.includes(':birth'),'Registro debe persistir fecha de nacimiento validada.');
expect(executor.includes('requires_revalidation')&&executor.includes('IDENTITY_MISMATCH'),'Executor debe exigir revalidación e identidad.');
expect(executor.includes('ACTION_IN_PROGRESS')&&executor.includes('ALREADY_COMPLETED'),'Executor debe ser idempotente.');
expect(adapter.includes('referral'),'Adapter debe conservar referral.');
expect(adapter.includes("'type'=>'interactive'")&&adapter.includes("'type'=>'list'"),'Adapter debe renderizar botones/listas reales.');
expect(adapter.includes('hache_sharky_whatsapp_resume_verified_state'),'La verificación debe reanudar el flujo controlado pendiente.');
expect(adapter.includes('hache_sharky_whatsapp_is_side_question'),'Las dudas laterales no deben destruir un flujo controlado.');
expect(batch.includes('hache_sharky_orchestrator_batch_enqueue_and_wait'),'Debe agrupar ráfagas antes de responder.');
expect(echoes.includes('smb_message_echoes')&&echoes.includes('message_echoes'),'Debe contemplar ecos de respuesta manual para takeover.');
expect(verify.includes('token_hash')&&verify.includes("status='VERIFIED'"),'Verificación debe usar token hash de un solo uso.');
expect(page.includes('auth_csrf_validate'),'Página de verificación debe exigir CSRF.');
expect(page.includes("($me['rol']??'')!=='ALUMNO'"),'Verificación web debe restringirse a rol ALUMNO.');
expect(login.includes('sharky_verification_token')&&login.includes('/sharky-verificar.php'),'Login debe regresar al alumno a la verificación pendiente.');
expect(router.includes('SHARKY_ORCHESTRATOR_LAB_ENABLED')&&router.includes('whatsapp-webhook-v2.php'),'El router debe conservar v2 como ruta productiva por defecto.');
expect(lab.includes("SHARKY_ORCHESTRATOR_LAB_ENABLED")&&lab.includes("!=='1'"),'Webhook laboratorio debe permanecer apagado por defecto.');
expect(lab.includes('X_HUB_SIGNATURE_256')||lab.includes('HTTP_X_HUB_SIGNATURE_256'),'Webhook laboratorio debe validar firma Meta.');
expect(lab.includes('if(!$pdo instanceof PDO)')&&lab.includes('event ignored safely'),'Si la BD no está disponible, el lab debe fallar cerrado sin ejecutar acciones.');
expect(lab.includes('hache_sharky_takeover_mark')&&lab.includes('manual'),'Una respuesta humana detectada debe silenciar Sharky.');
expect(!migration.includes('whatsapp VARCHAR'),'Persistencia del orquestador no debe almacenar teléfono crudo.');

console.log('SHARKY_TRANSACTIONAL_STATIC_OK');
