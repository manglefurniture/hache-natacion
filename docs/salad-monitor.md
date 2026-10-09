# Monitor SaladCloud

La página privada `/salad-monitor.php` y la API `/api/salad-monitor.php` son solo para `ADMIN`.

El proceso `hache-salad-monitor.timer` consulta SaladCloud cada cinco minutos en modo lectura, guarda un snapshot atómico con el último estado válido y evalúa alertas amarillas y rojas. El navegador **no consulta SaladCloud directamente**: lee únicamente el snapshot local. Así se evita multiplicar llamadas a Salad cuando se abre o recarga el dashboard desde móvil o escritorio.

Si Salad falla temporalmente:
- el último snapshot completo sigue disponible;
- un fallo aislado de un Container Group conserva el último dato válido de ese grupo y lo marca como desactualizado;
- un fallo de la consulta global no borra ni reemplaza el snapshot anterior.

El monitor es de solo lectura salvo una automatización explícita: puede pedir a SaladCloud que **reasigne una instancia**. Para RTX 4070 Ti SUPER en prioridad Low aplica dos lecturas consecutivas de media de 15 minutos por debajo de 125 TH/s, separadas por un mínimo de 5 minutos. Para las demás GPU conserva la regla previa de hashrate instantáneo por debajo de 130 TH/s durante 5 minutos, después de observar una lectura sana. Usa el endpoint oficial de reallocate para que Salad entregue un nodo distinto; no cambia el Container Group, la imagen, el precio ni la cantidad de réplicas.

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


## Indicador orientativo de rentabilidad: 4070 Ti SUPER Low

Para cada grupo de prioridad **Low** con **una sola instancia operativa** cuya GPU observada sea una **RTX 4070 Ti SUPER**, el monitor calcula una **categoría informativa** con la media de hashrate de 15 minutos:

- **140 TH/s o más:** margen orientativo favorable.
- **Desde 125 TH/s y menos de 140 TH/s:** aviso amarillo preventivo por margen reducido.
- **Menos de 125 TH/s:** aviso amarillo por posible pérdida; **no** es una prueba de pérdidas reales.
- **Sin datos de 15 minutos, instancia no lista o grupos multirréplica:** rentabilidad no evaluable, sin falsas alertas económicas.

Los umbrales de 140/125 TH/s son referencias **provisionales** basadas en la hipótesis de **$0.13 USD/h**, un precio del PRL y dificultad de red de una observación puntual. **No constituyen un cálculo de beneficio en tiempo real**. Para tomar decisiones económicas hay que medir PRL confirmados, precio de venta neto, comisiones y consumo real en Salad. Una caída momentánea del hashrate no implica automáticamente pérdida.

El estado amarillo usa las notificaciones ntfy existentes, sólo al entrar en alerta y sin duplicarlas mientras permanezca amarillo; los datos desactualizados no generan transiciones. El panel muestra la categoría por GPU y aclara que es estimada. El cálculo de costos de la caja reconoce cualquier grupo Low cuya GPU esté validada como 4070 Ti SUPER, aunque su nombre no contenga «4070».

La clasificación de 140/125 TH/s sigue siendo orientativa. **No calcula ingresos ni pérdidas reales.** En RTX 4070 Ti SUPER Low, la media inferior a 125 TH/s sirve además para la reasignación, siempre con confirmación consecutiva y sin detener el grupo ni cambiar precio o GPU.

## Auto-Reallocate (políticas por GPU)

El timer consulta Salad cada cinco minutos. Sólo considera una instancia única `ready/running`, con lecturas actuales y un ID válido.

- **RTX 4070 Ti SUPER Low:** requiere dos **muestras diferentes y recientes** del promedio de 15 min por debajo de 125 TH/s, con al menos 300 segundos entre la primera y la confirmación. Compara la fecha de la línea de hashrate de 15 minutos; si la API repite el mismo log, no cuenta otra muestra. La prioridad real informada por Salad debe ser `low`, además del nombre compatible. La lectura instantánea puede estar por encima de 130 TH/s; no impide actuar si persiste la media baja.
- **Resto de GPU:** se mantiene la protección histórica: el grupo debe haber registrado al menos 130 TH/s anteriormente y mantenerse por debajo de 130 TH/s instantáneos durante al menos 300 segundos.
- Si falta la media de 15 min, su fecha, la prioridad Low real, el grupo está desactualizado, cambia el nodo, hay más de una instancia activa o el promedio se recupera, se reinicia la secuencia para la RTX 4070 Ti SUPER Low.
- Una instancia recibe como máximo una solicitud de reasignación. El control se rearma al recibir un `instance_id` nuevo.
- Si Salad rechaza una solicitud, se registra el fallo y se establece una espera de 15 minutos antes de otro intento.
- **Importante:** la notificación ntfy es posterior a la aceptación de Salad; si ntfy falla no se repetirá la acción contra el mismo nodo.

El registro privado `/var/lib/hache-natacion/salad-monitor-reallocation-audit.jsonl` (modo 0600, rotación acotada) registra cada evaluación y su motivo, junto con solicitudes aceptadas o fallidas. No registra credenciales. Las alertas amarillas continúan siendo independientes del acto de reasignación.

La hipótesis económica de 125 TH/s presupone un precio aproximado de USD 0.13/h para Low y es **provisional**, no una garantía de pérdida real. No ampliar automáticamente a otros precios o GPU sin cálculos propios.
