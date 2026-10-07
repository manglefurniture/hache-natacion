# Monitor SaladCloud

La página privada `/salad-monitor.php` y la API `/api/salad-monitor.php` son solo para `ADMIN`.

El proceso `hache-salad-monitor.timer` consulta SaladCloud cada cinco minutos en modo lectura, guarda un snapshot atómico con el último estado válido y evalúa las alertas amarillas. El navegador **no consulta SaladCloud directamente**: lee únicamente el snapshot local. Así se evita multiplicar llamadas a Salad cuando se abre o recarga el dashboard desde móvil o escritorio.

Si Salad falla temporalmente:
- el último snapshot completo sigue disponible;
- un fallo aislado de un Container Group conserva el último dato válido de ese grupo y lo marca como desactualizado;
- un fallo de la consulta global no borra ni reemplaza el snapshot anterior.

No existe ninguna operación que cambie, reasigne o detenga instancias.

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
