# P2-07 — Restore drills programados e historial

## Estado

**SCHEDULE CONFIGURED / HISTORICAL CADENCE PENDING FIRST SCHEDULED RUN**

Hache Natación es el piloto real Nivel C. Esta evidencia amplía el restore drill ya validado en P1; no cambia los objetivos de recuperación aprobados ni convierte por sí sola P2-07 en PASS permanente.

## Cadencia aprobada

- backup de producción: diario a las `09:17 UTC` (`04:17 America/Cancun`);
- restore drill recurrente: después de que el backup programado del **día 1 de cada mes** termine correctamente;
- RPO usado por la ejecución recurrente: `86400` s (24 h);
- RTO usado por la ejecución recurrente: `3600` s (1 h).

La ruta manual `workflow_dispatch` permanece disponible y sigue exigiendo RPO/RTO explícitos, sin defaults.

La ruta recurrente ya no usa un cron independiente. `Production Restore Drill` escucha la finalización de `Production Backup Daily` mediante `workflow_run` y solo continúa cuando el backup padre:

- terminó con `success`;
- fue originado por `schedule`;
- corresponde a `main`;
- fue creado el día 1 UTC.

Esto elimina la carrera entre dos cron independientes: el restore no puede empezar antes de que termine el backup que le da origen. Una ejecución manual del workflow de backup tampoco dispara el restore recurrente.

## Evidencia histórica

Cada ejecución real de restore conserva:

- el run de GitHub Actions;
- la relación temporal con un backup programado ya terminado;
- un artifact único por `run_id` + `run_attempt`;
- únicamente `evidence/restore-drill.json` minimizado;
- retención de artifact de 90 días;
- resultado, backup real usado, edad del backup, RPO/RTO, duración, aislamiento, verificaciones críticas y cleanup.

El dump de producción permanece en el VPS y nunca se copia a GitHub Actions. No se guarda PII ni credenciales en el artifact.

El run real `33999270733` del 2026-09-05 permanece como evidencia bootstrap del restore: PASS, backup real usado, RPO 24 h cumplido, RTO 1 h cumplido, target aislado, verificaciones críticas correctas y cleanup exitoso. Esa ejecución fue manual y **no sustituye** la primera evidencia de la nueva cadencia recurrente.

## Regla de revisión

P2-07 no se cierra solo porque exista el trigger. Después de la primera ejecución recurrente se debe revisar que:

1. `Production Backup Daily` haya arrancado por `schedule` desde `main` el día 1 y terminado en `success`;
2. `Production Restore Drill` haya sido disparado por ese `workflow_run`, no por un cron independiente;
3. use RPO `86400` y RTO `3600`;
4. seleccione e importe un backup real de producción;
5. RPO/RTO queden cumplidos;
6. las tablas y guardas críticas pasen;
7. el target aislado se limpie correctamente;
8. el artifact permanezca minimizado y sin datos personales/credenciales.

Una ejecución fallida queda como evidencia de fallo y requiere revisión humana; no se reescribe ni se convierte automáticamente en PASS.

## Frontera

Este cambio implementa la **cadencia serializada y conservación de historia** en Hache Natación. Hache Base puede reutilizar el patrón después de observar una ejecución recurrente real; no debe afirmar evidencia histórica inexistente ni imponer esta cadencia a otros proyectos C con RPO/RTO distintos.
