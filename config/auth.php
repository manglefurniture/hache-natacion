<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $httpsDirect=!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $httpsForwarded=strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')))==='https';
    session_name('hache_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $httpsDirect || $httpsForwarded,
        'use_strict_mode' => true,
    ]);
}

function auth_user(): ?array
{
    $u = $_SESSION['hache_usuario'] ?? null;
    return is_array($u) ? $u : null;
}

function auth_login(array $user): void
{
    $role=strtoupper(trim((string)($user['rol']??'')));
    if(!in_array($role,['ADMIN','VERIFICADOR','ALUMNO'],true))throw new InvalidArgumentException('Rol de usuario inválido');
    session_regenerate_id(true);
    unset($_SESSION['hache_csrf'],$_SESSION['hache_reconciliada']);
    $sedeClave = !empty($user['sede_clave']) ? strtoupper((string)$user['sede_clave']) : null;
    $_SESSION['hache_usuario'] = [
        'id' => (string)$user['id'],
        'usuario' => (string)$user['usuario'],
        'rol' => $role,
        'alumno_id' => $user['alumno_id'] ?: null,
        'sede_id' => !empty($user['sede_id']) ? (string)$user['sede_id'] : null,
        'sede_clave' => $sedeClave,
        'sede_nombre' => !empty($user['sede_nombre']) ? (string)$user['sede_nombre'] : null,
        'sede_activa' => ($role === 'VERIFICADOR' && in_array($sedeClave, ['MONTEVERDE','PALAPAS'], true)) ? $sedeClave : 'MONTEVERDE',
        'debe_cambiar_password' => !empty($user['debe_cambiar_password']) ? 1 : 0,
        'auth_checked_at' => time(),
    ];
    $_SESSION['hache_password_fingerprint']=hash('sha256',(string)($user['password_hash']??''));
}

function auth_refresh_password_flag(bool $mustChange): void
{
    if (isset($_SESSION['hache_usuario']) && is_array($_SESSION['hache_usuario'])) {
        $_SESSION['hache_usuario']['debe_cambiar_password'] = $mustChange ? 1 : 0;
        $_SESSION['hache_usuario']['auth_checked_at'] = time();
    }
}

function auth_refresh_password_fingerprint(string $passwordHash): void
{
    $_SESSION['hache_password_fingerprint']=hash('sha256',$passwordHash);
    if(isset($_SESSION['hache_usuario'])&&is_array($_SESSION['hache_usuario']))$_SESSION['hache_usuario']['auth_checked_at']=time();
}

function auth_csrf_token(): string
{
    $token = $_SESSION['hache_csrf'] ?? null;
    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['hache_csrf'] = $token;
    }
    return $token;
}

function auth_csrf_validate(?string $token): bool
{
    $expected = $_SESSION['hache_csrf'] ?? null;
    return is_string($expected) && is_string($token) && hash_equals($expected, $token);
}

function auth_revalidate_user(bool $force=false): ?array
{
    static $checkedThisRequest=false;
    $u=auth_user();
    if(!$u || $checkedThisRequest)return $u;
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $mutation=!in_array($method,['GET','HEAD','OPTIONS'],true);
    $last=(int)($u['auth_checked_at']??0);
    if(!$force && !$mutation && $last>0 && (time()-$last)<60)return $u;
    $checkedThisRequest=true;
    try{
        $local=__DIR__.'/database.local.php';
        $cfg=is_file($local)?require $local:[
            'host'=>getenv('DB_HOST')?:'127.0.0.1',
            'dbname'=>getenv('DB_NAME')?:'hache_natacion',
            'user'=>getenv('DB_USER')?:'',
            'password'=>getenv('DB_PASS')?:'',
            'charset'=>getenv('DB_CHARSET')?:'utf8mb4',
        ];
        $pdo=new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}",
            $cfg['user'],$cfg['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
        );
        $st=$pdo->prepare("SELECT u.id,u.usuario,u.password_hash,u.rol,u.activo,u.alumno_id,u.sede_id,u.debe_cambiar_password,s.clave sede_clave,s.nombre sede_nombre,s.activo sede_activo FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.id=:id LIMIT 1");
        $st->execute([':id'=>(string)$u['id']]);$fresh=$st->fetch();
        if(!$fresh || !(bool)$fresh['activo']){auth_logout();return null;}
        $role=strtoupper((string)$fresh['rol']);$ownSite=!empty($fresh['sede_clave'])?strtoupper((string)$fresh['sede_clave']):null;
        if(!in_array($role,['ADMIN','VERIFICADOR','ALUMNO'],true)||($role==='ALUMNO'&&empty($fresh['alumno_id']))||($role==='VERIFICADOR'&&(!in_array($ownSite,['MONTEVERDE','PALAPAS'],true)||(int)($fresh['sede_activo']??0)!==1))){auth_logout();return null;}
        $fingerprint=hash('sha256',(string)$fresh['password_hash']);$storedFingerprint=$_SESSION['hache_password_fingerprint']??null;
        if(is_string($storedFingerprint)&&!hash_equals($storedFingerprint,$fingerprint)){auth_logout();return null;}
        $active=strtoupper((string)($u['sede_activa']??'MONTEVERDE'));
        if($role==='VERIFICADOR')$active=in_array($ownSite,['MONTEVERDE','PALAPAS'],true)?$ownSite:'MONTEVERDE';
        elseif(!in_array($active,['MONTEVERDE','PALAPAS'],true))$active='MONTEVERDE';
        $_SESSION['hache_usuario']=[
            'id'=>(string)$fresh['id'],'usuario'=>(string)$fresh['usuario'],'rol'=>$role,
            'alumno_id'=>$fresh['alumno_id']?:null,'sede_id'=>$fresh['sede_id']?:null,
            'sede_clave'=>$ownSite,'sede_nombre'=>$fresh['sede_nombre']?:null,'sede_activa'=>$active,
            'debe_cambiar_password'=>!empty($fresh['debe_cambiar_password'])?1:0,'auth_checked_at'=>time(),
        ];
        $_SESSION['hache_password_fingerprint']=$fingerprint;
        return $_SESSION['hache_usuario'];
    }catch(Throwable $e){
        error_log('Hache revalidación de sesión: '.$e->getMessage());
        auth_logout();
        return null;
    }
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_request_json(): array
{
    static $decoded=null;
    if(is_array($decoded)) return $decoded;
    $raw=file_get_contents('php://input');
    $value=json_decode((string)$raw,true);
    $decoded=is_array($value)?$value:[];
    return $decoded;
}

function auth_verificador_override(array $roles): bool
{
    $u=auth_user();
    if(($u['rol']??'')!=='VERIFICADOR' || in_array('VERIFICADOR',$roles,true)) return false;
    if(!in_array('ADMIN',$roles,true)) return false;
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $script=basename((string)($_SERVER['SCRIPT_NAME']??$_SERVER['SCRIPT_FILENAME']??''));
    if(in_array($method,['GET','HEAD'],true) && in_array($script,['conciliacion-proa.php','conciliacion-proa-pdf.php','comisiones-proa.php'],true)) return true;
    if($method!=='POST') return false;
    $accion=strtoupper(trim((string)(auth_request_json()['accion']??'')));
    if(in_array($script,['asistencia.php','sesiones.php'],true) && $accion==='ASISTENCIA') return true;
    if($script==='ausencias-programadas.php' && in_array($accion,['CREAR','CANCELAR'],true)) return true;
    return false;
}

function auth_require(array $roles = [], bool $allowForcedPassword = false): array
{
    $u = auth_revalidate_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Sesión no iniciada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$allowForcedPassword && !empty($u['debe_cambiar_password'])) {
        http_response_code(428);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Debes cambiar tu contraseña antes de continuar','password_change_required'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($roles && !in_array($u['rol'], $roles, true) && !auth_verificador_override($roles)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'No tienes permiso para realizar esta acción'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}

function auth_active_sede_clave(): string
{
    $u = auth_user();
    if (!$u) return 'MONTEVERDE';
    if (($u['rol'] ?? '') === 'VERIFICADOR') {
        $own = strtoupper(trim((string)($u['sede_clave'] ?? '')));
        return in_array($own, ['MONTEVERDE','PALAPAS'], true) ? $own : 'MONTEVERDE';
    }
    $active = strtoupper(trim((string)($u['sede_activa'] ?? 'MONTEVERDE')));
    return in_array($active, ['MONTEVERDE','PALAPAS'], true) ? $active : 'MONTEVERDE';
}

function auth_set_active_sede(string $clave): string
{
    $u = auth_user();
    if (!$u) {
        throw new RuntimeException('Sesión no iniciada');
    }
    $clave = strtoupper(trim($clave));
    if (!in_array($clave, ['MONTEVERDE','PALAPAS'], true)) {
        throw new InvalidArgumentException('Sede inválida');
    }
    if(!in_array((string)($u['rol']??''),['ADMIN','VERIFICADOR'],true))throw new RuntimeException('No tienes permiso para cambiar de sede');
    if (($u['rol'] ?? '') === 'VERIFICADOR') {
        $own = strtoupper(trim((string)($u['sede_clave'] ?? '')));
        if ($clave !== $own) throw new RuntimeException('No tienes permiso para cambiar de sede');
        $_SESSION['hache_usuario']['sede_activa'] = $own;
        return $own;
    }
    $_SESSION['hache_usuario']['sede_activa'] = $clave;
    return $clave;
}

function auth_resolve_sede_clave(?string $requested = null): string
{
    $u = auth_user();
    if ($u) return auth_active_sede_clave();
    $req = strtoupper(trim((string)$requested));
    return in_array($req, ['MONTEVERDE','PALAPAS'], true) ? $req : 'MONTEVERDE';
}

function page_require(array $roles = [], bool $allowForcedPassword = false): array
{
    $u = auth_revalidate_user();
    if (!$u) {
        header('Location: /');
        exit;
    }
    if (!$allowForcedPassword && !empty($u['debe_cambiar_password'])) {
        header('Location: /cambiar-password.php');
        exit;
    }
    $path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)?:'';
    $supervisorReadOnly=($u['rol']??'')==='VERIFICADOR' && in_array($path,['/conciliacion-proa.php','/comisiones-proa.php'],true);
    if ($roles && !in_array($u['rol'], $roles, true) && !$supervisorReadOnly) {
        header('Location: '.($u['rol']==='ALUMNO' ? '/mi-cuenta.php' : '/dashboard.php'));
        exit;
    }
    return $u;
}
