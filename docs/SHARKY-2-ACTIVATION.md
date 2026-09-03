# Sharky 2.0 — activación controlada

Este runbook parte de `main` después del merge de PR #73. **Ningún paso de deploy normal activa Sharky 2.0 ni ejecuta migraciones automáticamente.** El objetivo es preparar producción, validar persistencia/recovery real y habilitar el router nuevo solo cuando todo lo anterior esté verde.

## Regla de seguridad

- Mantener `SHARKY_ORCHESTRATOR_LAB_ENABLED=0` durante secretos, migración, instalación de workers y preflight.
- No imprimir ni copiar secretos al repositorio, PR, logs o chat.
- Si cualquier preflight falla, no activar el flag.
- Si el E2E real falla, volver el flag a `0` antes de investigar.
- El webhook v2 sigue siendo el fallback productivo mientras el flag esté apagado.

## 1. Configurar secretos estables en el servidor

En `/var/www/hache-natacion/.env` deben existir, sin reutilizar la misma cadena:

- `SHARKY_CONTACT_HASH_KEY` — mínimo 32 caracteres.
- `SHARKY_STATE_ENCRYPTION_KEY` — mínimo 32 caracteres y distinto del anterior.
- `WHATSAPP_VERIFY_TOKEN`.
- `META_APP_SECRET`.
- `WHATSAPP_PHONE_NUMBER_ID`.
- `WHATSAPP_ACCESS_TOKEN`.
- `SHARKY_ORCHESTRATOR_LAB_ENABLED=0`.

Para generar cada secreto Sharky en el servidor puede usarse `openssl rand -hex 32`. Guardar el valor únicamente en `.env` con permisos restringidos; no pegarlo en GitHub.

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
- verifica tablas, columnas e índices al terminar.

Las migraciones son aditivas/idempotentes para este rollout. No se ejecutan desde el workflow de deploy.

## 3. Preflight obligatorio

```bash
php bin/sharky-orchestrator-preflight.php
```

Debe terminar con:

```text
SHARKY_PREFLIGHT_OK
```

El preflight valida sin revelar secretos:

- extensiones PHP requeridas;
- secretos y credenciales de WhatsApp presentes;
- claves Sharky distintas;
- feature flag todavía seguro;
- tablas, columnas e índices esperados;
- ausencia de estado conversacional legado en texto claro;
- ausencia de outbox `PENDING` sin payload cifrado;
- ausencia de filas `DEAD` en el outbox antes del corte.

Para salida estructurada:

```bash
php bin/sharky-orchestrator-preflight.php --json
```

Después de activar el flag, para diagnóstico explícito:

```bash
php bin/sharky-orchestrator-preflight.php --allow-enabled
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
php /var/www/hache-natacion/bin/sharky-orchestrator-status.php
```

Con cola vacía no deben aparecer `dead_outbox`, payloads pendientes sin cifrar ni errores de schema/secrets.

## 6. E2E controlado

Solo después de los pasos 1–5 verdes:

1. Cambiar **únicamente** `SHARKY_ORCHESTRATOR_LAB_ENABLED=1` en el `.env` productivo.
2. Ejecutar `php bin/sharky-orchestrator-preflight.php --allow-enabled`.
3. Enviar una conversación de prueba desde un número controlado.
4. Cubrir primero conversación no transaccional y referral/batching.
5. Probar takeover humano y confirmar que Sharky queda silencioso.
6. Probar identidad conocida y challenge desde número desconocido.
7. Probar una ausencia con confirmación final.
8. Probar un alta intensiva únicamente con datos de prueba/controlados y validar credenciales temporales.
9. Revisar `php bin/sharky-orchestrator-status.php` entre escenarios.

No probar excepciones comerciales reales con alumnos sin coordinación humana.

## 7. Rollback inmediato

Ante comportamiento inesperado:

```text
SHARKY_ORCHESTRATOR_LAB_ENABLED=0
```

El router vuelve al webhook v2. No borrar las tablas: inbox/outbox/auditoría pueden contener evidencia necesaria para recovery. Mantener los workers activos mientras se inspeccionan filas pendientes, salvo que exista evidencia de envío incorrecto; en ese caso detener temporalmente los timers:

```bash
sudo systemctl stop hache-sharky-inbox.timer hache-sharky-outbox.timer
```

## 8. Estado operativo sin PII

```bash
php bin/sharky-orchestrator-status.php
```

El comando reporta únicamente estado de schema/flag y contadores técnicos; no imprime teléfonos, nombres, payloads ni secretos.

## Criterio de salida

Sharky 2.0 puede permanecer activado solo si:

- preflight verde;
- migration/schema verde;
- ambos timers activos;
- E2E conversacional verde;
- takeover verde;
- identidad/verificación verde;
- ausencia e intensivo controlados verdes;
- sin `DEAD`, credenciales perdidas ni receipts atascados;
- revisión final no tiene hallazgos sustanciales de seguridad, concurrencia, idempotencia o reglas comerciales.
