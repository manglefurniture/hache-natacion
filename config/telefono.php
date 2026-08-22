<?php
declare(strict_types=1);

function telefono_paises(): array
{
    return [
        'MX'=>['nombre'=>'México','codigo'=>'+52'],
        'US'=>['nombre'=>'Estados Unidos / Canadá','codigo'=>'+1'],
        'CO'=>['nombre'=>'Colombia','codigo'=>'+57'],
        'CU'=>['nombre'=>'Cuba','codigo'=>'+53'],
        'ES'=>['nombre'=>'España','codigo'=>'+34'],
        'AR'=>['nombre'=>'Argentina','codigo'=>'+54'],
        'BR'=>['nombre'=>'Brasil','codigo'=>'+55'],
        'CL'=>['nombre'=>'Chile','codigo'=>'+56'],
        'PE'=>['nombre'=>'Perú','codigo'=>'+51'],
        'VE'=>['nombre'=>'Venezuela','codigo'=>'+58'],
        'DO'=>['nombre'=>'República Dominicana','codigo'=>'+1'],
        'GT'=>['nombre'=>'Guatemala','codigo'=>'+502'],
        'HN'=>['nombre'=>'Honduras','codigo'=>'+504'],
        'SV'=>['nombre'=>'El Salvador','codigo'=>'+503'],
        'CR'=>['nombre'=>'Costa Rica','codigo'=>'+506'],
        'PA'=>['nombre'=>'Panamá','codigo'=>'+507'],
    ];
}

function telefono_digitos(string $valor): string
{
    return preg_replace('/\D+/','',$valor) ?? '';
}

function telefono_normalizar(string $pais,string $nacional): string
{
    $pais=strtoupper(trim($pais));
    $paises=telefono_paises();
    if(!isset($paises[$pais])) throw new InvalidArgumentException('Selecciona un país válido para el WhatsApp.');
    $local=telefono_digitos($nacional);
    if($local==='') throw new InvalidArgumentException('El WhatsApp es obligatorio.');
    if($pais==='MX' && strlen($local)!==10) throw new InvalidArgumentException('El número de México debe tener 10 dígitos.');
    if(in_array($pais,['US','DO'],true) && strlen($local)!==10) throw new InvalidArgumentException('El número debe tener 10 dígitos para el país seleccionado.');
    $prefijo=telefono_digitos($paises[$pais]['codigo']);
    $e164='+'.$prefijo.$local;
    $total=strlen($prefijo.$local);
    if($total<8 || $total>15) throw new InvalidArgumentException('El número no tiene una longitud internacional válida.');
    return $e164;
}

function telefono_descomponer(string $valor): array
{
    $v=trim($valor);
    if($v==='' || $v[0]!=='+') return ['pais'=>'','nacional'=>telefono_digitos($v),'e164'=>false];
    $digits=telefono_digitos($v);
    $candidatos=[];
    foreach(telefono_paises() as $iso=>$p){
        $pref=telefono_digitos($p['codigo']);
        if(str_starts_with($digits,$pref)) $candidatos[]=['pais'=>$iso,'pref'=>$pref,'len'=>strlen($pref)];
    }
    usort($candidatos,fn($a,$b)=>$b['len']<=>$a['len']);
    if(!$candidatos) return ['pais'=>'','nacional'=>$digits,'e164'=>true];
    $c=$candidatos[0];
    return ['pais'=>$c['pais'],'nacional'=>substr($digits,$c['len']),'e164'=>true];
}

function telefono_es_e164(string $valor): bool
{
    return (bool)preg_match('/^\+[1-9][0-9]{7,14}$/',trim($valor));
}
