<?php
declare(strict_types=1);

/** Lectura pública del pool Kryptex; no toca contenedores, pagos o claves. */
function hache_prl_pool_wallet(): string
{
    // Dirección pública de pago del grupo PRL verificado el 10/10/2026.
    // Se puede sustituir sin modificar el código mediante variable de entorno.
    $wallet=trim((string)(getenv('SALAD_PRL_POOL_WALLET') ?: 'prl1pcc2lcq2jnkzhfk9xnc2nvuv5hzla09ej2ra0g06gw60czv04hxksa090gu'));
    if (!preg_match('/^prl1[a-z0-9]{40,100}$/D', $wallet)) {
        throw new RuntimeException('Dirección PRL del pool inválida.');
    }
    return $wallet;
}
function hache_prl_pool_file(): string
{
    return (string)(getenv('SALAD_PRL_POOL_SNAPSHOT_FILE') ?: '/var/lib/hache-natacion/salad-prl-pool-snapshot.json');
}
/** @return array<string,mixed> */
function hache_prl_pool_request(string $endpoint): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('cURL no disponible.');
    $curl=curl_init('https://pool.kryptex.com/prl/api/v1/miner/'.$endpoint);
    curl_setopt_array($curl,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>12,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/json'],
    ]);
    $body=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status!==200 || !is_string($body) || strlen($body)>1024*1024) {
        throw new RuntimeException('Kryptex no respondió con datos válidos.');
    }
    $data=json_decode($body,true);
    if (!is_array($data)) throw new RuntimeException('Kryptex devolvió JSON inválido.');
    return $data;
}
/** Valida las unidades del pool: PRL, nunca USD. */
function hache_prl_pool_normalize(array $balance, array $rewardChart, array $payoutStats, ?DateTimeImmutable $now=null): array
{
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $value=static function(array $a,string $key): float {
        if (!isset($a[$key]) || !is_numeric($a[$key]) || !is_finite((float)$a[$key]) || (float)$a[$key]<0) {
            throw new RuntimeException('Métrica del pool ausente: '.$key);
        }
        return round((float)$a[$key],8);
    };
    $daily=[];
    foreach ($rewardChart as $item) {
        if (!is_array($item)) continue;
        $ts=$item['timestamp']??null;
        if (!is_numeric($ts) || !isset($item['reward']) || !is_numeric($item['reward'])) continue;
        if ((float)$item['reward']<0 || !is_finite((float)$item['reward'])) continue;
        $day=gmdate('Y-m-d',(int)floor((float)$ts/1000));
        $daily[$day]=['date_utc'=>$day,'prl'=>round((float)$item['reward'],8)];
    }
    ksort($daily);
    return [
        'observed_at'=>$now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        'wallet_suffix'=>substr(hache_prl_pool_wallet(),-6),
        'confirmed_prl'=>$value($balance,'confirmed'),
        'unconfirmed_prl'=>$value($balance,'unconfirmed'),
        'total_prl'=>$value($balance,'total'),
        'reward_week_prl'=>$value(is_array($payoutStats['reward']??null)?$payoutStats['reward']:[],'week'),
        'paid_prl'=>$value($payoutStats,'paid'),
        'unpaid_prl'=>$value($payoutStats,'unpaid'),
        'last_active_ms'=>isset($balance['last_active'])&&is_numeric($balance['last_active'])?(int)$balance['last_active']:null,
        'rewards_daily_utc'=>array_slice(array_values($daily),-14),
        'source'=>'Kryptex PRL (datos de pool, no valor en USD)',
    ];
}
function hache_prl_pool_read(): ?array
{
    $path=hache_prl_pool_file();
    if (is_link($path) || !is_readable($path)) return null;
    $s=file_get_contents($path);
    $d=is_string($s)?json_decode($s,true):null;
    return is_array($d)&&isset($d['observed_at'],$d['confirmed_prl'],$d['rewards_daily_utc'])?$d:null;
}
function hache_prl_pool_refresh(?DateTimeImmutable $now=null): void
{
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $old=hache_prl_pool_read();
    $oldAt=isset($old['observed_at'])?strtotime((string)$old['observed_at']):false;
    if ($oldAt!==false && $now->getTimestamp()-$oldAt>=0 && $now->getTimestamp()-$oldAt<900) return;
    $wallet=hache_prl_pool_wallet();
    $balance=hache_prl_pool_request('balance/'.rawurlencode($wallet));
    $rewards=hache_prl_pool_request('reward-chart/'.rawurlencode($wallet));
    $stats=hache_prl_pool_request('payouts/'.rawurlencode($wallet).'/stats');
    $data=hache_prl_pool_normalize($balance,$rewards,$stats,$now);
    $path=hache_prl_pool_file();
    $dir=dirname($path);
    if (is_link($path) || is_link($dir)) throw new RuntimeException('Ruta de pool insegura.');
    if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear carpeta de pool.');
    }
    $tmp=tempnam($dir,'prl-pool-');
    if ($tmp===false) throw new RuntimeException('No se pudo escribir snapshot del pool.');
    try {
        if (file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false
            || !chmod($tmp,0600) || !rename($tmp,$path)) {
            throw new RuntimeException('No se pudo publicar snapshot del pool.');
        }
    } finally {
        if (is_file($tmp)) unlink($tmp);
    }
}
