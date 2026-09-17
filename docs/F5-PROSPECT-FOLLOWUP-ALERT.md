# F5 — Alerta interna de prospecto sin seguimiento

Fecha de decisión: 2026-09-17.

## Alcance de este micro-paso

Se habilita la regla de F5 **prospecto sin seguimiento interno**. Es una señal administrativa para el equipo de Hache Natación; **no envía mensajes** al prospecto, no modifica el funnel, no cambia `_idle_followup`, no altera takeover y no ejecuta operaciones de inscripción o pago.

## Umbral aprobado

La decisión de negocio aprobada es **24 horas desde el último contacto verificable**.

El “último contacto verificable” conserva la definición de F4: el máximo disponible entre recepción persistida, salida efectivamente `SENT` y `last_seen_at`. Los seguimientos automáticos de Sharky de 15 minutos, 90 minutos y 48 horas siguen siendo reglas independientes y no determinan este umbral administrativo.

## Cuándo se activa

Un contacto puede generar la alerta cuando se cumplen todas estas condiciones:

- su autoridad actual en `sharky_contacts` sigue siendo `PROSPECT`;
- no existe una acción `register_intensive` o `register_regular` con estado `COMPLETED`;
- F4 deriva su gestión como `SIN_GESTION` o `ACTIVIDAD_POSTERIOR`;
- han transcurrido al menos 24 horas desde el último contacto verificable;
- puede comprobarse que el seguimiento no quedó pausado.

Una gestión `GESTIONADO` cubre la actividad observada y, por tanto, no alerta. Si después aparece actividad verificable nueva, F4 vuelve a derivar `ACTIVIDAD_POSTERIOR`; el nuevo contacto se convierte en la referencia temporal para las 24 horas.

## Exclusiones y expiración del estado

Se respetan las exclusiones ya documentadas para F5: un prospecto convertido no alerta y un seguimiento pausado (`completed_optout`) no alerta. `completed_registration` también se excluye mientras exista en el estado, aunque la auditoría durable de registro sigue siendo la autoridad principal de conversión.

El estado conversacional ordinario expira a las 24 horas. La alerta no puede depender de que ese estado siga vivo porque su propio umbral también es de 24 horas. Cuando `_idle_followup` ya no está disponible, F5 usa como respaldo **el último inbound persistido y cifrado en `sharky_message_receipts`** para reconstruir únicamente si ese último turno fue una pausa/opt-out. Esto no prolonga el estado de conversación ni modifica los seguimientos automáticos de Sharky.

El fallback descifra en memoria solo el último inbound necesario para esta decisión; no expone texto, nombre ni teléfono en la respuesta del Centro de alertas y no crea una copia adicional. Si el último inbound no existe o no puede descifrarse, la pausa queda como dato desconocido y la regla **falla cerrada**: no genera una alerta potencialmente falsa.

Cuando varios receipts comparten el mismo segundo `DATETIME`, se usa la marca durable de llegada incluida en el payload (`_inbox_arrival_us`) y, como respaldo, `timestamp_ms`, para identificar el último evento real.

## Presentación

El Centro de alertas mantiene una alerta global visible únicamente para ADMIN. Muestra un conteo agregado y, cuando está disponible, distribución por Monteverde, Palapas y “sin sede”. El enlace abre el CRM de prospectos.

No se asigna todavía prioridad alta/media/baja porque el roadmap mantiene esa decisión pendiente. La interfaz usa una presentación **NEUTRA**, que significa “prioridad no definida”, no “prioridad baja”.

## Centro de pendientes

La misma regla F5 se integra con F1 mediante el tipo `PROSPECTO_SIN_SEGUIMIENTO`. La identidad estable usa `contact_hash` como `origen_id`; no incorpora sede, horas transcurridas ni el valor del umbral. Por ello una consulta repetida, un aumento de horas o una sede comercial confirmada posteriormente no crean otro caso.

Los pendientes de prospectos son **globales y exclusivos de ADMIN**. `pendientes_gestion.sede_id` admite `NULL` para este tipo concreto, manteniendo la clave foránea y el aislamiento por sede de todos los tipos anteriores. Esto evita dos errores que el roadmap prohíbe: excluir a los prospectos cuya sede todavía no está confirmada o imputarles una sede ficticia.

Cuando F4 sí tiene una sede confirmada, se muestra como contexto de presentación, pero la gestión sigue siendo global. Si no existe, el Centro muestra “Sin sede confirmada”. VERIFICADOR no recibe ni gestiona estos pendientes y conserva el alcance previo del Centro.

La causa se revalida con `hache_internal_prospect_followup_candidates()`. Deja de aplicar, entre otros casos, si el prospecto se convierte, si se registra una gestión que cubre la actividad, si el seguimiento queda pausado o si deja de cumplir el umbral. El historial de atención permanece en `pendientes_gestion`; al desaparecer la causa puede confirmarse su resolución con el contrato normal de F1.

## Fuentes y seguridad

La evaluación reutiliza:

- `sharky_contacts` para identidad actual;
- `sharky_message_receipts`, `sharky_outbox` y `last_seen_at` para último contacto;
- `sharky_crm_managements` y sus contadores monotónicos para el estado de gestión de F4;
- `sharky_action_audit` para excluir conversiones `COMPLETED`;
- `_idle_followup` mientras el estado conversacional siga vigente;
- el último inbound persistido como evidencia durable de pausa cuando ese estado ya expiró.

La integración con F1 persiste solo `contact_hash`, tipo/origen, estado de atención, responsable, fechas y nota administrativa. No copia nombre, WhatsApp ni contenido de conversación a `pendientes_gestion`. Solo en la ruta de estado expirado la regla descifra en memoria el último inbound necesario para clasificar una pausa; no lo devuelve ni crea una copia adicional.
