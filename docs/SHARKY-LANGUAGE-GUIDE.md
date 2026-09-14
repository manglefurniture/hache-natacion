# Sharky — guía lingüística breve

Esta guía documenta expresiones reales de WhatsApp que Sharky puede traducir a una intención canónica antes de entrar a las reglas comerciales.

No es un diccionario general, no corrige todo el español y no sustituye las autoridades determinísticas. Su objetivo es cubrir frases frecuentes, coloquialismos y faltas comunes cuando el significado sea inequívoco.

## Principio

**Lenguaje flexible → intención canónica → reglas determinísticas.**

La capa lingüística puede entender cómo escribe la gente. No decide por sí sola identidad, producto, precio, sede, elegibilidad, inscripción ni pago.

## Sharky 3.0 — Meta, web y WhatsApp directo

Para prospectos nuevos con `entry_source = meta_ad`, `web` o `direct`, el onboarding vigente es un state machine **cerrado**.

Mientras `hache_sharky_meta_active(state)` sea verdadero:

- texto libre no sustituye un botón/quick reply;
- no se canonicaliza “Palapas”, “regulares”, “aprende a nadar”, “sí”, “no” u otra frase como selección del paso;
- la frase se conserva únicamente como texto original para diagnóstico cuando corresponda;
- el paso actual se mantiene y se repiten los controles vigentes sin repetir imágenes;
- una solicitud explícita de humano o la declaración de ser alumno sí puede usar el handoff permitido;
- Brain y las rutas laterales antiguas no reciben autoridad sobre el paso.

Esta regla estricta también protege notas de voz una vez transcritas mediante el lock del worker.

## Fallback por perfil — referrals no publicitarios

El onboarding anterior por perfil se conserva temporalmente para `entry_source = referral` no publicitario. Solo dentro de ese fallback siguen aplicando las canonicalizaciones descritas en las secciones siguientes.

Recorrido histórico:

**nombre → para quién son las clases → edad → nivel → formación solo si Intermedio**.

El texto libre puede equivaler a un botón únicamente cuando el estado actual hace la intención inequívoca. Si no, Sharky conserva el paso y vuelve a preguntar de forma suave.

### Nombre

En `prospect_onboarding → name`, se aceptan formas simples como:

- Roberto;
- Roberto Pérez;
- me llamo Roberto;
- mi nombre es Roberto;
- soy Roberto.

No se aceptan como nombre confirmado respuestas con dominio/URL, correo, números, emojis o contenido que claramente no sea un nombre de persona.

El primer mensaje que abre la conversación no se interpreta como nombre. Sharky formula explícitamente la pregunta.

Antes de consumir texto libre como dato pendiente, Sharky distingue entre **respuesta al paso actual** y **duda lateral informativa**. Esta interpretación nunca autoriza a Brain a completar identidad, edad, nivel, formación, producto o sede por su cuenta.

### ¿Las clases son para ti?

En `prospect_onboarding → participant`, además de los botones Sí / No, pueden entenderse equivalentes claros.

**Sí:** sí, claro, correcto, para mí, son para mí, yo.

**No:** no, no son para mí, es para otra persona, para otra persona.

Un sí/no fuera de este paso no adquiere esta semántica automáticamente.

### Edad

En `prospect_onboarding → age`, una edad numérica inequívoca se canonicaliza al entero correspondiente. Si no se puede obtener una edad válida, no se avanza.

## Nivel de natación del fallback

En `prospect_onboarding → level`:

**Principiante** puede incluir:

- principiante;
- básico / básica;
- desde cero;
- de cero / de ceros;
- nada de nada;
- no sé nadar;
- no ce nadar;
- nunca he nadado;
- no sé flotar;
- quiero aprender a nadar.

**Intermedio:** intermedio / intermedia / nivel intermedio.

**Avanzado:** avanzado / avanzada / nivel avanzado.

Frases abiertas como “nado un poco”, “me defiendo” o “más o menos” no deben promover automáticamente a Avanzado. Si la respuesta no es inequívoca, se conserva el paso.

## Formación previa de Intermedio

La pregunta se hace solo después de que el nivel quedó Intermedio.

**No ha tomado clases:**

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

**Sí ha tomado clases:**

- sí;
- sí he tomado clases;
- tomé clases;
- fui a clases;
- con profesor;
- con entrenador;
- clases formales.

La palabra aislada `nunca` no se canonicaliza globalmente. Solo vale como ausencia de formación cuando el estado confirma esa pregunta.

Avanzado no entra a este paso y no autoriza a afirmar formación formal.

## Información de producto del fallback

Cuando el producto ya quedó resuelto, “ver información” o equivalentes pueden tratarse como intención informativa si el producto activo es inequívoco. No cambian producto.

## Selección de sede del fallback

En `prospect_onboarding → sede` existen:

- `sede:monteverde`;
- `sede:palapas`;
- `sede:both`.

Equivalentes claros de **Ambas ubicaciones**: ambas, las dos, ambas sedes, las dos sedes.

Después de mostrar ambas se necesita una sede concreta. Una mención inequívoca de Monteverde/Palapas puede equivaler al botón únicamente en este fallback; **no en Sharky 3.0 cerrado**.

## Después de sede

Con producto y sede confirmados, plan/horario/fecha y demás opciones se resuelven contra catálogo real. Donde el recorrido lo permita, un texto libre inequívoco puede canonicalizarse a una opción vigente.

Preguntar por más horarios no cambia automáticamente la sede activa. Las alternativas deben provenir del backend y la sede solo cambia con aceptación explícita.

## Recuperación suave

En el fallback por perfil, si una respuesta no puede clasificarse con seguridad:

- no avanzar;
- no borrar datos confirmados;
- no inferir por parecido léxico;
- usar “Una disculpa, no entendí…” y reformular;
- volver a mostrar botones cuando el paso los tenga.

En Sharky 3.0 Meta/web/directo, la recuperación es más estricta: mantener el paso y repetir controles vigentes, sin intentar resolver el contenido libre.

## Preguntas laterales informativas seguras

En un paso cerrado, una pregunta sobre precio, duración, ubicación, horarios, requisitos o diferencias puede contestarse antes de reponer el mismo control. Esta clasificación solo abre una respuesta informativa determinística: nunca equivale a escoger producto, sede, nivel, turno u horario.

Expresiones observadas y anonimizadas como “¿es mensual?”, “¿dónde está?”, “¿qué otra ubicación tienen?” o “¿qué horarios manejan?” conservan el cursor pendiente. Una mención de otra sede dentro de una pregunta sirve solo para responder esa consulta; no cambia la sede activa.

Si la frase pide ejecutar una inscripción, pago, cancelación, reposición o cambio de datos, no pertenece a esta capa. Si la duda es ambigua o falta información confirmada, Sharky lo dice brevemente y vuelve al mismo control sin inferir.

## Cómo crecer esta guía

Cuando aparezca una expresión nueva:

1. anonimizar el caso;
2. comprobar si el recorrido permite texto libre en ese paso;
3. si el paso es cerrado Sharky 3.0, no crear una canonicalización que sustituya el control;
4. si el recorrido permite texto, comprobar que la intención sea inequívoca;
5. añadir regresión automática;
6. no convertir una excepción ambigua en regla general.

El objetivo es entender mejor cómo habla la gente sin debilitar las reglas de negocio.
