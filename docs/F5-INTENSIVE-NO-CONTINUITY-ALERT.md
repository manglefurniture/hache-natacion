# F5 — Alerta interna de intensivo terminado sin continuidad

Fecha de implementación: 2026-09-17.

## Alcance

Este micro-paso implementa la detección de **intensivo terminado sin continuidad** como señal administrativa de solo lectura. No registra continuidad, no crea mensualidades, no modifica alumnos, no envía mensajes y no altera Sharky.

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

Este micro-paso no persiste todavía la señal en `pendientes_gestion`. La relación `curso_intensivo_alumnos.id` ya deja resuelta la identidad estable necesaria para ese siguiente incremento: una misma relación no cambia de identidad porque cambie el plazo configurado.

Al integrar la señal con F1, la causa deberá revalidarse contra esta misma detección y resolverse cuando la configuración deje de incluir el caso o cuando cambie `continua_regular`.
