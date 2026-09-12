<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../config/auth.php';
require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-learning.php';

auth_require(['ADMIN']);
$pdo=hache_sharky_pdo();
if(!$pdo instanceof PDO){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'DB unavailable']);exit;}
if(!hache_sharky_learning_schema_ready($pdo)){echo json_encode(['ok'=>true,'summary'=>[],'cases'=>[]]);exit;}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='GET'){
    $status=strtoupper(trim((string)($_GET['status']??'')));
    $where='1=1';$params=[];
    if(in_array($status,['PENDING','REVIEWED','APPROVED','DISMISSED'],true)){$where='lc.status=:status';$params[':status']=$status;}
    $sql="SELECT lc.id,lc.case_kind,lc.status,lc.verdict,lc.priority,lc.regression_required,lc.rule_area,lc.rationale,lc.expected_behavior,lc.recommendation,lc.reviewer,lc.reviewed_at,lc.created_at,"
        ."r.classification,f.finding_type,f.severity FROM sharky_learning_cases lc JOIN sharky_conversation_reviews r ON r.id=lc.review_id LEFT JOIN sharky_conversation_findings f ON f.id=lc.finding_id WHERE $where ORDER BY FIELD(lc.status,'PENDING','APPROVED','REVIEWED','DISMISSED'),FIELD(lc.priority,'CRITICAL','HIGH','MEDIUM','LOW'),lc.created_at DESC LIMIT 100";
    $st=$pdo->prepare($sql);$st->execute($params);
    echo json_encode(['ok'=>true,'summary'=>hache_sharky_learning_summary($pdo),'cases'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if($method!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'Método no permitido']);exit;}
$input=json_decode(file_get_contents('php://input'),true);
if(!is_array($input)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'JSON inválido']);exit;}
$id=trim((string)($input['case_id']??''));$status=strtoupper(trim((string)($input['status']??'')));
if(!preg_match('/^[a-f0-9-]{36}$/i',$id)||!in_array($status,['REVIEWED','APPROVED','DISMISSED'],true)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Datos inválidos']);exit;}
$st=$pdo->prepare("UPDATE sharky_learning_cases SET status=:s,reviewed_at=COALESCE(reviewed_at,NOW()) WHERE id=:id");$st->execute([':s'=>$status,':id'=>$id]);
echo json_encode(['ok'=>true]);
