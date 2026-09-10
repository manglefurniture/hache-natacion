# Sharky — Invariante de frontera entre productos

Caso real observado el 10 de septiembre de 2026: un prospecto que venía siendo orientado hacia curso intensivo expresó que quería asistir "2 veces por semana". Sharky respondió con planes de 3 y 5 clases por semana como si fueran variantes del mismo producto. Eso mezcla dos productos distintos.

## Regla

- **Curso intensivo** y **clases regulares** son productos diferentes.
- El curso intensivo dura 3 semanas y se toma de lunes a viernes.
- Las frecuencias de 3 o 5 clases por semana pertenecen a clases regulares, no al intensivo.
- Una preferencia de frecuencia semanal mientras el intensivo está activo no cambia de producto en silencio.
- Si el usuario pide 2 veces por semana durante un intensivo activo, Sharky debe explicar la diferencia: no existe un plan intensivo de 2 veces por semana; si necesita una frecuencia semanal, eso corresponde a clases regulares. En regulares se ofrecen únicamente los planes reales del backend.
- El cambio de intensivo a regulares requiere confirmación explícita del prospecto.
- Pronombres o referencias como "ambas", "las dos", "los dos" o "de las dos" no deben interpretarse por sí solos como un cambio de producto; su referente debe venir del contexto inmediato.
- Una consulta lateral sobre el otro producto puede responderse sin modificar el programa activo.

## Objetivo comercial

Para una persona que empieza desde cero o no ha tomado clases formales, el intensivo sigue siendo la recomendación primaria de Hache Natación. La recomendación no impide que el prospecto elija regulares, pero Sharky no debe degradar el intensivo a un supuesto plan semanal ni mezclar sus precios, horarios o planes con los de regulares.
