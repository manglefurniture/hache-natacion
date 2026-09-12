# Sharky — guía lingüística breve

Esta guía documenta expresiones reales de WhatsApp que Sharky puede traducir a una intención canónica antes de entrar a las reglas comerciales.

No es un diccionario general, no corrige todo el español y no sustituye Brain. Su objetivo es cubrir frases frecuentes, mexicanismos/coloquialismos y faltas comunes cuando el significado es inequívoco.

## Principio

**Lenguaje flexible → intención canónica → reglas determinísticas.**

La capa lingüística puede entender cómo escribe la gente. No puede decidir por sí sola producto, precio, sede, elegibilidad, inscripción ni pago.

## Nivel de natación

Cuando Sharky está preguntando si la persona sabe nadar, estas expresiones pueden equivaler a **Desde cero**:

- de cero / de ceros;
- desde cero / desde ceros;
- en cero / en ceros;
- cero;
- nada de nada;
- no sé nadar;
- no ce nadar;
- no sé nada de nadar;
- nunca he nadado;
- no sé flotar;
- quiero aprender a nadar;
- quiero aprender a nadar y flotar.

Estas expresiones pueden equivaler a **Ya sé nadar**, pero todavía requieren preguntar por formación formal:

- nado un poco;
- nado perrito;
- me defiendo;
- me defiendo en el agua;
- me mantengo a flote;
- sé flotar;
- ya sé nadar.

## Formación previa

Cuando Sharky ya confirmó que la persona sabe nadar:

**Por mi cuenta / sin formación formal** puede expresarse como:

- aprendí solo / sola;
- por mi cuenta;
- nadie me enseñó;
- sin profesor;
- sin entrenador;
- nunca he tomado clases;
- no he tomado clases;
- autodidacta.

**He tomado clases** puede expresarse como:

- sí he tomado clases;
- tomé clases;
- fui a clases;
- con profesor;
- con entrenador;
- clases formales.

## Horarios y sede alternativa

Si ya existe un producto y una sede confirmados y el usuario pregunta por **más horarios** sin nombrar otra sede, Sharky puede interpretar la pregunta como una búsqueda informativa de alternativas en la otra sede, sin cambiar automáticamente la sede activa.

Ejemplos:

- ¿No tienes más horario?;
- ¿Hay otro horario?;
- ¿Tienen otros horarios?;
- ¿Manejan más horarios?;
- ¿Algún horario diferente?

Si el turno anterior dejó claro un periodo —por ejemplo mañana/matutino—, la consulta alternativa conserva ese periodo.

Ejemplo de intención canónica:

`¿No tienes más horario?` después de consultar la mañana en Colegio Monteverde → `¿Qué horarios de la mañana tiene la otra sede?`

La respuesta debe usar únicamente horarios verificados del backend. Consultar la otra sede **no cambia la sede seleccionada** hasta que el usuario la acepte de forma explícita.

## Cómo crecer esta guía

Cuando aparezca una expresión nueva en una conversación real:

1. anonimizar el caso;
2. comprobar que la intención sea inequívoca;
3. añadir la variante a la capa lingüística solo si no introduce falsos positivos razonables;
4. añadir una regresión automática;
5. no convertir una excepción ambigua en regla general.

El objetivo es que Sharky entienda mejor cómo habla la gente sin hacer más débiles las reglas de negocio.
