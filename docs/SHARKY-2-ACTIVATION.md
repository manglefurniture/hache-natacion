# Sharky 2.0 — activación controlada

Este runbook parte de `main` después del merge de PR #73. **Ningún paso de deploy normal activa Sharky 2.0 ni ejecuta migraciones automáticamente.** El objetivo es preparar producción, validar persistencia/recovery real y habilitar el router nuevo solo cuando todo lo anterior esté verde.

## Regla de seguridad

- Mantener `SHARKY_ORCHESTRATOR_LAB_ENABLED=0` de forma **explícita** durante secretos, migración, instalación de workers y preflight. Un valor ausente no cuenta como preflight válido.
- No imprimir ni copiar secretos al repositorio, PR, logs o chat.
- Si cualquier preflight falla, no activar el flag.
- Si el E2E real falla, volver el flag a `0` antes de investigar.
- El webhook v2 sigue siendo el fallback productivo mientras el flag esté apagado.
- Un rollback no autoriza una reactivación automática: antes de volver a `1` hay que revisar las colas pendientes para no responder tarde a eventos anteriores.

## 1. Configurar secretos estables en el servidor

En `/var/www/hache-natacion/.env` deben existir, sin reutilizar la misma cadena:

- `SHARKY_CONTACT_HASH_KEY` — mínimo 32 caracteres.
- `SHARKY_STATE_ENCRYPTION_KEY` — mínimo 32 caracteres y distinto del anterior.
- `WHATSAPP_VERIFY_TOKEN`.
- `META_APP_SECRET`.
- `WHATSAPP_PHONE_NUMBER_ID`.
- `WHATSAPP_ACCESS_TOKEN`.
- `SHARKY_ORCHESTRATOR_LAB_ENABLED=0`.

Para generar cada secreto Sharky en el servidor puede usarse `openssl rand -hex 32`. Guardar el valor únicamente en `.env`; no pegarlo en GitHub.

Los workers se ejecutan como `www-data`, por lo que **ese mismo usuario debe poder leer `.env`** sin volverlo público. Antes de continuar:

```bash
cd /var/www/hache-natacion
stat -c '%U %G %a %n' .env
sudo -u www-data test -r .env && echo ENV_READABLE_BY_WWW_DATA
```

Si no es legible, ajustar propietario/grupo/permisos de forma controlada según la configuración actual del servidor (por ejemplo `root:www-data` y `0640`), sin imprimir el contenido del archivo.

## 2. Ejecutar migraciones con el flag apagado

```bash
cd /var/www/hache-natacion
php bin/migrate-sharky-orchestrator.php
```

El runner:

- se niega a correr si el feature flag está en `1`;
- toma un `GET_LOCK` de MariaDB para impedir dos migradores simultáneos;
- aplica primero `20260902_sharky_orchestrator.sql` y después `20260903_sharky_orchestrator_hardening.sql`;
- ejecuta sentencias de forma explícita y reporta el archivo/sentencia que falla;
- verifica tablas, columnas, índices y claves foráneas esperadas al terminar.

Las migraciones son aditivas/idempotentes para este rollout. No se ejecutan desde el workflow de deploy. Si una sentencia falla, no activar: corregir la causa y volver a ejecutar el runner, que está diseñado para tolerar una ejecución parcial de los cambios aditivos.

## 3. Preflight obligatorio

Ejecutarlo **como el mismo usuario de los workers** para validar permisos y lectura real de secretos:

```bash
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-preflight.php
```

Debe terminar con:

```text
SHARKY_PREFLIGHT_OK
```

El preflight previo al primer cutover exige `SHARKY_ORCHESTRATOR_LAB_ENABLED=0` exactamente y valida sin revelar secretos:

- extensiones PHP requeridas;
- secretos y credenciales de WhatsApp presentes;
- claves Sharky distintas;
- tablas, columnas, índices y claves foráneas esperadas;
- ausencia de estado conversacional legado en texto claro;
- ausencia de outbox `PENDING` sin payload cifrado;
- ausencia de filas `DEAD` en el outbox;
- **corte limpio**: sin inbox pendiente, outbox pendiente, acciones `PENDING` ni acciones `COMPLETED` todavía sin entrega.

Para salida estructurada:

```bash
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-preflight.php --json
```

Después de activar el flag, para diagnóstico explícito (sin exigir colas vacías durante tráfico normal):

```bash
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-preflight.php --allow-enabled
```

## 4. Instalar workers de recovery/outbox

Copiar los cuatro archivos de `ops/systemd/` a `/etc/systemd/system/`:

```bash
sudo cp ops/systemd/hache-sharky-inbox.service /etc/systemd/system/
sudo cp ops/systemd/hache-sharky-inbox.timer /etc/systemd/system/
sudo cp ops/systemd/hache-sharky-outbox.service /etc/systemd/system/
sudo cp ops/systemd/hache-sharky-outbox.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now hache-sharky-inbox.timer hache-sharky-outbox.timer
```

Verificar:

```bash
systemctl status hache-sharky-inbox.timer hache-sharky-outbox.timer --no-pager
systemctl list-timers 'hache-sharky-*' --no-pager
```

Los workers corren como `www-data`, desde `/var/www/hache-natacion`, y usan el mismo `.env` que el runtime. No deben existir copias de secretos dentro de las unidades systemd.

## 5. Smoke de workers con Sharky 2.0 todavía apagado

```bash
sudo -u www-data php /var/www/hache-natacion/bin/sharky-inbox-dispatch.php
sudo -u www-data php /var/www/hache-natacion/bin/sharky-outbox-dispatch.php
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-status.php
```

Con el flag en `0`, ambos workers deben responder como deshabilitados y no procesar ni enviar nada. El status no debe mostrar `dead_outbox`, payloads pendientes sin cifrar ni errores de schema/secrets.

## 6. E2E controlado

Solo después de los pasos 1–5 verdes y de una revisión final del PR de activación:

1. Cambiar **únicamente** `SHARKY_ORCHESTRATOR_LAB_ENABLED=1` en el `.env` productivo.
2. Ejecutar `sudo -u www-data php bin/sharky-orchestrator-preflight.php --allow-enabled`.
3. Enviar una conversación de prueba desde un número controlado.
4. Cubrir primero conversación no transaccional y referral/batching.
5. Probar takeover humano y confirmar que Sharky queda silencioso.
6. Probar identidad conocida y challenge desde número desconocido.
7. Probar una ausencia con confirmación final.
8. Probar un alta intensiva únicamente con datos de prueba/controlados y validar credenciales temporales.
9. Revisar `sudo -u www-data php bin/sharky-orchestrator-status.php` entre escenarios.
10. Hacer una prueba explícita de rollback durante actividad controlada: cambiar a `0` y confirmar que no se procesa el siguiente evento del lote ni se ejecuta otro envío automático.

No probar excepciones comerciales reales con alumnos sin coordinación humana.

## 7. Rollback inmediato

Ante comportamiento inesperado:

```text
SHARKY_ORCHESTRATOR_LAB_ENABLED=0
```

El router vuelve al webhook v2. Los workers y el dispatch del outbox revalidan el flag durante el lote para impedir nuevas acciones/eventos y nuevos envíos automáticos después del apagado. No borrar las tablas: inbox/outbox/auditoría pueden contener evidencia necesaria para recovery.

Si existe evidencia de un envío incorrecto o se desea congelar por completo la inspección, detener temporalmente los timers:

```bash
sudo systemctl stop hache-sharky-inbox.timer hache-sharky-outbox.timer
```

Antes de una futura reactivación:

```bash
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-status.php
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-preflight.php
```

El segundo comando debe volver a dar `SHARKY_PREFLIGHT_OK`; si existen colas pendientes, no reactivar hasta decidir de forma explícita cómo reconciliarlas.

## 8. Estado operativo sin PII

```bash
sudo -u www-data php /var/www/hache-natacion/bin/sharky-orchestrator-status.php
```

El comando reporta únicamente estado de schema/flag y contadores técnicos; no imprime teléfonos, nombres, payloads ni secretos.

## Criterio de salida

Sharky 2.0 puede permanecer activado solo si:

- preflight verde;
- migration/schema/constraints verdes;
- `.env` legible por `www-data` sin exposición pública;
- ambos timers activos;
- E2E conversacional verde;
- takeover verde;
- identidad/verificación verde;
- ausencia e intensivo controlados verdes;
- rollback bajo actividad controlada verde;
- sin `DEAD`, credenciales perdidas ni receipts atascados;
- revisión final no tiene hallazgos sustanciales de seguridad, concurrencia, idempotencia o reglas comerciales.
