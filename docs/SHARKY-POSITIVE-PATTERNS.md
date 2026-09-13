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

## GP-001 — Prospecto nuevo → perfil mínimo → producto correcto → sede → catálogo guiado

**Estado:** patrón positivo activo, actualizado al onboarding aprobado el 12 de septiembre de 2026  
**Origen:** evolución de conversaciones reales observadas en septiembre de 2026  
**Privacidad:** casos anonimizados; no guardar nombre, teléfono, fecha de nacimiento ni capturas del prospecto en esta bitácora.

### Contexto

Un prospecto nuevo puede entrar desde Meta, web o WhatsApp directo. La fuente sirve como contexto, pero el primer objetivo ya no es vender ni inferir el producto desde el anuncio: Sharky primero obtiene una identidad mínima limpia y después resuelve el producto de forma determinística.

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
- El primer mensaje es neutral aunque el referral provenga de un anuncio de intensivo o regulares.
- El mensaje de entrada del prospecto no se interpreta como si fuera la respuesta a la pregunta de nombre.
- El nombre confirmado durante onboarding es la autoridad conversacional del contacto. Emojis, dominios o nombres extraños del perfil de WhatsApp no deben prevalecer sobre él.
- Contacto y alumno son entidades distintas cuando las clases son para otra persona.
- Si Sharky no entiende una de las preguntas iniciales, conserva el paso y responde de forma suave: “Una disculpa, no entendí…” + la pregunta correspondiente.
- Una duda lateral informativa durante una pregunta inicial se responde sin consumirla como dato ni perder el paso; después Sharky vuelve a mostrar la pregunta/controles pendientes.
- **Principiante → intensivo** sin ofrecer regulares automáticamente.
- **Intermedio + no ha tomado clases → intensivo**.
- **Intermedio + sí ha tomado clases → regulares**.
- **Avanzado → regulares directo**. El marcador interno de avanzado no debe convertirse en una afirmación falsa de formación formal.
- Curso intensivo y clases regulares son productos distintos. Una frecuencia semanal o la palabra genérica “clases” no cambia el producto por sí sola.
- El intensivo dura 3 semanas, de lunes a viernes, y su precio general se toma de configuración mientras no exista un curso concreto con precio propio.
- Los precios y cuotas de regulares se toman de las autoridades vigentes; el onboarding no debe duplicar una fuente de verdad independiente.
- El selector inicial de sede ofrece Monteverde, Palapas y Ambas. En este recorrido ya no existe una obligación de proponer Monteverde primero.
- “Ambas ubicaciones” es una solicitud de información/comparación, no una sede confirmada. Después de mostrar ambas, se exige selección explícita.
- La sede elegida se conserva en contexto y no se vuelve a preguntar sin motivo.
- Después de confirmar sede, las opciones de plan/horario/fecha salen del catálogo/backend. El modelo no puede ampliar, mezclar ni inventar disponibilidad.
- Si el usuario escribe texto libre equivalente a una opción existente y es inequívoco, puede canonicalizarse a la misma intención que el botón.
- Si el texto es ambiguo, Sharky no abre una ruta improvisada: conserva el paso y vuelve a presentar la pregunta/controles.
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

Considerar GP-001 roto si una adecuación provoca cualquiera de estos comportamientos:

- abrir sin “Hola” o dejar de identificar a Sharky como IA;
- vender un producto antes de pedir la identidad mínima del prospecto;
- interpretar el primer “Hola, quiero información” como nombre;
- conservar como nombre definitivo algo como un dominio, emoji o alias extraño cuando el prospecto ya dio su nombre;
- mezclar nombre del contacto con nombre del alumno cuando son personas distintas;
- avanzar de pregunta aunque la respuesta no se entendió;
- volver a preguntar datos ya confirmados sin motivo;
- ofrecer regulares a un Principiante;
- enviar un Intermedio a regulares sin confirmar si ya tomó clases;
- pedir formación previa adicional a un Avanzado antes de ofrecer regulares;
- afirmar que un Avanzado tomó clases formales sin que lo haya dicho;
- saltar directamente a sede sin mostrar la información básica del producto;
- imponer Monteverde como primera propuesta dentro de este onboarding;
- tratar “Ambas ubicaciones” como una sede confirmada;
- dejar la conversación completamente abierta después de sede y perder el estado de plan/horario/fecha;
- inventar horarios, fechas, precios o cupos fuera de sus autoridades;
- convertir una respuesta conversacional de Brain en una operación sensible directa;
- obligar a reiniciar el onboarding al entrar al flow de inscripción;
- impedir una derivación humana cuando el usuario ya es alumno o requiere una excepción.

### Cobertura automática relacionada

La protección técnica principal de este recorrido vive en `tests/sharky-guided-first-prospect-regression.php`, junto con las regresiones de frontera/elegibilidad de producto, alcance de horarios, WhatsApp adapter, commerce flows, inscripción, follow-up y pagos. Futuras adecuaciones deben conservar esas pruebas verdes y añadir cobertura específica cuando aparezca un nuevo borde real.

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
