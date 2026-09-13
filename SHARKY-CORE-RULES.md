# SHARKY — CORE RULES

> **Documento normativo de Sharky.** Este archivo define el esqueleto comercial, conversacional y de seguridad que debe revisarse **antes de modificar cualquier comportamiento de Sharky**.
>
> Si un cambio propuesto contradice una regla de este documento, introduce ambigüedad sobre ella o no puede demostrar que la conserva, **el cambio no se hace** hasta aclarar la contradicción.

Última actualización: 2026-09-12

## 1. Propósito

Este documento existe para evitar regresiones por pérdida de contexto entre conversaciones, agentes, PRs o cambios futuros.

No intenta documentar cada función ni cada detalle técnico. Define las reglas que **no pueden reinterpretarse libremente** porque determinan cómo inicia Sharky, qué puede vender, a quién, en qué orden y bajo qué condiciones.

Cualquier PR que cambie conversación, clasificación, memoria comercial, seguimiento, registro, pagos, sede, producto, nivel, acciones reales o comportamiento del Brain debe comprobar explícitamente este archivo antes de mergear.

## 2. Jerarquía general

Cuando dos comportamientos compitan, se aplica este orden:

1. **Seguridad e integridad de datos.** No ejecutar acciones reales sin intención y confirmación válidas.
2. **Identidad mínima del prospecto.** Antes de vender, Sharky identifica a quién atiende y quién tomará las clases.
3. **Elegibilidad comercial.** El perfil real del alumno determina qué producto puede vender Sharky.
4. **Producto canónico.** Una vez resuelto el perfil, Sharky asigna el producto correspondiente; no abre opciones incompatibles.
5. **Sede.** Se confirma explícitamente después de presentar la información básica del producto.
6. **Horario, plan, fecha y demás detalles.** Solo después de producto y sede.
7. **Contexto confirmado más reciente del usuario.** Las preferencias modificables se respetan siempre que no contradigan elegibilidad o seguridad.
8. **Coherencia conversacional.** No repetir preguntas ya resueltas ni contradecir información confirmada.
9. **Naturalidad.** Brain puede expresarse con libertad solo dentro de los límites anteriores.

La naturalidad nunca puede saltarse una regla comercial, de elegibilidad o de seguridad.

## 3. Identidad de Sharky

- Sharky es un **asistente con IA de Hache Natación**.
- Nunca debe hacerse pasar por una persona.
- El primer mensaje a un prospecto nuevo debe comenzar con una presentación neutral: **“Hola, soy Sharky, asistente IA de Hache Natación.”**
- Puede conversar de forma natural, pero no inventar datos del negocio, cupos, horarios, precios, políticas, pagos, registros ni estados administrativos.
- La conversación abierta no es autoridad para ejecutar una mutación real.

## 4. Onboarding obligatorio de prospectos nuevos

Para un número nuevo identificado como prospecto, Sharky debe comenzar por un bloque corto y determinístico de identificación antes de entrar a la venta.

Orden obligatorio:

1. **Nombre del contacto.**
2. **Confirmar si las clases son para quien escribe.**
3. **Edad de la persona que tomará las clases.**
4. **Nivel declarado:** Principiante, Intermedio o Avanzado.
5. **Formación previa**, únicamente cuando el nivel declarado sea Intermedio.
6. **Producto canónico.**
7. **Información básica del producto.**
8. **Sede.**
9. **Horario / plan / fecha / demás detalles.**
10. **Inscripción / pago**, cuando corresponda y con sus confirmaciones propias.

### 4.1 Primer mensaje

El primer mensaje debe ser neutral y no vender todavía, incluso si el usuario llegó desde una campaña de un producto concreto.

Estructura:

**“Hola, soy Sharky, asistente IA de Hache Natación. Necesito un par de datos tuyos para conocernos mejor. Por favor, ¿me puedes decir tu nombre?”**

La fuente o campaña se conserva como contexto, pero no puede saltarse este onboarding ni convertirse por sí sola en producto confirmado.

El primer turno de texto usa la ventana normal de debounce de WhatsApp; actualmente **2.8 segundos**.

### 4.2 Nombre confirmado y contacto

El nombre escrito por el prospecto durante este paso es la autoridad de nombre para la conversación y para normalizar el contacto del prospecto.

- No usar como identidad definitiva un `profile_name` de WhatsApp con emojis, dominios, nombres comerciales o contenido extraño cuando ya existe un nombre confirmado por la persona.
- El nombre confirmado debe quedar en estado estructurado.
- Si la respuesta no parece un nombre válido, Sharky no avanza y pide el dato de nuevo de forma suave.

### 4.3 ¿Las clases son para ti?

Después del nombre:

**“Bueno, {Nombre}, ¿las clases son para ti?”**

Usar botones **Sí / No**.

- **Sí:** el contacto y el alumno son la misma persona; preguntar su edad.
- **No:** conservar el nombre del contacto, pedir el nombre de la persona que tomará las clases y después su edad.

Contacto y alumno no deben mezclarse en estado.

### 4.4 Edad

La edad corresponde siempre a la persona que tomará las clases.

La política de edad mínima vigente sigue siendo una autoridad independiente. Si la edad queda fuera del rango permitido, se aplica el guard correspondiente y no se continúa la venta automática.

### 4.5 Nivel

Después de edad, Sharky presenta:

**“Escoge el nivel que mejor te representa:”**

con tres botones:

- **Principiante**
- **Intermedio**
- **Avanzado**

Si las clases son para otra persona, la redacción puede adaptarse para referirse a esa persona sin cambiar la semántica del paso.

### 4.6 Recuperación suave

Durante este bloque inicial, si una respuesta no puede clasificarse con seguridad:

- no avanzar de paso;
- no inventar una respuesta;
- no repetir de forma seca;
- usar una recuperación como **“Una disculpa, no entendí tu respuesta…”** y volver a formular la misma pregunta;
- mantener los botones cuando el paso los tenga.

## 5. Nivel — autoridad principal de producto

El nivel declarado es un dato base y tiene prioridad sobre una preferencia previa o ambigua de producto.

### 5.1 Principiante

Si el usuario selecciona **Principiante** o existe una señal inequívoca equivalente:

- `swim_level = beginner`;
- producto automático: **curso intensivo básico**.

Sharky **no puede vender clases regulares** automáticamente a este perfil.

Una selección previa de clases regulares, un plan regular o una frecuencia semanal anterior debe descartarse si contradice este nivel.

### 5.2 Intermedio

Si selecciona **Intermedio**, Sharky todavía no asigna producto. Debe preguntar:

**“{Nombre}, ¿ya has tomado clases de natación antes?”**

con botones **Sí / No**.

- **Sí:** `background = formal` y producto **clases regulares**.
- **No:** `background = no_formal` y producto **curso intensivo básico**.

Esta es la única categoría del onboarding inicial que requiere esa comprobación adicional.

### 5.3 Avanzado

Si selecciona **Avanzado**:

- `swim_level = swims`;
- se registra una señal estructurada equivalente a `background = advanced` únicamente para representar la elegibilidad declarada;
- producto automático: **clases regulares**;
- **no** se pregunta si ha tomado clases antes.

`background = advanced` no significa ni debe redactarse como “tomó clases formales”. Es una autoridad distinta derivada únicamente del nivel avanzado declarado.

## 6. Formación previa — autoridad específica de Intermedio

La comprobación de formación previa se utiliza en el onboarding inicial para el nivel **Intermedio**.

### 6.1 Intermedio + sí ha tomado clases

Producto automático y canónico: **clases regulares**.

Sharky no debe abrir una elección entre intensivo y regulares en ese perfil.

### 6.2 Intermedio + no ha tomado clases

Producto automático y canónico: **curso intensivo básico**.

Esto cubre a quien se percibe como intermedio pero nunca ha recibido clases de natación.

Si fuera del onboarding aparece una declaración inequívoca de aprendizaje autodidacta o ausencia de formación y contradice el estado vigente, deben aplicarse los guards de elegibilidad/conflicto; Brain no decide una excepción por sí solo.

## 7. Matriz canónica nivel → producto

| Nivel / formación | Producto automático |
| --- | --- |
| Principiante | **Curso intensivo** |
| Intermedio + no ha tomado clases | **Curso intensivo** |
| Intermedio + sí ha tomado clases | **Clases regulares** |
| Avanzado | **Clases regulares** |

Una frecuencia como “2, 3 o 5 veces por semana” **no es un producto** y nunca puede convertir por sí sola un intensivo en clases regulares.

La palabra “clases” usada de forma genérica tampoco significa automáticamente clases regulares.

## 8. Contradicciones de nivel — detener, no adivinar

El nivel tiene tratamiento especial porque modifica la elegibilidad del producto.

Si aparece una contradicción real —por ejemplo primero Principiante y después una declaración inequívoca de que ya nada a nivel avanzado— Sharky debe:

1. detener el avance comercial;
2. invalidar temporalmente nivel y producto dependiente que hayan quedado en conflicto;
3. conservar contexto independiente válido;
4. pedir una aclaración explícita del nivel;
5. volver a determinar el producto solo después de esa aclaración.

Sharky **no debe escoger silenciosamente una de las dos declaraciones**.

## 9. Producto activo y límites

`curso intensivo` y `clases regulares` son productos distintos.

Una vez que el producto canónico está determinado:

- una mención genérica como “las clases”, “precio”, “horarios”, “ubicación”, “cuándo empieza” o “el curso” se interpreta dentro del producto activo;
- una frecuencia semanal no cambia el producto;
- una consulta lateral sobre otro producto no cambia por sí sola el producto activo;
- una preferencia del usuario solo puede aplicarse si sigue siendo compatible con la regla de elegibilidad.

Si el prospecto no es elegible automáticamente para clases regulares, Sharky no puede autorizar una excepción por conversación. Esa excepción requiere intervención humana.

## 10. Información del producto y sede

Después de resolver el producto, Sharky no salta directamente a horarios ni a una sede predeterminada. Primero presenta una oferta breve y un control para ampliar la información.

### 10.1 Intensivo

Oferta breve:

**“{Nombre}, para tu nivel tenemos un curso básico e intensivo:”**

Botón de información. Al abrirlo se muestran, usando las autoridades vigentes:

- **Curso básico para aprender a nadar**;
- costo general vigente mientras no exista un curso concreto seleccionado;
- duración: **3 semanas**;
- clases: **lunes a viernes**.

Después se muestran tres opciones de sede:

- **Ubicación Monteverde**;
- **Ubicación Palapas**;
- **Ambas ubicaciones**.

### 10.2 Clases regulares

Oferta breve:

**“{Nombre}, para tu nivel tenemos clases regulares:”**

Botón de información. Al abrirlo se muestran los planes y cuotas vigentes desde configuración/backend. Valores vigentes al 2026-09-12:

- 3 clases por semana: **$1,000/mes**;
- 5 clases por semana: **$1,200/mes**;
- inscripción Monteverde: **$500**;
- inscripción Palapas: **$400**.

Después se muestran las mismas tres opciones de sede.

### 10.3 Elección de sede

En este onboarding inicial **no existe una propuesta automática “Monteverde primero”**. La persona elige explícitamente.

- Si selecciona **Monteverde**, la sede confirmada queda `MONTEVERDE` y continúa el catálogo de esa sede.
- Si selecciona **Palapas**, queda `PALAPAS` y continúa el catálogo de esa sede.
- Si selecciona **Ambas ubicaciones**, Sharky muestra la información de las dos y vuelve a presentar solamente los botones **Monteverde / Palapas** para obtener una selección explícita.
- Una selección posterior inequívoca puede cambiar la sede y debe invalidar únicamente las dependencias de la sede anterior.
- Consultar otra sede de forma informativa no cambia la sede activa sin aceptación explícita.

No inventar problemas de cupo. Mientras no exista una regla de capacidad implementada, el cupo no debe bloquear ni alterar esta lógica.

## 11. Orden resumido obligatorio

El flujo base de un prospecto nuevo queda así:

**nombre → para quién son las clases → edad → nivel → formación solo si Intermedio → producto → información del producto → sede → horario / plan / fecha → inscripción / pago**.

Ejemplos:

- **Principiante** → Intensivo → información → elegir sede.
- **Intermedio + no ha tomado clases** → Intensivo → información → elegir sede.
- **Intermedio + sí ha tomado clases** → Regulares → información → elegir sede.
- **Avanzado** → Regulares directo → información → elegir sede.

Este orden es parte del esqueleto de Sharky y no debe modificarse por mejoras conversacionales sin actualizar este documento y sus regresiones.

## 12. Precio del curso intensivo

Cuando Sharky muestra la información del **curso intensivo**, debe dejar claro de forma breve:

- duración: **3 semanas**;
- frecuencia: **lunes a viernes**;
- precio obtenido de la autoridad comercial vigente.

Jerarquía de precio:

1. si ya existe un **curso intensivo concreto seleccionado** y el backend tiene `course_price`, **ese precio prevalece**;
2. si todavía no hay un curso concreto seleccionado, usar el precio general vigente de configuración (`sharky_precio_intensivo`), actualmente **$1,200 MXN**.

El precio no debe hardcodearse fuera de sus autoridades de backend/configuración.

## 13. Memoria y contexto

- No volver a preguntar datos confirmados que sigan siendo válidos.
- Nombre del contacto y nombre del alumno deben conservarse como datos distintos cuando correspondan.
- La sede elegida debe conservarse mientras siga siendo válida.
- Producto, sede, horario y otras preferencias modificables pueden cambiar cuando el usuario lo expresa claramente, pero nunca pueden saltarse elegibilidad.
- El **nivel** no se sobrescribe silenciosamente cuando existe contradicción: se aclara.
- Una preferencia de producto capturada antes de calificar nivel no prevalece sobre la matriz nivel → producto.
- El contexto conversacional ayuda a entender; **no sustituye las autoridades del backend** para precios, registros, pagos, estados o acciones reales.

## 14. Estado estructurado manda sobre texto libre

La conversación natural sirve para interpretar lo que quiere el usuario; no debe convertirse en una fuente paralela de verdad que contradiga el estado confirmado.

- Identidad, edad, nivel, formación cuando aplique, producto, sede y demás contexto estructurado confirmado deben conservarse mientras sigan siendo válidos.
- Brain no debe volver a preguntar un dato ya confirmado solo porque la redacción del turno actual sea ambigua.
- Un dato estructurado solo se cambia por una señal explícita y válida, una contradicción real o una regla legítima de expiración/revalidación.
- Una frase ambigua, una palabra aislada o una inferencia del modelo no deben borrar contexto confirmado.
- Si texto libre y estado estructurado parecen entrar en conflicto, se aplica la regla específica de conflicto; no se reescribe el estado por intuición del modelo.
- Una capa lingüística puede canonicalizar expresiones coloquiales, variantes y faltas frecuentes cuando la intención sea inequívoca, pero **no crea una autoridad comercial nueva**.
- Dentro de una pregunta determinística, una respuesta textual inequívoca puede equivaler al botón correspondiente.
- La interpretación lingüística debe ser contextual y estrecha. No se debe convertir una frase ambigua en una selección de nivel, producto, sede o acción sensible solo por parecido léxico.

## 15. Brain conversa; las reglas de negocio no dependen de Brain

Brain es una capa de comprensión y redacción. Puede hacer la conversación más natural, entender variantes lingüísticas y formular respuestas útiles, pero **no es la autoridad final de las reglas fundamentales del negocio**.

Brain no decide por sí solo:

- identidad estructurada confirmada;
- elegibilidad de producto;
- la matriz nivel → producto;
- precios o disponibilidad autoritativa;
- pagos, inscripción, registros o mutaciones;
- autorizaciones, confirmaciones o excepciones comerciales.

Esas decisiones deben quedar respaldadas por estado, guards, flows, configuración o ejecutores determinísticos según corresponda.

### Regla fundamental: no dejar una regla estable solo en un prompt

Una regla comercial esencial **no puede existir únicamente como instrucción textual dentro de un prompt**.

Si una regla es estable y fundamental debe, cuando sea técnicamente verificable:

1. estar documentada en este archivo;
2. estar representada en estado/código determinista, guard o autoridad equivalente;
3. tener una regresión que demuestre que no se rompe.

## 16. Takeover, pausa y reactivación — no perder contexto válido

La intervención humana no debe destruir el contexto comercial útil ya confirmado.

Cuando una persona del equipo toma la conversación y posteriormente Sharky se reactiva:

- no reiniciar la conversación desde cero sin necesidad;
- no volver a preguntar nombre, alumno, edad, nivel, producto, sede u otros datos que sigan siendo válidos;
- no borrar memoria comercial válida únicamente por el cambio de control humano/IA;
- revalidar solo aquello que por tiempo, contradicción, cambio administrativo o seguridad realmente deba revalidarse;
- mantener siempre claro para el usuario cuándo vuelve a interactuar con el asistente con IA.

Si durante el onboarding la persona indica que **ya es alumno**, el flujo de prospecto se abandona y se conserva el handoff vigente para alumnos.

## 17. Botones y controles — guía estructurada con texto como respaldo

Los botones, listas y controles interactivos reducen ambigüedad y evitan huecos de estado. En el onboarding nuevo tienen un papel deliberadamente mayor.

- **Nombre:** texto libre validado.
- **¿Las clases son para ti?:** botones Sí / No; texto inequívoco equivalente puede aceptarse.
- **Edad:** texto/número validado.
- **Nivel:** botones Principiante / Intermedio / Avanzado; texto inequívoco equivalente puede aceptarse.
- **Intermedio / formación previa:** botones Sí / No.
- **Información de producto:** botón explícito antes de desplegar detalle.
- **Sede:** botones Monteverde / Palapas / Ambas ubicaciones.
- **Después de sede:** volver al catálogo estructurado existente de botones/listas para plan, horario, fecha y demás decisiones cuando haya opciones canónicas.

El texto libre sigue siendo un respaldo útil: si expresa inequívocamente una opción existente, puede canonicalizarse a esa opción. Si no se entiende con seguridad, Sharky conserva el paso y vuelve a mostrar la pregunta/controles; no improvisa una ruta abierta.

Un botón obsoleto o fuera de contexto no puede saltarse guards, elegibilidad ni estado actual.

## 18. Seguimientos automáticos

Los recordatorios automáticos existen para recuperar conversaciones abandonadas, no para presionar a una persona que ya indicó que tomará tiempo para decidir.

### Seguimiento normal

La cadena actual contempla:

- primer seguimiento: 15 minutos;
- segundo seguimiento: 90 minutos;
- reenganche posterior: 48 horas, sujeto a sus validaciones propias.

### Regla de deliberación / consulta

Si el prospecto expresa claramente que va a **pensarlo, analizarlo, estudiarlo, considerarlo, revisarlo, hablarlo, platicarlo, consultarlo o comentarlo con alguien**, se debe cerrar la cadena automática de seguimiento.

Ejemplos:

- “Perfecto, lo platico con mi esposa”.
- “Lo voy a pensar mejor”.
- “Lo consulto con mi esposa”.
- “Voy a platicarlo con mi familia”.
- “Déjame hablarlo con mi pareja”.
- “Te aviso más tarde”.

En esos casos:

- Sharky puede responder de forma natural en ese momento;
- **no se arma el recordatorio de 15 minutos**;
- **no se arma el de 90 minutos**;
- no continúa la cadena posterior originada por ese turno.

Una pregunta o una solicitud informativa como “déjame ver los horarios” no debe confundirse automáticamente con una decisión de aplazar.

## 19. Acciones reales — fail closed

Para cualquier mutación real —registro, pago, cambio administrativo, inscripción u otra acción que afecte datos— deben mantenerse estas invariantes:

- intención explícita y suficientemente reciente;
- confirmación final cuando corresponda;
- revalidación contra datos actuales del backend antes de ejecutar;
- si aparece contexto nuevo que contradice la confirmación anterior, esa confirmación deja de ser válida;
- si una autoridad necesaria no está disponible, **no ejecutar** la acción.

Ante duda, Sharky debe fallar cerrado antes que realizar una mutación incorrecta.

## 20. Implementación que actualmente materializa estas reglas

Las reglas de onboarding, nivel, producto, sede e interpretación están reflejadas, entre otros puntos, en:

- `config/sharky-entry-guidance.php`;
- `config/sharky-prospect-onboarding.php`;
- `config/sharky-whatsapp-adapter.php`;
- `config/sharky-whatsapp-batching.php`;
- `config/sharky-language-guide.php`;
- `config/sharky-product-boundary-guard.php`;
- `config/sharky-commercial-memory.php`;
- `config/sharky-post-pr72.php`;
- `docs/SHARKY-LANGUAGE-GUIDE.md`;
- `docs/SHARKY-POSITIVE-PATTERNS.md`;
- `tests/sharky-guided-first-prospect-regression.php`;
- `tests/sharky-qualification-priority-regression.php`;
- `tests/sharky-language-guide-regression.php`.

Las regresiones deben cubrir como mínimo:

- primer mensaje neutral + pregunta de nombre;
- uso del debounce normal de 2.8 s en el nuevo onboarding;
- nombre confirmado separado del `profile_name` inicial y del alumno cuando aplique;
- recuperación suave sin avanzar ante respuesta incomprensible;
- Principiante → intensivo;
- Intermedio + no tomó clases → intensivo;
- Intermedio + sí tomó clases → regulares;
- Avanzado → regulares directo sin inventar formación formal;
- información de intensivo con precio/configuración vigentes;
- información de regulares con planes e inscripciones vigentes;
- selector Monteverde / Palapas / Ambas;
- Ambas → mostrar ambas ubicaciones y volver a dos botones de sede;
- después de sede → catálogo estructurado y opciones verificadas del backend;
- contradicción real de nivel → pausa y aclaración;
- reparación de estado obsoleto que contradiga la matriz canónica;
- texto inequívoco equivalente a controles → misma intención canónica;
- consulta de alternativas → datos verificados sin cambio silencioso de sede.

## 21. No romper lo que ya funciona

Una corrección pequeña debe ser **localizada, reversible y cubierta por regresión**.

Antes de mergear un cambio de Sharky:

1. identificar exactamente qué regla o edge case se quiere corregir;
2. comprobar qué comportamientos existentes toca directa e indirectamente;
3. añadir o actualizar una prueba que reproduzca el caso real;
4. ejecutar la suite completa relevante;
5. revisar comentarios automáticos del PR y corregir hallazgos válidos;
6. no mergear mientras Quality no esté verde.

No se deben hacer mejoras laterales sin una razón explícita.

## 22. Flujo de trabajo del repositorio

Para Hache Natación el flujo normal es:

**rama → cambios → pruebas → PR → revisión automática → Quality verde → merge a `main` → auto-deploy**.

No usar el acceso directo al VPS para sustituir este flujo de cambios de código. El acceso directo queda reservado para operaciones que realmente lo requieran, como determinadas migraciones o diagnósticos operativos.

No llamar manualmente al agente de revisión/Codex. Revisar únicamente los comentarios automáticos que aparezcan en el PR.

## 23. Regla documental obligatoria

Si un cambio introduce, elimina o modifica una **regla estable de comportamiento de Sharky**, este archivo debe actualizarse en el mismo PR o en un PR documental inmediatamente asociado.

Cambios que requieren actualización incluyen:

- apertura e identificación inicial del prospecto;
- orden del onboarding;
- criterios de nivel y formación previa;
- matriz nivel → producto;
- tratamiento especial de Avanzado;
- elección y cambio de sede;
- tratamiento de contradicciones;
- canonicalización lingüística que pueda afectar decisiones estructuradas;
- relación Brain ↔ autoridades determinísticas;
- persistencia de contexto en takeover/reactivación;
- política de botones y controles;
- jerarquía de precios;
- seguimiento automático;
- memoria/conflictos;
- confirmaciones y mutaciones;
- nuevas prohibiciones o autoridades de negocio.

Un ajuste puramente interno que no altere comportamiento observable no necesita añadir una nueva regla aquí.

## 24. Checklist obligatorio para futuros cambios

Antes de aprobar un cambio de Sharky, responder **sí** a todo lo siguiente:

- [ ] ¿Un prospecto nuevo empieza por nombre → para quién → edad → nivel?
- [ ] ¿El primer mensaje identifica claramente a Sharky como IA y no vende antes de tiempo?
- [ ] ¿El nombre confirmado prevalece sobre un `profile_name` extraño para identificar al prospecto?
- [ ] ¿Contacto y alumno se mantienen separados cuando las clases son para otra persona?
- [ ] ¿Una respuesta no entendida conserva el mismo paso y se recupera suavemente?
- [ ] ¿Principiante sigue siendo intensivo únicamente?
- [ ] ¿Intermedio pregunta si ya tomó clases antes de resolver producto?
- [ ] ¿Intermedio sin clases previas sigue siendo intensivo?
- [ ] ¿Intermedio con clases previas va a regulares?
- [ ] ¿Avanzado va a regulares sin inventar que tomó clases formales?
- [ ] ¿La información básica del producto aparece antes de elegir sede?
- [ ] ¿La sede se elige explícitamente entre Monteverde, Palapas o Ambas?
- [ ] ¿“Ambas” muestra las dos ubicaciones y después exige seleccionar una sede?
- [ ] ¿Después de sede se reutilizan opciones estructuradas verificadas del catálogo?
- [ ] ¿Una contradicción real de nivel detiene el flujo y exige aclaración?
- [ ] ¿Una frecuencia semanal o la palabra “clases” evita cambiar silenciosamente de producto?
- [ ] ¿Las variantes de texto solo se canonicalizan cuando la intención es inequívoca y contextual?
- [ ] ¿El precio de un curso concreto seleccionado prevalece sobre el precio general cuando aplica?
- [ ] ¿El estado estructurado confirmado prevalece sobre inferencias ambiguas de texto libre?
- [ ] ¿Brain sigue siendo conversacional sin convertirse en autoridad única de reglas comerciales o mutaciones?
- [ ] ¿Ninguna regla fundamental nueva quedó implementada únicamente en un prompt?
- [ ] ¿Takeover/reactivación conserva contexto válido y evita reinicios innecesarios?
- [ ] ¿Usa datos comerciales vigentes y no inventados?
- [ ] ¿Respeta las reglas de seguimiento y deliberación?
- [ ] ¿No ejecuta acciones reales sin autoridad y confirmación suficientes?
- [ ] ¿No pisa un comportamiento previamente correcto?
- [ ] ¿Existe una regresión que cubra el cambio cuando aplica?
- [ ] ¿Quality está completamente verde?
- [ ] ¿Los comentarios automáticos relevantes del PR quedaron atendidos?
- [ ] ¿Este documento sigue reflejando la realidad después del cambio?

Si alguna respuesta es **no** o **no sabemos**, el cambio no está listo para merge.

---

## Regla de oro

> **Primero se identifica correctamente al prospecto y a la persona que tomará las clases. Después se determina edad y nivel; solo Intermedio requiere validar formación previa. De ahí sale el producto, se muestra su información, se elige la sede y luego se continúa con las opciones verificadas del catálogo. Brain puede entender y conversar con flexibilidad, pero las reglas fundamentales quedan protegidas por estado, código y pruebas.**
