<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-validation.php';

function expect_config(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message."\n");
        exit(1);
    }
}

foreach ([
    ['sharky_link_registro_monteverde','https://go.hnatacion.com/mv'],
    ['sharky_link_registro_palapas','https://go.hnatacion.com/pal'],
    ['sharky_maps_monteverde','https://maps.app.goo.gl/Ld75bhLforGm2Tk68'],
    ['sharky_maps_palapas','https://www.google.com/maps/place/Cancun'],
    ['sharky_pago_clabe','123456789012345678'],
    ['sharky_pago_institucion','Mercado Pago W'],
    ['sharky_pago_beneficiario','Nombre Apellido'],
    ['sharky_precio_intensivo','1200'],
    ['sharky_precio_intensivo','1350'],
    ['sharky_precio_intensivo','100000'],
    ['sharky_precio_regular_3','1000'],
    ['sharky_recargo_tarjeta_pct','5'],
    ['sharky_whatsapp','529902308165'],
] as [$key,$value]) {
    expect_config(hache_sharky_config_value_valid($key,$value), "Debió aceptar {$key}={$value}");
}

foreach ([
    ['sharky_link_registro_monteverde','http://go.hnatacion.com/mv'],
    ['sharky_link_registro_monteverde','https://evil.example/mv'],
    ['sharky_maps_monteverde','https://evil.example/maps'],
    ['sharky_pago_clabe','12345678901234567'],
    ['sharky_pago_clabe','12345678901234567X'],
    ['sharky_pago_institucion',''],
    ['sharky_precio_intensivo','-1'],
    ['sharky_precio_intensivo','1350.50'],
    ['sharky_precio_intensivo','150000'],
    ['sharky_precio_regular_3','1000.25'],
    ['sharky_recargo_tarjeta_pct','5.5'],
    ['sharky_recargo_tarjeta_pct','101'],
    ['sharky_audio_habilitado','2'],
    ['sharky_whatsapp','123'],
] as [$key,$value]) {
    expect_config(!hache_sharky_config_value_valid($key,$value), "Debió rechazar {$key}={$value}");
}

echo "SHARKY_CONFIG_VALIDATION_REGRESSION_OK\n";
