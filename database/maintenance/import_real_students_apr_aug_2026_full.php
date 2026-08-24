<?php
declare(strict_types=1);

/**
 * Importación COMPLETA abril-agosto 2026.
 *
 * Corrige la primera versión conservando también a TODOS los alumnos que
 * cursaron intensivos históricos aunque no hayan continuado a clases regulares.
 *
 * Simulación: php database/maintenance/import_real_students_apr_aug_2026_full.php
 * Aplicar:    php database/maintenance/import_real_students_apr_aug_2026_full.php --apply
 */

$config = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/passwords.php';
$apply = in_array('--apply', $argv ?? [], true);
$baseImporter = __DIR__ . '/import_real_students_apr_aug_2026.php';

function fullFail(string $m): never { fwrite(STDERR, "ERROR: {$m}\n"); exit(1); }
function fullUuid(PDO $pdo): string { return (string)$pdo->query('SELECT UUID()')->fetchColumn(); }
function fullSlug(string $nombre): string {
    $ascii = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nombre) ?: $nombre;
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9 ]+/',' ',$ascii) ?? '';
    $parts = array_values(array_filter(preg_split('/\s+/',trim($ascii)) ?: []));
    if (!$parts) return 'alumno';
    $base = $parts[0]; if (count($parts)>1) $base .= '.' . end($parts);
    return substr($base,0,40);
}
function fullUniqueUser(PDO $pdo,string $nombre): string {
    $base=fullSlug($nombre); $u=$base; $n=2;
    $st=$pdo->prepare('SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1');
    while(true){$st->execute([':u'=>$u]); if(!$st->fetchColumn()) return $u; $u=$base.$n++;}
}
function courseState(string $start,string $end,string $today): string {
    if ($end < $today) return 'TERMINADO';
    if ($start > $today) return 'PROGRAMADO';
    return 'EN_CURSO';
}

// Listado completo tomado de las bitácoras originales. 8:30 histórico se normaliza a 08:00–09:00.
$courses = [
 '2026-03-30'=>['CRISTIAN FLORES','WANDA GÓNGORA'],
 '2026-04-06'=>['GLADYS PÉREZ','LESLIE GARCÍA HERNÁNDEZ','OLGA MOLINA','ROCIO OLVERA','GISSEL GUILLÉN','GEORGINA PRIETO','EDUARDO MARTÍNEZ'],
 '2026-04-13'=>['KARLA RODRÍGUEZ SANTOS','ROBERTO PABLO ZAVALETA','ALDAIR CUEVAS ALONSO'],
 '2026-04-20'=>['JESUS BALCAZAR','JAVIER SÁNCHEZ LEZCANO','PERLA MIROSLAVA CRESPO LOPEZ','BRENDA ARIDAL RAMIREZ GARCIA','MACARMEN GARCIA BERNARDO'],
 '2026-05-11'=>['NORMA ANGÉLICA POOL','REYNA JIMÉNEZ DE LA CRUZ','GRACIELA TORRES','LETICIA ACUÑA','SUSANA MORALES'],
 '2026-05-18'=>['YOUSELANDE JEAN','YARELI BALAM','YANET ARCEO'],
 '2026-05-25'=>['CAROLINA VASQUEZ ZENDEJAS','JOSE HUMBERTO ALMAZAN CUE','PATRICIA SÁNCHEZ'],
 '2026-06-01'=>['DIANA ESTHER PACHECO OSUNA','MARÍA CLAUDIA LÓPEZ','IRMA AGUIRRE','JESUS LOPEZ'],
 '2026-06-08'=>['JOSUÉ SALVADOR MORENO','KARLA ÁLVAREZ VIVERO','ALEJANDRO GÓMEZ RODRIGUEZ','MAYTE GARCÍA QUEZADA','EDUARDO ALBERTO GARIBAY','KATIA SAMANTHA ORDÓÑEZ','LAURA KRYSTELL DE LA CRUZ','INES DE JESÚS GONZÁLEZ LUNA','YOLANDA CRUZ CRUZ','JONATHAN TABARES','DIEGO CARMONA','ROSA ALEJANDRA LOPEZ','PABLO TRUJILLO'],
 '2026-06-15'=>['HUGO SALAZAR','VALERIA GARCÍA','MARÍA DE LOS ANGELES CANCHE','JUDITH ESTRADA'],
 '2026-06-22'=>['ALBERTO RODRÍGUEZ'],
 '2026-06-29'=>['JHOANA NAVA','YAMICEL NAVA HERNÁNDEZ'],
 '2026-07-06'=>['EDGAR SOTO'],
 '2026-07-13'=>['YURIMA PUENTE','SOFÍA VILLAMIZAR','FABIÁN DUGARTE','LUIS LOPEZ VILLAREAL','ROBERTA DEL ROSARIO CAN POOL','LUISA IVONNE VICTORIA AZPIRI'],
 '2026-07-20'=>['LOGAN TAGORE'],
 '2026-07-27'=>['PATRICIA OCHOA ALTAMIRANO','PATRICIO MARTÍNEZ OCHOA','FABRICIO MARTÍNEZ OCHOA','YAMILET NÁJERA GÓMEZ','LIZETH LEON MERCADO','NANCY SOTO','JUAN DE DIOS CELEDON','JUANA RIOS'],
 '2026-08-03'=>['DIONISIO PEREZ','NORMA LAZCANO','JATZIRY FILOMENA','JUAN CARLOS RESENDIZ','MARLENYS CAMAAL','LUIS LORIA'],
 '2026-08-10'=>['MARICARMEN MENDOZA','EMIR LOIRA'],
];

$allIntensiveNames=[];
foreach($courses as $names) foreach($names as $name) $allIntensiveNames[$name]=true;
$totalParticipants=array_sum(array_map('count',$courses));

try {
    $pdo=new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'],$config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );

    $admin=$pdo->query("SELECT id,usuario FROM usuarios WHERE rol='ADMIN' AND activo=1 ORDER BY created_at,id LIMIT 1")->fetch();
    if(!$admin) fullFail('No existe ADMIN activo.');
    $monteverdeId=(string)$pdo->query("SELECT id FROM sedes WHERE clave='MONTEVERDE' AND activo=1 LIMIT 1")->fetchColumn();
    if(!$monteverdeId) fullFail('No existe la sede MONTEVERDE activa.');
    $st=$pdo->prepare("SELECT id FROM horarios WHERE sede_id=:s AND activo=1 AND intensivo=1 AND hora_inicio='08:00:00' AND hora_fin='09:00:00' LIMIT 1");
    $st->execute([':s'=>$monteverdeId]);
    $intensiveHorario=(string)$st->fetchColumn();
    if(!$intensiveHorario) fullFail('No existe horario intensivo 08:00–09:00.');
    $methodType=(string)$pdo->query("SHOW COLUMNS FROM pagos LIKE 'metodo'")->fetch()['Type'];
    if(!str_contains($methodType,'NO_REGISTRADO')) fullFail('Falta migración NO_REGISTRADO.');
    $currentStudents=(int)$pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn();
    $st=$pdo->prepare("SELECT COUNT(*) FROM alumnos WHERE sede_id<>:s");
    $st->execute([':s'=>$monteverdeId]);
    $otherSiteStudents=(int)$st->fetchColumn();
    if($otherSiteStudents>0) fullFail('Este importador histórico solo admite una base sin alumnos fuera de MONTEVERDE.');
    if(!$apply && !in_array($currentStudents,[0,61,109],true)) fullFail("La base tiene {$currentStudents} alumnos; estado no esperado para esta importación.");

    echo "===== SIMULACIÓN IMPORTACIÓN COMPLETA =====\n";
    echo 'Modo: '.($apply?'APLICAR CAMBIOS':'SOLO LECTURA')."\n";
    echo "Alumnos regulares/tolerancia esperados: 53\n";
    echo "Alumnos de intensivo actualmente en sus 3 semanas: 8\n";
    echo "Alumnos históricos de intensivo que no continuaron: 48\n";
    echo "TOTAL alumnos únicos esperado: 109\n";
    echo 'Cursos intensivos completos: '.count($courses)."\n";
    echo "Participaciones/pagos de intensivo completos: {$totalParticipants}\n";
    echo "Los cursos terminados permanecen en historial y sus alumnos exclusivos quedan en BAJA.\n";
    echo "Los cursos que aún están dentro de sus 3 semanas quedan EN_CURSO y sus alumnos ACTIVOS.\n";

    if(!$apply){
        echo "\nSIMULACIÓN OK. No se escribió nada.\n";
        echo "Para aplicar: php database/maintenance/import_real_students_apr_aug_2026_full.php --apply\n";
        exit(0);
    }

    // Si la base sigue limpia, ejecutar primero la importación base validada de mensualidades/inscripciones/continuidades.
    if($currentStudents===0){
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($baseImporter).' --apply';
        passthru($cmd,$code);
        if($code!==0) fullFail('La fase base de importación falló. No se ejecutó la ampliación histórica.');
    }

    // Reabrir conteo tras fase base.
    $currentStudents=(int)$pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn();
    if(!in_array($currentStudents,[61,109],true)) fullFail("Tras la fase base hay {$currentStudents} alumnos; se esperaban 61 o 109.");

    $today=date('Y-m-d');
    $pdo->beginTransaction();

    $students=[];
    $st=$pdo->prepare("SELECT id,nombre FROM alumnos WHERE sede_id=:s");
    $st->execute([':s'=>$monteverdeId]);
    foreach($st->fetchAll() as $r) $students[$r['nombre']]=$r['id'];

    $insertStudent=$pdo->prepare("INSERT INTO alumnos(id,sede_id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:s,:n,NULL,'9981231234',NULL,:fi,NULL,NULL,:estado,:o)");
    $insertUser=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:ph,'ALUMNO',1,1,:a)");
    $findCourse=$pdo->prepare("SELECT id,fecha_fin FROM cursos_intensivos WHERE sede_id=:s AND fecha_inicio=:fi LIMIT 1");
    $insertCourse=$pdo->prepare("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:s,:fi,:ff,1200,:e,:o,:u)");
    $updateCourse=$pdo->prepare("UPDATE cursos_intensivos SET estado=:e,observaciones=:o WHERE id=:id");
    $findParticipant=$pdo->prepare("SELECT 1 FROM curso_intensivo_alumnos WHERE curso_intensivo_id=:c AND alumno_id=:a LIMIT 1");
    $insertParticipant=$pdo->prepare("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,observaciones,created_by) VALUES(:id,:c,:a,:h,:o,:u)");
    $findPay=$pdo->prepare("SELECT 1 FROM pagos WHERE intensivo_id=:c AND alumno_id=:a AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1");
    $insertPay=$pdo->prepare("INSERT INTO pagos(id,alumno_id,intensivo_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:id,:a,:c,'INTENSIVO',1200,'NO_REGISTRADO',:f,'VALIDO',:o,:u)");

    $createdStudents=0;$createdCourses=0;$createdParticipants=0;$createdPayments=0;

    foreach($courses as $start=>$names){
        $startD=new DateTimeImmutable($start); $end=$startD->modify('+18 days')->format('Y-m-d');
        $state=courseState($start,$end,$today);
        $findCourse->execute([':s'=>$monteverdeId,':fi'=>$start]); $course=$findCourse->fetch();
        if($course){
            $cid=$course['id'];
            $updateCourse->execute([':e'=>$state,':o'=>'Curso intensivo real reconstruido desde bitácoras históricas. Horario normalizado a 08:00–09:00.',':id'=>$cid]);
        }else{
            $cid=fullUuid($pdo);$createdCourses++;
            $insertCourse->execute([':id'=>$cid,':s'=>$monteverdeId,':fi'=>$start,':ff'=>$end,':e'=>$state,':o'=>'Curso intensivo real reconstruido desde bitácoras históricas. Horario normalizado a 08:00–09:00.',':u'=>$admin['id']]);
        }

        foreach($names as $name){
            if(!isset($students[$name])){
                $sid=fullUuid($pdo);$students[$name]=$sid;$createdStudents++;
                $studentState=$state==='EN_CURSO'?'ACTIVO':'BAJA';
                $insertStudent->execute([':id'=>$sid,':s'=>$monteverdeId,':n'=>$name,':fi'=>$start,':estado'=>$studentState,':o'=>$state==='EN_CURSO'?'Alumno en curso intensivo activo. Importado desde bitácora real; WhatsApp provisional.':'Alumno histórico: completó curso intensivo y no registra continuidad regular. Importado desde bitácoras reales; WhatsApp provisional.']);
                $user=fullUniqueUser($pdo,$name);
                $insertUser->execute([':id'=>fullUuid($pdo),':u'=>$user,':ph'=>password_hash(password_temporal_segura(),PASSWORD_DEFAULT),':a'=>$sid]);
            }
            $sid=$students[$name];
            $findParticipant->execute([':c'=>$cid,':a'=>$sid]);
            if(!$findParticipant->fetchColumn()){
                $insertParticipant->execute([':id'=>fullUuid($pdo),':c'=>$cid,':a'=>$sid,':h'=>$intensiveHorario,':o'=>'Participación histórica real. Horario normalizado/asignado a 08:00–09:00.',':u'=>$admin['id']]);
                $createdParticipants++;
            }
            $findPay->execute([':c'=>$cid,':a'=>$sid]);
            if(!$findPay->fetchColumn()){
                $insertPay->execute([':id'=>fullUuid($pdo),':a'=>$sid,':c'=>$cid,':f'=>$start.' 12:00:00',':o'=>'Pago histórico real de curso intensivo. Fecha exacta y método no registrados.',':u'=>$admin['id']]);
                $createdPayments++;
            }
        }
    }

    $pdo->commit();

    echo "\n===== IMPORTACIÓN COMPLETA APLICADA =====\n";
    echo "Alumnos históricos adicionales creados: {$createdStudents}\n";
    echo "Cursos adicionales creados: {$createdCourses}\n";
    echo "Participaciones adicionales creadas: {$createdParticipants}\n";
    echo "Pagos intensivo adicionales creados: {$createdPayments}\n";
    echo 'TOTAL alumnos: '.$pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn()."\n";
    echo 'TOTAL usuarios ALUMNO: '.$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='ALUMNO'")->fetchColumn()."\n";
    echo 'TOTAL cursos intensivos: '.$pdo->query('SELECT COUNT(*) FROM cursos_intensivos')->fetchColumn()."\n";
    echo 'TOTAL participaciones intensivo: '.$pdo->query('SELECT COUNT(*) FROM curso_intensivo_alumnos')->fetchColumn()."\n";
    echo 'TOTAL pagos: '.$pdo->query('SELECT COUNT(*) FROM pagos')->fetchColumn()."\n";
    echo "IMPORTACIÓN COMPLETA OK.\n";

} catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    fullFail($e->getMessage());
}
