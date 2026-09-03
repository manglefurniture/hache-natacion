<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-store.php';

function hache_sharky_activation_required_tables(): array
{
    return [
        'sharky_message_receipts',
        'sharky_referrals',
        'sharky_conversation_state',
        'sharky_identity_challenges',
        'sharky_action_audit',
        'sharky_outbox',
    ];
}

function hache_sharky_activation_required_columns(): array
{
    return [
        'sharky_message_receipts'=>['message_id','contact_hash','message_type','payload_ciphertext','payload_iv','payload_tag','received_at','lease_until','attempt_count','last_error','handoff_pending_at','processed_at'],
        'sharky_referrals'=>['message_id','contact_hash','alumno_id','source_type','source_id','ctwa_clid','source_url','headline','body','media_type','image_url','video_url','thumbnail_url','referral_json','captured_at'],
        'sharky_conversation_state'=>['contact_hash','state_json','state_ciphertext','state_iv','state_tag','updated_at','expires_at'],
        'sharky_identity_challenges'=>['id','contact_hash','token_hash','status','verified_student_id','created_at','expires_at','verified_at'],
        'sharky_action_audit'=>['idempotency_key','action_type','contact_hash','alumno_id','status','payload_hash','source_message_id','result_code','result_json','result_ciphertext','result_iv','result_tag','result_message','delivery_queued_at','lease_until','owner_token','attempt_count','created_at','completed_at'],
        'sharky_outbox'=>['dedupe_key','contact_hash','payload_ciphertext','payload_iv','payload_tag','status','attempt_count','available_at','lease_until','owner_token','last_error','created_at','sent_at'],
    ];
}

function hache_sharky_activation_required_indexes(): array
{
    return [
        'sharky_message_receipts'=>['PRIMARY','idx_sharky_receipts_contact','idx_sharky_receipts_processed','idx_sharky_receipts_lease','idx_sharky_receipts_handoff'],
        'sharky_referrals'=>['PRIMARY','uq_sharky_referral_message','idx_sharky_referral_contact','idx_sharky_referral_source','idx_sharky_referral_student'],
        'sharky_conversation_state'=>['PRIMARY','idx_sharky_state_expires'],
        'sharky_identity_challenges'=>['PRIMARY','uq_sharky_identity_token','idx_sharky_identity_contact','idx_sharky_identity_status','idx_sharky_identity_student'],
        'sharky_action_audit'=>['PRIMARY','uq_sharky_action_idempotency','idx_sharky_action_contact','idx_sharky_action_student','idx_sharky_action_status','idx_sharky_action_delivery'],
        'sharky_outbox'=>['PRIMARY','uq_sharky_outbox_dedupe','idx_sharky_outbox_pending','idx_sharky_outbox_contact'],
    ];
}

function hache_sharky_activation_foreign_key_specs(): array
{
    return [
        [
            'table'=>'sharky_referrals','name'=>'fk_sharky_referral_alumno','column'=>'alumno_id',
            'ref_table'=>'alumnos','ref_column'=>'id','delete_rule'=>'SET NULL',
        ],
        [
            'table'=>'sharky_identity_challenges','name'=>'fk_sharky_identity_student','column'=>'verified_student_id',
            'ref_table'=>'alumnos','ref_column'=>'id','delete_rule'=>'SET NULL',
        ],
        [
            'table'=>'sharky_action_audit','name'=>'fk_sharky_action_alumno','column'=>'alumno_id',
            'ref_table'=>'alumnos','ref_column'=>'id','delete_rule'=>'SET NULL',
        ],
    ];
}

function hache_sharky_activation_required_constraints(): array
{
    $out=[];
    foreach(hache_sharky_activation_foreign_key_specs() as $spec)$out[(string)$spec['table']][]=(string)$spec['name'];
    return $out;
}

function hache_sharky_activation_constraint_present(PDO $pdo,array $spec): bool
{
    $st=$pdo->prepare("SELECT COUNT(*)
        FROM information_schema.key_column_usage k
        JOIN information_schema.referential_constraints r
          ON r.constraint_schema=k.constraint_schema
         AND r.table_name=k.table_name
         AND r.constraint_name=k.constraint_name
        WHERE k.table_schema=DATABASE()
          AND k.table_name=:t
          AND k.column_name=:c
          AND k.referenced_table_name=:rt
          AND k.referenced_column_name=:rc
          AND r.delete_rule=:dr");
    $st->execute([
        ':t'=>(string)$spec['table'],':c'=>(string)$spec['column'],
        ':rt'=>(string)$spec['ref_table'],':rc'=>(string)$spec['ref_column'],':dr'=>(string)$spec['delete_rule'],
    ]);
    return (int)$st->fetchColumn()>0;
}

function hache_sharky_activation_schema_report(PDO $pdo): array
{
    $missingTables=[];$missingColumns=[];$missingIndexes=[];$missingConstraints=[];
    foreach(hache_sharky_activation_required_tables() as $table){
        $st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
        $st->execute([':t'=>$table]);
        if((int)$st->fetchColumn()!==1){$missingTables[]=$table;continue;}

        $st=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
        $st->execute([':t'=>$table]);
        $present=array_fill_keys(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach(hache_sharky_activation_required_columns()[$table]??[] as $column){
            if(!isset($present[$column]))$missingColumns[]=$table.'.'.$column;
        }

        $st=$pdo->prepare('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:t');
        $st->execute([':t'=>$table]);
        $indexes=array_fill_keys(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach(hache_sharky_activation_required_indexes()[$table]??[] as $index){
            if(!isset($indexes[$index]))$missingIndexes[]=$table.'.'.$index;
        }
    }
    foreach(hache_sharky_activation_foreign_key_specs() as $spec){
        $table=(string)$spec['table'];
        if(in_array($table,$missingTables,true))continue;
        try{if(!hache_sharky_activation_constraint_present($pdo,$spec))$missingConstraints[]=$table.'.'.(string)$spec['name'];}
        catch(Throwable $e){$missingConstraints[]=$table.'.'.(string)$spec['name'];}
    }
    return [
        'ok'=>$missingTables===[]&&$missingColumns===[]&&$missingIndexes===[]&&$missingConstraints===[],
        'missing_tables'=>$missingTables,
        'missing_columns'=>$missingColumns,
        'missing_indexes'=>$missingIndexes,
        'missing_constraints'=>$missingConstraints,
    ];
}

function hache_sharky_activation_secret_report(): array
{
    $checks=[];
    $requirements=[
        'SHARKY_CONTACT_HASH_KEY'=>32,
        'SHARKY_STATE_ENCRYPTION_KEY'=>32,
        'WHATSAPP_VERIFY_TOKEN'=>1,
        'META_APP_SECRET'=>1,
        'WHATSAPP_PHONE_NUMBER_ID'=>1,
        'WHATSAPP_ACCESS_TOKEN'=>1,
    ];
    foreach($requirements as $name=>$minimum){
        $value=hache_sharky_orchestrator_secret($name);
        $checks[$name]=['ok'=>strlen($value)>=$minimum,'minimum_length'=>$minimum,'present'=>$value!==''];
    }
    $a=hache_sharky_orchestrator_secret('SHARKY_CONTACT_HASH_KEY');
    $b=hache_sharky_orchestrator_secret('SHARKY_STATE_ENCRYPTION_KEY');
    $checks['SHARKY_SECRETS_DISTINCT']=[
        'ok'=>$a!==''&&$b!==''&&!hash_equals($a,$b),
        'present'=>$a!==''&&$b!=='',
    ];
    return $checks;
}

function hache_sharky_activation_extension_report(): array
{
    $required=['openssl','curl','pdo_mysql','mbstring','json'];
    $out=[];foreach($required as $ext)$out[$ext]=extension_loaded($ext);
    return $out;
}

function hache_sharky_activation_data_report(PDO $pdo): array
{
    $queries=[
        'legacy_plaintext_state'=>"SELECT COUNT(*) FROM sharky_conversation_state WHERE state_json IS NOT NULL",
        'invalid_encrypted_state'=>"SELECT COUNT(*) FROM sharky_conversation_state WHERE state_json IS NULL AND (state_ciphertext IS NULL OR state_iv IS NULL OR state_tag IS NULL)",
        'pending_outbox_without_ciphertext'=>"SELECT COUNT(*) FROM sharky_outbox WHERE status='PENDING' AND (payload_ciphertext IS NULL OR payload_iv IS NULL OR payload_tag IS NULL)",
        'pending_outbox_total'=>"SELECT COUNT(*) FROM sharky_outbox WHERE status='PENDING'",
        'completed_actions_without_delivery'=>"SELECT COUNT(*) FROM sharky_action_audit WHERE status='COMPLETED' AND delivery_queued_at IS NULL",
        'pending_actions_total'=>"SELECT COUNT(*) FROM sharky_action_audit WHERE status='PENDING'",
        'pending_actions_expired'=>"SELECT COUNT(*) FROM sharky_action_audit WHERE status='PENDING' AND (lease_until IS NULL OR lease_until<NOW())",
        'pending_inbox_without_ciphertext'=>"SELECT COUNT(*) FROM sharky_message_receipts WHERE processed_at IS NULL AND (payload_ciphertext IS NULL OR payload_iv IS NULL OR payload_tag IS NULL)",
        'pending_inbox_total'=>"SELECT COUNT(*) FROM sharky_message_receipts WHERE processed_at IS NULL",
        'pending_inbox_expired'=>"SELECT COUNT(*) FROM sharky_message_receipts WHERE processed_at IS NULL AND (lease_until IS NULL OR lease_until<NOW())",
        'dead_outbox'=>"SELECT COUNT(*) FROM sharky_outbox WHERE status='DEAD'",
    ];
    $out=[];
    foreach($queries as $name=>$sql){
        try{$out[$name]=(int)$pdo->query($sql)->fetchColumn();}
        catch(Throwable $e){$out[$name]=null;}
    }
    return $out;
}

function hache_sharky_activation_preflight(PDO $pdo,bool $allowEnabled=false): array
{
    $schema=hache_sharky_activation_schema_report($pdo);
    $secrets=hache_sharky_activation_secret_report();
    $extensions=hache_sharky_activation_extension_report();
    $flag=hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED');
    $flagOk=$allowEnabled?in_array($flag,['0','1'],true):$flag==='0';
    $flagDisplay=in_array($flag,['0','1'],true)?$flag:($flag===''?'MISSING':'INVALID');
    $secretOk=true;foreach($secrets as $check)if(($check['ok']??false)!==true){$secretOk=false;break;}
    $extensionsOk=!in_array(false,$extensions,true);
    $data=$schema['ok']?hache_sharky_activation_data_report($pdo):[];
    $securityDataOk=$schema['ok']
        &&(($data['legacy_plaintext_state']??1)===0)
        &&(($data['invalid_encrypted_state']??1)===0)
        &&(($data['pending_inbox_without_ciphertext']??1)===0)
        &&(($data['pending_outbox_without_ciphertext']??1)===0)
        &&(($data['dead_outbox']??1)===0);
    $cleanCutoverOk=$allowEnabled||(
        (($data['pending_outbox_total']??1)===0)
        &&(($data['pending_inbox_total']??1)===0)
        &&(($data['pending_actions_total']??1)===0)
        &&(($data['completed_actions_without_delivery']??1)===0)
    );

    return [
        'ok'=>$schema['ok']&&$secretOk&&$extensionsOk&&$flagOk&&$securityDataOk&&$cleanCutoverOk,
        'feature_flag'=>$flagDisplay,
        'feature_flag_ok'=>$flagOk,
        'clean_cutover_ok'=>$cleanCutoverOk,
        'schema'=>$schema,
        'secrets'=>$secrets,
        'extensions'=>$extensions,
        'data'=>$data,
    ];
}

function hache_sharky_activation_split_sql(string $sql): array
{
    $statements=[];$buffer='';$quote=null;$lineComment=false;$blockComment=false;$length=strlen($sql);
    for($i=0;$i<$length;$i++){
        $ch=$sql[$i];$next=$i+1<$length?$sql[$i+1]:'';
        if($lineComment){$buffer.=$ch;if($ch==="\n")$lineComment=false;continue;}
        if($blockComment){$buffer.=$ch;if($ch==='*'&&$next==='/'){$buffer.='/';$i++;$blockComment=false;}continue;}
        if($quote!==null){
            $buffer.=$ch;
            if($ch==='\\'&&$i+1<$length){$buffer.=$sql[++$i];continue;}
            if($ch===$quote){
                if($quote!=="`"&&$next===$quote){$buffer.=$next;$i++;continue;}
                $quote=null;
            }
            continue;
        }
        if($ch==='-'&&$next==='-'&&($i+2>=$length||ctype_space($sql[$i+2]))){$lineComment=true;$buffer.='--';$i++;continue;}
        if($ch==='#'){$lineComment=true;$buffer.=$ch;continue;}
        if($ch==='/'&&$next==='*'){$blockComment=true;$buffer.='/*';$i++;continue;}
        if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buffer.=$ch;continue;}
        if($ch===';'){
            $statement=trim($buffer);if($statement!=='')$statements[]=$statement;$buffer='';continue;
        }
        $buffer.=$ch;
    }
    $tail=trim($buffer);if($tail!=='')$statements[]=$tail;
    return $statements;
}
