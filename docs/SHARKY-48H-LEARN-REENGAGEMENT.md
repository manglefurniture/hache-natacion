# Sharky — seguimiento 48 h para “Aprende a nadar”

**Vigente desde:** 2026-09-14  
**Decisión de producto:** sustituye, para la etapa automática de 48 horas, la regla genérica anterior descrita históricamente en `SHARKY-CORE-RULES.md` y en el código previo de reengagement.

## Regla vigente

El seguimiento automático de **48 horas** se envía únicamente cuando existe evidencia durable de que el prospecto tocó explícitamente el quick reply canónico:

- `meta:program:learn` — **Aprende a nadar**.

No basta con que el producto resuelto sea `intensive`. En particular, una persona que entre al intensivo mediante **Clases regulares → No, curso básico** no pertenece a esta audiencia de 48 h.

Los prospectos cuya última elección explícita sea `meta:program:regular` no reciben seguimiento automático de 48 h mientras esta decisión siga vigente.

## Plantilla

La plantilla nueva es:

- nombre: `hache_seguimiento_aprender_nadar`;
- idioma: `es_MX`;
- texto aprobado: **“Hola, hace unos días nos escribiste porque querías aprender a nadar y nos quedamos pendientes de tu respuesta. ¿Podemos ayudarte en algo más?”**

La plantilla anterior `hache_retomar_inscripcion` queda retirada del envío automático de 48 h. Puede conservarse en código histórico/diagnósticos que no envíen mensajes.

## Evidencia y persistencia

La selección explícita se obtiene del inbox cifrado de WhatsApp, usando los IDs de quick reply `meta:program:learn` y `meta:program:regular`. Al preparar la respuesta normal, la última selección explícita se copia al estado cifrado como:

- `commercial_context.program_button_choice`;
- `commercial_context.program_button_choice_at`.

Esto evita inferir “Aprende a nadar” únicamente porque el programa actual sea intensivo y permite invalidar un seguimiento si después cambia la elección.

## Programación

- El vencimiento base sigue siendo **48 horas desde el último turno del prospecto** asociado al token vigente.
- La ventana de gracia se mantiene en 24 horas adicionales.
- Se respetan las horas silenciosas actuales: 22:00–08:00, zona `America/Cancun`.
- Si el prospecto todavía está dentro del funnel cerrado Sharky 3.0 y no ha elegido sede, la etapa de 48 h puede quedar armada directamente; no requiere inventar una sede.
- Si ya existe el recorrido normal elegible de 15 y 90 minutos, esas dos etapas se conservan. Después de la segunda, solo se crea la etapa de 48 h si la marca explícita sigue siendo `learn`.
- Para clases regulares, la secuencia termina después del seguimiento de 90 minutos; no se crea etapa 3.

## Cancelaciones obligatorias

El mensaje de 48 h falla cerrado y no se envía si ocurre cualquiera de estos casos:

- el contacto ya es alumno o tiene registro pendiente/activo;
- existe una respuesta nueva pendiente o el usuario respondió después del turno asociado;
- la última elección explícita ya no es `learn`;
- el prospecto pidió tiempo para pensar, analizar, consultar o hablar con alguien;
- pidió no recibir más mensajes o cerró claramente la conversación;
- está dentro de un Flow protegido de inscripción;
- el estado/contexto no coincide con el token programado;
- la comprobación de registro no está disponible.

## Backfill único del cambio

Se autoriza un backfill de las últimas **24 horas** para recuperar prospectos recientes cuya última elección explícita haya sido `meta:program:learn` antes de desplegar esta regla. El proceso:

1. usa solo recibos cifrados ya procesados;
2. revalida que el contacto siga siendo prospecto y no esté registrado;
3. conserva las reglas de opt-out/defer y los Flows protegidos;
4. arma el nuevo seguimiento a 48 h usando el último turno durable;
5. cancela filas `PENDING` que aún intenten usar la plantilla genérica anterior;
6. produce únicamente conteos agregados, sin teléfonos ni contenido de conversaciones.

El backfill es idempotente por token/dedupe y se ejecuta una sola vez mediante el comando versionado correspondiente.

## NO ROMPER

- Brain no decide esta audiencia.
- Texto libre no sustituye el botón en Sharky 3.0.
- `regular → no_formal → intensive` no se convierte en `learn`.
- Una respuesta posterior invalida el token anterior.
- Registro/inscripción siempre tiene prioridad sobre reengagement.
- La plantilla anterior no vuelve a salir desde la etapa automática de 48 h.
