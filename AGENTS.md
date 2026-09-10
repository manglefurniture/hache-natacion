# Instrucciones para adecuaciones del repositorio

## Sharky

Antes de modificar cualquier archivo relacionado con Sharky —Brain, WhatsApp, onboarding, memoria comercial, routing, commerce flows, inscripción, pagos, follow-up, takeover o panel administrativo— revisar primero:

- `docs/SHARKY-POSITIVE-PATTERNS.md`

Los patrones `GP-*` documentados allí son **contratos de experiencia demostrados en producción**. Una corrección de un caso negativo no debe degradar un patrón positivo activo.

Al tocar Sharky:

1. Identificar qué `GP-*` puede verse afectado.
2. Preservar sus invariantes `NO ROMPER` salvo que el cambio de producto indique explícitamente lo contrario.
3. Preferir corregir el borde concreto antes que endurecer todo el flujo.
4. Mantener Brain como capa conversacional y los flows/ejecutores determinísticos como autoridad de operaciones sensibles.
5. Añadir o ampliar regresiones cuando el nuevo aprendizaje pueda verificarse automáticamente.
6. No incorporar nombres, teléfonos, fechas de nacimiento, capturas ni otros datos personales de conversaciones reales a documentación o fixtures; anonimizar los casos.
7. Si una conversación real demuestra un recorrido exitoso nuevo, considerar registrarlo como un nuevo `GP-*` antes de realizar adecuaciones que puedan afectarlo.

El objetivo no es congelar Sharky. Es permitir que evolucione corrigiendo errores sin destruir comportamientos que ya demostraron funcionar con usuarios reales.
