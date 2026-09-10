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

## GP-001 — Meta → principiante → recomendación intensivo → inscripción → pago

**Estado:** patrón positivo activo  
**Origen:** conversación real observada el 10 de septiembre de 2026  
**Privacidad:** caso anonimizado; no guardar nombre, teléfono, fecha de nacimiento ni capturas del prospecto en esta bitácora.

### Contexto

Un prospecto entra desde un anuncio de Meta relacionado con aprender a nadar. Sharky identifica el origen y se presenta de forma transparente como asistente IA. El prospecto indica que empieza desde cero.

### Recorrido que funcionó

1. Sharky usa el anuncio como contexto, sin asumir que ya conoce todas las necesidades del prospecto.
2. Pregunta por el nivel real de natación.
3. Al confirmar que empieza desde cero, lleva el prospecto al curso intensivo como producto automático elegible de Hache Natación y explica brevemente el motivo.
4. El prospecto elige sede.
5. Sharky ofrece horarios disponibles mediante opciones controladas.
6. El prospecto elige horario.
7. Sharky ofrece fechas de inicio válidas.
8. El prospecto elige fecha.
9. Sharky ofrece iniciar inscripción.
10. La inscripción pasa al flow determinístico protegido.
11. El formulario recopila los datos necesarios y presenta una confirmación antes de ejecutar el alta.
12. Una vez confirmada la inscripción, Sharky pasa al flow de forma de pago.
13. El backend conserva la autoridad sobre inscripción y pago; Brain no inventa ni ejecuta operaciones sensibles.

### Invariantes — NO ROMPER

- Brain puede comprender, recomendar y mantener el hilo conversacional.
- **Elegibilidad dura:** quien empieza desde cero, no sabe nadar, aprendió por su cuenta o nunca ha tomado clases formales de natación no es elegible para venta automática de clases regulares. Para Sharky, el único producto automático en ese perfil es el curso intensivo. Una excepción a regulares solo puede autorizarla una persona del equipo.
- Si alguien dice “sé nadar un poco” o equivalente pero todavía no sabemos si ha tomado clases formales, Sharky debe confirmar ese antecedente antes de ofrecer o comparar clases regulares. Nadar un poco no equivale a formación formal.
- **Curso intensivo y clases regulares son productos distintos.** El intensivo dura 3 semanas y se toma de lunes a viernes; los planes semanales/mensuales pertenecen a clases regulares.
- Cuando el intensivo ya es el programa activo, referencias genéricas como “las clases”, “precio”, “horarios”, “ubicación”, “cuándo empieza” o “el curso” siguen refiriéndose al intensivo; no pueden saltar a regulares por una palabra ambigua.
- Una preferencia de frecuencia como “2 veces por semana”, “3 veces por semana” o “5 veces por semana” no puede convertir silenciosamente el intensivo en un plan regular. Para un perfil sin formación formal, esa frecuencia no habilita regulares: se mantiene intensivo y cualquier excepción requiere evaluación humana.
- “Ambas”, “las dos”, “los dos” o “de las dos” no son por sí solos una selección de producto; deben resolverse contra el contexto inmediato y nunca cambiar de intensivo a regulares sin una intención inequívoca y elegible.
- Solo un prospecto con formación formal confirmada puede cambiar automáticamente a clases regulares mediante una preferencia explícita. Si no es elegible, Sharky no muestra planes, precios ni horarios regulares y deriva la excepción a una persona.
- La preferencia posterior del cliente se respeta dentro de las reglas de elegibilidad; una preferencia no autoriza a Sharky a saltarse una valoración humana requerida.
- La sede elegida se conserva en contexto y no se vuelve a preguntar sin motivo.
- Horario y fecha se eligen usando disponibilidad real del backend y siempre dentro del programa activo.
- El modelo no puede ampliar, mezclar ni inventar horarios fuera del catálogo verificado del backend; si la disponibilidad no puede verificarse, se falla cerrado.
- Una vez que empieza un flow protegido de inscripción o pago, Brain no reescribe ni suplanta ese flow.
- Debe existir confirmación explícita antes de ejecutar el alta.
- Tras una inscripción correcta, el recorrido puede continuar hacia la forma de pago sin reiniciar la conversación.
- Pausas de varios minutos entre mensajes no deben hacer perder el contexto.
- La presentación de Sharky como IA ocurre una sola vez salvo que el usuario pregunte expresamente quién es.

### Qué sí puede mejorar sin romper GP-001

- Hacer mensajes más cortos.
- Reducir confirmaciones redundantes.
- Mejorar el tono y la naturalidad.
- Elegir mejor cuándo mostrar botones/listas.
- Evitar preguntas que no aportan al siguiente paso.

### Señales de regresión

Considerar GP-001 roto si una adecuación provoca cualquiera de estos comportamientos:

- volver a preguntar nivel, sede, horario o fecha ya confirmados;
- perder el hilo después de una pausa normal;
- ofrecer clases regulares automáticamente a alguien que empieza desde cero o nunca ha tomado clases formales;
- interpretar “nado un poco” como permiso suficiente para vender regulares sin confirmar formación formal;
- cambiar de intensivo a regulares porque el usuario dijo de forma genérica “clases”, “precio” u otra referencia ambigua;
- presentar 2/3/5 clases por semana como si fueran modalidades del curso intensivo;
- interpretar “de las dos” como una orden para cambiar de producto;
- ofrecer horarios del otro producto, horarios inventados o horarios no verificables;
- convertir una respuesta conversacional de Brain en una operación sensible directa;
- reiniciar el onboarding al entrar al flow de inscripción;
- terminar una inscripción y no poder continuar al pago;
- obligar a usar botones cuando el texto libre ya expresa claramente la selección;
- impedir una derivación humana cuando el prospecto solicita una excepción a las reglas de elegibilidad.

### Cobertura automática relacionada

La protección técnica de este recorrido se reparte actualmente entre las regresiones de Brain conversacional, frontera y elegibilidad de producto, alcance de horarios, WhatsApp adapter, commerce flows, enrollment-after-reserve, follow-up y pagos. Las futuras adecuaciones deben conservar esas pruebas verdes y añadir cobertura específica cuando aparezca un nuevo borde real.

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
