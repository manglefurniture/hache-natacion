# Sharky — especificación de intervención humana

Estado: **decisión de producto aprobada; pendiente de implementación en código**.  
Fecha: 2026-09-15.

Este documento fija el comportamiento esperado para supervisión humana de conversaciones de Sharky sin sustituir las reglas comerciales, de seguridad ni los guards existentes.

## 1. Objetivo

Permitir que una persona autorizada de Hache Natación pueda intervenir en una conversación activa desde el mismo WhatsApp de Hache, resolver un punto concreto y devolver el control a Sharky de forma automática o explícita.

La intervención humana debe conservar el contexto de la conversación y evitar respuestas simultáneas de humano y Sharky.

## 2. Estados por conversación

Se definen tres estados funcionales:

### `normal`

- Sharky responde normalmente.
- No existe takeover manual exclusivo.
- No existe una ventana de gracia activa por intervención humana.

### `manual_grace`

- Existe una intervención humana puntual, pero Sharky no queda dormido de forma indefinida.
- El mensaje humano sale al cliente desde el WhatsApp oficial de Hache.
- Internamente debe seguir identificado como `HUMANO_HACHE`, no como respuesta generada por Sharky.
- Cuando el cliente responde, comienza una ventana de **30 segundos** antes de que Sharky retome.
- Si el cliente envía varios mensajes consecutivos, la ventana se cuenta desde el **último mensaje recibido**.
- Si la persona de Hache vuelve a responder durante esa ventana, se cancela la respuesta automática pendiente y la conversación continúa bajo intervención humana puntual.
- Si pasan 30 segundos sin nueva intervención humana, Sharky retoma automáticamente.

### `manual_takeover`

- Sharky queda completamente silenciado para esa conversación.
- La conversación queda bajo control humano exclusivo.
- No aplica la ventana de 30 segundos.
- El takeover permanece hasta que ocurra uno de estos eventos:
  1. se ejecuta `Sharky despierta`;
  2. llega el reinicio diario de takeovers ya existente, usando la fecha local de Cancún.

Prioridad operativa:

`manual_takeover` > `manual_grace` > `normal`.

## 3. Comandos operativos

### `Sharky duerme`

Activa `manual_takeover` únicamente para la conversación correspondiente.

Reglas:

- solo puede originarse desde un canal/actor autorizado de Hache;
- no se reenvía al cliente;
- no debe aparecer como texto conversacional normal;
- Sharky no responde a mensajes posteriores mientras el takeover esté activo;
- el takeover conserva su fecha de activación para que el reinicio diario existente pueda liberarlo al siguiente día local.

### `Sharky despierta`

Libera `manual_takeover` antes del reinicio diario.

Reglas:

- sustituye al comando anterior `Sharky vuelve ahora`;
- solo puede originarse desde un canal/actor autorizado de Hache;
- no se reenvía al cliente;
- no debe borrar producto, sede, plan, horario, intención ni demás contexto válido;
- la siguiente interacción vuelve al comportamiento normal de Sharky.

`Sharky vuelve ahora` queda definido como comando a retirar cuando se implemente este cambio. La compatibilidad temporal, si se necesita durante despliegue, debe quedar acotada y probada.

## 4. Intervención puntual sin dormir a Sharky

Una respuesta manual ordinaria ya no debe equivaler por sí sola a takeover completo.

Comportamiento esperado:

1. Sharky puede haber respondido o quedar atascado en un punto de la conversación.
2. Una persona de Hache envía manualmente una pregunta o aclaración desde el mismo WhatsApp oficial.
3. El cliente la recibe como un mensaje normal de Hache.
4. Internamente el mensaje se conserva como `HUMANO_HACHE`.
5. Cuando el cliente contesta, se abre `manual_grace` por 30 segundos.
6. Si no hay nueva intervención humana, Sharky retoma usando el contexto inmediato de la intervención manual.

Ejemplo anonimizado:

- `HUMANO_HACHE`: “¿Desea ver las ubicaciones?”
- `USUARIO`: “Sí”
- 30 segundos sin nueva intervención humana
- Sharky retoma y responde con la información de ubicaciones permitida por las autoridades vigentes.

## 5. Contexto después de la intervención humana

Al retomar, Sharky debe considerar el mensaje humano inmediatamente anterior como parte del contexto conversacional.

Una respuesta corta del usuario —por ejemplo `sí`, `no`, `esa`, `la segunda`— puede interpretarse en relación con la pregunta manual inmediata cuando el significado sea inequívoco.

Esta interpretación contextual **no autoriza** a saltarse guards ni controles protegidos:

- no convierte por sí sola una respuesta en inscripción, pago, cancelación, reposición o cambio de datos;
- no puede inventar producto, sede, plan, horario o elegibilidad;
- no puede sobreescribir estado confirmado sin una regla permitida;
- en el funnel cerrado Meta/web/directo, una intervención humana puede abrir contexto informativo puntual, pero no debe transformar una respuesta ambigua en una selección comercial protegida.

Las autoridades vigentes de `SHARKY-CORE-RULES.md`, backend/configuración y flows protegidos siguen prevaleciendo.

## 6. Seguridad contra respuestas dobles

La implementación debe conservar los invariantes actuales de concurrencia:

- una intervención humana debe poder invalidar/cancelar cualquier salida automática pendiente de esa conversación;
- si humano y Sharky compiten por responder, el humano tiene prioridad;
- el sistema debe revalidar el estado justo antes de despachar una salida automática;
- no puede existir un intervalo en el que un takeover humano ya sea efectivo para la persona pero Sharky todavía despache una respuesta vieja.

La solución debe reutilizar los locks, outbox y guards actuales en lugar de introducir un segundo canal de envío independiente.

## 7. Reinicio diario

`manual_takeover` reutiliza el mantenimiento diario vigente:

- zona horaria: `America/Cancun`;
- un takeover activado durante el día actual permanece activo;
- al comenzar un nuevo día local, los takeovers del día anterior se liberan;
- `Sharky despierta` permite liberarlos antes.

`manual_grace` no debe sobrevivir indefinidamente. Su vida útil es únicamente la ventana corta asociada al turno en curso.

## 8. Auditoría y aprendizaje

Debe mantenerse la separación de actores:

- `USUARIO`;
- `SHARKY`;
- `HUMANO_HACHE`.

Los comandos operativos `Sharky duerme` y `Sharky despierta` no deben generar ruido de aprendizaje ni tratarse como contenido comercial.

Las respuestas humanas normales sí pueden conservarse como contexto de aprendizaje, bajo las reglas vigentes de revisión y privacidad, sin convertirse automáticamente en nuevas reglas o prompts.

## 9. Visibilidad esperada en administración

Cuando exista UI para este comportamiento, el operador debe poder distinguir al menos:

- `Sharky activo`;
- `Intervención humana — ventana de 30 s`;
- `Sharky dormido`.

La UI es una capa de presentación; la autoridad real del estado debe vivir en la lógica de backend y ser segura frente a reinicios/procesos concurrentes.

## 10. Criterios de aceptación para la futura implementación

La implementación en código se considerará completa cuando se demuestre mediante regresiones y pruebas que:

1. una respuesta manual ordinaria no crea takeover completo;
2. el cliente puede responder a una pregunta manual y Sharky retoma tras 30 segundos;
3. mensajes consecutivos del cliente reinician la ventana desde el último mensaje;
4. una nueva respuesta humana dentro de la ventana evita que Sharky responda encima;
5. `Sharky duerme` crea takeover exclusivo solo para esa conversación;
6. mientras está dormido, Sharky permanece en silencio aunque el cliente escriba;
7. `Sharky despierta` libera el takeover sin borrar contexto válido;
8. el reinicio diario libera takeovers del día anterior;
9. las respuestas humanas siguen diferenciadas de las generadas por Sharky;
10. los comandos operativos no llegan al cliente ni contaminan la bandeja de aprendizaje;
11. no hay respuestas dobles ni carreras entre outbox, takeover e intervención humana;
12. Meta/web/directo, flows protegidos, pagos, inscripción, alumnos conocidos y demás recorridos existentes no sufren regresiones.

## 11. Trabajo pendiente que sí requiere código

- representar de forma durable `manual_grace` y su expiración;
- separar una respuesta manual ordinaria del takeover completo que hoy se activa automáticamente;
- reconocer `Sharky duerme`;
- sustituir `Sharky vuelve ahora` por `Sharky despierta`;
- adaptar el worker de ecos manuales;
- integrar la ventana de 30 segundos con batching/debounce/outbox;
- incorporar el contexto humano inmediato al turno que retoma Sharky sin romper el funnel cerrado;
- mantener/cancelar salidas automáticas pendientes de forma race-safe;
- exponer estados en la administración si se decide incluir UI en esta fase;
- añadir/regenerar pruebas de takeover, outbox, batching, conversación, aprendizaje y war games;
- actualizar `SHARKY-CORE-RULES.md` en el mismo PR de código que active realmente el comportamiento.

## 12. Fuentes normativas relacionadas

Antes de implementar el código deben revisarse, como mínimo:

- `AGENTS.md`;
- `SHARKY-CORE-RULES.md`;
- `docs/SHARKY-POSITIVE-PATTERNS.md`;
- `docs/SHARKY-LANGUAGE-GUIDE.md`;
- `docs/SHARKY-3-META-FLOW.md`;
- `docs/SHARKY-CONVERSATION-REVIEW.md`;
- `docs/SHARKY-LEARNING-INBOX.md`.

Esta especificación no declara que el comportamiento ya esté activo. Define el contrato aprobado que la futura implementación debe satisfacer.
