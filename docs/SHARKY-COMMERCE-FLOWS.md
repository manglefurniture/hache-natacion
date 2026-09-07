# Sharky — Flows de inscripción y pago

## Alcance

Esta implementación añade una capa de UX con WhatsApp Flows sin sustituir el motor determinista de Sharky.

### Inscripción

- La sede ya elegida queda fija y visible dentro del formulario.
- El Flow recopila nombre completo, fecha de nacimiento, fecha de inicio y horario.
- Cancelar no crea ningún registro y conserva el contexto comercial.
- Enviar el formulario vuelve al paso `register_intensive/confirm`.
- El alta real sigue ocurriendo únicamente mediante la acción existente `register_intensive` con `requires_revalidation=true`.
- Si fecha/horario dejaron de estar disponibles, el envío falla cerrado y se actualizan opciones.

### Pago

Después de un registro pendiente se ofrece, en este orden:

1. Transferencia SPEI — recomendada y sin recargo.
2. Tarjeta mediante Mercado Pago — con el porcentaje de recargo configurado en negocio (`sharky_recargo_tarjeta_pct`, actualmente 5% si no se cambia).
3. Efectivo — requiere coordinación humana y activa takeover.

Transferencia y tarjeta pueden solicitar una foto del comprobante mediante `PhotoPicker`. Sharky conserva solo la referencia estructurada entregada por Meta dentro del inbox cifrado; no descarga el archivo ni lo envía a OpenAI.

## Mercado Pago

Sharky reutiliza la configuración activa de la aplicación Tienda Natación en el mismo VPS. No se copian Access Tokens ni Webhook Secrets al repositorio o al estado conversacional.

`HACHE_TIENDA_ROOT` es un override opcional. Si queda vacío, Sharky busca ubicaciones de despliegue conocidas y solo acepta como raíz una carpeta que tenga simultáneamente `.env`, `src/PaymentCredentialCipher.php` y `src/PaymentGatewayConfig.php`. Si no encuentra una instalación válida, la opción de tarjeta falla de forma segura y conserva SPEI/efectivo.

La tarjeta usa Preferences API con `external_reference` opaco de Sharky. El total aplica el recargo configurado antes de crear la preferencia.

## Seguimiento de tarjeta

El mecanismo reutiliza el outbox/recordatorio cifrado existente:

- se arma únicamente después de que el mensaje con el enlace fue marcado como enviado;
- primera revisión: 15 minutos;
- si el pago está aprobado: cancelar seguimiento;
- si está pendiente o Mercado Pago no responde: reprogramar otra revisión de 15 minutos;
- si no existe pago o terminó fallido: ofrecer cambiar a SPEI o coordinar efectivo;
- si ya existe un pago válido en Hache o se recibió comprobante: cancelar seguimiento;
- mantiene horario de contacto 08:00–22:00 America/Cancun;
- el seguimiento de tarjeta expira para no generar mensajes tardíos.

## Provisioning de WhatsApp Flows

Los Flows son estáticos y se aprovisionan de forma idempotente después de responder `200` al webhook y después de procesar/despachar el turno actual. Los intentos fallidos quedan limitados por un backoff de 15 minutos y cada webhook permite como máximo un intento de red para un Flow todavía no resuelto.

Pueden fijarse IDs ya publicados mediante:

- `WHATSAPP_ENROLLMENT_FLOW_ID`
- `WHATSAPP_PAYMENT_METHOD_FLOW_ID`
- `WHATSAPP_PAYMENT_TRANSFER_FLOW_ID`
- `WHATSAPP_PAYMENT_CARD_FLOW_ID`

Si Meta no permite administrar/publicar Flows, Sharky conserva los caminos fallback de chat/botones y nunca bloquea el registro determinista por esa razón.

Los Flows de comercio son solo para chats directos; los grupos siguen usando respuestas de texto.
