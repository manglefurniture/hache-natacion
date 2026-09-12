<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-conversation-review.php';

function hache_sharky_learning_schema_ready(PDO $pdo): bool
{
    try{
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_learning_cases'");
        return (int)$st->fetchColumn()===1;
    }catch(Throwable $e){return false;}
}

function hache_sharky_learning_uuid(): string
{
    return hache_sharky_conversation_review_uuid();
}

function hache_sharky_learning_sync(PDO $pdo): array
{
    if(!hache_sharky_learning_schema_ready($pdo))return ['status'=>'schema_missing','created'=>0];
    $created=0;
    $sql="INSERT IGNORE INTO sharky_learning_cases(id,review_id,finding_id,contact_hash,case_kind,priority)\n"
        ."SELECT UUID(),f.review_id,f.id,f.contact_hash,'FINDING',CASE f.severity WHEN 'HIGH' THEN 'HIGH' WHEN 'WARN' THEN 'MEDIUM' ELSE 'LOW' END\n"
        ."FROM sharky_conversation_findings f WHERE f.status='NEW'";
    $created+=(int)$pdo->exec($sql);

    // Sample at most one clean conversation per hourly review window so positive
    // patterns are learned without flooding the queue.
    $sql="INSERT IGNORE INTO sharky_learning_cases(id,review_id,finding_id,contact_hash,case_kind,priority)\n"
        ."SELECT UUID(),r.id,NULL,r.contact_hash,'GOOD_SAMPLE','LOW' FROM sharky_conversation_reviews r\n"
        ."LEFT JOIN sharky_learning_cases lc ON lc.review_id=r.id AND lc.case_kind='GOOD_SAMPLE'\n"
        ."WHERE r.classification='OK' AND lc.id IS NULL ORDER BY r.created_at DESC LIMIT 1";
    $created+=(int)$pdo->exec($sql);
    return ['status'=>'ok','created'=>$created];
}

function hache_sharky_learning_pending(PDO $pdo,int $limit=10): array
{
    $limit=max(1,min(25,$limit));
    $sql="SELECT lc.id,lc.review_id,lc.finding_id,lc.contact_hash,lc.case_kind,lc.status,lc.priority,lc.created_at,"
        ."f.finding_type,f.severity,f.source_message_id,f.related_message_id,f.evidence_json,"
        ."UNIX_TIMESTAMP(r.window_start) window_start,UNIX_TIMESTAMP(r.window_end) window_end,r.classification "
        ."FROM sharky_learning_cases lc JOIN sharky_conversation_reviews r ON r.id=lc.review_id "
        ."LEFT JOIN sharky_conversation_findings f ON f.id=lc.finding_id "
        ."WHERE lc.status='PENDING' ORDER BY FIELD(lc.priority,'CRITICAL','HIGH','MEDIUM','LOW'),lc.created_at LIMIT $limit";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function hache_sharky_learning_context(PDO $pdo,array $case): array
{
    $since=(int)($case['window_start']??0);
    $timeline=hache_sharky_conversation_review_timeline($pdo,(string)$case['contact_hash'],$since);
    if(count($timeline)>30)$timeline=array_slice($timeline,-30);
    $turns=[];$i=0;
    foreach($timeline as $turn){
        $text=trim((string)($turn['text']??''));
        $text=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu','[email]',$text)??$text;
        $text=preg_replace('/\b(?:\+?\d[\s().-]*){8,15}\b/u','[telefono]',$text)??$text;
        $text=preg_replace('/https?:\/\/\S+/iu','[url]',$text)??$text;
        $turns[]=['n'=>++$i,'direction'=>$turn['direction'],'text'=>$text];
    }
    return $turns;
}

function hache_sharky_learning_export(PDO $pdo,int $limit=10): array
{
    $out=[];
    foreach(hache_sharky_learning_pending($pdo,$limit) as $case){
        $out[]=[
            'case_id'=>$case['id'],'kind'=>$case['case_kind'],'priority'=>$case['priority'],
            'classification'=>$case['classification'],'finding_type'=>$case['finding_type']??null,
            'severity'=>$case['severity']??null,'evidence'=>json_decode((string)($case['evidence_json']??''),true),
            'transcript'=>hache_sharky_learning_context($pdo,$case),
        ];
    }
    return $out;
}

function hache_sharky_learning_apply_verdict(PDO $pdo,array $input): array
{
    $id=trim((string)($input['case_id']??''));
    $verdict=strtoupper(trim((string)($input['verdict']??'')));
    $priority=strtoupper(trim((string)($input['priority']??'MEDIUM')));
    $allowedVerdicts=['CORRECTO','MEJORABLE','ERROR_REAL','FALSO_POSITIVO','GOOD_PATTERN'];
    $allowedPriorities=['LOW','MEDIUM','HIGH','CRITICAL'];
    if(!preg_match('/^[a-f0-9-]{36}$/i',$id)||!in_array($verdict,$allowedVerdicts,true)||!in_array($priority,$allowedPriorities,true))throw new InvalidArgumentException('Invalid learning verdict');
    $reg=!empty($input['regression_required'])?1:0;
    $status=in_array($verdict,['ERROR_REAL','MEJORABLE','GOOD_PATTERN'],true)?'APPROVED':($verdict==='FALSO_POSITIVO'?'DISMISSED':'REVIEWED');
    $st=$pdo->prepare("UPDATE sharky_learning_cases SET status=:s,verdict=:v,priority=:p,regression_required=:r,rule_area=:a,rationale=:ra,expected_behavior=:e,recommendation=:rec,reviewer='chatgpt',reviewed_at=NOW() WHERE id=:id AND status='PENDING'");
    $st->execute([':s'=>$status,':v'=>$verdict,':p'=>$priority,':r'=>$reg,':a'=>mb_substr(trim((string)($input['rule_area']??'')),0,80),':ra'=>trim((string)($input['rationale']??'')),':e'=>trim((string)($input['expected_behavior']??'')),':rec'=>trim((string)($input['recommendation']??'')),':id'=>$id]);
    if($st->rowCount()!==1)throw new RuntimeException('Learning case not pending');
    $q=$pdo->prepare('SELECT finding_id FROM sharky_learning_cases WHERE id=:id');$q->execute([':id'=>$id]);$finding=(string)($q->fetchColumn()?:'');
    if($finding!==''){
        $fs=$verdict==='FALSO_POSITIVO'?'IGNORED':($reg?'REGRESSION_CANDIDATE':'REVIEWED');
        $u=$pdo->prepare('UPDATE sharky_conversation_findings SET status=:s,regression_candidate=:r,reviewed_at=NOW() WHERE id=:id');
        $u->execute([':s'=>$fs,':r'=>$reg,':id'=>$finding]);
    }
    return ['ok'=>true,'status'=>$status];
}

function hache_sharky_learning_summary(PDO $pdo): array
{
    if(!hache_sharky_learning_schema_ready($pdo))return ['pending'=>0,'approved'=>0,'errors'=>0,'good_patterns'=>0];
    $row=$pdo->query("SELECT SUM(status='PENDING') pending,SUM(status='APPROVED') approved,SUM(verdict='ERROR_REAL') errors,SUM(verdict='GOOD_PATTERN') good_patterns FROM sharky_learning_cases")->fetch(PDO::FETCH_ASSOC)?:[];
    return array_map('intval',$row);
}
