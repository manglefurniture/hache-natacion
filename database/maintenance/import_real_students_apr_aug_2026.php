<?php
declare(strict_types=1);

/**
 * Importación controlada de alumnos reales Hache Natación — abril a agosto 2026.
 *
 * Modo por defecto: simulación (NO escribe).
 * Aplicar: php database/maintenance/import_real_students_apr_aug_2026.php --apply
 *
 * Reglas acordadas:
 * - Base actual = pagos/altas de agosto + alumnos cuyo último pago regular fue julio (tolerancia de un mes) + intensivos activos de agosto.
 * - Familia Villalpando y Ofelia Cristina Benítez quedan fuera.
 * - WhatsApp provisional 9981231234, correo/nacimiento vacíos.
 * - Regulares: horario provisional 07:00–08:00.
 * - Intensivos históricos/activos: horario válido 08:00–09:00.
 * - $1000 = Plan 3×, $1200 = Plan 5×; parciales conservan el importe real con observación.
 * - CCI conserva continuidad sin crear inscripción.
 * - Pagos históricos con fecha exacta desconocida usan día 15 del mes y método NO_REGISTRADO.
 * - No se fusionan nombres dudosos que no hayan sido confirmados.
 */

$config = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/passwords.php';
$apply = in_array('--apply', $argv ?? [], true);

function fail(string $message): never {
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}
function uuid(PDO $pdo): string { return (string)$pdo->query('SELECT UUID()')->fetchColumn(); }
function slugUsuario(string $nombre): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: $nombre;
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9 ]+/', ' ', $ascii) ?? '';
    $parts = array_values(array_filter(preg_split('/\s+/', trim($ascii)) ?: []));
    if (!$parts) return 'alumno';
    $base = $parts[0];
    if (count($parts) > 1) $base .= '.' . end($parts);
    return substr($base, 0, 40);
}
function usuarioUnico(PDO $pdo, string $nombre): string {
    $base = slugUsuario($nombre); $candidate = $base; $n = 2;
    $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1');
    while (true) {
        $st->execute([':u'=>$candidate]);
        if (!$st->fetchColumn()) return $candidate;
        $candidate = $base . $n++;
    }
}
function ymDate(string $ym): string { return $ym . '-15 12:00:00'; }

// Plan actual de alumnos regulares. El valor es el precio completo del plan vigente.
$currentRegular = [
 'PAOLA HERNANDEZ ZEPEDA'=>1000,'MARK ALBRECHT DAHLE'=>1000,'ALDAIR ULISES CUEVAS ALONSO'=>1000,
 'LEONOR LOYO'=>1200,'LUIS ALVARADO'=>1000,'MARILUZ QUINTERO JARAMILLO'=>1200,'GABRIEL DE LA GUARDIA'=>1000,
 'EDGAR SOTO'=>1200,'EDUARDO ALBERTO GARIBAY'=>1200,'GIBRAN TAGORE'=>1200,'MARÍA CLAUDIA LÓPEZ'=>1200,
 'DIANA ESTHER PACHECO OSUNA'=>1000,'JESUS BALCAZAR'=>1200,'MARIA ESTHER HERNÁNDEZ'=>1000,
 'ANGEL RADILLA'=>1000,'DANIELA SOTO'=>1000,'INDIRA RAYA ESPINOZA'=>1000,'GLADYS PÉREZ'=>1200,
 'LETICIA ACUÑA'=>1200,'GRACIELA TORRES'=>1200,'JESUS LOPEZ'=>1200,'FANNY AMIRA'=>1000,
 'JUDITH ESTRADA'=>1000,'MAYTE GARCÍA QUEZADA'=>1000,'VIVIANA CASAS SEGURA'=>1200,'ROSARIO CAHUN'=>1000,
 'ROSA ARIAS'=>1000,'LUCERO JIMÉNEZ'=>1200,'YAMICEL NAVA HERNÁNDEZ'=>1000,'DANIEL TREJO'=>1000,
 'ALEJANDRO GÓMEZ RODRIGUEZ'=>1000,'ABRAHAM GONZÁLEZ ZAMACONA'=>1200,'ARIAN GIOVANNI PEREZ'=>1200,'JHOANA NAVA'=>1000,
 // Último pago julio: permanecen activos administrativamente por tolerancia, sin derecho a clase en agosto hasta pagar.
 'JAVIER AMARO GAMBOA'=>1000,'MARICELA HECHEVARRIA'=>1200,'DANIELA RIVERO MAGOS'=>1200,'GERARDO ROMO'=>1200,
 'ALEXANDRA OSNAYA'=>1200,'ELIZABETH ENRÍQUEZ MARCELINO'=>1200,'PATRICIA SÁNCHEZ'=>1000,'BRENDA RAMIREZ GARCIA'=>1000,
 'MANUEL ALEGRÍA'=>1000,'TANIA MISS'=>1000,'REGINA ESTRADA'=>1000,'LUIS FELIPE ORTEGA'=>1000,'ALONDRA BALAM'=>1200,
 'GISSEL GUILLÉN'=>1200,'NORMA REYES'=>1200,'KARLA ÁLVAREZ VIVERO'=>1000,'IRMA AGUIRRE'=>1200,
 'INES DE JESÚS GONZÁLEZ LUNA'=>1000,'VALERIA GARCÍA'=>1000,
];

$activeIntensive = [
 'DIONISIO PEREZ','NORMA LAZCANO','JATZIRY FILOMENA','JUAN CARLOS RESENDIZ','MARLENYS CAMAAL','LUIS LORIA',
 'MARICARMEN MENDOZA','EMIR LOIRA',
];

// Mensualidades históricas vinculadas únicamente a alumnos de la base actual.
// p=plan completo cuando el cobro fue parcial/prorrateado.
$monthly = [
 ['2026-04','MARILUZ QUINTERO JARAMILLO',1000],['2026-04','FANNY AMIRA',1000],['2026-04','ABRAHAM GONZÁLEZ ZAMACONA',1200],
 ['2026-04','VIVIANA CASAS SEGURA',1000],['2026-04','GIBRAN TAGORE',1000],['2026-04','ANGEL RADILLA',1000],
 ['2026-04','GERARDO ROMO',1200],['2026-04','ALEXANDRA OSNAYA',1200],['2026-04','ELIZABETH ENRÍQUEZ MARCELINO',1200],
 ['2026-04','INDIRA RAYA ESPINOZA',1200],['2026-04','ROSARIO CAHUN',1000],['2026-04','LUCERO JIMÉNEZ',1200],
 ['2026-04','DANIEL TREJO',1200],['2026-04','ARIAN GIOVANNI PEREZ',1200],['2026-04','DANIELA SOTO',500,1000,'Pago parcial histórico'],
 ['2026-04','ALONDRA BALAM',600,1200,'Pago parcial histórico'],

 ['2026-05','MARICELA HECHEVARRIA',1200],['2026-05','DANIELA RIVERO MAGOS',1200],['2026-05','DANIELA SOTO',1000],
 ['2026-05','ARIAN GIOVANNI PEREZ',1200],['2026-05','GIBRAN TAGORE',1200],['2026-05','GERARDO ROMO',1200],
 ['2026-05','ALEXANDRA OSNAYA',1200],['2026-05','MARIA ESTHER HERNÁNDEZ',1000],['2026-05','ANGEL RADILLA',1000],
 ['2026-05','INDIRA RAYA ESPINOZA',1000],['2026-05','ELIZABETH ENRÍQUEZ MARCELINO',1200],['2026-05','LEONOR LOYO',1200],
 ['2026-05','VIVIANA CASAS SEGURA',1000],['2026-05','LUCERO JIMÉNEZ',1000],['2026-05','ROSARIO CAHUN',1000],
 ['2026-05','FANNY AMIRA',1000],['2026-05','DANIEL TREJO',1200],['2026-05','ABRAHAM GONZÁLEZ ZAMACONA',1200],
 ['2026-05','ALONDRA BALAM',1200],['2026-05','GLADYS PÉREZ',1200],['2026-05','GISSEL GUILLÉN',1200],
 ['2026-05','JESUS BALCAZAR',600,1200,'CCI · pago parcial al continuar del intensivo'],

 ['2026-06','GABRIEL DE LA GUARDIA',1000],['2026-06','JAVIER AMARO GAMBOA',1000],['2026-06','MARICELA HECHEVARRIA',1200],
 ['2026-06','DANIELA RIVERO MAGOS',1200],['2026-06','DANIELA SOTO',1000],['2026-06','ARIAN GIOVANNI PEREZ',1200],
 ['2026-06','GIBRAN TAGORE',1200],['2026-06','GERARDO ROMO',600,1200,'Pago parcial histórico'],
 ['2026-06','ALEXANDRA OSNAYA',600,1200,'Pago parcial histórico'],['2026-06','MARIA ESTHER HERNÁNDEZ',1000],
 ['2026-06','ANGEL RADILLA',1000],['2026-06','INDIRA RAYA ESPINOZA',1200],['2026-06','ELIZABETH ENRÍQUEZ MARCELINO',1200],
 ['2026-06','LEONOR LOYO',1200],['2026-06','VIVIANA CASAS SEGURA',1200],['2026-06','MARILUZ QUINTERO JARAMILLO',1200],
 ['2026-06','LUCERO JIMÉNEZ',1200],['2026-06','ROSARIO CAHUN',1000],['2026-06','FANNY AMIRA',1000],
 ['2026-06','DANIEL TREJO',1200],['2026-06','ABRAHAM GONZÁLEZ ZAMACONA',1200],['2026-06','ALONDRA BALAM',1200],
 ['2026-06','GLADYS PÉREZ',1200],['2026-06','GISSEL GUILLÉN',1200],['2026-06','JESUS BALCAZAR',1200],
 ['2026-06','LETICIA ACUÑA',1200],['2026-06','GRACIELA TORRES',1200],['2026-06','NORMA REYES',1200],
 ['2026-06','PATRICIA SÁNCHEZ',600,1200,'CCI · pago parcial al continuar del intensivo'],
 ['2026-06','BRENDA RAMIREZ GARCIA',500,1000,'CCI · pago parcial al continuar del intensivo'],
 ['2026-06','MARÍA CLAUDIA LÓPEZ',300,1200,'CCI · pago prorrateado al continuar del intensivo'],

 ['2026-07','MANUEL ALEGRÍA',1000],['2026-07','TANIA MISS',1000],['2026-07','REGINA ESTRADA',1000],['2026-07','LUIS FELIPE ORTEGA',1000],
 ['2026-07','ROSA ARIAS',1000],['2026-07','GABRIEL DE LA GUARDIA',1000],['2026-07','JAVIER AMARO GAMBOA',1000],
 ['2026-07','MARICELA HECHEVARRIA',1200],['2026-07','DANIELA RIVERO MAGOS',1200],['2026-07','DANIELA SOTO',1000],
 ['2026-07','ARIAN GIOVANNI PEREZ',1200],['2026-07','GIBRAN TAGORE',1200],['2026-07','GERARDO ROMO',1200],
 ['2026-07','ALEXANDRA OSNAYA',1200],['2026-07','MARIA ESTHER HERNÁNDEZ',1000],['2026-07','ANGEL RADILLA',1000],
 ['2026-07','INDIRA RAYA ESPINOZA',1000],['2026-07','ELIZABETH ENRÍQUEZ MARCELINO',1200],['2026-07','LEONOR LOYO',1200],
 ['2026-07','VIVIANA CASAS SEGURA',1200],['2026-07','MARILUZ QUINTERO JARAMILLO',600,1200,'Pago parcial por dos semanas de vacaciones'],
 ['2026-07','LUCERO JIMÉNEZ',1200],['2026-07','ROSARIO CAHUN',1000],['2026-07','ALONDRA BALAM',1200],
 ['2026-07','GLADYS PÉREZ',1200],['2026-07','GISSEL GUILLÉN',1200],['2026-07','JESUS BALCAZAR',1200],
 ['2026-07','GRACIELA TORRES',1200],['2026-07','NORMA REYES',1200],['2026-07','PATRICIA SÁNCHEZ',1000],
 ['2026-07','BRENDA RAMIREZ GARCIA',1000],['2026-07','MARÍA CLAUDIA LÓPEZ',1200],['2026-07','DANIEL TREJO',1200],
 ['2026-07','ABRAHAM GONZÁLEZ ZAMACONA',1200],['2026-07','KARLA ÁLVAREZ VIVERO',1000],['2026-07','JESUS LOPEZ',1200],
 ['2026-07','DIANA ESTHER PACHECO OSUNA',1200],['2026-07','EDUARDO ALBERTO GARIBAY',1200],['2026-07','IRMA AGUIRRE',1200],
 ['2026-07','JUDITH ESTRADA',1000],['2026-07','INES DE JESÚS GONZÁLEZ LUNA',1000],['2026-07','ALEJANDRO GÓMEZ RODRIGUEZ',1000],
 ['2026-07','MAYTE GARCÍA QUEZADA',1000],['2026-07','VALERIA GARCÍA',1000],['2026-07','JHOANA NAVA',500,1000,'CCI · pago parcial al continuar del intensivo'],
 ['2026-07','LUIS ALVARADO',1000,'','Pago identificado en conciliación de julio y confirmado manualmente'],

 ['2026-08','PAOLA HERNANDEZ ZEPEDA',1000],['2026-08','MARK ALBRECHT DAHLE',1000],['2026-08','ALDAIR ULISES CUEVAS ALONSO',500,1000,'Nuevo ingreso a medio mes'],
 ['2026-08','LEONOR LOYO',1200],['2026-08','LUIS ALVARADO',1000],['2026-08','MARILUZ QUINTERO JARAMILLO',1200],
 ['2026-08','GABRIEL DE LA GUARDIA',1000],['2026-08','EDGAR SOTO',1200],['2026-08','EDUARDO ALBERTO GARIBAY',1200],
 ['2026-08','GIBRAN TAGORE',1200],['2026-08','MARÍA CLAUDIA LÓPEZ',1200],['2026-08','DIANA ESTHER PACHECO OSUNA',1000],
 ['2026-08','JESUS BALCAZAR',1200],['2026-08','MARIA ESTHER HERNÁNDEZ',1000],['2026-08','ANGEL RADILLA',1000],
 ['2026-08','DANIELA SOTO',1000],['2026-08','INDIRA RAYA ESPINOZA',1000],['2026-08','GLADYS PÉREZ',1200],
 ['2026-08','LETICIA ACUÑA',1200],['2026-08','GRACIELA TORRES',1200],['2026-08','JESUS LOPEZ',1200],
 ['2026-08','FANNY AMIRA',1000],['2026-08','JUDITH ESTRADA',1000],['2026-08','MAYTE GARCÍA QUEZADA',1000],
 ['2026-08','VIVIANA CASAS SEGURA',1200],['2026-08','ROSARIO CAHUN',1000],['2026-08','ROSA ARIAS',1000],
 ['2026-08','LUCERO JIMÉNEZ',1200],['2026-08','YAMICEL NAVA HERNÁNDEZ',1000],['2026-08','DANIEL TREJO',1000],
 ['2026-08','ALEJANDRO GÓMEZ RODRIGUEZ',1000],['2026-08','ABRAHAM GONZÁLEZ ZAMACONA',1200],['2026-08','ARIAN GIOVANNI PEREZ',1200],
 ['2026-08','JHOANA NAVA',1000],
];

// Inscripciones regulares inequívocas de alumnos que forman parte de la base actual.
$inscriptions = [
 ['2026-04','DANIEL TREJO',300],
 ['2026-05','MARICELA HECHEVARRIA',300],['2026-05','DANIELA RIVERO MAGOS',300],
 ['2026-06','GABRIEL DE LA GUARDIA',300],['2026-06','JAVIER AMARO GAMBOA',300],
 ['2026-07','MANUEL ALEGRÍA',300],['2026-07','TANIA MISS',300],['2026-07','REGINA ESTRADA',300],['2026-07','LUIS FELIPE ORTEGA',300],['2026-07','ROSA ARIAS',300],
 ['2026-08','PAOLA HERNANDEZ ZEPEDA',500],['2026-08','MARK ALBRECHT DAHLE',500],['2026-08','ALDAIR ULISES CUEVAS ALONSO',500],
];

// Cursos intensivos históricos de alumnos que hoy forman parte de la base + cursos activos de agosto completos.
$courses = [
 '2026-04-06'=>['GLADYS PÉREZ','GISSEL GUILLÉN'],
 '2026-04-20'=>['JESUS BALCAZAR'],
 '2026-05-11'=>['GRACIELA TORRES','LETICIA ACUÑA'],
 '2026-05-25'=>['PATRICIA SÁNCHEZ'],
 '2026-06-01'=>['DIANA ESTHER PACHECO OSUNA','MARÍA CLAUDIA LÓPEZ','IRMA AGUIRRE','JESUS LOPEZ'],
 '2026-06-08'=>['KARLA ÁLVAREZ VIVERO','ALEJANDRO GÓMEZ RODRIGUEZ','MAYTE GARCÍA QUEZADA','EDUARDO ALBERTO GARIBAY','INES DE JESÚS GONZÁLEZ LUNA'],
 '2026-06-15'=>['VALERIA GARCÍA','JUDITH ESTRADA'],
 '2026-06-29'=>['JHOANA NAVA','YAMICEL NAVA HERNÁNDEZ'],
 '2026-07-06'=>['EDGAR SOTO'],
 '2026-08-03'=>['DIONISIO PEREZ','NORMA LAZCANO','JATZIRY FILOMENA','JUAN CARLOS RESENDIZ','MARLENYS CAMAAL','LUIS LORIA'],
 '2026-08-10'=>['MARICARMEN MENDOZA','EMIR LOIRA'],
];

// Primera mensualidad de continuidad conocida después del intensivo.
$continuity = [
 'GLADYS PÉREZ'=>['2026-05',1200],'GISSEL GUILLÉN'=>['2026-05',1200],'JESUS BALCAZAR'=>['2026-05',600],
 'GRACIELA TORRES'=>['2026-06',1200],'LETICIA ACUÑA'=>['2026-06',1200],'PATRICIA SÁNCHEZ'=>['2026-06',600],
 'MARÍA CLAUDIA LÓPEZ'=>['2026-06',300],'DIANA ESTHER PACHECO OSUNA'=>['2026-07',1200],'IRMA AGUIRRE'=>['2026-07',1200],
 'JESUS LOPEZ'=>['2026-07',1200],'KARLA ÁLVAREZ VIVERO'=>['2026-07',1000],'ALEJANDRO GÓMEZ RODRIGUEZ'=>['2026-07',1000],
 'MAYTE GARCÍA QUEZADA'=>['2026-07',1000],'EDUARDO ALBERTO GARIBAY'=>['2026-07',1200],'INES DE JESÚS GONZÁLEZ LUNA'=>['2026-07',1000],
 'VALERIA GARCÍA'=>['2026-07',1000],'JUDITH ESTRADA'=>['2026-07',1000],'JHOANA NAVA'=>['2026-07',500],
 'YAMICEL NAVA HERNÁNDEZ'=>['2026-08',1000],'EDGAR SOTO'=>['2026-08',1200],
];

$allStudents = array_fill_keys(array_keys($currentRegular), true);
foreach ($activeIntensive as $name) $allStudents[$name] = true;

// Validación interna del dataset antes de tocar MariaDB.
foreach ($monthly as $r) if (!isset($allStudents[$r[1]])) fail("Mensualidad referencia alumno no incluido: {$r[1]}");
foreach ($inscriptions as $r) if (!isset($allStudents[$r[1]])) fail("Inscripción referencia alumno no incluido: {$r[1]}");
foreach ($courses as $start=>$names) foreach ($names as $name) if (!isset($allStudents[$name])) fail("Intensivo {$start} referencia alumno no incluido: {$name}");

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
        $config['user'], $config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );

    $counts = [];
    foreach (['alumnos','pagos','mensualidades','inscripciones','cursos_intensivos','curso_intensivo_alumnos'] as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    foreach ($counts as $table=>$count) if ($count !== 0) fail("La tabla {$table} tiene {$count} registros. Este importador solo corre sobre la base limpia.");

    $admin = $pdo->query("SELECT id,usuario FROM usuarios WHERE rol='ADMIN' AND activo=1 ORDER BY created_at,id LIMIT 1")->fetch();
    if (!$admin) fail('No existe un usuario ADMIN activo para created_by.');

    $monteverdeId=(string)$pdo->query("SELECT id FROM sedes WHERE clave='MONTEVERDE' AND activo=1 LIMIT 1")->fetchColumn();
    if(!$monteverdeId) fail('No existe la sede MONTEVERDE activa.');

    $planByPrice = [];
    $st=$pdo->prepare("SELECT id,precio FROM planes WHERE sede_id=:s AND activo=1");
    $st->execute([':s'=>$monteverdeId]);
    foreach ($st->fetchAll() as $p) $planByPrice[(int)round((float)$p['precio'])] = $p['id'];
    foreach ([1000,1200] as $price) if (empty($planByPrice[$price])) fail("No existe plan activo de {$price}.");

    $st=$pdo->prepare("SELECT id FROM horarios WHERE sede_id=:s AND activo=1 AND regular=1 AND hora_inicio='07:00:00' AND hora_fin='08:00:00' LIMIT 1");
    $st->execute([':s'=>$monteverdeId]);
    $regularHorario=(string)$st->fetchColumn(); if(!$regularHorario) fail('No existe horario regular activo 07:00–08:00.');
    $st=$pdo->prepare("SELECT id FROM horarios WHERE sede_id=:s AND activo=1 AND intensivo=1 AND hora_inicio='08:00:00' AND hora_fin='09:00:00' LIMIT 1");
    $st->execute([':s'=>$monteverdeId]);
    $intensiveHorario=(string)$st->fetchColumn(); if(!$intensiveHorario) fail('No existe horario intensivo activo 08:00–09:00.');

    $methodCol=(string)$pdo->query("SHOW COLUMNS FROM pagos LIKE 'metodo'")->fetch()['Type'];
    if (!str_contains($methodCol,'NO_REGISTRADO')) fail('Falta aplicar la migración de método NO_REGISTRADO.');

    echo "===== SIMULACIÓN IMPORTACIÓN REAL =====\n";
    echo 'Modo: '.($apply?'APLICAR CAMBIOS':'SOLO LECTURA')."\n";
    echo 'ADMIN created_by: '.$admin['usuario']."\n";
    echo 'Alumnos regulares actuales/tolerancia: '.count($currentRegular)."\n";
    echo 'Alumnos en intensivo activo: '.count($activeIntensive)."\n";
    echo 'TOTAL alumnos a crear: '.count($allStudents)."\n";
    echo 'Mensualidades históricas: '.count($monthly)."\n";
    echo 'Inscripciones históricas: '.count($inscriptions)."\n";
    echo 'Cursos intensivos: '.count($courses)."\n";
    echo 'Participaciones intensivo: '.array_sum(array_map('count',$courses))."\n";
    echo 'Pagos intensivo: '.array_sum(array_map('count',$courses))."\n";
    echo "Excluidos expresamente: Familia Villalpando, Ofelia Cristina Benítez, Víctor Vega.\n";
    echo "Identidades confirmadas: Arian/Adrian; Abraham González Zamacona; Gissel/Giselle; Karla Álvarez Vivero.\n";
    echo "No fusionado por similitud: Aldair de intensivo abril con Aldair Ulises de agosto.\n";

    if (!$apply) {
        echo "\nSIMULACIÓN OK. No se escribió nada.\n";
        echo "Para aplicar: php database/maintenance/import_real_students_apr_aug_2026.php --apply\n";
        exit(0);
    }

    $pdo->beginTransaction();

    // Primera fecha conocida por alumno.
    $first = [];
    foreach ($monthly as $r) { $d=$r[0].'-15'; $first[$r[1]]=isset($first[$r[1]])?min($first[$r[1]],$d):$d; }
    foreach ($inscriptions as $r) { $d=$r[0].'-15'; $first[$r[1]]=isset($first[$r[1]])?min($first[$r[1]],$d):$d; }
    foreach ($courses as $d=>$names) foreach ($names as $name) $first[$name]=isset($first[$name])?min($first[$name],$d):$d;

    $studentIds=[]; $usernames=[];
    $insertStudent=$pdo->prepare("INSERT INTO alumnos(id,sede_id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:s,:n,NULL,'9981231234',NULL,:fi,:h,:p,'ACTIVO',:o)");
    $insertUser=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:ph,'ALUMNO',1,1,:a)");
    foreach (array_keys($allStudents) as $name) {
        $id=uuid($pdo); $studentIds[$name]=$id;
        $isRegular=array_key_exists($name,$currentRegular);
        $insertStudent->execute([
            ':id'=>$id, ':s'=>$monteverdeId, ':n'=>$name, ':fi'=>$first[$name]??'2026-08-01',
            ':h'=>$isRegular?$regularHorario:null,
            ':p'=>$isRegular?$planByPrice[$currentRegular[$name]]:null,
            ':o'=>$isRegular
                ? 'Importado desde bitácoras reales abril-agosto 2026. WhatsApp y horario regular provisionales.'
                : 'Importado desde bitácora real agosto 2026. Alumno en curso intensivo activo; WhatsApp provisional.'
        ]);
        $u=usuarioUnico($pdo,$name); $usernames[$name]=$u;
        $insertUser->execute([':id'=>uuid($pdo),':u'=>$u,':ph'=>password_hash(password_temporal_segura(),PASSWORD_DEFAULT),':a'=>$id]);
    }

    // Mensualidades + pago asociado.
    $insMens=$pdo->prepare("INSERT INTO mensualidades(id,sede_id,alumno_id,mes,anio,plan_id,importe_estandar,importe_a_cobrar,importe_cobrado,estado,observacion,fecha_pago,created_by) VALUES(:id,:s,:a,:m,:y,:p,:std,:due,:paid,'PAGADA',:o,:f,:u)");
    $insPayMonthly=$pdo->prepare("INSERT INTO pagos(id,alumno_id,mensualidad_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:id,:a,:mid,'MENSUALIDAD',:imp,'NO_REGISTRADO',:f,'VALIDO',:o,:u)");
    foreach ($monthly as $r) {
        [$ym,$name,$amount]=$r; $override=$r[3]??null; $note=$r[4]??null;
        $planPrice = (is_int($override) || is_float($override)) ? (int)$override : (($amount===1000||$amount===1200)?(int)$amount:$currentRegular[$name]);
        $std=$planPrice; [$year,$month]=array_map('intval',explode('-',$ym)); $f=ymDate($ym);
        $obs='Importación histórica real. Fecha exacta y método de pago no registrados.' . ($note?' '.$note.'.':'');
        $mid=uuid($pdo);
        $insMens->execute([':id'=>$mid,':s'=>$monteverdeId,':a'=>$studentIds[$name],':m'=>$month,':y'=>$year,':p'=>$planByPrice[$planPrice],':std'=>$std,':due'=>$amount,':paid'=>$amount,':o'=>$obs,':f'=>$f,':u'=>$admin['id']]);
        $insPayMonthly->execute([':id'=>uuid($pdo),':a'=>$studentIds[$name],':mid'=>$mid,':imp'=>$amount,':f'=>$f,':o'=>$obs,':u'=>$admin['id']]);
    }

    // Inscripciones + pago asociado.
    $insReg=$pdo->prepare("INSERT INTO inscripciones(id,sede_id,alumno_id,fecha,origen,importe,observacion,created_by) VALUES(:id,:s,:a,:f,'REGULAR',:imp,:o,:u)");
    $insPayReg=$pdo->prepare("INSERT INTO pagos(id,alumno_id,inscripcion_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:id,:a,:iid,'INSCRIPCION',:imp,'NO_REGISTRADO',:f,'VALIDO',:o,:u)");
    foreach ($inscriptions as [$ym,$name,$amount]) {
        $f=ymDate($ym); $obs='Inscripción histórica real importada. Fecha exacta y método de pago no registrados.';
        $iid=uuid($pdo);
        $insReg->execute([':id'=>$iid,':s'=>$monteverdeId,':a'=>$studentIds[$name],':f'=>substr($f,0,10),':imp'=>$amount,':o'=>$obs,':u'=>$admin['id']]);
        $insPayReg->execute([':id'=>uuid($pdo),':a'=>$studentIds[$name],':iid'=>$iid,':imp'=>$amount,':f'=>$f,':o'=>$obs,':u'=>$admin['id']]);
    }

    // Intensivos + participantes + pagos.
    $insCourse=$pdo->prepare("INSERT INTO cursos_intensivos(id,sede_id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:s,:fi,:ff,1200,:e,:o,:u)");
    $insParticipant=$pdo->prepare("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,continua_regular,plan_continuidad_id,importe_continuidad,observacion_continuidad,observaciones,created_by) VALUES(:id,:c,:a,:h,:cont,:p,:imp,:oc,:o,:u)");
    $insPayInt=$pdo->prepare("INSERT INTO pagos(id,alumno_id,intensivo_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:id,:a,:c,'INTENSIVO',1200,'NO_REGISTRADO',:f,'VALIDO',:o,:u)");
    foreach ($courses as $start=>$names) {
        $startD=new DateTimeImmutable($start); $end=$startD->modify('+18 days')->format('Y-m-d');
        $active=$start>='2026-08-01'; $cid=uuid($pdo);
        $insCourse->execute([':id'=>$cid,':s'=>$monteverdeId,':fi'=>$start,':ff'=>$end,':e'=>$active?'EN_CURSO':'TERMINADO',':o'=>$active?'Curso intensivo real agosto 2026.':'Curso histórico parcial: se conservaron participantes que forman parte de la base actual.',':u'=>$admin['id']]);
        foreach ($names as $name) {
            $cont=isset($continuity[$name]); $planId=null; $contAmount=null; $contObs=null;
            if ($cont) {
                [$cym,$contAmount]=$continuity[$name];
                $planPrice=($contAmount===1000||$contAmount===1200)?$contAmount:$currentRegular[$name];
                // Para parciales conocidos, inferir plan por contexto.
                if ($name==='JESUS BALCAZAR'||$name==='PATRICIA SÁNCHEZ'||$name==='MARÍA CLAUDIA LÓPEZ') $planPrice=1200;
                if ($name==='JHOANA NAVA') $planPrice=1000;
                $planId=$planByPrice[$planPrice];
                $contObs='CCI histórico reconstruido desde bitácoras. No generó inscripción.';
            }
            $insParticipant->execute([':id'=>uuid($pdo),':c'=>$cid,':a'=>$studentIds[$name],':h'=>$intensiveHorario,':cont'=>$cont?1:null,':p'=>$planId,':imp'=>$contAmount,':oc'=>$contObs,':o'=>'Horario histórico normalizado/asignado a 08:00–09:00.',':u'=>$admin['id']]);
            $insPayInt->execute([':id'=>uuid($pdo),':a'=>$studentIds[$name],':c'=>$cid,':f'=>$start.' 12:00:00',':o'=>'Pago histórico real de curso intensivo. Fecha exacta y método no registrados.',':u'=>$admin['id']]);
        }
    }

    $pdo->commit();

    echo "\n===== IMPORTACIÓN APLICADA =====\n";
    echo 'Alumnos: '.$pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn()."\n";
    echo 'Usuarios ALUMNO: '.$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='ALUMNO'")->fetchColumn()."\n";
    echo 'Mensualidades: '.$pdo->query('SELECT COUNT(*) FROM mensualidades')->fetchColumn()."\n";
    echo 'Inscripciones: '.$pdo->query('SELECT COUNT(*) FROM inscripciones')->fetchColumn()."\n";
    echo 'Cursos intensivos: '.$pdo->query('SELECT COUNT(*) FROM cursos_intensivos')->fetchColumn()."\n";
    echo 'Participaciones intensivo: '.$pdo->query('SELECT COUNT(*) FROM curso_intensivo_alumnos')->fetchColumn()."\n";
    echo 'Pagos: '.$pdo->query('SELECT COUNT(*) FROM pagos')->fetchColumn()."\n";
    echo "\nCredenciales creadas con contraseña temporal configurada y cambio obligatorio.\n";
    echo "IMPORTACIÓN OK.\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage());
}
