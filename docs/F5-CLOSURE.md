# F5 — Cierre de implementación y despliegue

Fecha de cierre documental: 2026-09-17.

## Estado

F5 — Alertas internas queda **Desplegado**. El alcance funcional comprometido está implementado, integrado, revisado y publicado en producción. No se marca **Verificado** porque la convención del roadmap exige comprobación operativa completa en producción de todos los criterios, no solo regresiones y despliegue correcto.

Base funcional de cierre: `cf7dc078cd71fa3d9fa479cb66fe66f53e97b3b4`.

## Cobertura implementada

- prospecto sin seguimiento interno;
- umbrales administrativos configurables de F5;
- ausencias consecutivas y ausencias injustificadas consecutivas;
- integración de ausencias con el Centro de pendientes;
- intensivo terminado sin continuidad, separado del aviso de fin próximo;
- integración de continuidad con el Centro de pendientes;
- saldo pendiente de intensivo usando la autoridad financiera F2;
- mensualidad regular sin cobertura vigente usando `regla_mensualidad_regular_cubierta()`;
- reposición regular disponible usando la misma fuente que F1;
- inscripción regular sin cobertura usando `regla_inscripcion_regular_cubierta()`;
- integración de prospectos sin seguimiento con F1 como pendientes globales ADMIN, sin inventar sede.

## Decisiones de umbral y prioridad

El seguimiento de prospectos usa 24 horas por defecto y sigue siendo configurable dentro del rango admitido por F5. Las ausencias consecutivas usan defaults configurables de 3 ausencias generales y 2 injustificadas.

La falta de continuidad de intensivo no tiene un plazo ni alcance implícitos: la regla permanece deshabilitada hasta que ADMIN configure `f5_intensive_no_continuity_days` y `f5_intensive_no_continuity_scope`. Esto es una decisión deliberada de seguridad, no trabajo de implementación pendiente.

La prioridad alta/media/baja de las reglas nuevas sigue sin decisión de negocio. Mientras no exista esa decisión, las reglas nuevas usan `NEUTRA`; no se interpreta como prioridad baja.

## Evidencia del criterio de terminado

Las regresiones versionadas cubren activación/no activación, deduplicación, fuentes y resolución. En particular:

- `tests/consecutive-absence-alert-regression.php` comprueba corrección de asistencia, sesión cancelada, sesión sin marca y rachas justificadas/no justificadas;
- `tests/intensive-balance-alert-regression.php` comprueba que un pago `INVALIDADO` no reduce saldo y que la liquidación elimina la causa;
- `tests/prospect-followup-alert-regression.php` comprueba umbral, pausa y prospecto convertido;
- `tests/centro-pendientes-regression.php` y `tests/centro-pendientes-mariadb.php` comprueban identidad estable, repetición sin duplicados y persistencia de gestión;
- `tests/prospect-pending-integration-regression.php` comprueba que alerta y pendiente de prospecto representan el mismo hecho, incluida sede no confirmada sin sede ficticia;
- las regresiones de mensualidad, inscripción y reposición comprueban que F5 consume las mismas autoridades que F1/F2.

Quality #1534 pasó en el PR #298 y Quality #1535 pasó después del merge sobre `main`. Deploy automático #264 publicó `cf7dc078cd71fa3d9fa479cb66fe66f53e97b3b4`; el log confirmó `CENTRO_PENDIENTES_MIGRATION_OK` y `Deploy OK` para ese SHA. El marcador de producción coincidió exactamente, `api/pendientes.php` pasó `php -l`, `PROSPECT_PENDING_INTEGRATION_REGRESSION_OK` pasó en producción y `/api/health.php` respondió `ok: true`.

## Pendiente para pasar a Verificado

La siguiente actividad de F5, si se exige el estado **Verificado**, es una comprobación operativa dirigida en producción sobre casos reales disponibles, sin fabricar datos: confirmar visualmente/funcionalmente ejemplos representativos de alerta → pendiente → atención → desaparición de causa/resolución y conservar evidencia fechada. Si un escenario no existe de forma natural en producción, se mantiene como cubierto por regresión y se documenta la limitación; no se crean pagos, ausencias ni prospectos ficticios para forzar la verificación.
