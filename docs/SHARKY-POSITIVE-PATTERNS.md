# Sharky — Bitácora de patrones positivos

Esta bitácora registra comportamientos reales de Sharky que demostraron valor en conversaciones de producción y que deben considerarse **contratos de experiencia** para futuras adecuaciones.

No sustituye las pruebas automáticas ni las reglas de seguridad. Su función es evitar que una corrección local destruya un recorrido que ya funciona bien.

## Cómo usar esta bitácora

Antes de modificar Brain, onboarding, memoria comercial, flows de inscripción, selección de sede/horario, pagos o takeover:

1. Revisar los patrones activos de este documento.
2. Identificar qué patrón podría verse afectado.
3. Preservar sus invariantes o justificar explícitamente por qué cambia.
4. Añadir una regresión cuando el patrón pueda expresarse de forma verificable.
5. Si una conversación real demuestra un comportamiento mejor, añadir un nuevo patrón o actualizar uno existente sin incluir datos personales del prospecto.

---

## GP-001 — Prospecto web/directo → perfil mínimo → producto correcto → sede → catálogo guiado

**Estado:** patrón positivo activo para web, WhatsApp directo y referrals no publicitarios  
**Origen:** evolución de conversaciones reales observadas en septiembre de 2026  
**Privacidad:** casos anonimizados; no guardar nombre, teléfono, fecha de nacimiento ni capturas del prospecto en esta bitácora.

### Contexto

Este patrón sigue siendo la autoridad del onboarding vigente para prospectos que llegan desde la web, WhatsApp directo o referrals no publicitarios. **No aplica a prospectos nuevos provenientes de publicidad Meta**: ese canal usa GP-002 y `docs/SHARKY-3-META-FLOW.md`.

La fuente sirve como contexto, pero en este recorrido Sharky obtiene primero una identidad mínima limpia y después resuelve el producto de forma determinística.

### Recorrido que se debe preservar

1. Sharky usa la ventana normal de debounce y se presenta una sola vez: **“Hola, soy Sharky, asistente IA de Hache Natación.”**
2. Pide el nombre del contacto antes de vender. Ese nombre confirmado sustituye al `profile_name` extraño como autoridad para identificar al prospecto.
3. Pregunta si las clases son para quien escribe mediante botones **Sí / No**.
4. Si son para otra persona, conserva separados contacto y alumno y pide el nombre del alumno.
5. Pregunta la edad de la persona que tomará las clases.
6. Pide nivel con tres botones: **Principiante / Intermedio / Avanzado**.
7. Principiante → curso intensivo.
8. Intermedio → pregunta si ya ha tomado clases; No → intensivo, Sí → regulares.
9. Avanzado → regulares directamente, sin afirmar ni preguntar que haya tomado clases formales.
10. Sharky presenta una oferta breve del producto y un botón para ver la información.
11. Al abrir información muestra precio/planes/duración vigentes y después ofrece **Monteverde / Palapas / Ambas ubicaciones**.
12. Si elige Ambas, muestra las dos ubicaciones y vuelve a pedir una selección explícita entre Monteverde y Palapas.
13. Con la sede confirmada, Sharky vuelve al catálogo estructurado existente para plan, horario, fecha y demás decisiones verificables.
14. El flow protegido de inscripción/pago conserva sus guards y confirmaciones; Brain no lo suplanta.

### Invariantes — NO ROMPER

- La presentación de Sharky como IA ocurre una sola vez salvo que el usuario pregunte expresamente quién es.
- El primer mensaje es neutral y el mensaje de entrada no se interpreta como si fuera la respuesta a la pregunta de nombre.
- El nombre confirmado durante onboarding es la autoridad conversacional del contacto. Emojis, dominios o nombres extraños del perfil de WhatsApp no deben prevalecer sobre él.
- Contacto y alumno son entidades distintas cuando las clases son para otra persona.
- Si Sharky no entiende una de las preguntas iniciales, conserva el paso y responde de forma suave: “Una disculpa, no entendí…” + la pregunta correspondiente.
- Una duda lateral informativa durante una pregunta inicial puede responderse sin consumirla como dato y después se vuelve a la pregunta pendiente.
- **Principiante → intensivo** sin ofrecer regulares automáticamente.
- **Intermedio + no ha tomado clases → intensivo**.
- **Intermedio + sí ha tomado clases → regulares**.
- **Avanzado → regulares directo**. El marcador interno de avanzado no debe convertirse en una afirmación falsa de formación formal.
- Curso intensivo y clases regulares son productos distintos. Una frecuencia semanal o la palabra genérica “clases” no cambia el producto por sí sola.
- El intensivo dura 3 semanas, de lunes a viernes, y su precio general se toma de configuración mientras no exista un curso concreto con precio propio.
- Los precios y cuotas de regulares se toman de las autoridades vigentes; el onboarding no debe duplicar una fuente de verdad independiente.
- El selector inicial de sede ofrece Monteverde, Palapas y Ambas. En este recorrido no existe una obligación de proponer Monteverde primero.
- “Ambas ubicaciones” es una solicitud de información/comparación, no una sede confirmada.
- La sede elegida se conserva en contexto y no se vuelve a preguntar sin motivo.
- Después de confirmar sede, plan/horario/fecha salen del catálogo/backend. El modelo no puede ampliar, mezclar ni inventar disponibilidad.
- Si el usuario escribe texto libre equivalente a una opción existente y es inequívoco, puede canonicalizarse a la misma intención que el botón.
- Si el texto es ambiguo, Sharky conserva el paso y vuelve a presentar la pregunta/controles.
- Una declaración nueva que contradiga nivel o elegibilidad confirmados no reemplaza silenciosamente el estado; se aclara antes de continuar.
- Una vez que empieza un flow protegido de inscripción o pago, Brain no reescribe ni suplanta ese flow.
- Debe existir confirmación explícita antes de ejecutar una mutación sensible.
- Pausas normales entre mensajes no deben hacer perder el contexto confirmado.
- Si la persona indica que ya es alumno, el onboarding de prospecto se abandona y se mantiene el handoff humano vigente para alumnos.

### Qué sí puede mejorar sin romper GP-001

- Hacer mensajes más cortos.
- Mejorar tono y naturalidad sin alterar la semántica de los pasos.
- Mejorar canonicalización de respuestas equivalentes.
- Mejorar la presentación visual de botones/listas respetando sus IDs y autoridades.
- Añadir contexto útil después de que el dato estructurado correspondiente ya esté confirmado.

### Señales de regresión

Considerar GP-001 roto si una adecuación provoca vender antes del perfil mínimo; mezclar contacto y alumno; ofrecer regulares a un Principiante; enviar Intermedio a regulares sin confirmar clases previas; pedir formación adicional a Avanzado; inventar horarios, fechas, precios o cupos; perder la sede; o ejecutar operaciones sensibles desde texto/modelo sin los guards existentes.

### Cobertura automática relacionada

La protección técnica principal vive en `tests/sharky-guided-first-prospect-regression.php`, junto con las regresiones de frontera/elegibilidad, alcance de horarios, WhatsApp adapter, commerce flows, inscripción, follow-up y pagos.

---

## GP-002 — Meta Ads → selector cerrado → producto → sede → inscripción protegida

**Estado:** patrón aprobado para Sharky 3.0; sustituye GP-001 únicamente en `meta_ad`  
**Origen:** especificación aprobada el 13 de septiembre de 2026  
**Autoridad detallada:** `docs/SHARKY-3-META-FLOW.md`

### Contexto

Aplica exclusivamente a **Facebook/Instagram Ads → WhatsApp → prospecto nuevo/no alumno**. No aplica a alumnos existentes, web ni WhatsApp directo. La campaña puede conservar interés para atribución, pero no puede escoger producto por el usuario.

### Recorrido que se debe preservar

1. Sharky se identifica explícitamente como asistente IA, informa el rango 12–65 y muestra **Aprende a nadar / Clases regulares** con sus imágenes aprobadas.
2. En pasos cerrados, texto libre no decide ni avanza: se conserva el paso y se repiten solo los botones vigentes, sin repetir imágenes.
3. **Aprende a nadar** muestra información completa del curso básico y después **Monteverde / Palapas**.
4. **Clases regulares** pregunta primero si ya tomó clases en alguna escuela mediante **Sí, continuar / No, curso básico**.
5. **No, curso básico** entra directamente al bloque completo de intensivo, sin volver al menú inicial.
6. **Sí, continuar** muestra información de regulares y después **Monteverde / Palapas**.
7. La sede muestra ubicación, Maps, referencia y horarios dinámicos de esa sede/producto. Intensivo añade “Iniciamos el próximo lunes”.
8. **Ver otra sede** cambia solo la sede y conserva el producto.
9. Intensivo reutiliza el Flow y proceso transaccional de inscripción/pago existentes.
10. Regulares usa su Flow específico con sede fija, nombre, nacimiento, perfil Intermedio/Avanzado, plan y horario activos; al completarse registra de forma protegida y termina en takeover humano para coordinar pago.
11. Edad válida: 12–65 inclusive. Fuera de rango no existe alta automática y se deriva a humano.

### Invariantes — NO ROMPER

- Alumno existente, aunque llegue desde anuncio, **no entra al funnel Meta** y pasa a takeover humano.
- Web y WhatsApp directo conservan su flujo vigente; Sharky 3.0 no los captura.
- Brain no interpreta texto para avanzar, no decide producto/sede/plan y no reescribe decisiones del state machine Meta.
- Brain **no se apaga globalmente**: la exclusión es específica a `meta_ad`/Sharky 3.0.
- Una solicitud explícita de hablar con una persona o una declaración de que ya es alumno sí deriva a humano.
- Texto libre durante un paso cerrado tampoco activa atajos laterales del flujo antiguo; salvo los handoffs anteriores, se limita a reintentar los controles vigentes.
- Retries no vuelven a enviar las imágenes.
- Horarios provienen de `horarios` filtrando sede + activo + producto; no se duplican como una segunda fuente.
- Precios, inscripción y Maps utilizan autoridades/configuración existentes cuando corresponda.
- Monteverde y Palapas nunca mezclan horarios, plan o inscripción.
- La sede seleccionada para regulares llega al Flow y no se vuelve a preguntar dentro del Flow.
- Cancelar el Flow regular vuelve al bloque de la sede elegida sin perder producto/contexto.
- Flow obsoleto, inconsistente o con datos fuera de rango falla cerrado y deriva a humano.
- El registro regular no cobra automáticamente.
- Los guards 12–65 aplican también al registro intensivo existente.
- Los recursos visuales aprobados se incorporan como assets; no se reinterpretan las fotografías.

### Señales de regresión

Considerar GP-002 roto si Meta cae al onboarding de nombre; un texto como “Palapas” avanza sin pulsar el botón; una duda lateral abre Brain; una imagen se repite en retry; un alumno conocido entra como prospecto; regulares permite nivel no verificable; el Flow vuelve a preguntar sede; >65 o <12 crea alumno; Brain 2B-A cambia una decisión Meta; o web/directo empieza a usar este funnel.

### Cobertura automática relacionada

- `tests/sharky-meta3-regression.php`
- `tests/sharky-pr162-review-regression.php`
- suite de commerce/WhatsApp Flow/registro/outbox existente
- Quality completo del PR

---

## Plantilla para nuevos patrones

### GP-XXX — Título breve

**Estado:** activo / en observación / reemplazado  
**Origen:** fecha o lote de conversaciones reales  
**Privacidad:** siempre anonimizado.

**Contexto:** qué intentaba hacer la persona.  
**Recorrido que funcionó:** secuencia mínima.  
**Invariantes — NO ROMPER:** qué debe sobrevivir futuras adecuaciones.  
**Qué sí puede mejorar:** margen de ajuste sin dañar el patrón.  
**Señales de regresión:** síntomas concretos.  
**Cobertura automática relacionada:** tests que protegen el patrón.

---

## Principio operativo

Una conversación problemática sirve para descubrir un borde. Una conversación exitosa sirve para definir un **contrato**. Sharky debe evolucionar corrigiendo los bordes sin degradar los contratos positivos ya demostrados en producción.
