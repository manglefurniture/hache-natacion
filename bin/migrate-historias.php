<?php
declare(strict_types=1);

$config=require __DIR__.'/../config/database.php';
$pdo=new PDO(
    "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
    $config['user'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$sql=file_get_contents(__DIR__.'/../database/migrations/20260828_historias_interacciones.sql');
if($sql===false){fwrite(STDERR,"No se pudo leer la migración de Historias.\n");exit(1);}
$pdo->exec($sql);
echo "OK: comentarios moderados, reacciones y bloqueos de Historias preparados.\n";
