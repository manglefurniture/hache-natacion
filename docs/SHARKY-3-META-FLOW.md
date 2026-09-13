# Sharky 3.0 — flujo determinístico para prospectos desde publicidad Meta

Estado: **diseño aprobado para implementación; PR sin merge hasta validación final**.

Fecha de definición: 2026-09-13.

## 1. Alcance

Esta versión aplica únicamente a conversaciones de WhatsApp que cumplen simultáneamente:

- el contacto no está identificado como alumno actual;
- la fuente de entrada es un anuncio de Meta (Facebook/Instagram), detectado por el referral/`ctwa_clid` existente;
- la conversación es de captación comercial.

No se reutiliza automáticamente este recorrido para:

- prospectos que llegan desde la página web;
- personas que escriben directamente al WhatsApp sin referral de publicidad;
- alumnos actuales.

Los flujos de **web** y **WhatsApp directo** se definirán por separado. Un alumno conocido que pulse un anuncio sigue siendo alumno y pasa a atención humana; el anuncio nunca degrada su identidad a prospecto.

## 2. Principio de Sharky 3.0

La captación desde Meta deja de depender de Brain para interpretar libremente el texto. Es un recorrido determinístico y guiado por controles de WhatsApp.

Reglas:

1. Sharky se identifica siempre como asistente IA.
2. Las decisiones comerciales del flujo se realizan con botones/Flow, no por inferencia de texto libre.
3. Si el usuario escribe cuando el paso espera un botón, el texto no consume ni cambia el paso.
4. Sharky responde de forma amable y vuelve a mostrar **solo los botones vigentes**.
5. Las imágenes se muestran únicamente la primera vez que se abre el bloque visual correspondiente; un reintento muestra texto + botones, sin repetir la imagen.
6. Solicitar una persona, declarar que ya es alumno o quedar fuera del rango de edad produce **takeover humano**.
7. Tras varios intentos inválidos se usa la autoridad existente `sharky_escalado_intentos` para ofrecer/activar atención humana; no se abre conversación libre para adivinar intención.
8. Precios, horarios, inscripciones, Maps y demás datos comerciales salen de backend/configuración; no se duplican como una nueva fuente de verdad.

## 3. Entrada desde publicidad

Primer bloque visual, una sola vez:

**Hola, soy Sharky, asistente IA de Hache Natación.**

**Importante: nuestras clases están dirigidas únicamente a personas de 12 a 65 años.**

**¿Qué opción buscas?**

Botones:

- **Aprende a nadar**
- **Clases regulares**

Descripción conceptual:

- Aprende a nadar → curso básico de 3 semanas.
- Clases regulares → niveles intermedio y avanzado.

Si el usuario escribe en vez de tocar un botón:

> Para orientarte mejor, elige una de estas opciones 👇

Se vuelven a mostrar únicamente los botones, sin repetir la fotografía.

## 4. Ruta 1 — Aprende a nadar

Al seleccionar **Aprende a nadar**, Sharky muestra:

**Curso básico para aprender a nadar**

- **Duración:** 3 semanas.
- **Clases:** de lunes a viernes, todos los días.
- **Precio total (todo el curso):** valor vigente de `sharky_precio_intensivo` (actualmente $1,200 MXN), salvo que exista un curso concreto con precio propio.
- **Dirigido a:** personas que empiezan desde cero o nunca han tomado clases de natación.
- **Tu lugar queda confirmado una vez realizado el pago.**

**No es mensualidad:** el precio cubre las 3 semanas completas del curso. **Para este curso no se paga inscripción; únicamente el costo del curso.**

**Para tomar las clases necesitas:**

- traje de baño cómodo para moverte en el agua;
- no debe ser de algodón ni mezclilla;
- gorro de natación;
- goggles para natación.

### 4.1 Selección de sede

Después de la información del producto se abre un bloque visual de sedes, una sola vez, con Monteverde y Palapas.

Botones:

- **Monteverde**
- **Palapas**

Si el usuario escribe en vez de escoger, Sharky responde de forma amable y repite solamente los dos botones, sin volver a enviar las fotografías.

### 4.2 Información de la sede elegida

Orden obligatorio:

1. nombre de la sede;
2. ubicación escrita;
3. enlace oficial de Google Maps;
4. referencia breve para llegar;
5. horarios activos divididos en **MATUTINOS** y **VESPERTINOS**;
6. frase fija **“Iniciamos el próximo lunes.”**;
7. controles de continuación.

Las horas se consultan desde `horarios` filtrando `sede + activo + intensivo`; no se hardcodean en este flujo.

#### Monteverde — referencia para llegar

> La alberca se encuentra al final del estacionamiento del colegio. No necesitas entrar a la escuela; solo ingresa al estacionamiento por Av. Bonampak. Puedes utilizar el estacionamiento durante tu clase.

Google Maps: autoridad `sharky_maps_monteverde`.

#### Palapas — referencia para llegar

> Entra por calle Alcatraces, viniendo desde Av. Cobá, por la zona del IMSS. Estamos aproximadamente a 100 metros del Parque de las Palapas.

Google Maps: autoridad `sharky_maps_palapas`.

Controles:

- **Inscribirme** → reutiliza el Flow existente de inscripción al intensivo y su proceso de pago.
- **Ver otra sede** → muestra directamente la información de la otra sede sin reiniciar el recorrido ni repetir la información general del curso.

## 5. Ruta 2 — Clases regulares

Al seleccionar **Clases regulares**, antes de mostrar planes se valida la formación previa con una sola pregunta:

**¿Ya has tomado clases de natación anteriormente, en alguna escuela?**

Botones:

- **Sí, continuar** → continúa a clases regulares.
- **No, curso básico** → lleva directamente a la información definida en la Ruta 1, sin volver al menú inicial.

El texto libre no responde esta pregunta: solo los controles vigentes hacen avanzar el estado.

### 5.1 Información de clases regulares

**Clases regulares de natación**

- **Modalidad:** clases continuas por mensualidad.
- **Plan 3x (3 clases por semana):** valor vigente de `sharky_precio_regular_3` (actualmente $1,000 MXN al mes).
- **Plan 5x (5 clases por semana):** valor vigente de `sharky_precio_regular_5` (actualmente $1,200 MXN al mes).
- **Dirigido a:** personas que ya han tomado clases de natación y tienen nivel intermedio o avanzado.
- **Estos planes llevan un pago de inscripción, cuyo costo depende de la sede que elijas.**

**Para tomar las clases necesitas:**

- traje de baño cómodo para moverte en el agua;
- no debe ser de algodón ni mezclilla;
- gorro de natación;
- goggles para natación.

Después se usa el mismo selector visual de **Monteverde / Palapas**.

### 5.2 Sede para regulares

El bloque de sede conserva el mismo orden de ubicación, Maps, referencia y horarios, pero usa los horarios con `regular=1` y añade la inscripción correspondiente:

- Monteverde → `sharky_inscripcion_monteverde` (actualmente $500 MXN).
- Palapas → `sharky_inscripcion_palapas` (actualmente $400 MXN).

No se repiten aquí los precios mensuales ni los requisitos ya mostrados.

Controles:

- **Inscribirme** → abre un nuevo WhatsApp Flow específico para clases regulares.
- **Ver otra sede** → muestra la otra sede sin reiniciar.

## 6. Flow de inscripción de clases regulares

El Flow nuevo será corto y recibirá la sede ya confirmada para no volver a preguntarla.

Datos mínimos:

- nombre completo;
- fecha de nacimiento / edad validada entre 12 y 65 años;
- plan 3x o 5x;
- horario activo de la sede elegida;
- confirmación de que corresponde a nivel intermedio/avanzado.

Al completar el formulario:

- se conserva la información estructurada para la inscripción;
- no se inicia cobro automático todavía;
- Sharky hace **takeover humano** para que el equipo ajuste y cierre el pago.

Hasta definir el contrato transaccional final de regulares, el Flow no debe inventar mensualidades, descuentos, cupos ni estados administrativos.

## 7. Edad

Autoridad comercial nueva:

- mínima: 12 años (`sharky_edad_minima`);
- máxima: 65 años (debe centralizarse como `sharky_edad_maxima`).

El rango se informa desde el primer mensaje. La validación efectiva debe repetirse en el Flow de inscripción. Si el dato confirmado queda fuera de rango, Sharky no continúa el alta automática y activa takeover humano.

## 8. Brain

El experimento Brain se retira del recorrido comercial vivo de esta versión.

- Brain no interpreta las selecciones de este funnel.
- Brain no responde preguntas laterales dentro de pasos cerrados.
- Brain no puede convertir texto libre en producto, sede, plan o inscripción.
- El deploy no debe reactivarlo automáticamente.
- El código histórico puede permanecer temporalmente como referencia/rollback mientras no exista un camino que lo active en producción.

## 9. Datos existentes que se reutilizan

No crear fuentes paralelas para información ya existente:

- referral Meta y `ctwa_clid`;
- identidad alumno/prospecto;
- `sharky_precio_intensivo`;
- `sharky_precio_regular_3`;
- `sharky_precio_regular_5`;
- `sharky_inscripcion_monteverde`;
- `sharky_inscripcion_palapas`;
- `sharky_maps_monteverde`;
- `sharky_maps_palapas`;
- tabla `horarios`, diferenciando `intensivo` y `regular`;
- Flow/ejecutores existentes de intensivo;
- takeover humano e inbox;
- atribución first-touch/latest-touch.

## 10. Recursos visuales pendientes

Antes de considerar el PR listo para merge se necesitan los recursos finales:

1. imagen inicial del selector **Aprende a nadar / Clases regulares**;
2. fotografía de **Monteverde**;
3. fotografía de **Palapas**.

Las fotografías deben conservarse como imágenes reales; no necesitan regeneración. Para el selector de sedes se puede preparar un único recurso visual con ambas fotografías si eso permite mantener un solo mensaje interactivo nativo con dos botones.

## 11. Criterios de no regresión

- Un alumno conocido jamás entra en este funnel aunque llegue desde un anuncio.
- Un referral Meta se conserva para atribución.
- Web y WhatsApp directo no heredan este funnel hasta que sus versiones sean definidas.
- Escribir texto durante un paso cerrado nunca hace avanzar el estado.
- Reintentar un paso no vuelve a enviar su imagen.
- `No, curso básico` nunca deja activo el producto regular.
- Horarios de intensivo y regular no se mezclan.
- Cambiar de sede invalida únicamente dependencias de la sede anterior.
- Edad fuera de 12–65 termina en takeover.
- Intensivo conserva sus guards de inscripción/pago.
- Regulares terminan en takeover después del Flow hasta que el cierre de pago sea definido.
- Ningún dato comercial estable depende de un prompt de IA.
