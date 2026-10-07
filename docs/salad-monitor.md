# Monitor SaladCloud

La página privada `/salad-monitor.php` y la API `/api/salad-monitor.php` son solo para `ADMIN`. La API consulta en modo lectura los Container Groups e instancias del proyecto `prl-tests`, y los logs de SRBMiner. No existe ninguna operación que cambie, reasigne o detenga instancias.

## Configuración de servidor

Configura el secreto únicamente en el VPS, con permisos de lectura para el proceso PHP, por ejemplo en `/etc/hache-salad-monitor.env`:

```text
SALAD_API_KEY=...
SALAD_ORGANIZATION=hache
SALAD_PROJECT=prl-tests
```

También se aceptan esas variables de entorno. No crear ni subir este archivo al repositorio. La clave se envía sólo en la cabecera servidor-a-servidor `Salad-Api-Key`; nunca llega al navegador ni aparece en las respuestas JSON.

El histórico visual actual se conserva en el navegador durante 24 horas. Es deliberadamente local: la solicitud `GET` del dashboard no escribe en la base de datos ni crea una carga adicional de mutación en producción.

## Verificación

Ejecutar `php tests/salad-monitor-regression.php`. Antes de activar la ruta en el VPS, verificar que `php-curl` esté instalado y que la clave tenga permisos de lectura sobre la organización/proyecto indicados.
