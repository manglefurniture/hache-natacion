<?php
declare(strict_types=1);

require_once __DIR__.'/../config/auth.php';
auth_require(['ADMIN','VERIFICADOR']);
$config = require __DIR__.'/../config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function csvCell(mixed $value): mixed
{
    if (!is_string($value)) return $value;
    return preg_match('/^\s*[=+\-@]/u', $value) ? "'".$value : $value;
}

$periodo = trim((string)($_GET['periodo'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
    http_response_code(422);
    exit('Periodo inválido');
}
$clave = auth_active_sede_clave();
$stmt = $pdo->prepare('SELECT id,nombre,socio,porcentaje_mensualidad_socio,porcentaje_intensivo_socio,porcentaje_inscripcion_socio,minimo_mensual_socio FROM sedes WHERE clave=:clave AND activo=1 LIMIT 1');
$stmt->execute([':clave'=>$clave]);
$sede = $stmt->fetch();
if (!$sede) {
    http_response_code(422);
    exit('Sede activa inválida');
}

[$anio,$mes] = array_map('intval', explode('-', $periodo));
$desde = $periodo.'-01';
$hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
$sql = "SELECT
          COALESCE(SUM(CASE WHEN p.tipo='MENSUALIDAD' THEN p.importe ELSE 0 END),0) mensualidades,
          COALESCE(SUM(CASE WHEN p.tipo='INSCRIPCION' THEN p.importe ELSE 0 END),0) inscripciones,
          COALESCE(SUM(CASE WHEN p.tipo='INTENSIVO' THEN p.importe ELSE 0 END),0) intensivos
        FROM pagos p
        LEFT JOIN mensualidades m ON m.id=p.mensualidad_id
        LEFT JOIN inscripciones i ON i.id=p.inscripcion_id
        LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id
        WHERE p.estado='VALIDO' AND (
          (p.tipo='MENSUALIDAD' AND m.sede_id=:sm AND m.mes=:mes AND m.anio=:anio)
          OR (p.tipo='INSCRIPCION' AND i.sede_id=:si AND i.fecha BETWEEN :di AND :hi)
          OR (p.tipo='INTENSIVO' AND ci.sede_id=:sc AND ci.fecha_inicio BETWEEN :dc AND :hc)
        )";
$stmt = $pdo->prepare($sql);
$stmt->execute([':sm'=>$sede['id'],':mes'=>$mes,':anio'=>$anio,':si'=>$sede['id'],':di'=>$desde,':hi'=>$hasta,':sc'=>$sede['id'],':dc'=>$desde,':hc'=>$hasta]);
$totals = $stmt->fetch();

$mensualidades = (float)$totals['mensualidades'];
$intensivos = (float)$totals['intensivos'];
$inscripciones = (float)$totals['inscripciones'];
$pMensualidad = (float)$sede['porcentaje_mensualidad_socio']/100;
$pIntensivo = (float)$sede['porcentaje_intensivo_socio']/100;
$pInscripcion = (float)$sede['porcentaje_inscripcion_socio']/100;
$socioMensualidad = $mensualidades*$pMensualidad;
$socioIntensivo = $intensivos*$pIntensivo;
$socioInscripcion = $inscripciones*$pInscripcion;
$socioTotal = $socioMensualidad+$socioIntensivo+$socioInscripcion;
$hacheTotal = ($mensualidades+$intensivos+$inscripciones)-$socioTotal;
$minimo = $sede['minimo_mensual_socio'] !== null ? (float)$sede['minimo_mensual_socio'] : null;

$filename = 'hache-liquidacion-'.strtolower($clave).'-'.$periodo.'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, array_map('csvCell', ['Sede',$sede['nombre']]));
fputcsv($out, array_map('csvCell', ['Periodo',$periodo]));
fputcsv($out, array_map('csvCell', ['Concepto','Total','Hache',$sede['socio'],'% socio']));
fputcsv($out, array_map('csvCell', ['Mensualidades',$mensualidades,$mensualidades-$socioMensualidad,$socioMensualidad,(float)$sede['porcentaje_mensualidad_socio']]));
fputcsv($out, array_map('csvCell', ['Cursos intensivos',$intensivos,$intensivos-$socioIntensivo,$socioIntensivo,(float)$sede['porcentaje_intensivo_socio']]));
fputcsv($out, array_map('csvCell', ['Inscripciones',$inscripciones,$inscripciones-$socioInscripcion,$socioInscripcion,(float)$sede['porcentaje_inscripcion_socio']]));
fputcsv($out, array_map('csvCell', ['TOTAL',$mensualidades+$intensivos+$inscripciones,$hacheTotal,$socioTotal,'']));
if ($minimo !== null && $minimo > 0) fputcsv($out, array_map('csvCell', ['Mínimo '.$sede['socio'],$minimo,'',$socioTotal >= $minimo ? 'ALCANZADO' : 'PENDIENTE','']));
fclose($out);
