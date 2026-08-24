<?php
declare(strict_types=1);

function password_temporal_segura(int $length=14): string
{
    $length=max(12,$length);
    $groups=['abcdefghjkmnpqrstuvwxyz','ABCDEFGHJKMNPQRSTUVWXYZ','23456789','@#_-'];
    $chars=[];
    foreach($groups as $group)$chars[]=$group[random_int(0,strlen($group)-1)];
    $all=implode('',$groups);
    while(count($chars)<$length)$chars[]=$all[random_int(0,strlen($all)-1)];
    for($i=count($chars)-1;$i>0;$i--){$j=random_int(0,$i);[$chars[$i],$chars[$j]]=[$chars[$j],$chars[$i]];}
    return implode('',$chars);
}

function password_error_politica(string $password): ?string
{
    if(strlen($password)<10)return 'La contraseña debe tener al menos 10 caracteres.';
    $classes=0;
    foreach(['/[a-z]/','/[A-Z]/','/[0-9]/','/[^a-zA-Z0-9]/'] as $pattern)if(preg_match($pattern,$password))$classes++;
    if($classes<3)return 'Combina al menos tres tipos: minúsculas, mayúsculas, números o símbolos.';
    return null;
}
