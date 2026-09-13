# Sharky — guía lingüística breve

Esta guía documenta expresiones reales de WhatsApp que Sharky puede traducir a una intención canónica antes de entrar a las reglas comerciales.

No es un diccionario general, no corrige todo el español y no sustituye Brain. Su objetivo es cubrir frases frecuentes, coloquialismos y faltas comunes cuando el significado es inequívoco.

## Principio

**Lenguaje flexible → intención canónica → reglas determinísticas.**

La capa lingüística puede entender cómo escribe la gente. No puede decidir por sí sola identidad, producto, precio, sede, elegibilidad, inscripción ni pago.

## Onboarding inicial

El onboarding de un prospecto nuevo sigue estos pasos estructurados:

**nombre → para quién son las clases → edad → nivel → formación solo si Intermedio**.

El texto libre puede equivaler a un botón únicamente cuando el estado actual hace la intención inequívoca. Si no, Sharky conserva el paso y vuelve a preguntar de forma suave.

### Nombre

En el paso `prospect_onboarding → name`, se aceptan formas simples como:

- Roberto;
- Roberto Pérez;
- me llamo Roberto;
- mi nombre es Roberto;
- soy Roberto.

No se deben aceptar como nombre confirmado respuestas con dominio/URL, correo, números, emojis o contenido que claramente no sea un nombre de persona.

El primer mensaje del usuario que abre la conversación no se interpreta como nombre. Sharky primero formula explícitamente la pregunta.

### ¿Las clases son para ti?

En `prospect_onboarding → participant`, además de los botones **Sí / No**, pueden entenderse equivalentes claros como:

**Sí**:

- sí;
- claro;
- correcto;
- para mí;
- son para mí;
- yo.

**No**:

- no;
- no son para mí;
- es para otra persona;
- para otra persona.

Un `sí` o `no` fuera de este paso no debe adquirir esta semántica automáticamente.

### Edad

En `prospect_onboarding → age`, una edad numérica inequívoca se canonicaliza al entero correspondiente. Si no se puede obtener una edad válida, no se avanza.

## Nivel de natación

La pregunta canónica presenta botones **Principiante / Intermedio / Avanzado**.

En `prospect_onboarding → level`, estas expresiones pueden equivaler a **Principiante**:

- principiante;
- básico / básica;
- desde cero;
- de cero / de ceros;
- en cero / en ceros;
- nada de nada;
- no sé nadar;
- no ce nadar;
- nunca he nadado;
- no sé flotar;
- quiero aprender a nadar.

Pueden equivaler a **Intermedio** cuando la persona lo expresa de forma inequívoca:

- intermedio / intermedia;
- nivel intermedio.

Pueden equivaler a **Avanzado**:

- avanzado / avanzada;
- nivel avanzado.

Expresiones abiertas como “nado un poco”, “me defiendo” o “más o menos” no deben promover automáticamente a Avanzado ni resolver por sí solas una clasificación dudosa. Si el paso exige una de las tres categorías y la respuesta no es inequívoca, Sharky conserva el paso y vuelve a mostrar la pregunta/controles.

## Formación previa de Intermedio

La pregunta de formación previa se hace **solo después de que el nivel quedó Intermedio**.

En `prospect_onboarding → intermediate_background`:

**No ha tomado clases** puede expresarse como:

- no;
- nunca;
- nunca he tomado clases;
- no he tomado clases;
- aprendí solo / sola;
- por mi cuenta;
- nadie me enseñó;
- sin profesor;
- sin entrenador;
- autodidacta.

**Sí ha tomado clases** puede expresarse como:

- sí;
- sí he tomado clases;
- tomé clases;
- fui a clases;
- con profesor;
- con entrenador;
- clases formales.

La palabra aislada `nunca` no se canonicaliza globalmente. Solo es inequívoca como ausencia de formación cuando el estado confirma que Sharky está haciendo esa pregunta.

**Avanzado no entra a este paso.** Seleccionar Avanzado permite clases regulares directamente, pero no autoriza a Sharky a afirmar que la persona tuvo formación formal.

## Información de producto

Cuando el producto ya quedó resuelto, el botón de información es la ruta preferente para desplegar el detalle. Frases libres como “ver información”, “dame información” o equivalentes pueden tratarse como intención informativa únicamente si el producto activo ya está inequívocamente resuelto; no deben utilizarse para cambiar de producto.

## Selección de sede

En `prospect_onboarding → sede` existen tres intenciones canónicas:

- `sede:monteverde` → Monteverde;
- `sede:palapas` → Palapas;
- `sede:both` → mostrar ambas ubicaciones, sin confirmar todavía una sede.

Equivalentes textuales claros de **Ambas ubicaciones**:

- ambas;
- ambas ubicaciones;
- las dos;
- las dos ubicaciones;
- ambas sedes;
- las dos sedes.

Después de mostrar ambas, Sharky vuelve a pedir una elección entre Monteverde y Palapas. En ese momento “ambas” ya no debe avanzar: se necesita una sede concreta.

Una mención inequívoca de “Monteverde” o “Palapas” puede equivaler al botón correspondiente cuando el paso activo es sede.

## Después de sede

Con producto y sede confirmados, plan/horario/fecha y demás opciones deben resolverse contra el catálogo real. Los botones/listas son la interfaz preferente, pero un texto libre inequívoco que coincida con una opción vigente puede canonicalizarse a esa misma selección.

Si el usuario pregunta por **más horarios** sin nombrar otra sede, Sharky puede consultar alternativas verificadas sin cambiar automáticamente la sede activa.

Ejemplos:

- ¿No tienes más horario?;
- ¿Hay otro horario?;
- ¿Tienen otros horarios?;
- ¿Manejan más horarios?;
- ¿Algún horario diferente?

Si el turno anterior dejó claro un periodo —por ejemplo mañana/matutino—, la consulta alternativa conserva ese periodo.

La respuesta debe usar únicamente horarios verificados del backend. Consultar otra sede **no cambia la sede seleccionada** hasta que el usuario la acepte de forma explícita.

## Recuperación suave

Si una respuesta no puede clasificarse con seguridad dentro de un paso controlado, la salida preferida es:

**“Una disculpa, no entendí…” + reformulación de la pregunta activa.**

Reglas:

- no avanzar;
- no borrar datos confirmados;
- no inferir una opción por parecido léxico;
- volver a mostrar botones cuando ese paso los tenga.

## Cómo crecer esta guía

Cuando aparezca una expresión nueva en una conversación real:

1. anonimizar el caso;
2. comprobar que la intención sea inequívoca;
3. añadir la variante a la capa lingüística solo si no introduce falsos positivos razonables;
4. añadir una regresión automática;
5. no convertir una excepción ambigua en regla general.

El objetivo es que Sharky entienda mejor cómo habla la gente sin hacer más débiles las reglas de negocio.
