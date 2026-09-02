# Sharky 2.0 — Conversation Orchestrator

Estado: **laboratorio integrado detrás de feature flag / producción sin cambios por defecto**.

Estado de activación: **laboratorio / no conectado al webhook de producción por defecto**. El router solo entra al adaptador nuevo cuando `SHARKY_ORCHESTRATOR_LAB_ENABLED=1`.

Sharky 2.0 evoluciona el asistente conversacional hacia un agente capaz de ejecutar operaciones controladas sin entregar a la IA autoridad directa sobre la base de datos.

## Principio rector

**Reconocer → conversar → entender → pedir permiso → controlar → revalidar → ejecutar → auditar → volver a conversar.**

La conversación empieza siempre en lenguaje natural. Detectar una intención nunca basta para ejecutar una operación: Sharky la ofrece, el usuario acepta entrar al flujo controlado y vuelve a confirmar antes de cualquier cambio de negocio.

## P0 implementados

### Referral / atribución Meta

El adaptador conserva `referral` antes de clasificar identidad o intención. Se mantienen first-touch y latest-touch y se persisten `source_type`, `source_id`, `source_url`, `headline`, `body`, `media_type` y `ctwa_clid` cuando Meta los entrega.

Un referral aporta contexto y atribución, pero no convierte automáticamente al contacto en prospecto. Un alumno existente puede tocar un anuncio y seguir siendo alumno.

### Idempotencia

- `message_id` original de Meta se reclama en `sharky_message_receipts`.
- Cada acción transaccional usa una llave independiente en `sharky_action_audit`.
- Una acción `COMPLETED` no vuelve a ejecutarse.
- Una acción `PENDING` no abre una ejecución paralela.
- Una acción `FAILED` exige una nueva confirmación.

### Serialización + burst batching

Cada contacto usa un lock exclusivo. Los mensajes de texto enviados en ráfaga se agrupan durante una ventana corta antes de invocar la conversación IA. Las respuestas interactivas no se agrupan.

El caso real que motivó esta protección —consulta del intensivo + saludo + “soy principiante” enviados casi juntos— debe convertirse en un único turno y una sola respuesta.

### Human takeover

El laboratorio contempla ecos de mensajes manuales de WhatsApp/Coexistence. Cuando se detecta una respuesta humana, se marca takeover y Sharky permanece en silencio. La solicitud explícita “hablar con alguien” también activa handoff.

### Backend como autoridad

La IA nunca escribe MySQL. El core solo produce decisiones y propuestas de acción. Antes de ejecutar se vuelven a validar identidad, estado, sede, curso, horario, fecha, duplicados y edad cuando corresponda.

## Identidad

### Número conocido

Si el WhatsApp coincide inequívocamente con un alumno, se reconoce silenciosamente como alumno verificado por número y la conversación continúa natural.

### Número desconocido que dice ser alumno

Decir “soy alumno” no autentica a nadie. El sistema genera un challenge de un solo uso con TTL y guarda únicamente el hash del token. El alumno abre `/sharky-verificar.php`, inicia sesión con su cuenta de Hache Natación y confirma la vinculación de esa conversación. La página exige rol `ALUMNO`, `alumno_id` real y CSRF.

Si el login ocurre con una verificación pendiente, `api/login.php` devuelve al alumno a `/sharky-verificar.php`. Después de verificar, el adaptador recupera la identidad y puede retomar el flujo que estaba pendiente, por ejemplo reportar una ausencia.

## Modos

### `conversation`

Conversación IA libre dentro de las reglas comerciales. El renderer exige respuestas cortas y estructuradas: no repetir presentación, responder primero la duda actual, evitar volcados masivos y hacer como máximo una pregunta útil para avanzar.

### `controlled`

Estado determinista. Las opciones válidas provienen del backend y se renderizan como botones/listas reales de WhatsApp. Cancelar, pedir humano o expirar el TTL devuelve la conversación a modo natural.

Una pregunta lateral durante el flujo —por ejemplo “¿aceptan tarjeta?” mientras se está confirmando un registro— se responde sin destruir el estado controlado; después se ofrece continuar donde quedó.

## Flujos transaccionales

### Reportar ausencia

1. Detectar intención conversacional.
2. Preguntar si desea registrar la ausencia.
3. Aceptación explícita.
4. Pedir/normalizar fecha.
5. Mostrar resumen.
6. Confirmación final.
7. Emitir `create_absence` con `requires_revalidation=true`.
8. Revalidar identidad contra el número conocido o challenge de portal.
9. Revalidar alumno/estado/duplicado dentro de transacción.
10. Escribir `avisos_ausencia` y auditar el resultado.

El servicio transaccional de Sharky reproduce las mismas invariantes de serialización y duplicados que protege el endpoint administrativo existente. La extracción completa hacia un único servicio de dominio se deja para la fase de integración, para no romper las regresiones actuales mientras #72 sigue moviéndose.

### Registro de intensivo

1. Orientación comercial natural.
2. Preguntar si desea registrarse.
3. Aceptación explícita.
4. Sede mediante botones.
5. Curso/fecha mediante lista generada con opciones activas del backend.
6. Horario mediante lista real.
7. Nombre completo.
8. Fecha de nacimiento y edad mínima determinista.
9. Resumen.
10. Confirmación final.
11. Emitir `register_intensive` con `requires_revalidation=true`.
12. Revalidar teléfono, sede, curso/fecha y horario dentro de transacción.
13. Crear alumno `PENDIENTE`, usuario, registro público y vínculo al intensivo.
14. Auditar resultado.

El nuevo servicio transaccional usa las mismas tablas, reglas de acceso, configuración de intensivos y fuentes de negocio del sistema actual. La extracción final de la creación común de `public/registro.php` también se mantiene como tarea previa a producción.

## Persistencia

La migración aditiva `database/migrations/20260902_sharky_orchestrator.sql` prepara:

- `sharky_message_receipts`
- `sharky_referrals`
- `sharky_conversation_state`
- `sharky_identity_challenges`
- `sharky_action_audit`

El estado conversacional puede persistirse en BD con expiración. Si esas tablas aún no existen, el laboratorio conserva el fallback local restrictivo utilizado durante desarrollo. Los registros de orquestación usan `contact_hash`; no agregan una columna con el número de WhatsApp crudo.

**No ejecutar esta migración en producción hasta la prueba controlada del adaptador.**

## Adaptador WhatsApp de laboratorio

`public/api/whatsapp-orchestrator-lab.php` implementa:

- verificación del webhook;
- validación de `X-Hub-Signature-256` con `META_APP_SECRET`;
- extracción de texto, botones, listas y referral;
- burst batching;
- persistencia e idempotencia;
- botones/listas Meta de salida;
- ejecución transaccional;
- takeover humano;
- fail-closed si la BD no está disponible.

El router `api/whatsapp-webhook.php` usa el laboratorio **solo** con `SHARKY_ORCHESTRATOR_LAB_ENABLED=1`. Sin ese flag continúa enviando a `public/api/whatsapp-webhook-v2.php`, por lo que el comportamiento productivo actual no cambia.

## Pruebas sin OpenAI/Codex

La suite cubre, entre otros:

- first-touch/latest-touch y alumno que llegó desde anuncio;
- número conocido/desconocido;
- verificación desde otro número;
- ausencia completa y duplicados;
- registro intensivo completo;
- edad mínima;
- cancelación;
- flujo expirado;
- cambio de opciones antes de confirmar;
- mensajes Meta duplicados;
- ráfaga de tres mensajes → un turno;
- límites de 3 botones y 10 filas;
- pregunta lateral sin perder flujo;
- takeover humano;
- feature flag apagado por defecto;
- firma Meta y fail-closed del laboratorio.

## Pendiente antes de producción

1. Mantener el Draft sincronizado con el HEAD del PR #72 mientras éste siga cambiando.
2. Extraer/reutilizar el motor de escritura común de registro y ausencias cuando #72 quede estable, conservando las regresiones de serialización existentes.
3. Ejecutar la migración únicamente en un entorno controlado y probar persistencia real.
4. Habilitar el lab de forma controlada y ejecutar pruebas end-to-end con Meta/WhatsApp.
5. Corregir cualquier hallazgo interno.
6. Solicitar revisión de Codex **solo al final**, con nuestra suite ya verde.
7. Tras aprobación, integrar gradualmente el adaptador en la ruta productiva.
