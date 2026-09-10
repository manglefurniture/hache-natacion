# Incidente Sharky Brain — 2026-09-10

## Síntoma observado

En una conversación real de WhatsApp el Brain conversacional perdió la prioridad del contexto activo y reinterpretó un número aportado durante una conversación de ubicación como una edad. También se observaron preguntas repetidas o de bajo valor y resolución parcial de solicitudes multi-intención.

## Contención inmediata

Se deshabilita `sharky_brain_conversacional_habilitado` mediante el kill switch persistido en `configuracion`. La Fase 2B-A/determinística permanece disponible como fallback estable.

## Casos mínimos que deben quedar cubiertos antes de reactivar

- Un número aislado no puede convertirse en edad si el turno activo está resolviendo ubicación, colonia, supermanzana, región, calle o intersección.
- La edad solo se captura cuando fue solicitada explícitamente o cuando el usuario la expresa con semántica inequívoca de edad.
- `Nichupté y Chac Mool`, dentro de una pregunta de ubicación, debe tratarse primero como posible intersección y no como dos alternativas independientes.
- `Ubicación y costo` es una solicitud multi-intención: se deben cubrir ambos puntos o explicar exactamente qué dato falta para uno de ellos.
- Si el origen es un anuncio de intensivo, no se debe volver a preguntar intensivo vs. regulares como primer movimiento; el nivel real determina la recomendación posterior.
- Una aclaración ambigua como `¿qué diferencia?` debe resolverse contra el objeto conversacional inmediato antes de descargar información de varias comparaciones.

## Criterio de reactivación

No reactivar el Brain conversacional hasta que estos casos tengan regresiones determinísticas y una prueba conversacional controlada fuera del tráfico general.
