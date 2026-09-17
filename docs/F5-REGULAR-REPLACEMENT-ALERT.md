# F5 — Alerta interna de reposición regular disponible

Fecha de implementación: 2026-09-17.

## Alcance

F5 alinea la señal histórica de **reposición pendiente** con la causa ya existente `REPOSICION_REGULAR_DISPONIBLE` del Centro de pendientes.

No se crea un criterio operativo nuevo. La causa existe mientras el registro de `reposiciones_regulares` permanezca en estado `DISPONIBLE` y pertenezca a un alumno de la sede consultada.

La alerta es exclusivamente administrativa y de solo lectura. No crea, asigna, utiliza, cancela ni modifica reposiciones; tampoco altera asistencia, alumnos, pagos, mensualidades o Sharky.

## Fuente compartida

`config/regular-replacement-source.php` concentra la consulta de reposiciones regulares disponibles. La consumen:

- el Centro de pendientes para generar `REPOSICION_REGULAR_DISPONIBLE`;
- la revalidación de ese pendiente antes de resolverlo;
- el Centro de alertas F5 para su resumen por sede.

Esto elimina la consulta equivalente que `api/alertas.php` mantenía de forma independiente.

## Criterio

Participa una reposición cuando:

- `reposiciones_regulares.estado = DISPONIBLE`;
- el alumno relacionado pertenece a la sede consultada.

La identidad individual sigue siendo el `id` de la reposición. Cuando cambia a otro estado, la misma fuente deja de devolverla y el Centro puede reflejar la causa como resuelta según su contrato vigente.

No se añade umbral, antigüedad mínima ni criterio comercial adicional.

## Presentación F5

La señal conserva el tipo `REPOSICION`, cuenta reposiciones disponibles y enlaza al Centro de pendientes.

La prioridad es `NEUTRA`, porque no existe una prioridad F5 aprobada para esta regla. Se elimina la prioridad histórica `BAJA` de esta señal para no presentar una decisión de prioridad que F5 no ha aprobado.

## Compatibilidad

No se modifica el contrato de creación, consumo o cancelación de reposiciones ni el flujo de asistencia. No se requiere migración ni configuración nueva.
