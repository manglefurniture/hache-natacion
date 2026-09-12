# Acceso operativo MariaDB para `deploy-hache`

## Objetivo

Permitir que `deploy-hache` ejecute diagnósticos y migraciones sobre la base `hache_natacion` sin otorgarle sudo general ni privilegios globales de MariaDB.

## Diseño

El wrapper autorizado `/usr/local/sbin/deploy-hache-natacion` incorpora dos comandos:

- `db-ops-bootstrap`: crea/rota el usuario MariaDB `deploy_hache_ops@localhost`, aplica el grant restringido y escribe `/home/deploy-hache/.my.cnf` con permisos `0600`.
- `db-ops-status`: verifica credenciales y muestra el usuario/base activos y sus grants.

El usuario MariaDB queda limitado a `hache_natacion.*` con:

`SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE`.

No recibe `ALL PRIVILEGES`, `SUPER`, `FILE`, `GRANT OPTION`, gestión de usuarios ni privilegio `DROP`.

## Uso operativo

Bootstrap, solo cuando se instala o rota la credencial:

```bash
sudo /usr/local/sbin/deploy-hache-natacion db-ops-bootstrap
```

Verificación:

```bash
sudo /usr/local/sbin/deploy-hache-natacion db-ops-status
```

Después del bootstrap, `deploy-hache` puede trabajar directamente sin sudo:

```bash
mariadb --defaults-extra-file=/home/deploy-hache/.my.cnf
mariadb --defaults-extra-file=/home/deploy-hache/.my.cnf < database/migrations/archivo.sql
```

## Límites

- Código y migraciones versionadas siguen entrando por GitHub/PR/Quality antes de producción.
- El acceso directo a MariaDB se reserva para aplicar migraciones aprobadas, diagnóstico y operaciones de datos autorizadas.
- No leer ni reutilizar `config/database.local.php`; las credenciales operativas son independientes.
- Si una operación futura necesita `DROP` u otro privilegio no concedido, debe revisarse de forma explícita en lugar de ampliar permisos silenciosamente.
