# Sharky 3.0 — flujo determinístico de captación

Estado: **flujo vigente para prospectos nuevos desde Meta Ads, web y WhatsApp directo**.

Fecha de definición original: 2026-09-13.  
Ampliación a web/directo: 2026-09-13.

> El nombre del archivo y el identificador interno `meta_ad_onboarding` se conservan por compatibilidad con conversaciones y pruebas existentes. No significan que el flujo siga siendo exclusivo de Meta.

## 1. Alcance

El recorrido se aplica cuando:

- el contacto no está identificado como alumno actual;
- la conversación es de captación comercial;
- `entry_source` es uno de: `meta_ad`, `web` o `direct`.

La fuente real **se conserva** para atribución y analítica. Compartir el state machine no convierte una entrada web/directa en Meta.

Por ahora, un referral no publicitario (`entry_source = referral`) conserva el onboarding anterior por perfil como fallback controlado. Un alumno conocido nunca se degrada a prospecto: pasa a takeover humano aunque haya llegado desde anuncio, web o WhatsApp directo.

## 2. Principio operativo

La captación inicial deja de depender de Brain para interpretar libremente el texto. Es un recorrido determinístico guiado por controles nativos de WhatsApp.

Reglas:

1. Sharky se identifica siempre como asistente IA.
2. Producto, sede y decisiones del funnel se realizan con botones/Flow.
3. El texto libre no consume ni cambia un paso cerrado.
4. Si el texto libre es una **duda lateral informativa**, Sharky responde de forma segura con datos verificados y después repone el control pendiente sin mover el cursor.
5. Si la persona escribe una supuesta selección en vez de usar el control vigente, Sharky conserva el paso y repite únicamente los controles, sin repetir imágenes.
6. Solicitar explícitamente una persona o declarar que ya es alumno sí produce takeover humano.
7. Brain no decide producto, sede, plan, inscripción ni excepciones dentro de este state machine.
8. Precios, horarios, inscripciones, Maps y demás datos comerciales salen de backend/configuración; no se crean fuentes paralelas.
9. Los mensajes comerciales para prospectos usan **2 a 5 emojis funcionales y naturales por respuesta**, por ejemplo 🏊‍♂️, 📍, 💰, 🕒, ✅, ✍️, 📅, 🎒 o 👇. Los emojis ayudan a escanear el mensaje; no deben decorar cada línea ni saturar el texto.

## 3. Detección de fuente

### Meta Ads

Se detecta mediante referral/`ctwa_clid`. Se conserva `entry_source = meta_ad` y cualquier `entry_interest` únicamente como contexto/atribución.

### Web

Los enlaces oficiales de hnATACION.com que abren WhatsApp usan prefills del tipo `Hola Hache Natación, quiero...`. Esa firma se clasifica como `entry_source = web`, incluso cuando el texto habla de inscripción, orientación o una sede y no incluye una selección canónica de producto.

El texto del enlace **no preselecciona** producto ni sede.

### WhatsApp directo

Un número nuevo sin referral publicitario ni firma web se clasifica como `entry_source = direct`.

La frase inicial del usuario tampoco preselecciona producto o sede: el usuario confirma mediante los controles del funnel.

## 4. Entrada común

Primer bloque visual, una sola vez:

**Hola, soy Sharky 🦈, asistente IA de Hache Natación.**

Sharky informa el rango vigente de edad y pregunta qué busca.

El mensaje se presenta como un **carrusel nativo de WhatsApp** con dos tarjetas:

1. **Aprende a nadar** — imagen aprobada + quick reply `meta:program:learn`.
2. **Clases regulares** — imagen aprobada + quick reply `meta:program:regular`.

La campaña, el prefill web o el texto inicial pueden conservar interés para atribución, pero nunca sustituyen el toque del botón.

Si el usuario escribe en lugar de usar el carrusel:

> 🏊‍♂️ Para orientarte mejor, elige una de estas dos opciones 👇

Solo se repiten los controles vigentes, sin volver a enviar las fotografías.

## 5. Ruta Aprende a nadar

Al seleccionar **Aprende a nadar**, Sharky muestra la información del curso intensivo usando las autoridades vigentes:

- duración: 3 semanas;
- clases de lunes a viernes;
- precio general desde `sharky_precio_intensivo`, salvo que exista un curso concreto con precio propio;
- dirigido a quien empieza desde cero o nunca ha tomado clases;
- no lleva inscripción;
- requisitos de traje de baño, gorro y goggles.

El mensaje usa emojis funcionales para separar duración, precio, requisitos y siguiente acción, respetando el rango de 2–5.

Después abre el carrusel de sedes.

## 6. Ruta Clases regulares

Antes de mostrar planes se valida una sola condición:

**¿Ya has tomado clases de natación anteriormente, en alguna escuela?**

Controles:

- `meta:regular:yes` — **Sí, continuar**.
- `meta:regular:no` — **No, curso básico**.

`No, curso básico` entra directamente a la información completa del intensivo, sin regresar al menú inicial.

`Sí, continuar` muestra:

- modalidad por mensualidad;
- planes 3x/5x desde las autoridades activas;
- perfil intermedio/avanzado;
- inscripción dependiente de la sede;
- requisitos para tomar las clases.

Después usa el mismo carrusel de sedes.

## 7. Carrusel de sedes

Monteverde y Palapas se muestran como **un único carrusel horizontal**, cada tarjeta con su fotografía aprobada y su propio quick reply:

- `meta:venue:monteverde` — Colegio Monteverde;
- `meta:venue:palapas` — Palapas Protudec.

Texto libre no selecciona sede. Un retry muestra únicamente los controles vigentes y no repite fotografías.

## 8. Información de la sede

Después de la selección se muestra:

1. 📍 nombre y ubicación;
2. enlace oficial de Google Maps;
3. referencia breve para llegar;
4. 🕒 horarios activos de mañana y tarde/noche para **esa sede + ese producto**;
5. en intensivo, 📅 “Iniciamos el próximo lunes”;
6. controles **Inscribirme / Ver otra sede**.

En regulares se incluyen mensualidades/inscripción de la sede desde backend/configuración. Monteverde y Palapas nunca mezclan horarios, cuotas o plan.

**Ver otra sede** cambia únicamente la sede y conserva el producto.

## 9. Inscripción

### Intensivo

`meta:register:intensive` reutiliza el Flow, guards, registro y pago existentes. La edad se vuelve a validar antes de cualquier alta real.

### Regulares

`meta:register:regular` abre el Flow específico con sede ya confirmada. Solicita los datos necesarios, perfil intermedio/avanzado, plan y horario activos. El registro se ejecuta de forma protegida y termina en takeover humano para coordinar el pago; no cobra automáticamente.

Cancelar el Flow regular vuelve al bloque de la sede elegida sin perder producto/contexto.

## 10. Edad

La autoridad central es:

- mínima: `sharky_edad_minima`;
- máxima: `sharky_edad_maxima`.

Actualmente el rango comunicado es 12–65 años. El valor mostrado en el saludo y los límites de los Flow deben derivarse de la misma política central.

Fuera de rango no existe alta automática y se deriva a humano.

## 11. Brain y llamadas de IA

Mientras `hache_sharky_meta_active(state)` sea verdadero:

- las políticas laterales antiguas no pueden adelantarse al state machine;
- Brain 2B-A no reescribe la decisión;
- una nota de voz transcrita tampoco debe abrir rutas laterales;
- texto libre no se convierte en producto/sede por inferencia;
- una duda lateral informativa sí puede usar interpretación semántica limitada para contestar, pero esa respuesta no modifica estado ni decisiones y siempre repone el control vigente.

La interpretación lateral es determinística y no llama a Brain conversacional ni a 2B-A. Solo cubre categorías informativas explícitas y datos confirmados por configuración, catálogo, estado o estas reglas. Si falta producto/sede u otra autoridad, la respuesta lo indica sin inventar. Solicitudes operativas o sensibles permanecen fuera de esta capa.

Brain sigue existiendo para recorridos donde esté habilitado, pero no es autoridad de captación en Meta/web/direct.

## 12. Recursos visuales aprobados

- `public/assets/Aprende a nadar en tres semanas.png`
- `public/assets/Clases regulares de natación nocturna.png`
- `public/assets/Sede Monteverde.png`
- `public/assets/SEDE Palapas PROTUDEC.png`

Las fotografías se usan tal como fueron aprobadas; no se reinterpretan.

## 13. No regresión

Considerar el flujo roto si ocurre cualquiera de estos casos:

- Meta, web o directo cae al onboarding de nombre por perfil;
- la fuente real deja de conservarse como `meta_ad`, `web` o `direct`;
- el texto del anuncio/web/directo preselecciona producto o sede;
- texto libre avanza un paso cerrado;
- una duda lateral avanza, cambia producto/sede/plan/horario o pierde el control pendiente;
- una nota de voz evita el lock determinístico;
- un retry repite imágenes;
- las dos opciones visuales dejan de formar un carrusel;
- un quick reply no llega con su ID canónico;
- horarios o precios mezclan sede/producto;
- un alumno conocido entra como prospecto;
- edad fuera de rango crea un alumno;
- regulares cobra automáticamente;
- una respuesta comercial de prospecto rompe la política de 2–5 emojis funcionales.

## 14. Cobertura

Cobertura principal:

- `tests/sharky-meta3-regression.php`;
- `tests/sharky-pr162-review-regression.php`;
- `tests/sharky-guided-first-prospect-regression.php`;
- `tests/sharky-language-guide-regression.php`;
- suites de WhatsApp adapter, outbox, commerce flows, inscripción y pagos;
- Quality completo del PR.
