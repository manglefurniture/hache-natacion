# SHARKY — CORE RULES

> **Documento normativo de Sharky.** Define el esqueleto comercial, conversacional y de seguridad que debe revisarse antes de modificar cualquier comportamiento de Sharky.
>
> Si un cambio contradice una regla de este documento, introduce ambigüedad sobre ella o no puede demostrar que la conserva, **el cambio no se hace** hasta aclarar la contradicción.

Última actualización: 2026-09-13.

## 1. Propósito

Este documento evita regresiones por pérdida de contexto entre conversaciones, PRs o cambios futuros. Las reglas fundamentales no pueden quedar únicamente en prompts: cuando sean verificables deben existir también en estado/código/guards y en regresiones.

Cualquier PR que cambie conversación, clasificación, memoria comercial, registro, pagos, sede, producto, nivel, acciones reales o Brain debe comprobar este archivo.

## 2. Alcance actual de Sharky 3.0

Para un **prospecto nuevo/no alumno**, las fuentes siguientes comparten el funnel determinístico de captación Sharky 3.0:

- `meta_ad` — publicidad Meta;
- `web` — enlace/prefill oficial desde hnATACION.com;
- `direct` — WhatsApp directo sin referral/prefill web.

La fuente real se conserva para atribución y analítica. Compartir el mismo state machine **no convierte web/directo en Meta**.

El identificador interno histórico `meta_ad_onboarding` y el archivo `docs/SHARKY-3-META-FLOW.md` se conservan por compatibilidad, pero su alcance normativo ya incluye Meta, web y directo.

Los **referrals no publicitarios** conservan temporalmente el onboarding anterior por perfil como fallback controlado.

Un alumno conocido nunca se degrada a prospecto por entrar desde anuncio, web o WhatsApp directo: conserva su identidad y pasa al manejo de alumno/takeover correspondiente.

## 3. Jerarquía general

Cuando dos comportamientos compitan, se aplica este orden:

1. **Seguridad e integridad de datos.**
2. **Identidad alumno/prospecto y fuente real.**
3. **Estado del funnel determinístico vigente.**
4. **Elección explícita del usuario mediante el control vigente.**
5. **Elegibilidad comercial y guards del producto.**
6. **Producto canónico.**
7. **Sede confirmada.**
8. **Horario, plan, fecha y demás datos verificados del backend.**
9. **Contexto confirmado más reciente.**
10. **Naturalidad conversacional**, solo donde el recorrido la permita.

La naturalidad nunca puede saltarse una regla comercial, de elegibilidad o de seguridad.

## 4. Identidad de Sharky

- Sharky es un **asistente con IA de Hache Natación**.
- Nunca se hace pasar por una persona.
- El primer mensaje de captación cerrada debe identificarlo claramente, por ejemplo: **“Hola, soy Sharky 🦈, asistente IA de Hache Natación.”**
- La presentación ocurre una sola vez salvo que el usuario pregunte quién es.
- No inventa datos del negocio, cupos, horarios, precios, políticas, pagos, registros ni estados administrativos.
- La conversación abierta no es autoridad para ejecutar mutaciones.

## 5. Entrada y atribución

### 5.1 Meta Ads

Referral/`ctwa_clid` determina `entry_source = meta_ad`. El interés de campaña puede conservarse como atribución, pero **no escoge producto por el usuario**.

### 5.2 Web

Los enlaces oficiales que abren WhatsApp usan prefills del tipo `Hola Hache Natación, quiero...`. Esa firma se clasifica como `entry_source = web` aunque el texto mencione inscripción, orientación o una sede.

El prefill no preselecciona producto ni sede.

### 5.3 WhatsApp directo

Un prospecto nuevo sin referral publicitario ni firma web queda `entry_source = direct`.

Lo que escriba en el primer turno tampoco sustituye los controles del funnel.

### 5.4 Referral no publicitario

`entry_source = referral` conserva por ahora el onboarding por perfil anterior. Este fallback no puede invadir Meta/web/direct.

## 6. Funnel determinístico común — Meta/web/direct

Orden obligatorio:

1. presentación de Sharky como IA + edad vigente;
2. carrusel **Aprende a nadar / Clases regulares**;
3. si Aprende a nadar → información completa de intensivo;
4. si Clases regulares → confirmar si ya tomó clases;
5. si No → intensivo; si Sí → información de regulares;
6. carrusel **Colegio Monteverde / Palapas Protudec**;
7. información autoritativa de la sede/producto;
8. **Inscribirme / Ver otra sede**;
9. Flow protegido correspondiente;
10. pago/takeover según reglas del producto.

Producto, sede, plan o inscripción **no se deciden por texto libre o Brain** mientras este state machine esté activo.

## 7. Selector de producto

El primer bloque visual usa un carrusel nativo con dos tarjetas:

- `meta:program:learn` — **Aprende a nadar**;
- `meta:program:regular` — **Clases regulares**.

El usuario debe tocar el control. El texto libre no avanza el paso.

Si escribe en vez de tocar:

- conservar el mismo paso;
- no interpretar la frase como selección;
- repetir solo los controles vigentes;
- no repetir las imágenes.

## 8. Aprende a nadar → curso intensivo

La ruta **Aprende a nadar** es el curso básico/intensivo.

Debe mostrar, desde autoridades vigentes:

- duración: 3 semanas;
- clases: lunes a viernes;
- precio general desde `sharky_precio_intensivo`, salvo precio propio de un curso concreto seleccionado;
- no lleva inscripción;
- requisitos de traje de baño, gorro y goggles;
- confirmación de lugar conforme al pago vigente.

La palabra genérica “clases” o una frecuencia semanal nunca cambia por sí sola este producto.

## 9. Clases regulares — elegibilidad

Antes de ofrecer planes, preguntar mediante controles:

**“¿Ya has tomado clases de natación anteriormente, en alguna escuela?”**

- **Sí, continuar** → regulares.
- **No, curso básico** → intensivo directamente, sin regresar al menú inicial.

El Flow regular valida perfil intermedio/avanzado. Sharky no concede excepciones de elegibilidad por conversación.

Los planes/inscripciones se obtienen del backend/configuración vigente; no se crea una segunda fuente de verdad.

## 10. Matriz histórica de nivel → producto

Esta matriz sigue siendo autoridad para el fallback por perfil y para guards que necesiten resolver elegibilidad:

| Nivel / formación | Producto automático |
| --- | --- |
| Principiante | **Curso intensivo** |
| Intermedio + no ha tomado clases | **Curso intensivo** |
| Intermedio + sí ha tomado clases | **Clases regulares** |
| Avanzado | **Clases regulares** |

`background = advanced` representa elegibilidad declarada de nivel avanzado; no significa “tomó clases formales”.

Una contradicción real de nivel se aclara; no se sobrescribe silenciosamente.

## 11. Selección de sede

Después de la información del producto se muestra un carrusel horizontal con:

- `meta:venue:monteverde` — Colegio Monteverde;
- `meta:venue:palapas` — Palapas Protudec.

Cada tarjeta usa su fotografía aprobada y su quick reply.

Reglas:

- texto libre no selecciona sede;
- retry no repite imágenes;
- la sede elegida queda estructurada;
- **Ver otra sede** cambia únicamente sede y dependencias de sede, conservando producto;
- no inventar problemas de cupo.

## 12. Autoridad de horarios, planes, fecha y precio

Después de producto+sede:

- horarios provienen de `horarios` filtrando sede + activo + producto;
- intensivo y regular no mezclan horarios;
- planes regulares provienen de planes activos de la sede;
- inscripción usa `sharky_inscripcion_monteverde` / `sharky_inscripcion_palapas`;
- Maps usa las autoridades configuradas;
- un curso intensivo concreto seleccionado con `course_price` prevalece sobre el precio general.

No hardcodear una segunda fuente estable cuando ya existe backend/configuración.

## 13. Edad

La política central usa:

- `sharky_edad_minima`;
- `sharky_edad_maxima`.

Actualmente se comunica 12–65 años. Saludo, Flow y registro deben derivar de la misma autoridad.

Fuera de rango no existe alta automática y se deriva a humano.

## 14. Estado estructurado manda sobre texto libre

- Fuente, identidad, producto, sede, horario y demás contexto confirmado se conservan mientras sigan siendo válidos.
- Un prefill, una palabra aislada o una inferencia no borran ni reemplazan contexto confirmado.
- En el funnel Sharky 3.0, texto libre **no sustituye controles**.
- Un botón obsoleto no puede saltarse estado/guards.
- Si texto y estado parecen entrar en conflicto, aplica la regla específica de conflicto; no se reescribe estado por intuición del modelo.

## 15. Brain y políticas laterales

Mientras `hache_sharky_meta_active(state)` sea verdadero para Meta/web/direct:

- Brain no interpreta selecciones del funnel;
- Brain 2B-A no reescribe decisiones;
- texto libre no se convierte en producto/sede/plan;
- las políticas laterales antiguas no se adelantan al state machine;
- una nota de voz transcrita conserva el mismo lock determinístico.

Brain sigue disponible fuera de estos recorridos donde corresponda, pero nunca es autoridad única para reglas fundamentales.

Las salidas laterales permitidas dentro del funnel son explícitas y estrechas:

- solicitud clara de hablar con una persona;
- declaración de que ya es alumno;
- guard de seguridad/edad/datos que requiera humano.
- pregunta lateral informativa sobre precio, duración, ubicación, horarios, requisitos o diferencias de producto, respondida por una capa determinística con datos confirmados.

La respuesta lateral informativa no depende de Brain conversacional ni de 2B-A. No consume el control pendiente, no modifica producto, sede, nivel, horario, turno o intención, no ejecuta operaciones y repone exactamente los controles del cursor vigente. Si falta una autoridad necesaria, responde brevemente sin inventar y conserva el mismo paso. Una solicitud de inscripción, pago, cancelación, reposición o cambio de datos nunca se ejecuta desde esta capa.

## 16. Takeover, pausa y reactivación

La intervención humana no destruye contexto comercial válido.

Al reactivar Sharky:

- no reiniciar sin necesidad;
- no volver a preguntar datos válidos;
- revalidar solo por tiempo, contradicción, cambio administrativo o seguridad;
- dejar claro cuándo vuelve el asistente IA.

Un alumno declarado abandona el funnel de prospecto.

## 17. Flows de inscripción

### Intensivo

Reutiliza el Flow y ejecutores existentes de inscripción/pago. Mantiene intención, confirmación y revalidación antes de mutaciones.

### Regulares

El Flow recibe sede fija y opciones verificadas de perfil/plan/horario. El registro es protegido e idempotente. Al terminar, takeover humano coordina el pago; no existe cobro automático en este recorrido.

Cancelar el Flow regular vuelve al bloque de sede sin perder producto/contexto.

## 18. Emojis y tono en prospectos

Regla estable ya existente y ahora aplicada también a los mensajes determinísticos:

- orientación comercial para prospectos: **2 a 5 emojis funcionales y naturales por respuesta**;
- ejemplos útiles: 🏊‍♂️, 📍, 💰, 🕒, ✅, ✍️, 📅, 🎒, 👇;
- se usan para marcar información/acción, no para decorar cada línea;
- evitar superar cinco por saturación;
- alumnos registrados mantienen el criterio más contenido de 1 a 3 cuando aporten claridad.

El saludo puede usar 🦈 para reforzar la identidad de Sharky sin ocultar que es IA.

## 19. Seguimientos automáticos

La cadena normal puede contemplar 15 minutos, 90 minutos y reenganche posterior según configuración vigente.

Si el prospecto expresa claramente que va a pensarlo, analizarlo, consultarlo o hablarlo con alguien, se cierra la cadena automática originada por ese turno. Una solicitud informativa no debe confundirse con deliberación.

## 20. Acciones reales — fail closed

Para registro, pago o cualquier mutación:

- intención explícita y suficientemente reciente;
- confirmación final cuando corresponda;
- revalidación contra datos actuales del backend;
- si nueva información contradice la confirmación anterior, esta deja de ser válida;
- si falta una autoridad necesaria, no ejecutar.

Ante duda, Sharky falla cerrado.

## 21. Implementación relacionada

Las reglas se materializan, entre otros puntos, en:

- `config/sharky-entry-guidance.php`;
- `config/sharky-meta-ad-flow.php`;
- `config/sharky-whatsapp-adapter.php`;
- `config/sharky-whatsapp-batching.php`;
- `config/sharky-language-guide.php`;
- `config/sharky-lab-worker.php`;
- `config/sharky-product-boundary-guard.php`;
- `config/sharky-commercial-memory.php`;
- `config/sharky-post-pr72.php`;
- `docs/SHARKY-3-META-FLOW.md`;
- `docs/SHARKY-POSITIVE-PATTERNS.md`;
- `tests/sharky-meta3-regression.php`;
- `tests/sharky-guided-first-prospect-regression.php`;
- `tests/sharky-pr162-review-regression.php`;
- suites de WhatsApp, Flow, outbox, registro y pagos.

## 22. Regresiones mínimas

Deben cubrir:

- Meta/web/direct → mismo selector cerrado;
- fuente real preservada;
- prefill/mensaje inicial no preselecciona producto/sede;
- primer mensaje identifica a Sharky como IA;
- carrusel producto y carrusel sede conservan imagen + quick reply correcto;
- texto libre no avanza;
- retry no repite imagen;
- Brain/políticas laterales no invaden el funnel, incluso tras audio;
- Aprende a nadar → intensivo;
- Regulares + No → intensivo;
- Regulares + Sí → regular;
- precio/horarios/sede desde autoridades vigentes;
- cambio de sede no cambia producto;
- edad fuera de rango falla cerrado;
- flows protegidos mantienen sus guards;
- emoji comercial se mantiene en 2–5 funcionales;
- referral no publicitario conserva el fallback por perfil hasta nueva decisión explícita.

## 23. Flujo de trabajo del repositorio

Para Hache Natación:

**rama → cambios → pruebas → PR → revisión automática → Quality verde → merge a `main` → auto-deploy → verificación de producción**.

No editar código versionado directamente en el VPS. El VPS se usa para deploy, diagnóstico, logs, servicios e inspección/verificación operativa.

No llamar manualmente Codex/Inge salvo instrucción explícita. Revisar sus comentarios/checks automáticos si aparecen.

## 24. Regla documental obligatoria

Si un cambio introduce, elimina o modifica una regla estable de Sharky, este archivo y los patrones/documentación afectados se actualizan en el mismo PR.

## 25. Checklist obligatorio

Antes de mergear un cambio de captación responder **sí** a lo aplicable:

- [ ] ¿Meta, web y directo comparten el selector cerrado sin perder su fuente real?
- [ ] ¿Un referral no publicitario conserva el fallback vigente?
- [ ] ¿El primer mensaje identifica claramente a Sharky como IA?
- [ ] ¿El texto inicial/prefill no preselecciona producto ni sede?
- [ ] ¿Texto libre mantiene el paso cerrado y los controles vigentes?
- [ ] ¿Carruseles conservan imagen + quick reply correctos?
- [ ] ¿Retries evitan repetir imágenes?
- [ ] ¿Aprende a nadar continúa únicamente por intensivo?
- [ ] ¿Regulares valida clases previas antes de continuar?
- [ ] ¿Producto y sede se conservan sin mezclarse?
- [ ] ¿Horarios/planes/precios provienen de autoridades verificadas?
- [ ] ¿Brain y políticas laterales quedan fuera de las decisiones del funnel?
- [ ] ¿Audio conserva el lock determinístico?
- [ ] ¿Alumno conocido no entra como prospecto?
- [ ] ¿Takeover/reactivación conserva contexto válido?
- [ ] ¿Edad y mutaciones fallan cerrado cuando corresponde?
- [ ] ¿Mensajes de prospectos usan 2–5 emojis funcionales sin saturar?
- [ ] ¿Existe regresión del comportamiento nuevo?
- [ ] ¿Quality está completamente verde?
- [ ] ¿Comentarios automáticos relevantes quedaron atendidos?
- [ ] ¿Este documento refleja la realidad después del cambio?

Si alguna respuesta es **no** o **no sabemos**, el cambio no está listo para merge.

---

## Regla de oro

> **Primero se identifica correctamente identidad y fuente. Los prospectos nuevos de Meta Ads, web y WhatsApp directo usan el mismo funnel Sharky 3.0 cerrado, conservando atribución. El usuario decide mediante controles; backend y guards mandan sobre datos/acciones; Brain no invade el state machine. Los referrals no publicitarios conservan temporalmente el fallback por perfil.**
