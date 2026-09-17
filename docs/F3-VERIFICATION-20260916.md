# F3 — Verificación funcional de Expediente 360°

Fecha de verificación: 2026-09-16.

Producción comprobada después de los PR #274 y #275, con versión desplegada `9ddea409b5e2138af555621a398ee396265cdd16`.

## Casos reales comprobados

1. Alumno regular: estado, sede, horario y plan visibles; pagos actuales conservan fecha exacta y pagos históricos importados muestran solo mes/año con “fecha exacta no registrada”; no hay línea de tiempo duplicada.
2. Alumno únicamente de intensivo: curso terminado y pago de intensivo visibles; no se presenta continuidad a regular; pago histórico sin día exacto se etiqueta correctamente.
3. Alumno que pasó de intensivo a regular: curso intensivo terminado, pago de intensivo, marca “Continuó a regular” y mensualidad/plan regular posteriores visibles en un único expediente.

Los tres escenarios conservaron fuente y acceso al registro original.

## Reposición regular

Al momento de la verificación no existía un alumno real disponible con reposición regular para una comprobación visual en producción. El comportamiento queda cubierto por la regresión automatizada de Expediente 360 y por Quality exitoso. No se crearon datos ficticios para completar la evidencia.

Con esta salvedad explícita, F3 queda funcionalmente verificada para los escenarios reales disponibles en producción; la primera reposición regular real deberá comprobarse visualmente cuando exista.
