# Sharky — Invariante de alcance de horarios

Caso real observado el 10 de septiembre de 2026: un prospecto ya orientado a curso intensivo eligió Colegio Monteverde y Sharky mostró horarios mezclados de clases regulares e intensivo. Después aceptó un horario regular como si fuera intensivo.

## Regla

Cuando `commercial_context.program` y `commercial_context.sede_clave` están confirmados:

- una respuesta genérica de horarios debe mostrar únicamente horarios activos del programa y sede confirmados;
- un horario escrito por el usuario debe validarse contra ese mismo conjunto antes de continuar;
- un horario que pertenezca solo al otro programa no puede aceptarse, reinterpretarse ni presentarse como válido;
- una consulta explícita sobre el otro programa puede responderse como consulta lateral, pero no cambia por sí misma el programa activo;
- el modelo conversacional no tiene autoridad para ampliar el conjunto de horarios devuelto por el backend.

La regresión canónica reproduce el borde `intensivo → Colegio monte verde → De 6 a 7` y exige que un horario regular-only sea rechazado en vez de llegar al modelo.
