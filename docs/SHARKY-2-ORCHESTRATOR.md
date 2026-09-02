# Sharky 2.0 — Conversation Orchestrator

Estado: **laboratorio / no conectado al webhook de producción**.

Este módulo define la arquitectura aprobada para evolucionar Sharky desde un asistente conversacional a un agente que puede iniciar operaciones de negocio sin entregar el control de la base de datos al modelo de IA.

## Principio rector

**Reconocer → conversar → entender → pedir permiso → controlar → revalidar → ejecutar → auditar → volver a conversar.**

La conversación empieza siempre en lenguaje natural. Un flujo controlado solo comienza cuando una intención accionable fue detectada y el usuario acepta expresamente iniciar la operación.

## P0

1. **Referral / atribución de Meta**
   - Capturar `referral` antes de cualquier clasificación.
   - Conservar `source_type`, `source_id`, `source_url`, `headline`, `body`, `media_type` y `ctwa_clid` cuando Meta los entregue.
   - Mantener first-touch y latest-touch en el estado conversacional.
   - Persistir el referral durablemente en `sharky_referrals`.
   - Un referral nunca convierte automáticamente a alguien en prospecto: un alumno existente también puede tocar un anuncio.

2. **Idempotencia**
   - `message_id` de Meta es único en `sharky_message_receipts`.
   - Cada acción usa una llave de idempotencia propia en `sharky_action_audit`.
   - Un reintento de Meta o un doble toque nunca puede ejecutar dos operaciones.

3. **Serialización por contacto + debounce**
   - Una conversación por WhatsApp no puede ejecutar dos respuestas de Sharky en paralelo.
   - Mensajes enviados en ráfaga se agrupan durante una ventana corta (2.8 s por defecto, tope duro de 8 s).
   - “quiero información” + “buenas noches” + “soy principiante” debe producir **una** llamada al modelo y **una** respuesta.

4. **Human takeover**
   - Si el equipo responde manualmente, Sharky permanece en silencio.
   - Debe revalidarse el takeover inmediatamente antes de invocar IA o ejecutar una acción.

5. **Backend como autoridad**
   - Sharky puede proponer una acción; nunca escribe directamente en MySQL.
   - Curso, horario, edad, identidad, estado, duplicados y disponibilidad se revalidan al ejecutar.
   - Ninguna respuesta de IA puede considerarse confirmación de una operación.

## Identidad

### Número conocido

Si el WhatsApp entrante coincide inequívocamente con un alumno, el backend lo marca como `student + verified + whatsapp_number`. Sharky continúa en conversación natural sin mostrar un menú de identidad.

### Número desconocido

Sharky responde primero la consulta breve y pregunta de forma natural si ya es alumno:

- `Ya soy alumno`
- `Soy nuevo`

Decir “soy alumno” desde un número desconocido **no es autenticación**. Antes de una operación de alumno se requiere un mecanismo de verificación.

### Referral + alumno conocido

Se conserva simultáneamente identidad de alumno y atribución del anuncio/post. La intención actual del usuario manda sobre el contenido del anuncio.

## Modos

### `conversation`

IA libre dentro de las reglas comerciales. Debe responder breve, contextual y sin repetir información ya resuelta.

### `controlled`

Estado determinista. La IA no decide opciones válidas ni ejecuta acciones. Se usan botones/listas/valores provenientes del backend. Cancelar, pedir humano o expirar el TTL devuelve la conversación a `conversation`.

## Flujos iniciales

### Reportar ausencia

1. Detectar intención en conversación.
2. Preguntar `¿Quieres que registre tu ausencia?`.
3. Confirmación positiva → pedir fecha.
4. Resolver fecha relativa a fecha concreta.
5. Mostrar resumen y pedir confirmación final.
6. Emitir `create_absence` con `requires_revalidation=true`.
7. El adaptador valida identidad/alumno/sede/duplicado y ejecuta.

### Registro de intensivo

1. Orientación comercial normal.
2. Preguntar `¿Quieres que te ayude a registrarte?`.
3. Sí → flujo controlado.
4. Sede mediante botones.
5. Curso/fecha mediante lista desde backend.
6. Horario mediante lista desde backend.
7. Nombre completo.
8. Fecha de nacimiento; edad mínima validada deterministicamente.
9. Resumen.
10. Confirmación final.
11. Emitir `register_intensive` con `requires_revalidation=true`.
12. El adaptador final debe reutilizar el motor de registro público; no debe existir una segunda implementación de las reglas de alta.

## Situaciones cubiertas por diseño

- alumno desde otro número;
- alumno que tocó un anuncio;
- prospecto nuevo;
- identidad no verificada;
- ausencia;
- cancelación en cualquier paso;
- humano interviene;
- doble entrega de webhook;
- mensajes rápidos;
- estado controlado expirado;
- curso/horario que cambia antes de confirmar;
- menor de edad;
- fallo de backend antes de ejecutar;
- first-touch/latest-touch de campañas.

## Persistencia y privacidad

Se persiste durablemente solo lo necesario para idempotencia, atribución y auditoría. El número de WhatsApp se representa mediante `contact_hash`; el log de acciones guarda hash del payload, no el payload con datos personales.

El estado conversacional temporal vive en `/var/tmp/hache-sharky-state` con permisos restrictivos y expira. Si se pierde, la operación falla cerrada y se vuelve a confirmar; nunca se reconstruye una acción por suposición.

## Estrategia de integración

Este PR está apilado sobre el PR #72 y no cambia todavía la ruta viva de WhatsApp. Primero se valida el core con pruebas deterministas que no llaman OpenAI ni Codex. Cuando #72 quede estable:

1. sincronizar la rama con el HEAD de #72;
2. extraer/reutilizar los servicios reales de registro/ausencia;
3. conectar el adaptador del webhook al orquestador;
4. ejecutar pruebas end-to-end controladas;
5. solicitar revisión de Codex **solo al final**.
