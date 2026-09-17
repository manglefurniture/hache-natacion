# F5 — Alerta interna de inscripción regular sin cobertura

Fecha de implementación: 2026-09-17.

## Alcance

Este incremento cubre una inconsistencia administrativa ya materializada por F1: un alumno regular que no tiene una inscripción cubierta según la autoridad vigente.

No crea una nueva definición de inscripción pendiente. F5 consume `regla_inscripcion_regular_cubierta()` desde `config/reglas-acceso.php`, la misma autoridad usada por F1 y F2.

## Criterio compartido

La señal se evalúa únicamente sobre el mismo alcance regular ya usado por la alerta de mensualidad y el Centro de pendientes:

- alumno perteneciente a la sede consultada;
- `plan_actual_id` presente;
- estado administrativo distinto de `BAJA`;
- sin intensivo `PROGRAMADO` o `EN_CURSO` en esa misma sede.

Dentro de ese alcance, la cobertura de inscripción se decide exclusivamente con `regla_inscripcion_regular_cubierta()`.

Esa autoridad conserva las excepciones ya vigentes, incluida la cobertura histórica y la continuidad aplicable en Monteverde. F5 no las reimplementa ni las aproxima.

## Presentación

`api/alertas.php` expone una alerta agregada `INSCRIPCION` cuando existe al menos un alumno sin cobertura.

Mientras F5 no tenga prioridad alta/media/baja aprobada para esta regla, la señal se presenta como `NEUTRA`.

El enlace abre `/pendientes.php`, donde F1 ya materializa cada caso individual `INSCRIPCION_REGULAR_SIN_COBERTURA` y conserva su gestión.

## Exclusiones

Este incremento no:

- crea ni cobra inscripciones;
- modifica pagos o mensualidades;
- cambia elegibilidad o acceso a clase;
- modifica alumnos, asistencia o reposiciones;
- altera Sharky;
- añade umbrales, antigüedad o reglas comerciales;
- requiere migración.

La implementación es de lectura y señalización interna únicamente.
