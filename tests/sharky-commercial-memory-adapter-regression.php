<?php

declare(strict_types=1);
require_once __DIR__.'/../config/sharky-lab-worker.php';
function adapter_memory_ok(bool $ok,string $why): void{if(!$ok)throw new RuntimeException($why);}
putenv('SHARKY_STATE_ENCRYPTION_KEY='.str_repeat('adapter-memory-key-',3));
putenv('SHARKY_CONTACT_HASH_KEY='.str_repeat('adapter-contact-key-',3));
/** A query-boundary test double: production encryption/load/save and adapter run unchanged. */
final class MemoryStatement extends PDOStatement {
    private array $rows=[];private int $affected=0;
    public function __construct(private MemoryPdo $db,private string $sql){}
    public function execute(?array $params=null): bool {[$this->rows,$this->affected]=$this->db->executeQuery($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args): array{return $mode===PDO::FETCH_COLUMN?array_map(static fn($r)=>array_values($r)[0],$this->rows):$this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed{return array_shift($this->rows)??false;}
    public function fetchColumn(int $column=0): mixed{return isset($this->rows[0])?array_values($this->rows[0])[$column]:false;}
    public function rowCount(): int{return $this->affected;}
}
final class MemoryPdo extends PDO {
    public array $states=[];public array $receipts=[];public bool $failSave=false;public bool $transaction=false;private array $backup=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]): PDOStatement|false{return new MemoryStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,...$fetchModeArgs): PDOStatement|false{$s=$this->prepare($query);$s->execute();return $s;}
    public function exec(string $statement): int|false{if(str_starts_with($statement,'DELETE FROM sharky_conversation_state'))return 0;throw new RuntimeException('Unexpected exec '.$statement);}
    public function inTransaction(): bool{return $this->transaction;}
    public function beginTransaction(): bool{$this->backup=$this->states;$this->transaction=true;return true;}
    public function rollBack(): bool{$this->states=$this->backup;$this->transaction=false;return true;}
    public function commit(): bool{$this->transaction=false;return true;}
    public function executeQuery(string $sql,array $p): array {
        if(str_contains($sql,'information_schema.columns'))return [array_map(static fn($k)=>['column_name'=>$k],['contact_hash','state_ciphertext','state_iv','state_tag','expires_at']),0];
        if(str_contains($sql,'information_schema.tables'))return [[['count'=>1]],0];
        if(str_starts_with($sql,'INSERT INTO sharky_conversation_state')){
            if($this->failSave)throw new RuntimeException('Injected durable save failure');
            $this->states[$p[':c']]=['state_json'=>null,'state_ciphertext'=>$p[':s'],'state_iv'=>$p[':iv'],'state_tag'=>$p[':tag'],'expires_at'=>$p[':e']];return [[],1];
        }
        if(str_starts_with($sql,'SELECT state_json'))return [isset($this->states[$p[':c']])?[$this->states[$p[':c']]]:[],0];
        if(str_starts_with($sql,'INSERT IGNORE INTO sharky_message_receipts')){if(isset($this->receipts[$p[':m']]))return [[],0];$this->receipts[$p[':m']]=true;return [[],1];}
        if(str_starts_with($sql,'UPDATE sharky_message_receipts'))return [[],0];
        if(str_contains($sql,'FROM alumnos')||str_contains($sql,'FROM sharky_identity_challenges')||str_contains($sql,'FROM sharky_action_audit'))return [[],0];
        if(str_contains($sql,'FROM sedes WHERE activo=1'))return [[],0];
        if(str_contains($sql,'FROM planes p'))return [[['id'=>'real-five','nombre'=>'Cinco semanal','sesiones_semana'=>5,'precio'=>1475]],0];
        if(str_contains($sql,'FROM horarios h'))return [[['id'=>'real-seven','hora_inicio'=>'07:00:00','hora_fin'=>'08:00:00']],0];
        throw new RuntimeException('Unexpected query '.$sql);
    }
}
$db=new MemoryPdo();$contact='529989991111';$s=hache_sharky_orchestrator_state();
$s['identity']=array_replace($s['identity'],['kind'=>'prospect','verified'=>false,'source'=>'whatsapp_unmatched']);
hache_sharky_db_state_save($db,$contact,$s);
$seen=[];
$model=static function(string $text,string $instruction,array $state,array $context)use(&$seen):string{
    $seen[]=$state;
    return str_contains($text,'costo')?'El plan seleccionado cuesta $1,475 MXN mensuales. ¿Prefieres 3 o 5 clases?':'Seguimos con tu elección. ¿Qué horario prefieres?';
};
foreach(['Buenas noches','Clases regulares','Monteverde','5 clases','7-8','¿Y el costo?','¿Puedo ir con traje de baño completo?'] as $i=>$text){
    $result=hache_sharky_whatsapp_process($db,['id'=>'adapter-'.$i,'from'=>$contact,'type'=>'text','text'=>$text],$model,['today'=>'2026-09-07','now'=>1788796800,'defer_receipt_completion'=>true]);
    adapter_memory_ok(!($result['skip']??false),'Adapter must produce response');
    $loaded=hache_sharky_db_state_load($db,$contact);
    adapter_memory_ok(json_encode($loaded['commercial_context'])===json_encode($result['state']['commercial_context']),'Returned state must equal actual encrypted persisted state at '.(string)$i.' '.json_encode([$loaded['commercial_context'],$result['state']['commercial_context']]));
    if($i>=4){
        adapter_memory_ok($loaded['commercial_context']['plan_id']==='real-five','Plan id survives adapter turns');
        adapter_memory_ok($loaded['commercial_context']['schedule_id']==='real-seven','Schedule id survives adapter turns');
        adapter_memory_ok(($result['payload']['interactive']['action']['buttons'][0]['reply']['id']??'')==='action:human','Ready regular gets an immediate actionable exit');
        adapter_memory_ok(!str_contains(hache_sharky_draft_payload_text($result['payload']),'prefieres'),'No backward question in final payload');
    }
}
adapter_memory_ok(end($seen)['commercial_context']['plan_id']==='real-five','Model receives persisted catalog-backed memory');
$loaded=hache_sharky_db_state_load($db,$contact);$before=$db->states;$db->failSave=true;$thrown=false;
try{hache_sharky_whatsapp_process($db,['id'=>'fail-save','from'=>$contact,'type'=>'text','text'=>'7-8'],$model,['today'=>'2026-09-07','now'=>1788796800,'defer_receipt_completion'=>true]);}catch(RuntimeException $e){$thrown=true;}
adapter_memory_ok($thrown&&$db->states===$before,'Save failure must not return a confirmation payload or change durable memory');
$payload=hache_sharky_whatsapp_text_payload($contact,'Continuamos.');
$queued=hache_sharky_lab_queue_and_complete($db,$contact,$payload,'test-failure','fail-save',[],['contact'=>$contact,'state'=>$loaded,'ttl'=>86400]);
adapter_memory_ok(!$queued&&$db->states===$before&&!$db->inTransaction(),'AI disclosure/state write rolls back with delivery boundary failure');
$db->failSave=false;
$result=hache_sharky_whatsapp_process($db,['id'=>'adapter-4','from'=>$contact,'type'=>'text','text'=>'7-8'],$model,['defer_receipt_completion'=>true]);
adapter_memory_ok(($result['code']??'')==='DUPLICATE','Repeated receipt cannot produce a second response');
fwrite(STDOUT,"SHARKY_COMMERCIAL_MEMORY_ADAPTER_OK\n");
