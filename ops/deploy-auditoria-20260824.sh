#!/usr/bin/env bash
set -Eeuo pipefail

APP=/var/www/hache-natacion
OLD=65d5e0aaaa5f8ebe4b24e1fd15e913011f037a4a
NEW=ae6fe76ee3b4ee245550c9786aafad36e7f48780
PRIOR_BACKUP=/var/backups/hache-natacion/20260824-190703
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/var/backups/hache-natacion/deploy-${STAMP}"
CODE_CHANGED=0

on_error() {
    rc=$?
    trap - ERR
    echo "DEPLOY_ERROR rc=${rc} linea=${BASH_LINENO[0]}"
    echo "RESPALDO_FRESCO=${BACKUP}"
    if [[ "$CODE_CHANGED" == 1 ]]; then
        echo "Revirtiendo inmediatamente el codigo al commit estable..."
        git -C "$APP" switch --detach "$OLD" || true
        php-fpm8.4 -t || true
        systemctl reload php8.4-fpm || true
        echo "CODIGO_REVERTIDO_A=${OLD}"
        echo "La base no se restaura automaticamente; el dump completo esta en ${BACKUP}."
    fi
    exit "$rc"
}
trap on_error ERR

echo "=== PREFLIGHT ==="
cd "$APP"
test "$(git branch --show-current)" = main
test "$(git rev-parse HEAD)" = "$OLD"
test -s "$PRIOR_BACKUP/code.tar.gz"
test -s "$PRIOR_BACKUP/mariadb.sql.gz"
(cd "$PRIOR_BACKUP" && sha256sum -c checksums.sha256)

git fetch origin main
test "$(git rev-parse origin/main)" = "$NEW"
git merge-base --is-ancestor "$OLD" "$NEW"
git diff --check "$OLD" "$NEW"

git diff --name-only "$OLD" "$NEW" | sort -u > /tmp/hache-deploy-changed.txt
{
    git diff HEAD --name-only
    git ls-files --others --exclude-standard
} | sort -u > /tmp/hache-deploy-local.txt
OVERLAP="$(comm -12 /tmp/hache-deploy-local.txt /tmp/hache-deploy-changed.txt || true)"
if [[ -n "$OVERLAP" ]]; then
    echo "CONFLICTO_CON_ARCHIVOS_LOCALES:"
    echo "$OVERLAP"
    exit 20
fi
git branch "rollback/pre-auditoria-${STAMP}" "$OLD"

echo "=== RESPALDO FRESCO ==="
install -d -m 700 "$BACKUP"
git status --short > "$BACKUP/git-status-before.txt"
git rev-parse HEAD > "$BACKUP/commit-before.txt"
git bundle create "$BACKUP/repository.bundle" --all
tar --exclude='hache-natacion/.git' -C /var/www -czf "$BACKUP/worktree-before.tar.gz" hache-natacion
mariadb-dump \
    --single-transaction --quick --routines --triggers --events \
    --hex-blob --default-character-set=utf8mb4 \
    --databases hache_natacion | gzip -9 > "$BACKUP/mariadb-before.sql.gz"
gzip -t "$BACKUP/mariadb-before.sql.gz"
git bundle verify "$BACKUP/repository.bundle" >/dev/null
sha256sum \
    "$BACKUP/repository.bundle" \
    "$BACKUP/worktree-before.tar.gz" \
    "$BACKUP/mariadb-before.sql.gz" > "$BACKUP/checksums.sha256"
chmod -R go-rwx "$BACKUP"

HIST_TABLES=(
    sedes usuarios alumnos planes horarios inscripciones mensualidades pagos
    cursos_intensivos curso_intensivo_alumnos sesiones asistencias ausencias
    avisos_ausencia reposiciones_regulares cierres_mensuales mensajes registros_publicos
)
for table in "${HIST_TABLES[@]}"; do
    printf '%s\t' "$table"
    mariadb -NBe "SELECT COUNT(*) FROM \`${table}\`" hache_natacion
done > "$BACKUP/historical-before.tsv"

echo "=== MIGRACIONES AUDITADAS ==="
MIGRATIONS=(
    database/migrations/20260815_group_intensive_model.sql
    database/migrations/20260816_security_messages_settings.sql
    database/migrations/20260819_palapas_finanzas.sql
    database/migrations/20260822_integrity_support.sql
)
for migration in "${MIGRATIONS[@]}"; do
    echo "Aplicando ${migration}"
    git show "$NEW:$migration" > "$BACKUP/$(basename "$migration")"
    mariadb hache_natacion < "$BACKUP/$(basename "$migration")"
done

for table in "${HIST_TABLES[@]}"; do
    printf '%s\t' "$table"
    mariadb -NBe "SELECT COUNT(*) FROM \`${table}\`" hache_natacion
done > "$BACKUP/historical-after.tsv"
cmp "$BACKUP/historical-before.tsv" "$BACKUP/historical-after.tsv"

test "$(mariadb -NBe "SELECT COUNT(*) FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id JOIN alumnos a ON a.id=cia.alumno_id JOIN horarios h ON h.id=cia.horario_id WHERE a.sede_id<>ci.sede_id OR h.sede_id<>ci.sede_id" hache_natacion)" = 0
test "$(mariadb -NBe "SELECT COUNT(*) FROM (SELECT cia.alumno_id FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE ci.estado IN ('PROGRAMADO','EN_CURSO') GROUP BY cia.alumno_id HAVING COUNT(*)>1) x" hache_natacion)" = 0
test "$(mariadb -NBe "SELECT COUNT(*) FROM mensajes WHERE sede_id IS NULL" hache_natacion)" = 0
mariadb-check hache_natacion >/dev/null

echo "=== FAST-FORWARD DE CODIGO ==="
git merge --ff-only origin/main
CODE_CHANGED=1
test "$(git rev-parse HEAD)" = "$NEW"

while IFS= read -r file; do
    [[ -z "$file" ]] && continue
    php -l "$APP/$file" >/dev/null
done < <(git diff --name-only "$OLD" "$NEW" -- '*.php')

php-fpm8.4 -t
nginx -t
systemctl reload php8.4-fpm

echo "=== SMOKE TESTS DE PRODUCCION ==="
HOME_HTTP="$(curl -sS --resolve hnatacion.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://hnatacion.com/)"
HEALTH_HTTP="$(curl -sS --resolve hnatacion.com:443:127.0.0.1 -o "$BACKUP/health.json" -w '%{http_code}' https://hnatacion.com/api/health.php)"
LOGIN_HTTP="$(curl -sS --resolve hnatacion.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://hnatacion.com/index.php)"
LOOKUP_HTTP="$(curl -sS --resolve hnatacion.com:443:127.0.0.1 -o /dev/null -w '%{http_code}' 'https://hnatacion.com/api/alumno-por-whatsapp.php?telefono=%2B529900000001')"
N8N_HTTP="$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1:5678/healthz)"
test "$HOME_HTTP" = 200
test "$HEALTH_HTTP" = 200
test "$LOGIN_HTTP" = 200
test "$LOOKUP_HTTP" = 401
test "$N8N_HTTP" = 200

SHARKY_HTTP="$(curl -sS --resolve hnatacion.com:443:127.0.0.1 \
    -H 'Content-Type: application/json' \
    -o "$BACKUP/sharky.json" -w '%{http_code}' \
    --data '{"message":"¿Cuál es la edad mínima para inscribirse?","history":[]}' \
    https://hnatacion.com/api/sharky.php)"
test "$SHARKY_HTTP" = 200
php -r '$j=json_decode(file_get_contents($argv[1]),true); if(!is_array($j)||($j["ok"]??false)!==true||trim((string)($j["answer"]??""))==="") exit(1);' "$BACKUP/sharky.json"

for table in "${HIST_TABLES[@]}"; do
    printf '%s\t' "$table"
    mariadb -NBe "SELECT COUNT(*) FROM \`${table}\`" hache_natacion
done > "$BACKUP/historical-final.tsv"
cmp "$BACKUP/historical-before.tsv" "$BACKUP/historical-final.tsv"

rm -f /tmp/hache-deploy-changed.txt /tmp/hache-deploy-local.txt
trap - ERR
echo "DEPLOY_OK"
echo "COMMIT=$(git rev-parse HEAD)"
echo "ROLLBACK_BRANCH=rollback/pre-auditoria-${STAMP}"
echo "BACKUP=$BACKUP"
echo "HTTP home=$HOME_HTTP health=$HEALTH_HTTP login=$LOGIN_HTTP lookup=$LOOKUP_HTTP n8n=$N8N_HTTP sharky=$SHARKY_HTTP"
echo "DATOS_HISTORICOS_INTACTOS"
