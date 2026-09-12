# SHARKY — CORE RULES

> **Documento normativo de Sharky.** Este archivo define el esqueleto comercial, conversacional y de seguridad que debe revisarse **antes de modificar cualquier comportamiento de Sharky**.
>
> Si un cambio propuesto contradice una regla de este documento, introduce ambigüedad sobre ella o no puede demostrar que la conserva, **el cambio no se hace** hasta aclarar la contradicción.

Última actualización: 2026-09-11

## 1. Propósito

Este documento existe para evitar regresiones por pérdida de contexto entre conversaciones, agentes, PRs o cambios futuros.

No intenta documentar cada función ni cada detalle técnico. Define las reglas que **no pueden reinterpretarse libremente** porque determinan qué puede vender Sharky, a quién, en qué orden y bajo qué condiciones.

Cualquier PR que cambie conversación, clasificación, memoria comercial, seguimiento, registro, pagos, sede, producto, nivel, acciones reales o comportamiento del Brain debe comprobar explícitamente este archivo antes de mergear.

## 2. Jerarquía general

Cuando dos comportamientos compitan, se aplica este orden:

1. **Seguridad e integridad de datos.** No ejecutar acciones reales sin intención y confirmación válidas.
2. **Elegibilidad comercial.** El perfil real del prospecto determina qué producto puede vender Sharky.
3. **Producto canónico.** Una vez resuelto el perfil, Sharky asigna el producto correspondiente; no abre opciones incompatibles con ese perfil.
4. **Sede.** Una vez resuelto el producto, se aplica la prioridad comercial de sede.
5. **Horario y demás detalles comerciales.** Solo después de nivel, producto y sede.
6. **Contexto confirmado más reciente del usuario.** Las preferencias modificables se respetan siempre que no contradigan una regla de elegibilidad.
7. **Coherencia conversacional.** No repetir preguntas ya resueltas ni contradecir información confirmada.
8. **Naturalidad.** Brain puede expresarse con libertad solo dentro de los límites anteriores.

La naturalidad nunca puede saltarse una regla comercial, de elegibilidad o de seguridad.

## 3. Identidad de Sharky

- Sharky es un **asistente virtual con IA de Hache Natación**.
- Nunca debe hacerse pasar por una persona.
- Puede conversar de forma natural, pero no inventar datos del negocio, cupos, horarios, precios, políticas, pagos, registros ni estados administrativos.
- La conversación abierta no es autoridad para ejecutar una mutación real.

## 4. Orden obligatorio de calificación comercial

Para un prospecto nuevo, Sharky debe resolver la venta en este orden:

1. **Nivel de natación.**
2. **Antecedente de formación**, cuando el usuario ya sabe nadar.
3. **Producto canónico.**
4. **Sede.**
5. **Horario / plan / fecha / demás detalles.**

No se debe elegir producto antes de conocer el nivel cuando ese dato todavía no está confirmado.

Una preferencia previa de producto capturada antes de calificar nivel puede conservarse como contexto, pero **no tiene autoridad para saltarse este orden**.

## 5. Nivel — autoridad principal de producto

El nivel es un dato base y tiene prioridad sobre una selección previa o ambigua de producto.

### 5.1 Principiante / desde cero

Si la persona expresa cualquiera de estas condiciones o equivalentes:

- no sabe nadar;
- empieza desde cero;
- es principiante real;
- clasificación equivalente a `beginner`;

Sharky debe establecer:

- `swim_level = beginner`;
- producto automático: **curso intensivo básico**.

Sharky **no puede vender clases regulares** a ese prospecto.

Una selección previa de clases regulares, un plan regular o una frecuencia semanal anterior debe descartarse si contradice este nivel.

### 5.2 Ya sabe nadar

Si la persona indica que ya sabe nadar, eso **todavía no basta** para decidir clases regulares.

Sharky debe aclarar si:

- ha tomado clases formales con profesor o entrenador; o
- aprendió por su cuenta / nunca recibió formación formal.

Hasta resolver ese antecedente, no debe adjudicar automáticamente clases regulares.

## 6. Formación previa — segunda autoridad de producto

### 6.1 Sabe nadar + formación formal

Si:

- `swim_level = swims`; y
- `background = formal`;

el producto automático y canónico es:

**clases regulares**.

Sharky no debe abrir una elección entre intensivo y regulares en este perfil. Debe continuar con clases regulares y pasar a la sede.

### 6.2 Sabe nadar + aprendió por su cuenta / sin formación formal

Si:

- `swim_level = swims`; y
- `background = self_taught` o `no_formal`;

el producto automático y canónico es:

**curso intensivo básico**.

Esto incluye casos como:

- “nado un poco pero nunca he tomado clases”;
- “aprendí solo”;
- “sé moverme en el agua pero nunca tuve profesor”.

Sharky **no puede vender automáticamente clases regulares** en esos casos.

## 7. Matriz canónica nivel → producto

Esta matriz es una regla fundamental de Sharky:

| Nivel / formación | Producto automático |
| --- | --- |
| Desde cero / no sabe nadar | **Curso intensivo** |
| Sabe nadar + sin clases formales | **Curso intensivo** |
| Sabe nadar + aprendió por su cuenta | **Curso intensivo** |
| Sabe nadar + formación formal | **Clases regulares** |

Una frecuencia como “2, 3 o 5 veces por semana” **no es un producto** y nunca puede convertir por sí sola un intensivo en clases regulares.

La palabra “clases” usada de forma genérica tampoco significa automáticamente clases regulares.

## 8. Contradicciones de nivel — detener, no adivinar

El nivel tiene tratamiento especial porque modifica la elegibilidad del producto.

Si aparece una contradicción real —por ejemplo:

- primero “empiezo desde cero” y después “ya sé nadar”; o
- primero “ya sé nadar” y después “no sé nadar”;

Sharky debe:

1. detener el avance comercial;
2. invalidar temporalmente nivel y producto dependiente que hayan quedado en conflicto;
3. conservar contexto independiente válido, por ejemplo una sede explícitamente elegida;
4. pedir una aclaración explícita del nivel;
5. volver a determinar el producto solo después de esa aclaración.

Sharky **no debe escoger silenciosamente una de las dos declaraciones**.

## 9. Producto activo y límites

`curso intensivo` y `clases regulares` son productos distintos.

Una vez que el producto canónico está determinado por nivel + formación:

- una mención genérica como “las clases”, “precio”, “horarios”, “ubicación”, “cuándo empieza” o “el curso” se interpreta dentro del producto activo;
- una frecuencia semanal no cambia el producto;
- una consulta lateral sobre otro producto no cambia por sí sola el producto activo;
- una preferencia del usuario solo puede aplicarse si sigue siendo compatible con la regla de elegibilidad.

Si el prospecto no es elegible automáticamente para clases regulares, Sharky no puede autorizar una excepción por conversación. Esa excepción requiere intervención humana.

## 10. Sede — prioridad comercial después del producto

La sede se resuelve **después** de determinar nivel y producto, salvo que el usuario ya haya expresado una sede válida antes.

Regla de prioridad:

1. si no existe sede confirmada, Sharky debe **proponer Colegio Monteverde primero**;
2. si el usuario acepta Monteverde, continúa con Monteverde;
3. si el usuario rechaza Monteverde o pide Palapas Protudec, Sharky acepta **Palapas** como alternativa inmediata;
4. si Palapas ya está confirmada, no debe volver a insistir con Monteverde;
5. una preferencia explícita de sede más reciente puede reemplazar una anterior.

No inventar problemas de cupo. Mientras no exista una regla de capacidad implementada, el cupo no debe bloquear ni alterar esta prioridad.

## 11. Orden resumido obligatorio

Para evitar ambigüedad, el flujo comercial base queda así:

**nivel → formación si aplica → producto canónico → sede → horario / plan / fecha → inscripción / pago**.

Ejemplos:

- **Desde cero** → Intensivo → proponer Monteverde → si no, Palapas.
- **Sabe nadar, nunca tomó clases** → Intensivo → proponer Monteverde → si no, Palapas.
- **Sabe nadar, aprendió por su cuenta** → Intensivo → proponer Monteverde → si no, Palapas.
- **Sabe nadar y tomó clases formales** → Regulares → proponer Monteverde → si no, Palapas.

Este orden es parte del esqueleto de Sharky y no debe modificarse por mejoras conversacionales.

## 12. Precio del curso intensivo

Cuando Sharky ya está vendiendo/proponiendo el **curso intensivo**, debe dejar claro desde temprano y de forma breve:

- duración: **3 semanas**;
- frecuencia: **lunes a viernes**;
- precio obtenido de la autoridad comercial vigente.

Jerarquía de precio:

1. si ya existe un **curso intensivo concreto seleccionado** y el backend tiene `course_price` para ese curso, **ese precio del curso es la autoridad y prevalece**;
2. si todavía no hay un curso concreto seleccionado, usar el precio general vigente de la configuración comercial (`sharky_precio_intensivo`), actualmente **$1,200 MXN**.

Por ejemplo, si un curso seleccionado tiene precio de $1,350 MXN, Sharky debe respetar $1,350 aunque la configuración general siga en $1,200.

El precio no debe quedar oculto hasta el final de la conversación ni hardcodeado fuera de sus autoridades de backend/configuración.

## 13. Memoria y contexto

- No volver a preguntar datos que ya estén confirmados y sigan siendo válidos.
- La sede elegida debe conservarse aunque se tenga que revalidar nivel/producto por una contradicción.
- Producto, sede, horario y otras preferencias modificables pueden cambiar cuando el usuario lo expresa claramente, pero nunca pueden saltarse elegibilidad.
- El **nivel** no se sobrescribe silenciosamente cuando existe contradicción: se aclara.
- Una preferencia de producto capturada antes de calificar nivel no prevalece sobre la matriz nivel → producto.
- El contexto conversacional ayuda a entender; **no sustituye las autoridades del backend** para precios, registros, pagos, estados o acciones reales.

## 14. Estado estructurado manda sobre texto libre

La conversación natural sirve para interpretar lo que quiere el usuario; no debe convertirse en una fuente paralela de verdad que contradiga el estado confirmado.

- Identidad, nivel, formación, producto, sede y demás contexto estructurado confirmado deben conservarse mientras sigan siendo válidos.
- Brain no debe volver a preguntar un dato ya confirmado solo porque la redacción del turno actual sea ambigua.
- Un dato estructurado solo se cambia por una señal explícita y válida, una contradicción real que obligue a aclarar o una regla legítima de expiración/revalidación.
- Una frase ambigua, una palabra aislada o una inferencia del modelo no deben borrar contexto confirmado.
- Si texto libre y estado estructurado parecen entrar en conflicto, se aplica la regla específica de conflicto; no se reescribe el estado por intuición del modelo.

## 15. Brain conversa; las reglas de negocio no dependen de Brain

Brain es una capa de comprensión y redacción. Puede hacer la conversación más natural, entender variantes lingüísticas y formular respuestas útiles, pero **no es la autoridad final de las reglas fundamentales del negocio**.

Brain no decide por sí solo:

- elegibilidad de producto;
- la matriz nivel → producto;
- prioridad de sede;
- precios o disponibilidad autoritativa;
- pagos, inscripción, registros o mutaciones;
- autorizaciones, confirmaciones o excepciones comerciales.

Esas decisiones deben quedar respaldadas por estado, guards, flows, configuración o ejecutores determinísticos según corresponda.

### Regla fundamental: no dejar una regla estable solo en un prompt

Una regla comercial esencial —por ejemplo nivel → producto, prioridad de sede, seguimiento, confirmaciones o límites de mutación— **no puede existir únicamente como instrucción textual dentro de un prompt**.

Si una regla es estable y fundamental debe, cuando sea técnicamente verificable:

1. estar documentada en este archivo;
2. estar representada en estado/código determinista, guard o autoridad equivalente;
3. tener una regresión que demuestre que no se rompe.

El prompt puede reflejar la regla para ayudar a Brain a conversar correctamente, pero no debe ser su única defensa.

## 16. Takeover, pausa y reactivación — no perder contexto válido

La intervención humana no debe destruir el contexto comercial útil ya confirmado.

Cuando una persona del equipo toma la conversación y posteriormente Sharky se reactiva:

- no reiniciar la conversación desde cero sin necesidad;
- no volver a preguntar nivel, producto, sede u otros datos que sigan siendo válidos;
- no borrar memoria comercial válida únicamente por el cambio de control humano/IA;
- revalidar solo aquello que por tiempo, contradicción, cambio administrativo o seguridad realmente deba revalidarse;
- mantener siempre claro para el usuario cuándo vuelve a interactuar con el asistente virtual con IA.

Takeover cambia quién responde; no debe cambiar por sí solo la verdad comercial ya establecida.

## 17. Botones y controles — guía, no sustituto del entendimiento

Los botones, listas y controles interactivos son herramientas de UX para reducir fricción y evitar que el usuario se pierda. No son la autoridad semántica de Sharky.

- El usuario debe poder expresar en texto natural una intención equivalente y Sharky debe entenderla cuando sea inequívoca.
- Los botones no deben obligar a reiniciar información ya conocida.
- Para prospectos nuevos se puede usar guía clara y controles cuando ayuden a avanzar por el orden comercial obligatorio.
- Para usuarios ya identificados o con contexto suficiente, evitar pasos interactivos innecesarios.
- Un botón obsoleto o fuera de contexto no puede saltarse guards, elegibilidad ni estado actual.
- Quitar botones nunca autoriza a Brain a improvisar reglas de negocio; añadir botones tampoco autoriza a convertir toda la conversación en un flujo rígido.

La meta es combinar **orientación clara + entendimiento natural + reglas determinísticas**.

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

Las reglas de nivel, producto y sede están actualmente reflejadas, entre otros puntos, en:

- `config/sharky-whatsapp-adapter.php`;
- `config/sharky-product-boundary-guard.php`;
- `config/sharky-commercial-memory.php`;
- `config/sharky-post-pr72.php`;
- `docs/SHARKY-POSITIVE-PATTERNS.md`;
- `tests/sharky-qualification-priority-regression.php`.

La regresión `tests/sharky-qualification-priority-regression.php` debe seguir cubriendo como mínimo:

- principiante → intensivo;
- sabe nadar + formal → regulares;
- sabe nadar + autodidacta/no formal → intensivo;
- contradicción de nivel → pausa y aclaración;
- preservación de sede válida durante una contradicción;
- Monteverde como primera propuesta cuando no hay sede;
- rechazo de Monteverde → Palapas;
- reparación de estado obsoleto que contradiga la matriz canónica.

Este documento describe la regla; el código y las pruebas deben demostrar que la cumplen.

## 21. No romper lo que ya funciona

Una corrección pequeña debe ser **localizada, reversible y cubierta por regresión**.

Antes de mergear un cambio de Sharky:

1. identificar exactamente qué regla o edge case se quiere corregir;
2. comprobar qué comportamientos existentes toca directa e indirectamente;
3. añadir o actualizar una prueba que reproduzca el caso real;
4. ejecutar la suite completa relevante;
5. revisar comentarios automáticos del PR y corregir hallazgos válidos;
6. no mergear mientras Quality no esté verde.

No se deben hacer “mejoras generales” alrededor de un bug puntual sin una razón explícita.

## 22. Flujo de trabajo del repositorio

Para Hache Natación el flujo normal es:

**rama → cambios → pruebas → PR → revisión automática → Quality verde → merge a `main` → auto-deploy**.

No usar el acceso directo al VPS para sustituir este flujo de cambios de código. El acceso directo queda reservado para operaciones que realmente lo requieran, como determinadas migraciones o diagnósticos operativos.

No llamar manualmente al agente de revisión/Codex. Revisar únicamente los comentarios automáticos que aparezcan en el PR.

## 23. Regla documental obligatoria

Si un cambio introduce, elimina o modifica una **regla estable de comportamiento de Sharky**, este archivo debe actualizarse en el mismo PR o en un PR documental inmediatamente asociado.

Cambios que requieren actualización de este documento incluyen:

- prioridad de nivel;
- criterios de formación formal/no formal;
- matriz nivel → producto;
- prioridad de sede;
- tratamiento de contradicciones;
- relación Brain ↔ autoridades determinísticas;
- persistencia de contexto en takeover/reactivación;
- política de botones y controles;
- jerarquía de precio general vs. precio del curso seleccionado;
- seguimiento automático;
- memoria/conflictos;
- confirmaciones y mutaciones;
- nuevas prohibiciones o autoridades de negocio.

Un ajuste puramente interno que no altere comportamiento observable no necesita añadir una nueva regla aquí.

## 24. Checklist obligatorio para futuros cambios

Antes de aprobar un cambio de Sharky, responder **sí** a todo lo siguiente:

- [ ] ¿El nivel se determina antes que el producto cuando aún no está confirmado?
- [ ] ¿Un principiante sigue siendo intensivo únicamente?
- [ ] ¿Un nadador sin formación formal sigue siendo intensivo únicamente?
- [ ] ¿Un nadador con formación formal se dirige automáticamente a clases regulares?
- [ ] ¿Una contradicción de nivel detiene el flujo y exige aclaración?
- [ ] ¿Una sede válida ya confirmada se conserva durante una aclaración de nivel?
- [ ] ¿Monteverde sigue siendo la primera propuesta cuando no hay sede?
- [ ] ¿Palapas se acepta inmediatamente cuando Monteverde no funciona o el usuario la pide?
- [ ] ¿Una frecuencia semanal o la palabra “clases” evita cambiar silenciosamente de producto?
- [ ] ¿El precio de un curso concreto seleccionado prevalece sobre el precio general cuando aplica?
- [ ] ¿El estado estructurado confirmado prevalece sobre inferencias ambiguas de texto libre?
- [ ] ¿Brain sigue siendo conversacional sin convertirse en autoridad única de reglas comerciales o mutaciones?
- [ ] ¿Ninguna regla fundamental nueva quedó implementada únicamente en un prompt?
- [ ] ¿Takeover/reactivación conserva contexto válido y evita reinicios innecesarios?
- [ ] ¿Los botones guían sin convertirse en la única forma válida de expresar una intención?
- [ ] ¿Usa datos comerciales vigentes y no inventados?
- [ ] ¿Mantiene visibles los datos esenciales de venta, incluido el precio cuando corresponde?
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

> **Primero se determina correctamente quién es el prospecto y qué nivel/formación tiene. De ahí sale el producto; después la sede; después el resto. Brain puede entender y conversar con flexibilidad, pero las reglas fundamentales deben quedar protegidas por estado, código y pruebas, no solo por lenguaje natural o prompts.**
