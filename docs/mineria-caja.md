# Caja minera PRL — versión inicial

## Alcance
- Pantalla privada `/mineria-caja.php` (solo ADMIN) y API `/api/mineria-caja.php`.
- Registra exclusivamente movimientos **confirmados** y **en dólares** (USDT/USDC equivalentes a USD al valor de operación). PRL pendientes y confirmados sin vender **no son caja**.
- El monitor SaladCloud existente suministra automáticamente el número de instancias **RTX 4070 Ti Super Low** efectivamente `ready/running` y una *proyección* a 0.13 USD/h por GPU. Las instancias `Allocating` no se cuentan. El costo proyectado **NO** se añade al ledger.
- No hay integración automática de facturas de Salad, de ventas SafeTrade/Kryptex ni de saldos Solflare/ARQ en esta versión. Esas fuentes necesitan permisos/formatos oficiales confirmados antes de sincronizarse.
- El saldo de las GPU es un **estimado**, no el consumo facturado. Si datos de monitor están obsoletos/incompletos, se oculta la cifra.

## Operación
1. Registrar los dos saldos iniciales actuales **una sola vez**, incluso si alguno es 0 USD. No importar movimientos históricos que ya estén incluidos en la apertura, porque se duplicarían.
2. Registrar ingreso externo a wallet con `capital_wallet`; recargas directas de Salad desde tarjeta o efectivo con `capital_salad`.
3. Registrar venta PRL como `sale` usando el **neto recibido** en USDT/USDC después de la comisión del exchange. No volver a registrar esa comisión si ya se dedujo del neto.
4. Registrar `transfer_salad` cuando el dinero salga de la wallet para aumentar créditos Salad: mueve saldo entre cuentas internas, **no es gasto**.
5. Registrar `usage` solo cuando haya cargo real verificado en Salad; nunca usar proyecciones como consumo real.
6. Registrar `fee` únicamente cuando la comisión se cobre aparte de la billetera y `withdrawal` para fondos retirados por el propietario, sin confundirlo con un gasto operativo.
7. Exportar CSV periódicamente; todos los asientos son inmutables y llevan identificador anti-duplicado. Los errores de captura se resuelven con una nueva versión de correcciones auditadas, no editando directamente el JSON.

## Cálculos
- Wallet = apertura + aportes externos + ventas netas − transferencias a Salad − comisiones − retiros.
- Salad = apertura + aportes directos + transferencias desde wallet − consumo real.
- Resultado operativo = ventas netas − consumo real − comisiones. Los aportes, retiros personales y transferencias internas no crean beneficios ni pérdidas.
- No incluir el PRL por cobrar ni valoraciones especulativas.
- Autonomía = créditos Salad registrados / costo horario *estimado*, sólo si ambas aperturas constan y hay dato reciente de GPU.

## Seguridad y persistencia
Archivo privado: `/var/lib/hache-natacion/mineria-caja/ledger.json` (modo 0600). La carpeta ya debe existir, pertenecer a `www-data` y ser escribible por PHP-FPM; **no crear rutas públicas ni guardar contraseñas/API keys**. Un lock separado serializa escrituras; guarda mediante temp + rename. Lecturas y escrituras de la API exigen sesión ADMIN, mutaciones CSRF, respuestas sin caché. El registro no forma parte de los cierres financieros de natación.

**Antes de habilitar en producción:** confirmar que el procedimiento de backup existente protege también este JSON. Si sólo respalda MariaDB y código, ampliar el backup **versionado en GitHub y revisado** antes de aceptar asientos reales. No incluir datos reales del usuario en GitHub.

## Verificación
```bash
php -l config/mineria-caja.php
php -l api/mineria-caja.php
php -l public/mineria-caja.php
php tests/mineria-caja-regression.php
```

Comprobar además en el servidor: ruta privada no accesible por HTTP, login/roles, CSRF, exportación CSV móvil, snapshots viejos, cálculo correcto sin datos de apertura y backup/restauración. No se modifican contenedores SaladCloud ni el monitor existente.
