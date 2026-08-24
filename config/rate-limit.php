<?php
declare(strict_types=1);

/**
 * Limitador local y atómico para los pocos endpoints públicos del sistema.
 * Se usa almacenamiento temporal para no convertir una falla de base de datos
 * en una caída del login, el registro o Sharky.
 */
function security_rate_limit_client_ip(): string
{
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));
    return substr($ip!==''?$ip:'unknown',0,64);
}

function security_rate_limit_file(string $scope,string $subject): string
{
    $dir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'hache-rate-limits';
    if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear el almacén de límites');
    @chmod($dir,0700);
    return $dir.DIRECTORY_SEPARATOR.hash('sha256',$scope."\0".$subject).'.json';
}

function security_rate_limit_state(string $scope,string $subject,int $limit,int $windowSeconds,bool $increment): array
{
    if($limit<1||$windowSeconds<1) return ['allowed'=>true,'remaining'=>0,'retry_after'=>0];
    try{
        $path=security_rate_limit_file($scope,$subject);
        $fh=fopen($path,'c+');
        if($fh===false) throw new RuntimeException('No se pudo abrir el límite');
        @chmod($path,0600);
        try{
            if(!flock($fh,LOCK_EX)) throw new RuntimeException('No se pudo bloquear el límite');
            rewind($fh);$raw=stream_get_contents($fh);$data=is_string($raw)?json_decode($raw,true):null;
            $now=time();$start=(int)($data['start']??$now);$attempts=(int)($data['attempts']??0);
            if($start>$now||($now-$start)>=$windowSeconds){$start=$now;$attempts=0;}
            if($increment)$attempts++;
            $retry=max(0,$windowSeconds-($now-$start));
            $allowed=$attempts<$limit||(!$increment&&$attempts<$limit)||($increment&&$attempts<=$limit);
            if($increment){
                rewind($fh);ftruncate($fh,0);
                fwrite($fh,json_encode(['start'=>$start,'attempts'=>$attempts],JSON_UNESCAPED_SLASHES));
                fflush($fh);
            }
            flock($fh,LOCK_UN);
            return ['allowed'=>$allowed,'remaining'=>max(0,$limit-$attempts),'retry_after'=>$allowed?0:$retry];
        }finally{fclose($fh);}
    }catch(Throwable $e){
        error_log('Hache rate limit unavailable: '.$e->getMessage());
        return ['allowed'=>true,'remaining'=>$limit,'retry_after'=>0];
    }
}

function security_rate_limit_check(string $scope,string $subject,int $limit,int $windowSeconds): array
{
    return security_rate_limit_state($scope,$subject,$limit,$windowSeconds,false);
}

function security_rate_limit_record(string $scope,string $subject,int $limit,int $windowSeconds): array
{
    return security_rate_limit_state($scope,$subject,$limit,$windowSeconds,true);
}

function security_rate_limit_clear(string $scope,string $subject): void
{
    try{$path=security_rate_limit_file($scope,$subject);if(is_file($path))@unlink($path);}catch(Throwable $e){error_log('Hache rate limit cleanup unavailable: '.$e->getMessage());}
}
