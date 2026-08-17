<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
$user=auth_require(['ADMIN','VERIFICADOR']);
$config=require __DIR__.'/../config/database.php';
$pdo=new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",$config['user'],$config['password'],[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->exec("CREATE TABLE IF NOT EXISTS diagnostico_eventos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 usuario_id CHAR(36) NULL,
 rol VARCHAR(20) NULL,
 tipo VARCHAR(30) NOT NULL,
 nivel VARCHAR(12) NOT NULL DEFAULT 'INFO',
 pagina VARCHAR(190) NULL,
 recurso VARCHAR(190) NULL,
 mensaje VARCHAR(500) NULL,
 detalle TEXT NULL,
 duracion_ms INT NULL,
 status_http SMALLINT NULL,
 dispositivo VARCHAR(40) NULL,
 user_agent VARCHAR(255) NULL,
 PRIMARY KEY(id),
 KEY idx_diag_fecha(creado_en),
 KEY idx_diag_tipo(tipo),
 KEY idx_diag_nivel(nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
function out(array $d,int $code=200):never{http_response_code($code);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function clean(?string $v,int $max):?string{$v=trim((string)$v);if($v==='')return null;return mb_substr($v,0,$max,'UTF-8');}
function safePath(?string $v):?string{$v=clean($v,500);if(!$v)return null;$p=parse_url($v,PHP_URL_PATH);return clean(is_string($p)?$p:$v,190);}
if($_SERVER['REQUEST_METHOD']==='POST'){
 $raw=file_get_contents('php://input');$d=json_decode($raw?:'{}',true);if(!is_array($d))$d=[];
 $tipo=strtoupper((string)($d['tipo']??'CLIENT'));if(!in_array($tipo,['JS_ERROR','PROMISE_ERROR','HTTP_ERROR','API_TIMING','PAGE_TIMING','DOM_ACTIVITY'],true))$tipo='CLIENT';
 $nivel=strtoupper((string)($d['nivel']??'INFO'));if(!in_array($nivel,['INFO','WARN','ERROR'],true))$nivel='INFO';
 $dur=isset($d['duracion_ms'])?max(0,min(600000,(int)$d['duracion_ms'])):null;$status=isset($d['status_http'])?max(0,min(999,(int)$d['status_http'])):null;
 $st=$pdo->prepare("INSERT INTO diagnostico_eventos(usuario_id,rol,tipo,nivel,pagina,recurso,mensaje,detalle,duracion_ms,status_http,dispositivo,user_agent) VALUES(:u,:r,:t,:n,:p,:re,:m,:d,:du,:s,:di,:ua)");
 $st->execute([':u'=>$user['id']??null,':r'=>$user['rol']??null,':t'=>$tipo,':n'=>$nivel,':p'=>safePath($d['pagina']??null),':re'=>safePath($d['recurso']??null),':m'=>clean($d['mensaje']??null,500),':d'=>clean($d['detalle']??null,3000),':du'=>$dur,':s'=>$status,':di'=>clean($d['dispositivo']??null,40),':ua'=>clean($_SERVER['HTTP_USER_AGENT']??'',255)]);
 if(random_int(1,25)===1){$pdo->exec("DELETE FROM diagnostico_eventos WHERE creado_en < NOW() - INTERVAL 14 DAY");$pdo->exec("DELETE FROM diagnostico_eventos WHERE id NOT IN (SELECT id FROM (SELECT id FROM diagnostico_eventos ORDER BY id DESC LIMIT 2000) x)");}
 out(['ok'=>true],201);
}
if(($user['rol']??'')!=='ADMIN')out(['ok'=>false,'error'=>'Solo ADMIN puede consultar diagnóstico'],403);
$summary=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(nivel='ERROR'),0) errores,COALESCE(SUM(nivel='WARN'),0) avisos,COALESCE(SUM(tipo='HTTP_ERROR'),0) http_errores,COALESCE(SUM(tipo='JS_ERROR' OR tipo='PROMISE_ERROR'),0) js_errores FROM diagnostico_eventos WHERE creado_en >= NOW() - INTERVAL 24 HOUR")->fetch();
$slow=$pdo->query("SELECT recurso,ROUND(AVG(duracion_ms)) promedio_ms,MAX(duracion_ms) max_ms,COUNT(*) muestras FROM diagnostico_eventos WHERE tipo='API_TIMING' AND duracion_ms IS NOT NULL AND creado_en >= NOW() - INTERVAL 24 HOUR GROUP BY recurso HAVING COUNT(*)>=1 ORDER BY promedio_ms DESC LIMIT 8")->fetchAll();
$recent=$pdo->query("SELECT creado_en,tipo,nivel,pagina,recurso,mensaje,duracion_ms,status_http,dispositivo FROM diagnostico_eventos WHERE nivel IN ('ERROR','WARN') ORDER BY id DESC LIMIT 20")->fetchAll();
out(['ok'=>true,'resumen'=>$summary,'lentas'=>$slow,'recientes'=>$recent,'retencion_dias'=>14,'max_eventos'=>2000]);
