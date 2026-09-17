# F4 — CRM interno de prospectos: primer incremento

Fecha: 2026-09-16.

## Alcance

Primer incremento estrictamente de lectura para hacer visibles hechos comerciales ya registrados por Sharky sin convertir el CRM en un segundo controlador del funnel.

- Contactos: `sharky_contacts`, descifrados únicamente dentro de una sesión ADMIN.
- Fuente: `commercial_context.entry_source` mientras exista estado estructurado; como respaldo persistente solo se reconoce `meta_ad` cuando existe referral publicitario/`ctwa_clid`, y `referral` para referral no publicitario. Web/directo expirados se muestran como “Sin fuente persistente”; no se infieren.
- Producto y sede: únicamente valores confirmados del estado estructurado todavía disponible.
- Último contacto: máximo comprobable entre recepción, salida enviada y `last_seen_at`.
- Inscripción iniciada: solo cuando el Flow vigente es `register_intensive` o `register_regular`.
- Inscrito: solo cuando `sharky_action_audit` registra una acción `register_intensive`/`register_regular` `COMPLETED`.

## Límites deliberados

Este incremento no aprueba todavía el pipeline conceptual completo de F4. No crea `Calificado`, `Información enviada` o `Interesado` porque actualmente no existe una fuente persistente inequívoca para esas etapas después de expirar el estado conversacional.

No se crea tabla CRM, no se copian conversaciones y no se persiste PII nueva. No se envían mensajes, no se modifican seguimientos, no se mueve el cursor del funnel, no se cambia producto/sede y no se altera Brain, takeover, inscripción ni pagos.

## Compatibilidad

Se preservan `SHARKY-CORE-RULES.md`, GP-002, la separación entre fuente e identidad y el principio de que campaña/interés no equivale a elección confirmada.

El incremento sirve como base para resolver P-04 con datos observables antes de añadir gestión de seguimiento o etapas persistentes.
