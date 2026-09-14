# Sharky — Bitácora de patrones positivos

Esta bitácora registra comportamientos reales de Sharky que demostraron valor en producción y que deben considerarse **contratos de experiencia** para futuras adecuaciones.

No sustituye las pruebas automáticas ni las reglas de seguridad. Su función es evitar que una corrección local destruya un recorrido que ya funciona bien.

## Cómo usar esta bitácora

Antes de modificar Brain, onboarding, memoria comercial, flows de inscripción, selección de sede/horario, pagos o takeover:

1. Revisar los patrones activos.
2. Identificar qué patrón podría verse afectado.
3. Preservar sus invariantes o justificar explícitamente el cambio.
4. Añadir una regresión cuando el patrón sea verificable.
5. Mantener los casos reales anonimizados.

---

## GP-001 — Onboarding por perfil anterior

**Estado:** reemplazado para `meta_ad`, `web` y `direct`; se conserva como fallback para referrals no publicitarios y como cobertura histórica.  
**Origen:** conversaciones reales observadas en septiembre de 2026.

### Contexto histórico

El recorrido anterior pedía nombre → para quién son las clases → edad → nivel → formación previa cuando correspondía → producto → sede. Fue útil para ordenar elegibilidad y sigue siendo una referencia valiosa para guards de negocio.

A partir de la validación en producción del selector cerrado de Sharky 3.0, **los prospectos nuevos de Meta Ads, web y WhatsApp directo ya no recorren GP-001**. Esos tres canales usan GP-002.

Los siguientes contratos históricos siguen siendo relevantes donde aplique el fallback:

- contacto y alumno son entidades distintas cuando las clases son para otra persona;
- Principiante → intensivo;
- Intermedio + no ha tomado clases → intensivo;
- Intermedio + sí ha tomado clases → regulares;
- Avanzado → regulares sin inventar formación formal;
- producto y sede confirmados no se pierden sin causa;
- backend/configuración sigue siendo autoridad de precios, horarios y cupos;
- flows protegidos y mutaciones conservan sus guards;
- alumno existente abandona captación y pasa a humano.

Cobertura histórica principal: `tests/sharky-guided-first-prospect-regression.php` mantiene este fallback mediante un referral no publicitario.

---

## GP-002 — Prospecto nuevo Meta/web/direct → selector cerrado → producto → sede → inscripción protegida

**Estado:** patrón activo y preferido para captación comercial nueva.  
**Origen:** flujo Meta validado con conversaciones reales el 13 de septiembre de 2026; ampliado a web/directo tras observar avance limpio, sin rutas laterales ni errores en los primeros casos reales.  
**Autoridad detallada:** `docs/SHARKY-3-META-FLOW.md`.

### Contexto

Aplica a prospectos nuevos/no alumnos cuando `entry_source` es:

- `meta_ad`;
- `web`;
- `direct`.

Los tres canales comparten el mismo state machine, pero **la fuente real se conserva** para atribución. Un anuncio, un prefill web o la frase inicial del usuario pueden aportar contexto, pero no seleccionan producto ni sede automáticamente.

### Recorrido que se debe preservar

1. Sharky se identifica explícitamente como asistente IA y comunica el rango de edad vigente.
2. Muestra un carrusel horizontal con **Aprende a nadar / Clases regulares**, cada tarjeta con su imagen y quick reply.
3. Texto libre en un paso cerrado no avanza. Una duda lateral informativa se responde con datos confirmados y después se repone exactamente el mismo control; una supuesta selección escrita solo repite los controles, sin repetir imágenes.
4. **Aprende a nadar** muestra información completa del intensivo y después el carrusel **Monteverde / Palapas**.
5. **Clases regulares** pregunta si ya tomó clases mediante **Sí, continuar / No, curso básico**.
6. **No, curso básico** entra directamente al bloque del intensivo.
7. **Sí, continuar** muestra información de regulares y después el mismo carrusel de sedes.
8. La sede elegida muestra ubicación, Maps, referencia y horarios dinámicos de esa sede/producto.
9. **Ver otra sede** cambia solo la sede y conserva el producto.
10. Intensivo reutiliza el Flow y proceso de inscripción/pago existentes.
11. Regulares usa su Flow específico con sede fija, perfil, plan y horario activos; termina en takeover humano para coordinar pago.
12. Los mensajes comerciales del funnel usan **2 a 5 emojis funcionales y naturales por respuesta**, sin saturación.

### Invariantes — NO ROMPER

- Un alumno existente nunca entra a este funnel y pasa a takeover humano.
- `entry_source` conserva `meta_ad`, `web` o `direct`; compartir flujo no borra atribución.
- Un prefill web o mensaje directo no se convierte por sí solo en producto/sede confirmado.
- Brain no interpreta texto para avanzar ni reescribe producto, sede, plan o inscripción dentro del state machine.
- La respuesta lateral segura funciona sin Brain conversacional, no abre 2B-A y falla sin inventar cuando falta una autoridad.
- Contestar una duda no modifica producto, sede, nivel, turno, horario ni intención; una consulta comparativa sobre otra sede tampoco cambia la sede activa.
- La capa informativa no ejecuta inscripciones, pagos, cancelaciones, reposiciones ni cambios de datos.
- Las políticas laterales antiguas tampoco pueden adelantarse al funnel, incluso después de transcribir una nota de voz.
- Solicitar explícitamente una persona o declarar que ya es alumno sí puede derivar a humano.
- Retries no vuelven a enviar imágenes.
- Las opciones visuales de producto y sede permanecen como carruseles nativos de WhatsApp.
- Los quick replies conservan sus IDs canónicos.
- Horarios provienen de `horarios` filtrando sede + activo + producto.
- Precios, inscripción y Maps utilizan autoridades existentes.
- Monteverde y Palapas nunca mezclan horarios, planes o inscripción.
- La sede seleccionada para regulares llega al Flow y no se vuelve a preguntar dentro del formulario.
- Cancelar el Flow regular vuelve al bloque de la sede elegida sin perder producto/contexto.
- Flow obsoleto/inconsistente falla cerrado.
- El registro regular no cobra automáticamente.
- Los guards de edad aplican a ambos productos.
- Los recursos visuales aprobados no se reinterpretan.
- Emojis: normalmente **2–5 funcionales** para prospectos; no usar un emoji en cada renglón ni superar el rango por decoración.

### Señales de regresión

Considerar GP-002 roto si:

- web/directo vuelve al onboarding de nombre;
- se pierde o falsea la fuente de entrada;
- texto libre como “Palapas” avanza sin tocar el control vigente;
- una duda lateral abre Brain, se limita a repetir el mensaje anterior sin contestar o pierde el cursor pendiente;
- un audio abre una ruta lateral;
- una imagen se repite en retry;
- las tarjetas dejan de formar un carrusel;
- un alumno conocido entra como prospecto;
- regulares permite un perfil no verificable;
- el Flow vuelve a preguntar sede;
- edad fuera de rango crea alumno;
- Brain cambia una decisión del funnel;
- horarios/precios se mezclan entre sedes;
- mensajes del funnel caen en exceso de emojis o pierden las señales visuales funcionales acordadas.

### Cobertura automática relacionada

- `tests/sharky-meta3-regression.php`
- `tests/sharky-pr162-review-regression.php`
- `tests/sharky-guided-first-prospect-regression.php`
- `tests/sharky-language-guide-regression.php`
- `tests/sharky-safe-side-question-regression.php`
- suites de commerce/WhatsApp Flow/registro/outbox
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

Una conversación problemática sirve para descubrir un borde. Una conversación exitosa sirve para definir un **contrato**. Sharky debe evolucionar corrigiendo bordes sin degradar contratos positivos demostrados en producción.
