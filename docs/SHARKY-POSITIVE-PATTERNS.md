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
3. Al confirmar que empieza desde cero, recomienda el curso intensivo como opción primaria de Hache Natación y explica brevemente el motivo.
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
- La recomendación de intensivo se basa en el **nivel real**: quien empieza desde cero o nunca ha tomado clases formales recibe el intensivo como recomendación primaria.
- La preferencia posterior del cliente se respeta; recomendar no significa imponer.
- La sede elegida se conserva en contexto y no se vuelve a preguntar sin motivo.
- Horario y fecha se eligen usando disponibilidad real del backend.
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
- ofrecer horarios o fechas no disponibles;
- convertir una respuesta conversacional de Brain en una operación sensible directa;
- reiniciar el onboarding al entrar al flow de inscripción;
- terminar una inscripción y no poder continuar al pago;
- obligar a usar botones cuando el texto libre ya expresa claramente la selección;
- impedir que el usuario cambie de decisión antes de la operación final.

### Cobertura automática relacionada

La protección técnica de este recorrido se reparte actualmente entre las regresiones de Brain conversacional, WhatsApp adapter, commerce flows, enrollment-after-reserve, follow-up y pagos. Las futuras adecuaciones deben conservar esas pruebas verdes y añadir cobertura específica cuando aparezca un nuevo borde real.

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
