# F5 — Configuración administrativa de alertas internas

Fecha de decisión: 2026-09-17.

## Objetivo

Los umbrales operativos de F5 dejan de depender de números enterrados en código. ADMIN puede ajustarlos desde `/configuracion.php` y los consumidores de F5 leen una única autoridad compartida.

## Defaults que preservan producción

Al desplegar este incremento no cambia ningún comportamiento existente:

- prospecto sin seguimiento: **24 horas**;
- ausencias consecutivas: **3**;
- ausencias no justificadas consecutivas: **2**.

Si la tabla `configuracion` no contiene una clave, no puede leerse o contiene un valor inválido, la regla usa esos defaults seguros.

## Parámetros disponibles

| Parámetro | Rango | Default |
| --- | --- | --- |
| Prospecto sin seguimiento | 1–168 horas | 24 |
| Ausencias consecutivas | 1–30 | 3 |
| Ausencias no justificadas consecutivas | 1–30 | 2 |
| Intensivo terminado sin continuidad | 0–60 días o vacío | vacío |
| Alcance de continuidad | `SIN_EVALUAR`, `SIN_EVALUAR_O_NO` o vacío | vacío |

Los dos parámetros de continuidad quedan **vacíos y sin efecto** hasta definir expresamente esa regla. Este incremento no crea todavía la alerta de intensivo terminado sin continuidad.

## Autoridad y consumidores

`config/internal-alert-settings.php` define claves, defaults, validación y lectura. El Centro de alertas, la detección de prospectos y la detección de rachas de ausencia consumen esa autoridad. El Centro de pendientes reutiliza la misma detección de rachas, por lo que un cambio de umbral no crea una segunda definición.

## Permisos y auditoría

Solo `ADMIN` recibe y modifica la superficie `alertas_internas` de `/api/configuracion.php`. `VERIFICADOR` mantiene su conjunto limitado de configuración operativa.

Cada cambio real de un parámetro F5 registra `CONFIG_ALERTA_ACTUALIZADA` en `auditoria_eventos`, incluyendo clave, valor anterior y valor nuevo. Guardar nuevamente el mismo valor no se registra como un cambio.

## Límites deliberados

- No se modifica Sharky, sus follow-ups automáticos ni el funnel.
- No se envían mensajes externos.
- No se altera asistencia, pagos, reposiciones ni derecho a clase.
- No se asignan prioridades alta/media/baja nuevas.
- No se requiere una migración: las claves F5 se crean con `UPSERT` al primer guardado y los defaults funcionan aunque todavía no existan filas persistidas.
