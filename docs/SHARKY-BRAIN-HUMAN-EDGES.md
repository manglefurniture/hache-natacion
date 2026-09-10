# Sharky Brain — bordes humanos y subreglas candidatas

Estado: **especificación para la próxima intervención**. Este documento no concede autoridad operativa nueva a Brain.

## Principio

No convertir cada frase rara en una regex. Separar tres capas:

1. **Inequívoco**: puede resolverse de forma determinística y cubrirse con regresión.
2. **Contextual**: depende del último turno, la última pregunta o la información que Sharky acaba de mostrar.
3. **Semántico**: dejar que Brain interprete lenguaje libre, pero sin ejecutar operaciones protegidas.

## Subreglas propuestas

### H1 — Agradecimiento o acuse no es una invitación a vender

Entradas típicas: `gracias`, `ok`, `va`, `sale`, `perfecto`, `👍`, `👌`, `🙏`.

Regla candidata: si no hay una pregunta determinística obligatoria pendiente ni una operación incompleta, responder como máximo con un cierre breve o guardar silencio; **no abrir una pregunta comercial nueva**.

### H2 — Negación local no equivale a rechazo global

Entradas típicas: `no puedo hoy`, `no en la mañana`, `no sé todavía`, `no, mejor Monteverde`, `no intensivo, regular`.

Regla candidata: aplicar el `no` al objeto inmediato (fecha, horario, sede, programa) y no interpretarlo como cancelación, pausa u opt-out salvo lenguaje explícito.

### H3 — La intención final domina en autocorrecciones

Entradas típicas: `no, sí quiero`, `perdón, Palapas`, `mejor Monteverde`, `dije 8 pero mejor 7`.

Regla candidata: cuando el mismo turno contiene corrección explícita (`perdón`, `mejor`, `quise decir`, `no, ...`), usar la corrección final y no mantener dos selecciones contradictorias.

### H4 — Números desnudos solo con contexto inmediato

Entradas típicas: `3`, `5`, `8`, `12`, `50`.

Regla candidata: interpretar un número solo si la última pregunta restringe inequívocamente su significado. Nunca inferir por sí solo que `8` es edad, 8 AM, número de clases o monto.

### H5 — Solicitud de información y pausa necesitan contexto

Entradas ambiguas: `déjame revisar los horarios`, `déjame checar las opciones`, `voy a revisar precios`.

Regla candidata:
- si Sharky **todavía no mostró** esa información, tratarlo como solicitud de información;
- si Sharky **acaba de mostrarla**, tratarlo como posible deliberación/pausa;
- si sigue ambiguo, Brain hace una sola aclaración corta o evita empujar.

No ampliar el detector determinístico de pausa con estas frases sin contexto.

### H6 — Cierre diferido explícito sí detiene el empuje

Entradas típicas: `lo pienso y te digo`, `lo consulto con mi esposa`, `te confirmo mañana`, `déjame revisarlo y te aviso`.

Regla candidata: marcar pausa comercial y no hacer otra pregunta. Mantener contexto para cuando la persona vuelva.

### H7 — Pregunta lateral no reinicia el embudo

Entradas típicas: `¿hay estacionamiento?`, `¿aceptan transferencia?`, `¿dónde queda?`, `¿necesito goggles?`.

Regla candidata: responder la duda y conservar programa/sede/nivel ya confirmados. No repetir saludo, no volver al menú inicial y no repetir preguntas ya contestadas.

### H8 — Multi-intención en un mismo burst

Entradas típicas: `no sé nadar, quiero precios y horarios de noche`.

Regla candidata: extraer y conservar todos los datos inequívocos del burst, responder primero lo preguntado y preguntar solo por **un dato faltante real**. No volver a preguntar algo que ya vino en el mismo burst.

### H9 — “Ya te dije” es señal de memoria, no de confrontación

Entradas típicas: `ya te dije que Monteverde`, `te comenté que no sé nadar`.

Regla candidata: consultar memoria estructurada antes de responder; reconocer brevemente el dato y continuar sin repetir la pregunta. Si el dato no está realmente guardado, no fingir que sí.

### H10 — Cambio de tema permitido; operación protegida no

Entradas típicas: una persona pasa de horarios a ubicación, kit, pagos o fechas.

Regla candidata: Brain puede cambiar de tema libremente para información. Si el usuario intenta ejecutar inscripción, pago, cancelación, reposición, cambio de datos o una acción sensible, el flujo determinístico recupera autoridad.

### H11 — Petición de humano tiene prioridad absoluta

Entradas típicas: `quiero hablar con alguien`, `pásame con una persona`, `no quiero hablar con el bot`.

Regla candidata: takeover inmediato; no intentar convencer, vender ni hacer otra pregunta antes del handoff.

### H12 — Identidad del asistente no reinicia la venta

Entradas típicas: `¿eres un bot?`, `¿eres IA?`, `¿con quién hablo?`.

Regla candidata: contestar claramente que es Sharky, asistente de IA de Hache Natación, y después conservar exactamente el contexto previo. No volver a presentar opciones desde cero.

### H13 — Restricciones personales no son necesariamente rechazo

Entradas típicas: `me queda lejos`, `salgo tarde del trabajo`, `solo puedo después de las 8`, `me da miedo el agua`.

Regla candidata: tratarlo como restricción/objeción útil, no como cancelación. Ofrecer únicamente alternativas soportadas por datos reales y hacer como máximo una pregunta útil.

### H14 — Broma, risa y muletillas no cambian intención por sí solas

Entradas típicas: `jajaja no, sí quiero`, `😂 sí`, `wey, no sé nadar`.

Regla candidata: ignorar risa/muletillas para clasificación y conservar la intención semántica principal.

### H15 — Opt-out fuerte distinto de pausa suave

Entradas típicas: `no me escriban`, `ya no me contacten`, `déjenme de mandar mensajes`.

Regla candidata: distinguirlo de `ahora no` o `lo pienso`. Un opt-out fuerte bloquea seguimientos automáticos hasta una nueva iniciativa explícita del usuario.

## Regresiones que sí conviene mantener desde ahora

- Pausas inequívocas siguen siendo pausa.
- Pedidos con `ver` siguen siendo información.
- Negaciones locales comunes no se vuelven pausa accidentalmente.
- Agradecimientos, emojis y autocorrecciones no deben entrar al detector de pausa.
- El filtro de muletillas iniciales no debe borrar una frase significativa que empiece por `Perfecto, ...`.
- Flujos protegidos y `action_result` nunca son reescritos por Brain.

## Orden sugerido para la próxima intervención

**P1:** H1, H2, H5, H6 y H15. Son los que más fácilmente pueden volver molesto o invasivo al asistente.

**P2:** H3, H4, H8 y H9. Mejoran memoria y reducen preguntas tontas/repetidas.

**P3:** H7, H10, H12, H13 y H14. Pulido conversacional y continuidad.

## Regla de diseño

Antes de agregar una subregla nueva, exigir al menos una de estas evidencias:

- conversación real donde falle;
- caso de regresión razonable que proteja una frontera ya acordada;
- ambigüedad con riesgo de empuje comercial, pérdida de contexto o ejecución incorrecta.

Evitar coleccionar frases exactas si pueden representarse por una intención más general.