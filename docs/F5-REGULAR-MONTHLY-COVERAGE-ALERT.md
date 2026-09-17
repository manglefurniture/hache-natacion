# F5 — Alerta interna de mensualidad regular sin cobertura

Fecha de implementación: 2026-09-17.

## Alcance

F5 alinea la alerta histórica de **alumno regular sin mensualidad vigente** con la causa ya existente `MENSUALIDAD_REGULAR_SIN_COBERTURA` del Centro de pendientes.

No se crea una deuda, obligación ni fórmula de cobertura nueva. La autoridad sigue siendo `regla_mensualidad_regular_cubierta()` y el período sigue siendo `regla_periodo_regular_actual()`, las mismas reglas que consumen F1/F2.

La alerta es exclusivamente administrativa y de solo lectura. No registra pagos, no modifica mensualidades, no altera derecho a clase, asistencia, Sharky ni datos operativos.

## Criterio compartido

Participa un alumno cuando:

- pertenece a la sede consultada;
- tiene `plan_actual_id`;
- su estado administrativo no es `BAJA`;
- no está cubierto por un intensivo `PROGRAMADO` o `EN_CURSO` en esa sede;
- `regla_mensualidad_regular_cubierta()` no encuentra una mensualidad `PAGADA` para el período exacto que devuelve `regla_periodo_regular_actual()`.

Esto conserva el ciclo mensual normal y el ciclo `P15` de Palapas. Una mensualidad cuya fecha simplemente contiene el día actual, pero que no coincide con el período normativo del alumno, no sustituye esa regla.

## Eliminación de la duplicación histórica

`api/alertas.php` ya no usa la fórmula histórica basada en `CURDATE()/BETWEEN periodo_inicio AND periodo_fin`. Esa consulta podía discrepar de F1/F2, especialmente cuando el período normativo no coincide con un mes calendario.

La alerta `PAGO` ahora delega la cobertura a `regla_mensualidad_regular_cubierta()` y conserva el mismo alcance de alumno regular que el Centro de pendientes. No se añade una segunda alerta equivalente.

## Presentación F5

La señal conserva el tipo `PAGO`, muestra el número de alumnos afectados y enlaza al Centro de pendientes, donde ya existe la causa individual `MENSUALIDAD_REGULAR_SIN_COBERTURA`.

La prioridad es `NEUTRA`, porque no existe una prioridad F5 aprobada para esta regla.

## Compatibilidad

No se modifica el contrato del Centro de pendientes, pagos, mensualidades, asistencia, derecho a clase ni Sharky. No se requiere migración ni configuración nueva.
