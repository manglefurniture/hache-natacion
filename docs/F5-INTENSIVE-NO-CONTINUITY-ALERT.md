# F5 — Alerta interna de intensivo terminado sin continuidad

Fecha de implementación: 2026-09-17.

## Alcance

F5 detecta **intensivo terminado sin continuidad** como señal administrativa de solo lectura. No registra continuidad, no crea mensualidades, no modifica alumnos, no envía mensajes y no altera Sharky.

La regla es distinta de la alerta existente de **intensivo próximo a terminar**. La alerta previa mira cursos `EN_CURSO` próximos a `fecha_fin`; esta regla solo considera relaciones cuyo curso ya terminó por fecha.

## Configuración

La regla usa exclusivamente los parámetros F5 existentes:

- `f5_intensive_no_continuity_days`: 0–60 días después de `fecha_fin`;
- `f5_intensive_no_continuity_scope`: `SIN_EVALUAR` o `SIN_EVALUAR_O_NO`.

No existe un default de negocio para esos dos campos. Si cualquiera permanece vacío, la regla devuelve cero candidatos. Esto permite desplegar la capacidad sin decidir implícitamente el plazo o el alcance.

`SIN_EVALUAR` incluye solo relaciones con `continua_regular IS NULL`. `SIN_EVALUAR_O_NO` incluye además `continua_regular=0`. Una relación con `continua_regular=1` nunca genera esta alerta.

## Fuente y fecha

La fuente de identidad es `curso_intensivo_alumnos.id`, que representa una relación estable entre curso y alumno. Se usan además:

- `cursos_intensivos.sede_id` para alcance por sede;
- `cursos_intensivos.fecha_fin` para el umbral temporal;
- `cursos_intensivos.estado` únicamente para excluir `CANCELADO`;
- `curso_intensivo_alumnos.continua_regular` como autoridad de continuidad;
- `alumnos.nombre` solo para presentación.

La fecha operativa se evalúa en `America/Cancun`. Un curso que termina hoy todavía no se considera terminado. Con un umbral de 7 días, una `fecha_fin` del día 10 queda vencida el día 17.

La detección no llama a `intensivos_reconciliar_estados_sede()` ni a endpoints GET con efectos laterales: deriva la finalización directamente de `fecha_fin` y permanece de solo lectura.

## Exclusiones deliberadas

- Cursos `CANCELADO` no participan.
- Continuidad `1` no participa.
- No se añade una exclusión implícita por estado administrativo del alumno; el criterio se mantiene estrictamente ligado a la relación intensivo–continuidad configurada.
- No se infiere continuidad a partir de pagos, plan actual, horario o mensajes.

## Presentación

El Centro de alertas muestra una entrada `CONTINUIDAD` con nivel `NEUTRA`, nombre del alumno, fecha de fin, estado de continuidad que motivó el caso y el umbral configurado. El enlace abre el detalle del intensivo.

`NEUTRA` conserva la decisión de F5 de no inventar prioridad alta/media/baja para reglas nuevas.

## Centro de pendientes

La misma detección se integra con F1 mediante el tipo `INTENSIVO_SIN_CONTINUIDAD`. No se crea otra cola ni otra regla: `api/pendientes.php` compone la fuente F5 con las fuentes ya existentes y persiste la gestión, cuando ADMIN marca el caso atendido, en la tabla común `pendientes_gestion`.

La identidad usa `curso_intensivo_alumnos.id` como `origen_id` y no incorpora el número de días configurado. Por tanto, cambiar el umbral no duplica el mismo caso. La sede sigue viniendo del curso y el enlace activo abre el detalle del intensivo.

La causa se revalida contra la misma detección F5 antes de resolver. Deja de estar activa si, entre otros casos verificables:

- `continua_regular` cambia a un valor que el alcance configurado ya no incluye;
- ADMIN deshabilita la regla dejando incompleta su configuración;
- el curso deja de pertenecer al alcance válido de la regla.

Un caso atendido conserva quién, cuándo y la nota mediante el contrato existente de F1. Cuando la causa deja de aplicar, el Centro lo presenta como resuelto por fuente hasta que ADMIN confirme la resolución. Si la misma relación vuelve a cumplir la regla más adelante, reaparece con la misma identidad en vez de crear un duplicado.

No se requiere migración: `pendientes_gestion.tipo` y `origen_id` ya admiten este nuevo origen. Los prospectos sin sede confirmada continúan fuera del Centro de pendientes; esa limitación F1/F4 es independiente de esta integración.
