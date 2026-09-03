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
const worker=read('config/sharky-lab-worker.php');
const recovery=read('config/sharky-action-recovery.php');
const outbox=read('config/sharky-outbox.php');
const login=read('api/login.php');
const router=read('api/whatsapp-webhook.php');

for(const table of ['sharky_message_receipts','sharky_referrals','sharky_conversation_state','sharky_identity_challenges','sharky_action_audit'])expect(migration.includes(table),`Falta tabla ${table}`);
expect(actions.includes("estado_administrativo') === 'BAJA'")||actions.includes("estado_administrativo'] === 'BAJA'"),'Ausencias deben bloquear alumno de baja.');
expect(actions.includes("SELECT id FROM alumnos WHERE whatsapp=:w LIMIT 1"),'Registro debe revalidar WhatsApp duplicado dentro de transacción.');
expect(actions.includes('FOR UPDATE'),'Registro/ausencia deben revalidar bajo bloqueo.');
expect(actions.includes("'PENDIENTE'"),'Registro conversacional debe quedar PENDIENTE.');
expect(actions.includes(':birth'),'Registro debe persistir fecha de nacimiento validada.');
const txPos=actions.indexOf('$pdo->beginTransaction();',actions.indexOf('function hache_sharky_business_register_intensive'));
const pwdPos=actions.indexOf('$tempPassword = password_temporal_segura();',actions.indexOf('function hache_sharky_business_register_intensive'));
expect(txPos>=0&&pwdPos>txPos,'Credenciales del registro deben generarse después de entrar a la transacción/revalidación.');
expect(executor.includes('requires_revalidation')&&executor.includes('IDENTITY_MISMATCH'),'Executor debe exigir revalidación e identidad.');
expect(executor.includes('ACTION_IN_PROGRESS')&&executor.includes('hache_sharky_action_recovery_claim'),'Executor debe ser idempotente y recuperar leases vencidos.');
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
expect(/SHARKY_ORCHESTRATOR_LAB_ENABLED[\s\S]{0,80}!==?\s*['"]1['"]/.test(lab),'Webhook laboratorio debe permanecer apagado por defecto.');
expect(lab.includes('X_HUB_SIGNATURE_256')||lab.includes('HTTP_X_HUB_SIGNATURE_256'),'Webhook laboratorio debe validar firma Meta.');
// Fail closed actual: BD/migración/inbox durable se validan ANTES del ACK 200.
const dbGuardPos=lab.indexOf('if(!$pdo instanceof PDO)sharky_lab_json(503');
const storeGuardPos=lab.indexOf('if(!hache_sharky_orchestrator_store_ready($pdo))sharky_lab_json(503');
const inboxPos=lab.indexOf('hache_sharky_inbox_store');
const ackPos=lab.indexOf('http_response_code(200)');
expect(dbGuardPos>=0&&storeGuardPos>dbGuardPos&&inboxPos>storeGuardPos&&ackPos>inboxPos,'Si la BD/migración/inbox durable falla, el lab debe responder 503 antes del ACK 200 y no ejecutar acciones.');
expect(lab.includes("'Database unavailable'")&&lab.includes("'Sharky migration incomplete'")&&lab.includes("'Unable to persist inbound event'"),'El fail-closed del lab debe distinguir fallas durables antes del ACK.');
expect(lab.includes('hache_sharky_lab_process_event')&&lab.indexOf('hache_sharky_lab_process_event')>ackPos,'Las acciones solo pueden ejecutarse después de persistir y ACKear el evento durable.');
expect(lab.includes('hache_sharky_outbox_dispatch'),'El lab debe reintentar respuestas durables pendientes.');
expect(!migration.includes('whatsapp VARCHAR'),'Persistencia del orquestador no debe almacenar teléfono crudo.');

// P1 final delivery boundary: no durable flow advance before outbox + receipts commit.
expect(executor.includes('hache_sharky_db_state_defer_begin')&&executor.includes('hache_sharky_db_state_save_now'),'El estado debe poder diferirse hasta la frontera durable de entrega.');
expect(worker.includes('hache_sharky_db_state_defer_begin()')&&worker.includes('hache_sharky_db_state_defer_take()'),'El worker debe diferir el estado mientras procesa el turno.');
const statePos=worker.indexOf('hache_sharky_lab_persist_deferred_state($pdo,$deferredState)');
const queuePos=worker.indexOf('hache_sharky_outbox_enqueue($pdo,$contact,$payload',$statePos);
const receiptPos=worker.indexOf('hache_sharky_orchestrator_mark_processed($pdo,$messageId)',$queuePos);
expect(statePos>=0&&queuePos>statePos&&receiptPos>queuePos,'Estado, outbox y receipts deben quedar en la misma transacción y en ese orden.');

// P1 privacy: temporary_password nunca queda en JSON de auditoría en claro.
for(const field of ['result_ciphertext','result_iv','result_tag'])expect(migration.includes(field),`Falta campo cifrado de resultado ${field}`);
expect(recovery.includes('aes-256-gcm')&&recovery.includes('hache-sharky-action-result-v1'),'Resultados sensibles deben cifrarse autenticadamente.');
expect(recovery.includes("unset($public['temporary_password'])"),'La contraseña temporal debe eliminarse del result_json público.');

// P1 takeover: una respuesta humana invalida cualquier mensaje automático pendiente.
expect(migration.includes("'PENDING','SENT','DEAD','CANCELLED'"),'Outbox debe poder cancelar mensajes pendientes por takeover.');
expect(outbox.includes('hache_sharky_takeover_active($contact)')&&outbox.includes('hache_sharky_outbox_mark_cancelled'),'Dispatcher debe revalidar takeover antes de cada envío.');

console.log('SHARKY_TRANSACTIONAL_STATIC_OK');
