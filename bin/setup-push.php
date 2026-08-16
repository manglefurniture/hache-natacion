<?php
declare(strict_types=1);
$root=dirname(__DIR__);$autoload=$root.'/vendor/autoload.php';if(!is_file($autoload)){fwrite(STDERR,"Falta vendor/autoload.php. Ejecuta composer install.\n");exit(1);}require $autoload;
use Minishlink\WebPush\VAPID;
$file=$root.'/config/push-keys.php';if(is_file($file)){echo "Las claves push ya existen en config/push-keys.php\n";exit(0);} $keys=VAPID::createVapidKeys();$content="<?php\nreturn ".var_export(['subject'=>'https://hachenatacion.duckdns.org','publicKey'=>$keys['publicKey'],'privateKey'=>$keys['privateKey']],true).";\n";if(file_put_contents($file,$content)===false){fwrite(STDERR,"No se pudieron guardar las claves.\n");exit(1);}chmod($file,0600);echo "Claves VAPID creadas. Public key:\n{$keys['publicKey']}\n";