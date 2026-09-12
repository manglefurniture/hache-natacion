# Instrucciones para adecuaciones del repositorio

## Sharky

Antes de modificar cualquier archivo relacionado con Sharky —Brain, WhatsApp, onboarding, memoria comercial, routing, commerce flows, inscripción, pagos, follow-up, takeover o panel administrativo— revisar primero:

- `SHARKY-CORE-RULES.md`
- `docs/SHARKY-POSITIVE-PATTERNS.md`

`SHARKY-CORE-RULES.md` es la fuente normativa de las reglas núcleo de Sharky: nivel, elegibilidad, producto, sede, memoria, seguimiento, acciones reales y límites de Brain. Si un cambio contradice ese documento o no puede demostrar que conserva sus invariantes, no debe avanzar hasta resolver la contradicción.

Los patrones `GP-*` documentados en `docs/SHARKY-POSITIVE-PATTERNS.md` son **contratos de experiencia demostrados en producción**. Una corrección de un caso negativo no debe degradar un patrón positivo activo.

Al tocar Sharky:

1. Revisar primero `SHARKY-CORE-RULES.md` y detectar qué regla estable puede verse afectada.
2. Identificar qué `GP-*` puede verse afectado.
3. Preservar las invariantes núcleo y los `NO ROMPER` salvo que una decisión de producto explícita indique lo contrario.
4. Preferir corregir el borde concreto antes que endurecer todo el flujo.
5. Mantener Brain como capa conversacional y los flows/ejecutores determinísticos como autoridad de reglas comerciales estables y operaciones sensibles.
6. No introducir una regla comercial fundamental únicamente en un prompt: debe quedar respaldada por estado/código determinista y regresión cuando sea verificable.
7. Añadir o ampliar regresiones cuando el nuevo aprendizaje pueda verificarse automáticamente.
8. No incorporar nombres, teléfonos, fechas de nacimiento, capturas ni otros datos personales de conversaciones reales a documentación o fixtures; anonimizar los casos.
9. Si una conversación real demuestra un recorrido exitoso nuevo, considerar registrarlo como un nuevo `GP-*` antes de realizar adecuaciones que puedan afectarlo.
10. Si el cambio modifica una regla estable de Sharky, actualizar `SHARKY-CORE-RULES.md` en el mismo PR o en un PR documental inmediatamente asociado.

El objetivo no es congelar Sharky. Es permitir que evolucione corrigiendo errores sin destruir reglas fundamentales ni comportamientos que ya demostraron funcionar con usuarios reales.
