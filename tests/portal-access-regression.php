<?php
declare(strict_types=1);

function portal_expect(bool $ok,string $message): void
{
    if(!$ok){fwrite(STDERR,"PORTAL ACCESS FAIL: {$message}\n");exit(1);}
}

$root=dirname(__DIR__);
$service=file_get_contents($root.'/config/portal-access.php')?:'';
$migration=file_get_contents($root.'/database/migrations/20260916_portal_access_tokens.sql')?:'';
$route=file_get_contents($root.'/public/acceso.php')?:'';
$auth=file_get_contents($root.'/config/auth.php')?:'';
$passwordApi=file_get_contents($root.'/api/cambiar-password.php')?:'';
$passwordPage=file_get_contents($root.'/public/cambiar-password.php')?:'';
$template=file_get_contents($root.'/config/sharky-template-notifications.php')?:'';
$registration=file_get_contents($root.'/public/registro.php')?:'';
$adminStudents=file_get_contents($root.'/api/alumnos.php')?:'';
$deploy=file_get_contents($root.'/ops/production-readiness/deploy-hache-natacion')?:'';

portal_expect(str_contains($service,'random_bytes(32)'),'El token debe tener entropía criptográfica.');
portal_expect(str_contains($service,"hash('sha256',\$token)"),'Solo debe persistirse el hash del token.');
portal_expect(str_contains($service,'consumed_at IS NULL')&&str_contains($service,'expires_at>=NOW()'),'El acceso debe ser de un solo uso y expirar.');
portal_expect(str_contains($service,'FOR UPDATE'),'El consumo debe bloquear el token antes de invalidarlo.');
portal_expect(str_contains($service,'function hache_portal_access_revoke_user(PDO $pdo,string $userId): bool'),'La revocación debe reportar si realmente pudo ejecutarse.');
portal_expect(!str_contains($migration,' token TEXT')&&!str_contains($migration,' token VARCHAR'),'La base no debe guardar el token en claro.');
portal_expect(str_contains($migration,'token_hash CHAR(64) NOT NULL'),'La base debe guardar un hash SHA-256.');
portal_expect(str_contains($migration,'UNIQUE KEY uq_portal_access_token_hash'),'Cada token debe ser único.');

portal_expect(str_contains($route,"header('Cache-Control: no-store"),'La ruta de acceso no debe cachearse.');
portal_expect(str_contains($route,"header('Referrer-Policy: no-referrer')"),'El token no debe filtrarse por Referer.');
portal_expect(str_contains($route,'hache_portal_access_consume($pdo,$token)'),'La ruta debe consumir el token antes de iniciar sesión.');
portal_expect(str_contains($route,'auth_login($user)'),'El acceso válido debe crear la sesión normal del alumno.');
portal_expect(str_contains($route,'auth_portal_bootstrap_grant'),'El primer acceso debe autorizar únicamente la creación de contraseña.');
portal_expect(str_contains($auth,'function auth_portal_bootstrap_active'),'La sesión debe validar el grant temporal.');
portal_expect(str_contains($passwordApi,'!$bootstrap&&!password_verify($actual,$hash)'),'Solo el grant seguro puede omitir la contraseña actual.');
$txPos=strpos($passwordApi,'$pdo->beginTransaction()');
$updatePos=strpos($passwordApi,'UPDATE usuarios SET password_hash');
$revokePos=strpos($passwordApi,'if(!hache_portal_access_revoke_user');
$commitPos=strpos($passwordApi,'$pdo->commit()');
portal_expect($txPos!==false&&$updatePos>$txPos&&$revokePos>$updatePos&&$commitPos>$revokePos,'Contraseña y revocación deben confirmarse en una sola transacción.');
portal_expect(str_contains($passwordApi,'$pdo->inTransaction())$pdo->rollBack()'),'Un fallo de revocación debe revertir la contraseña.');
portal_expect(str_contains($passwordPage,"Entraste desde el acceso seguro que enviamos a tu WhatsApp"),'La pantalla debe explicar el origen del acceso.');

portal_expect(str_contains($template,"HACHE_SHARKY_TEMPLATE_ENROLLMENT_CONFIRMED = 'hache_registro_recibido_mx'"),'Debe usarse la plantilla configurada mientras Meta aprueba la definitiva con URL dinámica.');
portal_expect(str_contains($template,"'sub_type'=>'url'")&&str_contains($template,"'index'=>'0'"),'La plantilla debe alimentar el botón URL dinámico.');
portal_expect(str_contains($template,'hache_portal_access_issue($pdo,$studentId)'),'Cada aviso WhatsApp debe emitir su acceso seguro.');
portal_expect(str_contains($registration,'hache_portal_access_issue($pdo,$aid)'),'El registro web debe crear un acceso seguro independiente del envío por WhatsApp.');
portal_expect(str_contains($registration,"\$portalAccessUrl='/acceso.php?t='"),'El registro web debe ofrecer un fallback directo al portal.');
portal_expect(str_contains($registration,'Abrir PORTAL'),'El fallback web debe ser accionable sin mostrar credenciales.');
portal_expect(str_contains($registration,"header('Cache-Control: no-store"),'La página que contiene el fallback no debe cachearse.');
portal_expect(str_contains($registration,'La confirmación y el acceso al portal se enviarán al número que registraste.'),'WhatsApp se mantiene como segundo canal de acceso.');
portal_expect(!str_contains($registration,'Contraseña temporal:'),'El registro web no debe mostrar credenciales.');
portal_expect(str_contains($adminStudents,'hache_notificar_nueva_inscripcion($alumno,$tipoIngreso'),'Un alta manual de admin debe pasar por el mismo notificador canónico.');
portal_expect(str_contains($deploy,'migrate-portal-access.php'),'El despliegue debe aplicar la migración antes de usar el flujo.');

echo "PORTAL_ACCESS_REGRESSION_OK\n";
