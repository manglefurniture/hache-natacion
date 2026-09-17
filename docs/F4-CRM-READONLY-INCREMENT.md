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

## Límites deliberados

El CRM sigue siendo de solo lectura. No crea tabla CRM, no copia conversaciones y no persiste PII nueva. No envía mensajes, no modifica seguimientos automáticos, no mueve el cursor del funnel, no cambia producto/sede y no altera Brain, takeover, inscripción ni pagos.

Cuando expira el estado conversacional, producto/sede pueden dejar de estar disponibles. El CRM no reconstruye esos hechos por intuición ni convierte una campaña en elección del usuario. `INSCRITO` sí puede permanecer porque su evidencia está en el registro durable de acciones.

## Compatibilidad

Se preservan `SHARKY-CORE-RULES.md`, GP-002, la separación entre fuente e identidad y el principio de que campaña/interés no equivale a elección confirmada.

Este incremento deja una base objetiva para el siguiente paso de F4: gestión interna de seguimiento separada del funnel, sin usar esos controles administrativos para disparar mensajes o alterar decisiones de Sharky.
