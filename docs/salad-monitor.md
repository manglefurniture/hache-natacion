# Monitor SaladCloud

La página privada `/salad-monitor.php` y la API `/api/salad-monitor.php` son solo para `ADMIN`.

El proceso `hache-salad-monitor.timer` consulta SaladCloud cada cinco minutos en modo lectura, guarda un snapshot atómico con el último estado válido y evalúa alertas amarillas y rojas. El navegador **no consulta SaladCloud directamente**: lee únicamente el snapshot local. Así se evita multiplicar llamadas a Salad cuando se abre o recarga el dashboard desde móvil o escritorio.

Si Salad falla temporalmente:
- el último snapshot completo sigue disponible;
- un fallo aislado de un Container Group conserva el último dato válido de ese grupo y lo marca como desactualizado;
- un fallo de la consulta global no borra ni reemplaza el snapshot anterior.

El monitor es de solo lectura salvo una automatización explícita: puede pedir a SaladCloud que **reasigne una instancia** cuando un grupo previamente sano permanece por debajo de 130 TH/s durante al menos 5 minutos. Usa el endpoint oficial de reallocate para que Salad entregue un nodo distinto; no cambia el Container Group, la imagen, el precio ni la cantidad de réplicas.

## Configuración de servidor

Configura el secreto únicamente en el VPS, con permisos de lectura para el proceso PHP, por ejemplo en `/etc/hache-salad-monitor.env`:

```text
SALAD_API_KEY=...
SALAD_ORGANIZATION=hache
SALAD_PROJECT=prl-tests
```

También se aceptan esas variables de entorno. No crear ni subir este archivo al repositorio. La clave se envía sólo en la cabecera servidor-a-servidor `Salad-Api-Key`; nunca llega al navegador ni aparece en las respuestas JSON.

El snapshot se guarda por defecto en `/var/lib/hache-natacion/salad-monitor-snapshot.json`. Puede cambiarse con `SALAD_MONITOR_SNAPSHOT_FILE`.

El histórico visual de 24 horas sigue siendo local al navegador.

## Verificación

Ejecutar:

```bash
php -l config/salad-monitor.php
php -l bin/salad-monitor-poll.php
php -l api/salad-monitor.php
php tests/salad-monitor-regression.php
```

Tras desplegar, ejecutar una vez `hache-salad-monitor.service` para generar el primer snapshot y comprobar después `/api/salad-monitor.php`.


## Alertas al teléfono

El poller envía notificaciones por ntfy únicamente en transiciones de estado para evitar spam:

- `green -> yellow`: alerta amarilla por temperatura, ventilador, shares rechazadas o errores HW.
- `green/yellow -> red`: alerta roja si el grupo deja de estar operativo, la instancia deja de estar `ready/running`, desaparece la instancia o se pierde el hashrate/dato de GPU.
- Mientras un grupo permanezca en el mismo estado amarillo o rojo no repite la alerta.
- Los datos `stale` conservados por un fallo temporal de la API no generan una falsa alerta roja.
- La latencia máxima normal depende del timer de cinco minutos.


## Auto-reallocate por hashrate bajo

La protección de rendimiento se evalúa en cada ciclo del timer de 5 minutos:

- El grupo debe haber demostrado previamente al menos una lectura de **130 TH/s o más**. Esto evita reciclar automáticamente pruebas/GPU que nunca estuvieron diseñadas para superar ese umbral.
- Si una única instancia activa y `ready/running` cae por debajo de **130 TH/s**, se inicia un temporizador persistente.
- Si sigue por debajo del umbral al menos **300 segundos** después, el poller solicita `POST .../instances/{instance_id}/reallocate` a SaladCloud.
- La misma instancia nunca recibe dos solicitudes de reallocate; el control se rearma cuando Salad entrega un nuevo `instance_id`.
- Un snapshot `stale`, un fallo de API o un grupo sin exactamente una instancia activa no dispara la automatización.
- Tras aceptar Salad la reasignación, se envía una notificación ntfy `Salad Monitor - AUTO REALLOCATE` con el hashrate observado.
