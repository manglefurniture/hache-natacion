<?php
declare(strict_types=1);

/**
 * Caja de minería independiente de las finanzas de natación.
 * Solo registra movimientos efectivamente realizados; no inventa costos a partir del hashrate.
 */
function mineria_caja_path(): string {
    return (string)(getenv('MINERIA_CAJA_FILE') ?: '/var/lib/hache-natacion/mineria-caja.json');
}
function mineria_caja_empty(): array { return ['version'=>1,'entries'=>[]]; }
function mineria_caja_read_unlocked(string $path): array {
    if(is_link($path))throw new RuntimeException('Ruta de caja no segura.');
    if(!is_file($path))return mineria_caja_empty();
    $raw=file_get_contents($path);
    if($raw===false)throw new RuntimeException('No se pudo leer la caja.');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data)||($data['version']??null)!==1||!is_array($data['entries']??null))
        throw new RuntimeException('Registro de caja inválido.');
    return $data;
}
function mineria_caja_read(): array { return mineria_caja_read_unlocked(mineria_caja_path()); }
function mineria_caja_amount_cents(mixed $value): int {
    if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('Importe inválido.');
    $amount=trim((string)$value);
    if(!preg_match('/^([0-9]{1,8})(?:\\.([0-9]{1,2}))?$/D',$amount,$m))
        throw new InvalidArgumentException('Usa un importe positivo con hasta dos decimales.');
    $cents=(int)$m[1]*100+(int)str_pad($m[2]??'0',2,'0');
    if($cents<1)throw new InvalidArgumentException('El importe debe ser mayor que cero.');
    return $cents;
}
function mineria_caja_types(): array {
    return [
        'opening_wallet'=>'Saldo inicial en USDT/USDC',
        'opening_salad'=>'Saldo inicial de créditos Salad',
        'capital_wallet'=>'Aporte externo a billetera',
        'capital_salad'=>'Aporte externo directo a Salad',
        'sale'=>'Venta cobrada de PRL (neto USD)',
        'transfer_salad'=>'Traslado billetera a Salad',
        'usage'=>'Consumo real facturado por Salad',
        'fee'=>'Comisión pagada desde billetera',
        'withdrawal'=>'Retiro personal desde billetera',
    ];
}
function mineria_caja_validate(array $input): array {
    $type=(string)($input['type']??'');
    if(!isset(mineria_caja_types()[$type]))throw new InvalidArgumentException('Tipo de movimiento inválido.');
    $cents=mineria_caja_amount_cents($input['amount']??null);
    $date=(string)($input['date']??'');
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('America/Cancun'));
    if(!$parsed||$parsed->format('Y-m-d')!==$date)
        throw new InvalidArgumentException('Fecha inválida.');
    $today=new DateTimeImmutable('today',new DateTimeZone('America/Cancun'));
    if($parsed>$today->modify('+1 day')||$parsed<$today->modify('-10 years'))
        throw new InvalidArgumentException('Fecha fuera del rango permitido.');
    $note=trim((string)($input['note']??''));
    if(strlen($note)>360||preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/',$note))
        throw new InvalidArgumentException('Nota inválida (máximo 360 bytes).');
    $requestId=(string)($input['request_id']??'');
    if(!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D',$requestId))
        throw new InvalidArgumentException('Identificador de solicitud inválido.');
    return ['type'=>$type,'amount_cents'=>$cents,'date'=>$date,'note'=>$note,'request_id'=>$requestId];
}
function mineria_caja_add(array $input): array {
    $entry=mineria_caja_validate($input);
    $file=mineria_caja_path();$dir=dirname($file);
    if(!is_dir($dir)||is_link($dir)||!is_writable($dir))
        throw new RuntimeException('La carpeta privada de caja no está preparada.');
    if(is_link($file))throw new RuntimeException('Ruta de caja no segura.');
    $lock=fopen($file.'.lock','c');
    if($lock===false)throw new RuntimeException('No se pudo abrir el bloqueo.');
    try {
        if(!flock($lock,LOCK_EX))throw new RuntimeException('No se pudo bloquear el registro.');
        $data=mineria_caja_read_unlocked($file);
        foreach($data['entries'] as $existing) {
            if(($existing['request_id']??'')===$entry['request_id']) {
                if(($existing['type']??null)!==$entry['type']||($existing['amount_cents']??null)!==$entry['amount_cents']
                    ||($existing['date']??null)!==$entry['date']||($existing['note']??null)!==$entry['note'])
                    throw new InvalidArgumentException('Identificador reutilizado con datos diferentes.');
                return $existing;
            }
        }
        if(count($data['entries'])>=10000)throw new RuntimeException('El registro llegó a su límite: exportar antes de continuar.');
        $entry['id']=bin2hex(random_bytes(12));
        $entry['created_at']=gmdate(DATE_ATOM);
        $data['entries'][]=$entry;
        $tmp=tempnam($dir,'.mineria-caja-');
        if($tmp===false)throw new RuntimeException('No se pudo preparar escritura.');
        try{
            if(file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)
                throw new RuntimeException('No se pudo guardar el registro.');
            if(!chmod($tmp,0600)||!rename($tmp,$file))throw new RuntimeException('No se pudo publicar el registro.');
        }finally{ if(is_file($tmp))@unlink($tmp); }
        return $entry;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function mineria_caja_summary(array $entries): array {
    $wallet=0;$salad=0;$sold=0;$usage=0;$fees=0;$outside=0;
    foreach($entries as $e){
        $c=(int)($e['amount_cents']??0);
        switch($e['type']??''){
            case 'opening_wallet':case 'capital_wallet':$wallet+=$c;break;
            case 'opening_salad':case 'capital_salad':$salad+=$c;break;
            case 'sale':$wallet+=$c;$sold+=$c;break;
            case 'transfer_salad':$wallet-=$c;$salad+=$c;break;
            case 'usage':$salad-=$c;$usage+=$c;break;
            case 'fee':$wallet-=$c;$fees+=$c;break;
            case 'withdrawal':$wallet-=$c;$outside+=$c;break;
        }
    }
    return ['wallet_cents'=>$wallet,'salad_cents'=>$salad,'combined_cents'=>$wallet+$salad,
        'sale_cents'=>$sold,'usage_cents'=>$usage,'fees_cents'=>$fees,'withdrawal_cents'=>$outside,
        'operating_result_cents'=>$sold-$usage-$fees,'entry_count'=>count($entries),
        'has_opening_wallet'=>count(array_filter($entries,static fn($e)=>($e['type']??'')==='opening_wallet'))>0,
        'has_opening_salad'=>count(array_filter($entries,static fn($e)=>($e['type']??'')==='opening_salad'))>0];
}
/**
 * Automatic indicator only. Does not create invoices, expense ledger entries or assumed balances.
 * Conservative scope: RTX 4070 Ti Super Low confirmed, current non-stale instances only.
 */
function mineria_caja_operational(?array $snapshot,int $now): array {
    if($snapshot===null)return ['available'=>false,'reason'=>'Sin snapshot SaladCloud'];
    $observed=(string)($snapshot['observed_at']??'');
    $ts=strtotime($observed);
    if($ts===false||$ts>$now+120||$now-$ts>660)
        return ['available'=>false,'reason'=>'Datos de SaladCloud desactualizados'];
    $count=0;$uncertain=false;
    foreach($snapshot['groups']??[] as $group){
        if(!is_array($group))continue;
        $name=strtolower((string)($group['group']??''));
        $gpu=strtoupper((string)($group['metrics']['gpu']??''));
        if(!str_contains($name,'4070')||!str_contains($name,'low'))continue;
        if(($group['stale']??false)===true){$uncertain=true;continue;}
        if(!str_contains($gpu,'4070 TI SUPER')){$uncertain=true;continue;}
        foreach($group['instances']??[] as $i)
            if(is_array($i)&&($i['ready']??false)===true&&(($i['started']??false)===true||strtolower((string)($i['state']??''))==='running'))$count++;
    }
    return ['available'=>!$uncertain,'observed_at'=>$observed,'running_4070_low'=>$count,
        'hourly_usd_estimate'=>round($count*0.13,2),'daily_usd_estimate'=>round($count*0.13*24,2),
        'reason'=>$uncertain?'Algunos grupos Low tienen datos sin verificar':'Estimación a 0.13 USD por GPU-hora; no es facturación'];
}
