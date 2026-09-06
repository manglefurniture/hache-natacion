# P2-07 — Restore drills programados e historial

## Estado

**SCHEDULE CONFIGURED / HISTORICAL CADENCE PENDING FIRST SCHEDULED RUN**

Hache Natación es el piloto real Nivel C. Esta evidencia amplía el restore drill ya validado en P1; no cambia los objetivos de recuperación aprobados ni convierte por sí sola P2-07 en PASS permanente.

## Cadencia aprobada

- backup de producción: diario a las `09:17 UTC` (`04:17 America/Cancun`);
- restore drill recurrente: día 1 de cada mes a las `10:17 UTC` (`05:17 America/Cancun`), una hora después del backup diario;
- RPO usado por la ejecución programada: `86400` s (24 h);
- RTO usado por la ejecución programada: `3600` s (1 h).

La ruta manual `workflow_dispatch` permanece disponible y sigue exigiendo RPO/RTO explícitos, sin defaults. La ruta `schedule` utiliza únicamente los objetivos ya aprobados para este proyecto y falla si esos valores se desalinean del contrato.

## Evidencia histórica

Cada ejecución conserva:

- el run de GitHub Actions;
- un artifact único por `run_id` + `run_attempt`;
- únicamente `evidence/restore-drill.json` minimizado;
- retención de artifact de 90 días;
- resultado, backup real usado, edad del backup, RPO/RTO, duración, aislamiento, verificaciones críticas y cleanup.

El dump de producción permanece en el VPS y nunca se copia a GitHub Actions. No se guarda PII ni credenciales en el artifact.

El run real `33999270733` del 2026-09-05 permanece como evidencia bootstrap del restore: PASS, backup real usado, RPO 24 h cumplido, RTO 1 h cumplido, target aislado, verificaciones críticas correctas y cleanup exitoso. Esa ejecución fue manual y **no sustituye** la primera evidencia de la nueva cadencia programada.

## Regla de revisión

P2-07 no se cierra solo porque exista el cron. Después de la primera ejecución programada se debe revisar que:

1. el workflow haya arrancado por `schedule` desde `main`;
2. use RPO `86400` y RTO `3600`;
3. seleccione e importe un backup real de producción;
4. RPO/RTO queden cumplidos;
5. las tablas y guardas críticas pasen;
6. el target aislado se limpie correctamente;
7. el artifact permanezca minimizado y sin datos personales/credenciales.

Una ejecución fallida queda como evidencia de fallo y requiere revisión humana; no se reescribe ni se convierte automáticamente en PASS.

## Frontera

Este cambio implementa la **cadencia y conservación de historia** en Hache Natación. Hache Base puede reutilizar el patrón después de observar una ejecución programada real; no debe afirmar evidencia histórica inexistente ni imponer esta cadencia a otros proyectos C con RPO/RTO distintos.
