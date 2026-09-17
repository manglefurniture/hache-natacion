# F5 — Alerta interna por ausencias consecutivas

Fecha de decisión: 2026-09-17.

## Alcance vigente

La regla interna de F5 detecta rachas de ausencia ya registradas y la misma detección alimenta tanto el Centro de alertas como el Centro de pendientes. No modifica asistencia, no genera reposiciones, no cambia el derecho a clase y no envía mensajes.

## Umbrales aprobados

Se aprobaron dos umbrales de negocio independientes:

- **3 ausencias consecutivas** contando tanto `AUSENTE_JUSTIFICADA` como `AUSENTE_NO_JUSTIFICADA`.
- **2 ausencias no justificadas consecutivas** como alerta temprana específica.

Si un alumno cumple ambos criterios al mismo tiempo, se genera una sola señal interna y un solo pendiente; no se duplica el caso.

## Fuente y significado de “consecutivas”

La regla usa exclusivamente marcas existentes en `asistencias`, vinculadas a sesiones de la sede que ya están `REALIZADA` y cerradas. La secuencia vigente es la posterior a la última marca `PRESENTE`.

Esto evita reconstruir por inferencia horarios o clases históricas. En esta regla, “consecutivas” significa **marcas de asistencia consecutivas comprobadas**:

- `PRESENTE` corta la racha general y la racha de no justificadas;
- `AUSENTE_JUSTIFICADA` mantiene la racha general, pero corta la racha específica de no justificadas;
- `AUSENTE_NO_JUSTIFICADA` incrementa ambas mientras corresponda;
- clases `CANCELADA` quedan fuera;
- sesiones todavía abiertas quedan fuera;
- **sesiones sin marca** no se convierten en ausencia ni crean una alerta por sí mismas;
- alumnos con estado `BAJA` quedan fuera;
- se respeta la sede de la sesión y del alumno.

La regla no intenta completar huecos históricos ni usar el horario actual del alumno para reconstruir qué debió ocurrir en el pasado.

## Presentación en alertas

La alerta aparece en el Centro de alertas y abre la ficha del alumno. Mientras F5 no tenga una prioridad alta/media/baja aprobada para esta regla, se presenta como `NEUTRA`. `NEUTRA` significa prioridad todavía no definida, no prioridad baja.

Cuando se cumplen 2 no justificadas pero todavía no 3 ausencias totales, se muestra la alerta temprana. Cuando se cumplen 3 ausencias y además las últimas 2 o más son no justificadas, se conserva una sola tarjeta con ambos datos.

## Integración con el Centro de pendientes

F1 reutiliza exactamente `hache_internal_consecutive_absence_candidates()`; no existe un segundo cálculo de racha.

El tipo persistente es `RACHA_AUSENCIAS_CONSECUTIVAS`. Su origen estable es la **primera marca de ausencia de la racha activa** (`ASISTENCIA_RACHA` + `asistencias.id`). Por tanto:

- si la misma racha crece de 3 a 4 o más ausencias, conserva la misma identidad y no crea duplicados;
- si una presencia o una corrección hace que la racha deje de cumplir el umbral, la causa deja de estar activa y F1 puede reflejarla como resuelta según su gestión;
- si posteriormente aparece una racha nueva, su primera marca de ausencia es distinta y se crea una recurrencia independiente sin borrar la historia anterior;
- “Marcar atendido” solo registra la gestión administrativa; nunca cambia una asistencia ni resuelve la causa por etiqueta.

El pendiente abre la ficha del alumno. No se necesita migración nueva porque `pendientes_gestion` ya admite tipos y orígenes versionados mediante campos `VARCHAR`.

## Límites deliberados

- No se envían WhatsApp ni otras comunicaciones.
- No se modifica Sharky, funnel, takeover ni CRM.
- No se crean o corrigen asistencias.
- No se generan reposiciones.
- No se sanciona ni bloquea al alumno.
- No se asigna prioridad alta/media/baja todavía.
- No se infiere una ausencia por falta de datos.
