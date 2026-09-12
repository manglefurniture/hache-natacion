# Sharky — revisión horaria de conversaciones

## Objetivo

Usar conversaciones reales como fuente de aprendizaje sin permitir que producción se auto-modifique.

El sistema revisa cada hora las conversaciones recientes ya almacenadas por Sharky y clasifica cada contacto en:

- `OK`: no se detectaron señales de problema;
- `REVIEW`: hay señales que conviene revisar;
- `PROBLEM`: existe al menos un hallazgo de severidad alta.

## Fuente de datos

No se crea un segundo log de texto libre.

La revisión reutiliza:

- `sharky_message_receipts`: mensajes entrantes cifrados;
- `sharky_outbox`: mensajes salientes cifrados.

Los textos se descifran únicamente en memoria durante la revisión. Las tablas de revisión guardan solo hashes de contacto, IDs de mensajes, tipo/severidad del hallazgo y metadatos técnicos no sensibles.

## Cadencia

No existe un timer nuevo. El proceso reutiliza `hache-sharky-inbox.timer`, que ya se ejecuta cada minuto. Una reclamación atómica en `sharky_conversation_review_state` permite ejecutar la revisión como máximo una vez por hora.

Ventana revisada: últimas 2 horas. El solapamiento evita perder conversaciones que cruzan el límite horario; fingerprints idempotentes evitan duplicar hallazgos.

## Señales iniciales

La primera versión es determinística y sin consumo adicional de modelos externos. Busca señales de alto valor y bajo riesgo:

- corrección explícita del usuario (`USER_CORRECTION`);
- usuario obligado a repetir una respuesta (`USER_REPEAT`);
- repetición de una pregunta de calificación (`REPEATED_QUESTION`);
- pérdida contextual ante una respuesta corta como `Nunca` en formación (`CONTEXT_LOSS_SHORT_ANSWER`);
- pregunta directa de precio desplazada por otra pregunta de calificación (`DIRECT_PRICE_QUESTION_DEFERRED`);
- cambio silencioso entre curso intensivo y clases regulares (`PRODUCT_DRIFT`).

Estas señales son **candidatos de revisión**, no nuevas reglas comerciales.

## Aprendizaje controlado

Un hallazgo nunca modifica automáticamente:

- `SHARKY-CORE-RULES.md`;
- `docs/SHARKY-LANGUAGE-GUIDE.md`;
- prompts;
- código;
- precios, horarios, elegibilidad, sede, pagos o registros.

El flujo correcto es:

**conversación real → hallazgo → revisión humana → caso anonimizado → regresión/regla si corresponde → PR → Quality → merge → deploy**.

Un hallazgo validado puede pasar a `REGRESSION_CANDIDATE`. Solo después se convierte manualmente en una prueba o adecuación siguiendo `AGENTS.md` y las reglas núcleo.

## Alertas

Los hallazgos `HIGH` generan una línea en el journal con:

- tipo de hallazgo;
- prefijo del hash del contacto;
- ID técnico del mensaje.

Nunca se escribe texto de la conversación, nombre ni teléfono en el log de alerta.

## Operación

Ejecución manual forzada:

```bash
php bin/sharky-conversation-review.php --force
```

Migración aditiva:

```bash
php bin/migrate-sharky-conversation-review.php
```

La migración puede ejecutarse con Sharky activo porque solo crea tablas nuevas y no altera las tablas de conversación existentes.

## Tablas

- `sharky_conversation_review_state`: control de cadencia;
- `sharky_conversation_reviews`: resultado horario por contacto;
- `sharky_conversation_findings`: hallazgos deduplicados y candidatos a regresión.

## Regla de privacidad

Las conversaciones reales siguen cifradas en sus almacenes originales. No copiar texto real a fixtures, documentación, logs ni findings. Cuando un caso se convierta en regresión, debe anonimizarse y reducirse al mínimo necesario para reproducir el comportamiento.
