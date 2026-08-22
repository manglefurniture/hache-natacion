<?php
declare(strict_types=1);

function regla_periodo_regular_actual(string $sedeClave, ?string $cicloPago, ?DateTimeImmutable $referencia=null): array
{
    $d=$referencia ?: new DateTimeImmutable('today');
    $sede=strtoupper($sedeClave);
    $ciclo=strtoupper((string)$cicloPago);
    if($sede==='PALAPAS' && $ciclo==='P15'){
        $inicio=((int)$d->format('j')>=15)
            ? new DateTimeImmutable($d->format('Y-m-15'))
            : new DateTimeImmutable($d->modify('-1 month')->format('Y-m-15'));
        $fin=$inicio->modify('+1 month')->modify('-1 day');
        return ['mes'=>(int)$inicio->format('n'),'anio'=>(int)$inicio->format('Y'),'inicio'=>$inicio->format('Y-m-d'),'fin'=>$fin->format('Y-m-d')];
    }
    $inicio=new DateTimeImmutable($d->format('Y-m-01'));
    $fin=$inicio->modify('last day of this month');
    return ['mes'=>(int)$inicio->format('n'),'anio'=>(int)$inicio->format('Y'),'inicio'=>$inicio->format('Y-m-d'),'fin'=>$fin->format('Y-m-d')];
}

function regla_asegurar_tabla_negocio(PDO $pdo): void
{
    static $ok=false;
    if($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS alumno_reglas_negocio (
        alumno_id CHAR(36) PRIMARY KEY,
        inscripcion_historica_cubierta TINYINT(1) NOT NULL DEFAULT 0,
        nota VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_alumno_reglas_negocio_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE
    )");
    $ok=true;
}

function regla_inscripcion_historica_cubierta(PDO $pdo,string $alumnoId): bool
{
    regla_asegurar_tabla_negocio($pdo);
    $st=$pdo->prepare("SELECT inscripcion_historica_cubierta FROM alumno_reglas_negocio WHERE alumno_id=:a LIMIT 1");
    $st->execute([':a'=>$alumnoId]);
    return (bool)$st->fetchColumn();
}

function regla_marcar_inscripcion_historica(PDO $pdo,string $alumnoId,bool $cubierta,?string $nota=null): void
{
    regla_asegurar_tabla_negocio($pdo);
    $st=$pdo->prepare("INSERT INTO alumno_reglas_negocio(alumno_id,inscripcion_historica_cubierta,nota) VALUES(:a,:c,:n)
        ON DUPLICATE KEY UPDATE inscripcion_historica_cubierta=VALUES(inscripcion_historica_cubierta),nota=VALUES(nota),updated_at=NOW()");
    $st->execute([':a'=>$alumnoId,':c'=>$cubierta?1:0,':n'=>$nota]);
}

function regla_es_continuidad_intensivo_monteverde(PDO $pdo,string $alumnoId,string $sedeId): bool
{
    $st=$pdo->prepare("SELECT 1 FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a AND ci.sede_id=:s AND cia.continua_regular=1 LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
    return (bool)$st->fetchColumn();
}

function regla_inscripcion_regular_cubierta(PDO $pdo,string $alumnoId,string $sedeId,string $sedeClave): bool
{
    if(regla_inscripcion_historica_cubierta($pdo,$alumnoId)) return true;
    if(strtoupper($sedeClave)==='MONTEVERDE' && regla_es_continuidad_intensivo_monteverde($pdo,$alumnoId,$sedeId)) return true;
    $st=$pdo->prepare("SELECT 1 FROM pagos p INNER JOIN inscripciones i ON i.id=p.inscripcion_id WHERE p.alumno_id=:a AND i.sede_id=:s AND p.tipo='INSCRIPCION' AND p.estado='VALIDO' LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId]);
    return (bool)$st->fetchColumn();
}

function regla_mensualidad_regular_cubierta(PDO $pdo,string $alumnoId,string $sedeId,string $sedeClave,?string $cicloPago): bool
{
    $p=regla_periodo_regular_actual($sedeClave,$cicloPago);
    $st=$pdo->prepare("SELECT 1 FROM mensualidades m WHERE m.alumno_id=:a AND m.sede_id=:s AND m.mes=:m AND m.anio=:y AND m.estado='PAGADA' LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId,':m'=>$p['mes'],':y'=>$p['anio']]);
    return (bool)$st->fetchColumn();
}

function regla_crear_mensualidad_pendiente(PDO $pdo,string $alumnoId,string $sedeId,string $sedeClave,?string $cicloPago,string $planId,float $precio,string $createdBy,?DateTimeImmutable $referencia=null): void
{
    $p=regla_periodo_regular_actual($sedeClave,$cicloPago,$referencia);
    $st=$pdo->prepare("SELECT id FROM mensualidades WHERE alumno_id=:a AND sede_id=:s AND mes=:m AND anio=:y LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$sedeId,':m'=>$p['mes'],':y'=>$p['anio']]);
    if($st->fetchColumn()) return;
    $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $importe=number_format($precio,2,'.','');
    $st=$pdo->prepare("INSERT INTO mensualidades(id,sede_id,alumno_id,mes,anio,periodo_inicio,periodo_fin,plan_id,importe_estandar,importe_a_cobrar,importe_cobrado,estado,observacion,fecha_pago,created_by) VALUES(:id,:s,:a,:m,:y,:pi,:pf,:plan,:ie,:ia,NULL,'PENDIENTE',NULL,NULL,:u)");
    $st->execute([':id'=>$id,':s'=>$sedeId,':a'=>$alumnoId,':m'=>$p['mes'],':y'=>$p['anio'],':pi'=>$p['inicio'],':pf'=>$p['fin'],':plan'=>$planId,':ie'=>$importe,':ia'=>$importe,':u'=>$createdBy]);
}

function regla_recalcular_alumno_regular(PDO $pdo,string $alumnoId): array
{
    $st=$pdo->prepare("SELECT a.id,a.sede_id,a.ciclo_pago,a.plan_actual_id,a.estado_administrativo,s.clave sede_clave FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id WHERE a.id=:a LIMIT 1");
    $st->execute([':a'=>$alumnoId]);$a=$st->fetch();
    if(!$a || $a['estado_administrativo']==='BAJA' || empty($a['plan_actual_id'])) return ['aplica'=>false];
    $st=$pdo->prepare("SELECT 1 FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a AND ci.sede_id=:s AND ci.estado IN ('PROGRAMADO','EN_CURSO') LIMIT 1");
    $st->execute([':a'=>$alumnoId,':s'=>$a['sede_id']]);
    if($st->fetchColumn()) return ['aplica'=>false,'intensivo_activo'=>true];
    $historica=regla_inscripcion_historica_cubierta($pdo,$alumnoId);
    $ins=regla_inscripcion_regular_cubierta($pdo,$alumnoId,(string)$a['sede_id'],(string)$a['sede_clave']);
    $men=regla_mensualidad_regular_cubierta($pdo,$alumnoId,(string)$a['sede_id'],(string)$a['sede_clave'],$a['ciclo_pago']);
    $estado=($ins && $men)?'ACTIVO':'PENDIENTE';
    if($a['estado_administrativo']!==$estado){$up=$pdo->prepare("UPDATE alumnos SET estado_administrativo=:e,updated_at=NOW() WHERE id=:a AND estado_administrativo<>'BAJA'");$up->execute([':e'=>$estado,':a'=>$alumnoId]);}
    return ['aplica'=>true,'inscripcion_cubierta'=>$ins,'mensualidad_cubierta'=>$men,'estado'=>$estado,'inscripcion_historica'=>$historica,'inscripcion_exenta'=>strtoupper((string)$a['sede_clave'])==='MONTEVERDE'&&regla_es_continuidad_intensivo_monteverde($pdo,$alumnoId,(string)$a['sede_id'])];
}

function regla_reconciliar_sede(PDO $pdo,string $sedeId): void
{
    $st=$pdo->prepare("SELECT a.id FROM alumnos a WHERE a.sede_id=:s AND a.estado_administrativo<>'BAJA' AND a.plan_actual_id IS NOT NULL");
    $st->execute([':s'=>$sedeId]);
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $id) regla_recalcular_alumno_regular($pdo,(string)$id);
}
