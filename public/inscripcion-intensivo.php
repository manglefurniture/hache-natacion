<?php
declare(strict_types=1);

session_name('hache_public_form');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'use_strict_mode' => true,
]);

$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);

function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function uuid(PDO $pdo): string { return (string)$pdo->query('SELECT UUID()')->fetchColumn(); }
function phone_digits(string $v): string { return preg_replace('/\D+/', '', $v) ?? ''; }
function slug_user(string $nombre): string {
    $ascii = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nombre) ?: $nombre;
    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9 ]+/',' ', $ascii) ?? '';
    $parts = array_values(array_filter(preg_split('/\s+/',trim($ascii)) ?: []));
    if (!$parts) return 'alumno';
    $base = $parts[0];
    if (count($parts) > 1) $base .= '.' . end($parts);
    return substr($base, 0, 40);
}
function unique_user(PDO $pdo,string $nombre): string {
    $base=slug_user($nombre);$u=$base;$n=2;
    $st=$pdo->prepare('SELECT 1 FROM usuarios WHERE usuario=:u LIMIT 1');
    while(true){$st->execute([':u'=>$u]);if(!$st->fetchColumn())return $u;$u=$base.$n++;}
}
function monday_options(int $count=10): array {
    $today = new DateTimeImmutable('today');
    $first = (int)$today->format('N') === 1 ? $today : $today->modify('next monday');
    $out=[];
    for($i=0;$i<$count;$i++){
        $d=$first->modify('+'.($i*7).' days');
        $out[]=$d->format('Y-m-d');
    }
    return $out;
}
function human_date(string $date): string {
    $d=new DateTimeImmutable($date);
    $months=[1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return 'lunes '.$d->format('j').' de '.$months[(int)$d->format('n')].' de '.$d->format('Y');
}
function human_time(string $a,string $b): string { return substr($a,0,5).'–'.substr($b,0,5); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
if (empty($_SESSION['form_started'])) $_SESSION['form_started']=time();

$mondays=monday_options();
$horarios=$pdo->query("SELECT id,hora_inicio,hora_fin FROM horarios WHERE activo=1 AND intensivo=1 ORDER BY hora_inicio")->fetchAll();
$error='';$success=null;
$old=['nombre'=>'','whatsapp'=>'','correo'=>'','fecha_inicio'=>$mondays[0]??'','horario_id'=>$horarios[0]['id']??''];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach($old as $k=>$_) $old[$k]=trim((string)($_POST[$k]??''));
    $csrf=(string)($_POST['csrf']??'');
    $honeypot=trim((string)($_POST['website']??''));
    try {
        if (!hash_equals((string)$_SESSION['csrf'],$csrf)) throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo otra vez.');
        if ($honeypot!=='') throw new RuntimeException('No se pudo procesar la solicitud.');
        if (time()-(int)($_SESSION['form_started']??0)<2) throw new RuntimeException('Espera un momento y vuelve a enviar el formulario.');
        $nombre=preg_replace('/\s+/u',' ',trim($old['nombre'])) ?? '';
        $wa=phone_digits($old['whatsapp']);
        $correo=trim($old['correo']);
        $inicio=$old['fecha_inicio'];
        $horarioId=$old['horario_id'];
        if (mb_strlen($nombre)<5 || mb_strlen($nombre)>160) throw new RuntimeException('Escribe tu nombre completo.');
        if (strlen($wa)<10 || strlen($wa)>15) throw new RuntimeException('Escribe un número de WhatsApp válido.');
        if ($correo!=='' && !filter_var($correo,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        if (!in_array($inicio,$mondays,true)) throw new RuntimeException('Selecciona uno de los lunes disponibles.');
        $validHorario=null;
        foreach($horarios as $h){if(hash_equals((string)$h['id'],$horarioId)){$validHorario=$h;break;}}
        if(!$validHorario) throw new RuntimeException('Selecciona un horario válido.');

        foreach($pdo->query("SELECT id,nombre,whatsapp FROM alumnos WHERE whatsapp IS NOT NULL")->fetchAll() as $a){
            if(phone_digits((string)$a['whatsapp'])===$wa){
                throw new RuntimeException('Ya existe un registro con este WhatsApp. Escríbenos por WhatsApp para revisar tu inscripción.');
            }
        }

        $admin=$pdo->query("SELECT id FROM usuarios WHERE rol='ADMIN' AND activo=1 ORDER BY created_at,id LIMIT 1")->fetchColumn();
        if(!$admin) throw new RuntimeException('No se pudo procesar la preinscripción en este momento.');
        $temp=(string)($pdo->query("SELECT valor FROM configuracion WHERE clave='password_temporal' LIMIT 1")->fetchColumn() ?: '123456');
        $start=new DateTimeImmutable($inicio);$end=$start->modify('+18 days')->format('Y-m-d');
        $state=$inicio<=date('Y-m-d')?'EN_CURSO':'PROGRAMADO';

        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT id,precio FROM cursos_intensivos WHERE fecha_inicio=:f LIMIT 1");$st->execute([':f'=>$inicio]);$course=$st->fetch();
        if($course){$courseId=(string)$course['id'];$price=(float)$course['precio'];}
        else{
            $courseId=uuid($pdo);$price=1200.0;
            $st=$pdo->prepare("INSERT INTO cursos_intensivos(id,fecha_inicio,fecha_fin,precio,estado,observaciones,created_by) VALUES(:id,:fi,:ff,:p,:e,:o,:u)");
            $st->execute([':id'=>$courseId,':fi'=>$inicio,':ff'=>$end,':p'=>$price,':e'=>$state,':o'=>'Curso generado automáticamente desde preinscripción pública.',':u'=>$admin]);
        }
        $studentId=uuid($pdo);
        $st=$pdo->prepare("INSERT INTO alumnos(id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:n,NULL,:wa,:c,:fi,NULL,NULL,'PENDIENTE',:o)");
        $st->execute([':id'=>$studentId,':n'=>$nombre,':wa'=>$wa,':c'=>$correo!==''?$correo:null,':fi'=>$inicio,':o'=>'Preinscripción pública a curso intensivo. Pendiente de confirmación de pago.']);
        $username=unique_user($pdo,$nombre);
        $st=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:p,'ALUMNO',1,1,:a)");
        $st->execute([':id'=>uuid($pdo),':u'=>$username,':p'=>password_hash($temp,PASSWORD_DEFAULT),':a'=>$studentId]);
        $st=$pdo->prepare("INSERT INTO curso_intensivo_alumnos(id,curso_intensivo_id,alumno_id,horario_id,observaciones,created_by) VALUES(:id,:c,:a,:h,:o,:u)");
        $st->execute([':id'=>uuid($pdo),':c'=>$courseId,':a'=>$studentId,':h'=>$horarioId,':o'=>'Preinscripción pública. Participación pendiente de pago.',':u'=>$admin]);
        $pdo->commit();
        $_SESSION['csrf']=bin2hex(random_bytes(24));$_SESSION['form_started']=time();
        $success=['nombre'=>$nombre,'inicio'=>$inicio,'fin'=>$end,'horario'=>human_time((string)$validHorario['hora_inicio'],(string)$validHorario['hora_fin']),'precio'=>$price];
    } catch(Throwable $ex) {
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$ex->getMessage();
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Preinscripción · Curso intensivo — Hache Natación</title><style>
:root{--ink:#172033;--muted:#64748b;--line:#dde5ee;--brand:#123b5d;--blue:#1976a8;--bg:#f3f7fb;--ok:#166534;--okbg:#ecfdf3;--err:#9f1239;--errbg:#fff1f2}*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#eaf3fa 0,#f6f8fb 38%,#f6f8fb 100%);color:var(--ink);font-family:Inter,Manrope,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{width:min(100%,620px);margin:auto;padding:28px 14px 48px}.brand{font-size:13px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:var(--brand);margin-bottom:24px}.hero{margin-bottom:18px}.hero h1{margin:0;font-size:34px;line-height:1.04;letter-spacing:-.04em}.hero p{margin:12px 0 0;color:var(--muted);font-size:15px;line-height:1.55}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:18px 0}.mini{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px}.mini b{display:block;font-size:17px}.mini span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:800;margin-top:3px}.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:0 12px 38px rgba(15,23,42,.07)}.field{margin-bottom:14px}.field label{display:block;font-size:12px;font-weight:850;margin-bottom:6px}.field input,.field select{width:100%;border:1px solid #cbd5e1;border-radius:11px;padding:12px 13px;background:#fff;color:var(--ink);font:inherit;font-size:16px}.hint{font-size:11px;color:var(--muted);margin-top:5px}.btn{width:100%;border:0;border-radius:12px;background:var(--ink);color:#fff;padding:14px;font-size:16px;font-weight:900;cursor:pointer}.notice{border-radius:13px;padding:12px 13px;margin-bottom:14px;font-size:13px;line-height:1.5}.notice.err{background:var(--errbg);color:var(--err);border:1px solid #fecdd3}.success{background:#fff;border:1px solid #bbf7d0;border-radius:20px;padding:22px;box-shadow:0 12px 38px rgba(15,23,42,.06)}.success .check{width:48px;height:48px;display:grid;place-items:center;border-radius:50%;background:var(--okbg);color:var(--ok);font-size:24px;font-weight:900}.success h2{font-size:26px;margin:15px 0 7px;letter-spacing:-.03em}.success p{color:var(--muted);line-height:1.55}.details{margin-top:16px;border-top:1px solid var(--line);padding-top:8px}.detail{display:flex;justify-content:space-between;gap:14px;padding:9px 0;border-bottom:1px solid #eef2f7}.detail:last-child{border-bottom:0}.detail span{color:var(--muted)}.foot{font-size:11px;color:var(--muted);text-align:center;line-height:1.5;margin-top:16px}.hp{position:absolute!important;left:-9999px!important}@media(max-width:480px){.wrap{padding-top:20px}.hero h1{font-size:30px}.summary{grid-template-columns:1fr 1fr}.summary .mini:last-child{grid-column:1/-1}.card{padding:16px}}
</style></head><body><main class="wrap"><div class="brand">Hache Natación · Monteverde</div><?php if($success):?><section class="success"><div class="check">✓</div><h2>Preinscripción recibida</h2><p><strong><?=e($success['nombre'])?></strong>, ya registramos tu solicitud. Tu lugar queda <strong>pendiente de confirmación de pago</strong>.</p><div class="details"><div class="detail"><span>Inicio</span><strong><?=e(human_date($success['inicio']))?></strong></div><div class="detail"><span>Horario</span><strong><?=e($success['horario'])?></strong></div><div class="detail"><span>Duración</span><strong>3 semanas · lunes a viernes</strong></div><div class="detail"><span>Curso</span><strong>$<?=number_format((float)$success['precio'],0)?> MXN</strong></div></div><p>Cuando Hache Natación confirme tu pago, tu inscripción quedará activa.</p></section><?php else:?><header class="hero"><h1>Curso intensivo de natación</h1><p>Completa tus datos para reservar tu preinscripción. El curso dura 3 semanas, de lunes a viernes.</p></header><div class="summary"><div class="mini"><b>3 semanas</b><span>Duración</span></div><div class="mini"><b>L–V</b><span>Frecuencia</span></div><div class="mini"><b>$1,200</b><span>Curso</span></div></div><section class="card"><?php if($error):?><div class="notice err"><?=e($error)?></div><?php endif;?><form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?=e((string)$_SESSION['csrf'])?>"><div class="hp" aria-hidden="true"><label>Sitio web<input name="website" tabindex="-1" autocomplete="off"></label></div><div class="field"><label>Nombre completo</label><input name="nombre" value="<?=e($old['nombre'])?>" maxlength="160" autocomplete="name" required></div><div class="field"><label>WhatsApp</label><input name="whatsapp" value="<?=e($old['whatsapp'])?>" inputmode="tel" autocomplete="tel" placeholder="Ej. 9981234567" required><div class="hint">Lo usaremos para identificar tu inscripción.</div></div><div class="field"><label>Correo electrónico <span style="color:#94a3b8;font-weight:600">(opcional)</span></label><input type="email" name="correo" value="<?=e($old['correo'])?>" autocomplete="email"></div><div class="field"><label>Lunes de inicio</label><select name="fecha_inicio" required><?php foreach($mondays as $d):?><option value="<?=e($d)?>" <?=$old['fecha_inicio']===$d?'selected':''?>><?=e(human_date($d))?></option><?php endforeach;?></select></div><div class="field"><label>Horario preferido</label><select name="horario_id" required><?php foreach($horarios as $h):?><option value="<?=e((string)$h['id'])?>" <?=$old['horario_id']===$h['id']?'selected':''?>><?=e(human_time((string)$h['hora_inicio'],(string)$h['hora_fin']))?></option><?php endforeach;?></select></div><button class="btn" type="submit">Enviar preinscripción</button></form></section><div class="foot">Enviar este formulario no registra un pago. Hache Natación confirmará tu lugar cuando se valide el pago del curso.</div><?php endif;?></main></body></html>