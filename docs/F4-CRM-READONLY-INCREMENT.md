# F4 — CRM interno de prospectos: incrementos de lectura

Fecha inicial: 2026-09-16.

## Primer incremento

Primer incremento estrictamente de lectura para hacer visibles hechos comerciales ya registrados por Sharky sin convertir el CRM en un segundo controlador del funnel.

- Contactos: `sharky_contacts`, descifrados únicamente dentro de una sesión ADMIN.
- Fuente: `commercial_context.entry_source` mientras exista estado estructurado; como respaldo persistente solo se reconoce `meta_ad` cuando existe referral publicitario/`ctwa_clid`, y `referral` para referral no publicitario. Web/directo expirados se muestran como “Sin fuente persistente”; no se infieren.
- Producto y sede: únicamente valores confirmados del estado estructurado todavía disponible.
- Último contacto: máximo comprobable entre recepción, salida enviada y `last_seen_at`.
- Inscripción iniciada: solo cuando el Flow vigente es `register_intensive` o `register_regular`.
- Inscrito: solo cuando `sharky_action_audit` registra una acción `register_intensive`/`register_regular` `COMPLETED`.

## Segundo incremento — hitos verificables

El segundo incremento resuelve el primer mapeo de estados comerciales sin introducir etiquetas subjetivas ni persistencia paralela. El CRM muestra exclusivamente estos hitos, en orden de evidencia:

1. `PROSPECTO`: existe el contacto, pero no hay otro hito comercial verificable disponible.
2. `PRODUCTO_CONFIRMADO`: `commercial_context.program` contiene `intensive` o `regular` en el estado estructurado vigente.
3. `SEDE_CONFIRMADA`: además del producto, `commercial_context.sede_clave` contiene `MONTEVERDE` o `PALAPAS`.
4. `INSCRIPCION_INICIADA`: existe un Flow vigente `register_intensive` o `register_regular`.
5. `INSCRITO`: existe una acción de registro `COMPLETED` en `sharky_action_audit`; este hecho durable prevalece sobre intentos posteriores incompletos o fallidos.

La UI explica la evidencia de cada hito. Para producto también distingue:

- **Selección explícita del prospecto** cuando `program_button_choice` coincide con el producto canónico;
- **Resuelto por regla de elegibilidad** para el recorrido `Clases regulares → sin clases previas → intensivo`;
- **Contexto estructurado vigente** cuando existe producto válido pero no una marca explícita suficiente para atribuirlo a un botón.

Estos hitos no equivalen a “Calificado”, “Información enviada” o “Interesado”. Esas etiquetas continúan fuera del modelo hasta existir una fuente inequívoca y una decisión explícita sobre su significado.

## F4.3.1 — Contrato de seguimiento interno

Este paso es exclusivamente documental. No añade botones, migraciones, escrituras, mensajes, alertas ni cambios al comportamiento de Sharky.

### Qué significa “dar seguimiento”

Una **gestión interna** es una acción explícita de un ADMIN que deja constancia de que revisó el estado comercial visible de un contacto en un momento determinado. No significa por sí sola que:

- se haya enviado un mensaje al prospecto;
- el prospecto esté interesado o calificado;
- se haya cambiado producto, sede, nivel o cursor del funnel;
- se haya pausado o reactivado Sharky;
- se haya ejecutado el follow-up automático;
- exista una inscripción o un pago.

La gestión interna es una capa administrativa separada del estado conversacional y de los hitos comerciales del CRM.

### Unidad e identidad

La unidad estable es `contact_hash`. No se crea otra identidad de prospecto y no se copia nombre, teléfono, conversación ni otra PII a la gestión.

El CRM vigente conserva como autoridades:

- identidad/contacto: `sharky_contacts`;
- último contacto verificable: máximo disponible entre recepción, salida enviada y `last_seen_at`;
- hito comercial: proyección actual de `config/sharky-crm.php`;
- inscripción real: acción `register_intensive`/`register_regular` `COMPLETED` en `sharky_action_audit`;
- seguimiento automático: `_idle_followup` dentro del estado comercial vigente, que continúa siendo independiente de la gestión humana;
- takeover: autoridad propia definida en `SHARKY-CORE-RULES.md`.

### Estado de gestión derivado

No se introduce todavía un enum persistente. La UI futura podrá derivar tres situaciones sencillas:

1. **SIN_GESTIÓN**: no existe una gestión interna explícita que cubra el último contacto verificable disponible.
2. **GESTIONADO**: existe una gestión explícita anclada al último contacto que era visible cuando se realizó y no hay actividad verificable posterior.
3. **ACTIVIDAD POSTERIOR**: después de la última gestión existe un contacto verificable más reciente; la gestión histórica se conserva, pero ya no representa el estado más reciente.

`INSCRITO` sigue siendo un hito comercial derivado del alta real y no se reescribe como estado de gestión. La conversión puede hacer innecesaria una nueva gestión comercial en la UI, pero no borra su historia.

### Ancla temporal

Cada gestión futura debe conservar el **último contacto verificable observado en el momento de la gestión**. Esa marca evita que “Gestionado” se convierta en una etiqueta permanente que oculte actividad posterior.

La fecha técnica de actualización de estado no sustituye a una fecha real de contacto cuando exista evidencia más precisa.

### Persistencia mínima para el siguiente incremento

Cuando se implemente la primera escritura de F4, la persistencia deberá guardar como mínimo:

- `contact_hash`;
- usuario ADMIN que realizó la gestión;
- fecha/hora real de la gestión;
- ancla del último contacto verificable observado;
- nota interna opcional y breve, si ese incremento la habilita.

La historia debe conservarse; una gestión posterior no debe borrar quién realizó la anterior. No se persistirán producto, sede, campaña, mensajes ni datos personales duplicados porque esas autoridades ya existen.

`pendientes_gestion` no será la autoridad primaria de este estado: pertenece a F1, exige sede y varios prospectos pueden no tener una sede confirmada. F1 podrá consumir posteriormente una regla definida por F4/F5 sin convertir su cola en la fuente del estado comercial.

### Qué queda expresamente fuera de F4.3.1

- decidir después de cuántos minutos/horas/días un prospecto “requiere” seguimiento;
- generar automáticamente un pendiente;
- enviar WhatsApp desde el CRM;
- cerrar o reabrir el follow-up automático;
- cambiar takeover;
- modificar hitos comerciales;
- introducir puntuaciones de interés, prioridad o probabilidad;
- inferir una gestión a partir de mensajes humanos, salidas de Sharky o actividad automática.

El umbral y las exclusiones de “prospecto sin seguimiento” pertenecen al paso de alertas/reglas posterior. Primero se implementará una gestión humana explícita y trazable.

### Siguiente microincremento

**F4.3.2** podrá implementar únicamente el registro explícito de una gestión interna sobre un contacto, respetando este contrato. No deberá enviar mensajes ni alterar Sharky, el funnel, el follow-up automático o el Centro de pendientes.

## Límites deliberados

El CRM sigue siendo de solo lectura. No crea tabla CRM, no copia conversaciones y no persiste PII nueva. No envía mensajes, no modifica seguimientos automáticos, no mueve el cursor del funnel, no cambia producto/sede y no altera Brain, takeover, inscripción ni pagos.

Cuando expira el estado conversacional, producto/sede pueden dejar de estar disponibles. El CRM no reconstruye esos hechos por intuición ni convierte una campaña en elección del usuario. `INSCRITO` sí puede permanecer porque su evidencia está en el registro durable de acciones.

## Compatibilidad

Se preservan `SHARKY-CORE-RULES.md`, GP-002, la separación entre fuente e identidad y el principio de que campaña/interés no equivale a elección confirmada.

Este incremento deja una base objetiva para el siguiente paso de F4: gestión interna de seguimiento separada del funnel, sin usar esos controles administrativos para disparar mensajes o alterar decisiones de Sharky.
