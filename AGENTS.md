# Instrucciones para adecuaciones del repositorio

## Sharky

Antes de modificar cualquier archivo relacionado con Sharky —WhatsApp, onboarding, memoria comercial, routing, commerce flows, inscripción, pagos, follow-up, takeover, panel administrativo o código histórico de Brain— revisar primero:

- `SHARKY-CORE-RULES.md`
- `docs/SHARKY-POSITIVE-PATTERNS.md`
- `docs/SHARKY-LANGUAGE-GUIDE.md`
- `docs/SHARKY-3-META-FLOW.md` cuando el cambio toque captación de prospectos nuevos desde Meta Ads, web o WhatsApp directo.
- `docs/SHARKY-CONVERSATION-REVIEW.md` cuando el cambio nace de conversaciones reales o hallazgos automáticos.
- `docs/SHARKY-LEARNING-INBOX.md` cuando el cambio nace de un veredicto de la bandeja de aprendizaje.

`SHARKY-CORE-RULES.md` es la fuente normativa de las reglas núcleo de Sharky: identidad, fuente, producto, sede, memoria, seguimiento y acciones reales. `docs/SHARKY-3-META-FLOW.md` conserva un nombre histórico, pero actualmente define el **funnel Sharky 3.0 común para prospectos nuevos `meta_ad`, `web` y `direct`**. La fuente real debe conservarse para atribución; compartir state machine no convierte web/directo en Meta. Los referrals no publicitarios conservan temporalmente el fallback por perfil documentado en Core/GP-001.

Los patrones `GP-*` documentados en `docs/SHARKY-POSITIVE-PATTERNS.md` son contratos de experiencia demostrados en producción. Una corrección de un caso negativo no debe degradar un patrón positivo que continúe dentro de su alcance. Si una nueva versión sustituye deliberadamente un patrón, debe dejarse documentado el nuevo alcance y su regresión correspondiente.

`docs/SHARKY-LANGUAGE-GUIDE.md` registra expresiones coloquiales, variantes reales y faltas frecuentes que pueden canonicalizarse antes de entrar a reglas determinísticas. Es una guía de interpretación, no una autoridad comercial. En pasos cerrados de Sharky 3.0 para Meta/web/directo, el texto libre **no sustituye** los controles vigentes salvo una regla explícita de takeover/seguridad.

`docs/SHARKY-CONVERSATION-REVIEW.md` define el circuito de aprendizaje desde conversaciones reales. Un hallazgo automático es solo un candidato de revisión: nunca puede modificar por sí solo reglas, prompts, código, elegibilidad, precios, horarios, pagos o datos administrativos.

`docs/SHARKY-LEARNING-INBOX.md` define el segundo filtro del circuito: ChatGPT revisa contexto real de forma transitoria, emite un veredicto estructurado y puede marcar un caso como candidato a regresión o patrón positivo. Ese veredicto tampoco modifica producción por sí mismo.

Al tocar Sharky:

1. Revisar primero `SHARKY-CORE-RULES.md` y detectar qué regla estable puede verse afectada.
2. Si toca captación de prospectos nuevos Meta/web/directo, revisar también `docs/SHARKY-3-META-FLOW.md`.
3. Identificar qué `GP-*` puede verse afectado.
4. Revisar la guía lingüística si el cambio toca comprensión de texto libre, variantes idiomáticas o faltas comunes.
5. Preservar las invariantes núcleo y los `NO ROMPER` salvo decisión de producto explícita.
6. Brain queda **retirado del routing vivo del funnel de captación Sharky 3.0** para Meta/web/directo. Flows/ejecutores determinísticos y autoridades de backend son fuente de verdad para reglas comerciales y operaciones sensibles. El código histórico de Brain puede permanecer como referencia/rollback, pero no debe reactivarse automáticamente ni ganar autoridad sobre el funnel.
7. Las políticas laterales antiguas tampoco pueden adelantarse al state machine cerrado, incluso después de transcribir audio.
8. En orientación comercial para prospectos, mantener la regla vigente de **2 a 5 emojis funcionales y naturales por respuesta**; sirven para lectura/acción y no para decoración excesiva.
9. No introducir una regla comercial fundamental únicamente en un prompt: debe quedar respaldada por estado/código determinista y regresión cuando sea verificable.
10. Añadir o ampliar regresiones cuando el nuevo aprendizaje pueda verificarse automáticamente.
11. No incorporar nombres, teléfonos, fechas de nacimiento, capturas ni otros datos personales de conversaciones reales a documentación o fixtures; anonimizar los casos.
12. Si una conversación real demuestra un recorrido exitoso nuevo, considerar registrarlo como un nuevo `GP-*` antes de realizar adecuaciones que puedan afectarlo.
13. Si el cambio modifica una regla estable de Sharky, actualizar `SHARKY-CORE-RULES.md` o el documento de versión que explícitamente lo sustituye en el mismo PR o en un PR documental inmediatamente asociado.
14. Si un hallazgo automático se valida, convertirlo primero en caso anonimizado/regresión y después aplicar el cambio mediante PR; no implementar aprendizaje autónomo directo en producción.
15. Un veredicto `ERROR_REAL`, `MEJORABLE` o `GOOD_PATTERN` en la bandeja autoriza análisis y preparación de un caso, no cambio automático. El cambio sigue pasando por PR, Quality y deploy.
16. Diferenciar siempre fuente de entrada (`meta_ad`, `web`, `direct`, `referral`) de identidad. Un alumno existente sigue siendo alumno y no entra en onboarding de prospecto.

El objetivo no es congelar Sharky. Es permitir que evolucione corrigiendo errores sin destruir reglas fundamentales ni comportamientos que siguen demostrando valor con usuarios reales.
