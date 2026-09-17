# F5 — Alerta interna de prospecto sin seguimiento

Fecha de decisión: 2026-09-17.

## Alcance de este micro-paso

Se habilita únicamente la primera regla de F5: **prospecto sin seguimiento interno**. Es una señal administrativa para el equipo de Hache Natación; **no envía mensajes** al prospecto, no modifica el funnel, no cambia `_idle_followup`, no altera takeover y no ejecuta operaciones de inscripción o pago.

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

La primera salida es una alerta global visible únicamente para ADMIN en el Centro de alertas. Muestra un conteo agregado y, cuando está disponible, distribución por Monteverde, Palapas y “sin sede”. El enlace abre el CRM de prospectos.

No se asigna todavía prioridad alta/media/baja porque el roadmap mantiene esa decisión pendiente. La interfaz usa una presentación **NEUTRA**, que significa “prioridad no definida”, no “prioridad baja”.

## Centro de pendientes

Este micro-paso **no persiste todavía el caso en el Centro de pendientes**. F1 exige `sede_id` en `pendientes_gestion` y F4 documenta que algunos prospectos pueden no tener sede confirmada. Excluirlos de la regla o imputarles una sede sería una decisión nueva. La integración persistente con F1 queda para un micro-paso posterior que resuelva ese contrato sin duplicar ni perder prospectos.

## Fuentes y seguridad

La evaluación reutiliza:

- `sharky_contacts` para identidad actual;
- `sharky_message_receipts`, `sharky_outbox` y `last_seen_at` para último contacto;
- `sharky_crm_managements` y sus contadores monotónicos para el estado de gestión de F4;
- `sharky_action_audit` para excluir conversiones `COMPLETED`;
- `_idle_followup` mientras el estado conversacional siga vigente;
- el último inbound persistido como evidencia durable de pausa cuando ese estado ya expiró.

La regla trabaja con `contact_hash` y metadatos operativos. Solo en la ruta de estado expirado descifra en memoria el último inbound necesario para clasificar una pausa; no lo devuelve, no lo copia y no añade migraciones ni escrituras nuevas.
