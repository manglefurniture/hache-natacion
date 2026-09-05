# Hache Natación — piloto Production Readiness Nivel C

Este documento adopta de forma controlada el baseline de **Hache Base Production Readiness** como piloto real de proyecto. No copia ni modifica la norma: la referencia de implementación usada para esta adopción es Hache Base `af09a753098234525f10ff10403c0d1bdb4afbe1`.

## Clasificación

**Nivel: C — Crítico.**

La elevación a C no depende de Sharky. El backend conserva y modifica estado financiero y operativo cuyo duplicado, pérdida o corrupción puede causar daño material:

- `database/schema_hache_monteverde_v1.sql` define `pagos` y relaciones con alumnos, mensualidades, inscripciones e intensivos;
- `api/pagos-smart.php` registra pagos y bloquea filas durante decisiones financieras;
- `api/cierres-mensuales.php` materializa cierres de periodo;
- `api/editar-pago.php` modifica efectos financieros ya registrados;
- `config/intensivo-transferencias.php` bloquea transferencias cuando existe un pago válido;
- Sharky añade otra frontera externa durable mediante inbox/outbox y envío por WhatsApp, pero el proyecto seguiría siendo C aunque Sharky estuviera apagado.

Por esa razón una clasificación B sería insuficiente para el proyecto completo.

## CUF-C-01 — Registrar un pago sin duplicar el efecto

- **Dueño técnico:** Hache Interactive.
- **Dueño de negocio:** Hache Natación.
- **Objetivo:** registrar un pago válido para el concepto correcto sin crear dos efectos financieros para la misma obligación.
- **Precondiciones:** sesión ADMIN válida; alumno/sede/concepto existentes; periodo abierto cuando corresponda.
- **Pasos:** validar request → bloquear/leer estado financiero relevante → crear/actualizar obligación → insertar pago → recalcular estado → commit.
- **Resultado observable:** el pago aparece una vez y el estado financiero asociado refleja el importe válido.
- **Resultado autoritativo:** DB (`pagos` + obligación relacionada + cierre de periodo cuando aplique).
- **Mutaciones:** pagos, mensualidades/inscripciones/intensivos y reglas derivadas.
- **Integraciones:** ninguna obligatoria para persistir el pago.
- **Fallos críticos:** doble submit, periodo cerrado, carrera concurrente, rollback parcial, edición de pago ya cerrado.
- **Idempotencia/concurrencia:** constraints/transacción/`FOR UPDATE` y reglas que impiden más de un pago válido donde el concepto lo exige.
- **Pruebas existentes:** regresiones financieras, intensivos, periodos y runtime smoke de la suite del proyecto.
- **Smoke de producción:** lectura autenticada del estado resultante y conciliación sin crear un segundo pago.
- **Recovery:** no repetir una mutación ambigua; reconciliar primero contra DB y auditoría.

## CUF-C-02 — Inscripción / cupo de curso intensivo

- **Dueño técnico:** Hache Interactive.
- **Dueño de negocio:** Hache Natación.
- **Objetivo:** incorporar al alumno al curso/horario correcto sin perder ni duplicar la inscripción ni desalinearla de pagos/reglas.
- **Precondiciones:** curso activo, sede/horario válidos y alumno identificable.
- **Resultado autoritativo:** `curso_intensivo_alumnos` y las entidades financieras relacionadas.
- **Fallos críticos:** inscripción duplicada, traslado con pago incompatible, carrera sobre cupo/curso, mail posterior fallido.
- **Recovery:** la DB manda; notificaciones son efectos secundarios y nunca deben revertir una inscripción ya confirmada.

## CUF-C-03 — Comunicación transaccional Sharky / WhatsApp

- **Dueño técnico:** Hache Interactive.
- **Dueño de negocio:** Hache Natación.
- **Objetivo:** emitir una respuesta automática como máximo una vez por intención durable y conservar un estado observable del envío.
- **Precondiciones:** Sharky habilitado, migración completa, secretos estables y contacto no tomado por humano.
- **Resultado autoritativo local:** `sharky_outbox` (`PENDING`, `SENT`, `DEAD`, `CANCELLED`) y sus attempts/leases.
- **Correlación de proveedor:** cuando `20260905_sharky_delivery_status.sql` está aplicado, el outbox conserva únicamente el `provider_message_id` devuelto por Meta y los webhooks firmados persisten `SENT`, `DELIVERED`, `READ` o `FAILED` en `sharky_delivery_status`.
- **Privacidad:** la tabla de delivery no conserva teléfono, `recipient_id`, payload, conversación ni hash de contacto.
- **Orden:** eventos duplicados/fuera de orden no regresan un estado cuyo `provider_event_at_utc` sea posterior; el timestamp se deriva del Unix epoch del proveedor.
- **Evidencia cerrada:** el 2026-09-05 se revisó un snapshot real de producción con 36 estados correlacionados (`DELIVERED=10`, `READ=26`, `FAILED=0`) y aprobación humana explícita. La decisión está documentada en `COMMUNICATION-DELIVERY-REVIEW-20260905.md`.
- **Recovery:** reintento fenced del mismo outbox; nunca generar una segunda intención para resolver un resultado ambiguo.

Este CUF satisface el criterio de piloto que exige una comunicación aplicable con estado real de proveedor correlacionado y revisión humana.

## Evidencia automatizada del piloto

`bin/production-readiness-evidence.php` es un collector **read-only** pensado para ejecutarse en el host desplegado. Produce únicamente metadatos técnicos/aggregados:

- SHA desplegado cuando puede resolverse de forma local;
- versión PHP;
- presencia de tablas críticas (sin filas ni valores personales);
- presencia de tablas/constraints financieros relevantes;
- agregados de estados de `sharky_outbox` y día almacenado del último `SENT`; `sent_at` es un `DATETIME` sin zona normalizada y por eso el collector **no lo etiqueta como UTC**;
- disponibilidad del schema de delivery y conteos agregados de estados de proveedor correlacionados a un outbox mediante `provider_message_id`;
- estado explícito de los gates `field`, `restore` y `communication_delivery`.

No emite nombres, teléfonos, correos, payloads, hashes de contacto, credenciales ni contenido de conversaciones. Aunque existan `DELIVERED`/`READ`, el collector expone `EVIDENCE AVAILABLE — HUMAN REVIEW REQUIRED` y nunca convierte por sí solo el gate en PASS. El PASS de Communication status es una decisión humana versionada, no una salida automática del collector.

En producción el usuario SSH de deploy no tiene permiso de lectura sobre `config/database.local.php`, que permanece `root:www-data` con acceso restringido. Para no ampliar permisos sobre secretos, `api/production-readiness-evidence.php` se usa únicamente a través del host local y exige `POST` más un token aleatorio de 256 bits creado por el workflow en `/tmp`, válido como máximo 120 segundos y eliminado al terminar. El check de `REMOTE_ADDR` se conserva como defensa adicional, pero el token efímero es obligatorio incluso si un proxy altera la dirección observada. La entrada ejecuta el mismo collector bajo PHP/FPM, que ya posee el acceso mínimo necesario a la configuración local; no confía en `X-Forwarded-For` ni en cabeceras de Cloudflare y no introduce mutaciones de negocio.

`.github/workflows/production-readiness-evidence.yml` permite obtener dos artefactos manuales y auditables:

1. **production snapshot**: usa el mismo canal SSH del deploy para crear el token efímero e invocar por loopback la entrada interna del collector, elimina el token inmediatamente, exige que el SHA desplegado coincida exactamente con el SHA del workflow, comprueba desde Internet que una petición sin el token válido termina en 4xx y mide una solicitud HTTPS pública a `https://hnatacion.com/`;
2. **restore lab**: crea dos DB aisladas en MariaDB de CI, importa `database/schema_hache_monteverde_v1.sql` como baseline sintético versionado, inserta un marker, hace dump y restore, y verifica integridad básica. Este drill **no reproduce por sí solo todas las migraciones aplicadas en producción** y no sustituye un restore de un backup real de producción.

El 2026-09-05 se ejecutó correctamente el restore lab sintético aislado: marker, tabla `pagos` y los dos triggers de validez de pago fueron verificados tras dump/restore. Este resultado reduce incertidumbre del mecanismo, pero no cambia por sí solo el gate de restore a PASS porque no utilizó un backup real de producción ni midió RPO/RTO del proceso real.

El mismo día se ejecutó el snapshot productivo seguro del run `33945691437` contra `35c305c0c92bf12915612a72b4a563744a0d09b1`. La evidencia minimizada confirmó schema de delivery disponible, 36 estados reales correlacionados (`DELIVERED=10`, `READ=26`, `FAILED=0`), bloqueo externo sin token válido y health local `200`. Tras revisión humana explícita, `Communication status` queda cerrado en PASS; véase `COMMUNICATION-DELIVERY-REVIEW-20260905.md`.

## Estado de los tres gates del criterio de salida P1

| Gate | Estado | Evidencia necesaria para cerrarlo |
| --- | --- | --- |
| Campo | `NOT EVALUATED` | ventana representativa de RUM/Web Vitals o evidencia de campo equivalente aprobada; una medición HTTP aislada no cuenta como p75 de campo |
| Restore | `PARTIAL` | restore lab sintético del baseline ya ejecutado y verificado; falta restore aislado de un backup real con RPO/RTO medidos |
| Communication status | `PASS` | evidencia real de Meta correlacionada y revisión humana registradas en `COMMUNICATION-DELIVERY-REVIEW-20260905.md` |

El PASS de Communication status no altera los otros dos gates. Field y Restore se mantienen abiertos hasta tener su evidencia específica.

## Política de cambios del piloto

- no tocar Standard/Checklist de Hache Base desde este repo;
- no exponer secretos o PII en artefactos de Actions;
- no ejecutar restore sobre la DB de producción;
- no añadir una mutación de negocio para “probar” el sistema;
- cada paso que cruce producción debe ser read-only o usar un target aislado explícito;
- no mezclar esta adopción con cambios funcionales de Sharky/Historias que estén en PRs separados.