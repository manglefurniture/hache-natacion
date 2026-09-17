# F5 — Alerta interna por ausencias consecutivas

Fecha de decisión: 2026-09-17.

## Alcance de este micro-paso

Se habilita únicamente la regla interna de F5 para detectar rachas de ausencia ya registradas. Es una alerta administrativa de solo lectura: no modifica asistencia, no genera reposiciones, no cambia el derecho a clase, no envía mensajes y no crea todavía un caso persistente en el Centro de pendientes.

## Umbrales aprobados

Se aprobaron dos umbrales de negocio independientes:

- **3 ausencias consecutivas** contando tanto `AUSENTE_JUSTIFICADA` como `AUSENTE_NO_JUSTIFICADA`.
- **2 ausencias no justificadas consecutivas** como alerta temprana específica.

Si un alumno cumple ambos criterios al mismo tiempo, se genera una sola alerta interna y se muestran ambos hechos; no se duplica el caso.

## Fuente y significado de “consecutivas”

La regla usa exclusivamente marcas existentes en `asistencias`, ordenadas desde la más reciente, vinculadas a sesiones de la sede que ya están `REALIZADA` y cerradas.

Esto evita reconstruir por inferencia horarios o clases históricas. En este incremento, “consecutivas” significa **marcas de asistencia consecutivas comprobadas**:

- `PRESENTE` corta la racha general y la racha de no justificadas;
- `AUSENTE_JUSTIFICADA` mantiene la racha general, pero corta la racha específica de no justificadas;
- `AUSENTE_NO_JUSTIFICADA` incrementa ambas mientras corresponda;
- clases `CANCELADA` quedan fuera;
- sesiones todavía abiertas quedan fuera;
- **sesiones sin marca** no se convierten en ausencia ni crean una alerta por sí mismas;
- alumnos con estado `BAJA` quedan fuera;
- se respeta la sede de la sesión y del alumno.

La regla no intenta completar huecos históricos ni usar el horario actual del alumno para reconstruir qué debió ocurrir en el pasado.

## Presentación

La alerta aparece en el Centro de alertas y abre la ficha del alumno. Mientras F5 no tenga una prioridad alta/media/baja aprobada para esta regla, se presenta como `NEUTRA`, igual que el primer micro-paso de F5. `NEUTRA` significa prioridad todavía no definida, no prioridad baja.

Cuando se cumplen 2 no justificadas pero todavía no 3 ausencias totales, se muestra la alerta temprana. Cuando se cumplen 3 ausencias y además las últimas 2 o más son no justificadas, se conserva una sola tarjeta con ambos datos.

## Centro de pendientes

Este micro-paso no añade todavía un nuevo tipo a `pendientes_gestion`. La detección queda aislada primero en F5 para validar la regla y sus exclusiones. La integración posterior con F1 deberá reutilizar exactamente esta misma detección y una identidad estable, sin volver a calcular la racha con otra definición.

## Límites deliberados

- No se envían WhatsApp ni otras comunicaciones.
- No se modifica Sharky, funnel, takeover ni CRM.
- No se crean o corrigen asistencias.
- No se generan reposiciones.
- No se sanciona ni bloquea al alumno.
- No se asigna prioridad alta/media/baja todavía.
- No se infiere una ausencia por falta de datos.
