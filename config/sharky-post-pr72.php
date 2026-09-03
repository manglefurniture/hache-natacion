<?php

declare(strict_types=1);

/**
 * Ajustes posteriores al PR #72 que pertenecen al laboratorio Sharky 2.0.
 * Mantiene las reglas de lenguaje abiertas separadas de las decisiones comerciales duras.
 */

function hache_sharky_post72_normalize(string $text): string
{
    if (function_exists('hache_sharky_normalize_text')) return hache_sharky_normalize_text($text);
    return strtr(mb_strtolower(trim($text), 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

/**
 * Detecta la excepción comercial que Sharky NO puede autorizar:
 * 0% anticipado + pagar el 100% al iniciar / apartar sin pago.
 *
 * No intenta enumerar todo el español: cubre la clase de intención y deja fuera
 * explícitamente el caso válido de reserva previa de al menos 50%.
 */
function hache_sharky_post72_payment_exception_request(string $text): bool
{
    $t = hache_sharky_post72_normalize($text);
    if ($t === '') return false;

    // Casos válidos: ya existe o se realizará reserva anticipada de al menos 50%,
    // o se paga el total por anticipado. El saldo sí puede llegar hasta el día de inicio.
    $validAdvance = [
        '/\b(ya\s+)?(pague|he pagado|pago|voy a pagar|pagare)\b.{0,24}\b(50\s*%|50\s+por\s+ciento|cincuenta\s+por\s+ciento|la\s+mitad|mitad)\b/u',
        '/\b(50\s*%|50\s+por\s+ciento|cincuenta\s+por\s+ciento|la\s+mitad|mitad)\b.{0,24}\b(pague|he pagado|pago|voy a pagar|pagare|anticipo|reserva)\b/u',
        '/\b(reservar|reservo|apart(?:ar|o))\b.{0,24}\b(pagando|pago|pagar)\b.{0,14}\b(el\s+)?total\b.{0,20}\b(hoy|ahora|antes)\b/u',
    ];
    foreach ($validAdvance as $pattern) if (preg_match($pattern, $t) === 1) return false;

    $skipAdvance = [
        '/\b(sin|ningun|ninguna)\s+(anticipo|adelanto|abono|pago|reserva)\b/u',
        '/\bno\s+(quiero|voy a|pienso|puedo)\b.{0,18}\b(dar|hacer|pagar)\b.{0,12}\b(anticipo|adelanto|abono|reserva)\b/u',
        '/\b(apart(?:ar|ame|as|o)|reserv(?:ar|ame|as|o)|confirm(?:ar|ame|as))\b.{0,30}\b(lugar|cupo|espacio)?\b.{0,30}\b(sin\s+pagar|pago\s+despues|pago\s+cuando|pagar\s+cuando)\b/u',
        '/\b(entrar|asistir|ir)\b.{0,28}\b(primero|al\s+curso|ese\s+dia|el\s+primer\s+dia)\b.{0,30}\b(pagar|pago)\b.{0,18}\b(despues|ese\s+dia|cuando\s+llegue)\b/u',
    ];
    foreach ($skipAdvance as $pattern) if (preg_match($pattern, $t) === 1) return true;

    $startDay = '(?:el\s+)?(?:mismo\s+)?dia\s+(?:que\s+)?(?:empieza|inicia|comienza)|primer\s+dia|cuando\s+(?:empiece|inicie|comience|llegue)|lunes\s+cuando\s+llegue|ese\s+dia';
    $fullPayment = '(?:pagar|pago|pagaria|pagare|liquidar|liquido)';
    $fullAmount = '(?:todo|el\s+total|total|completo|completa|100\s*%|cien\s+por\s+ciento)';
    if (preg_match('/\b'.$fullPayment.'\b.{0,22}\b'.$fullAmount.'\b.{0,45}(?:'.$startDay.')/u', $t) === 1) return true;
    if (preg_match('/(?:'.$startDay.').{0,45}\b'.$fullPayment.'\b.{0,22}\b'.$fullAmount.'\b/u', $t) === 1) return true;

    // Frase común sin "todo": "quiero entrar al curso y pagar ese día".
    if (preg_match('/\b(entrar|asistir|ir)\b.{0,28}\b(curso|clase|intensivo)\b.{0,32}\b(pagar|pago)\b.{0,18}\b(ese\s+dia|cuando\s+llegue|primer\s+dia)\b/u', $t) === 1) return true;

    return false;
}

function hache_sharky_post72_whatsapp_style_policy(): string
{
    return implode("\n", [
        'PRESENTACIÓN ESTRUCTURADA EN WHATSAPP:',
        '- Si muestras horarios, precios, formas de pago o datos estructurados, sepáralos por sede o categoría.',
        '- No pongas muchas horas corridas en una sola línea: cada horario debe ir en una viñeta breve cuando haya varios.',
        '- Separa claramente precios de horarios y usa saltos de línea para lectura rápida en móvil.',
        '- Puedes usar un emoji funcional en un encabezado (por ejemplo 📍, 🕐, 💰 o ✅), pero no en cada línea.',
        '- No conviertas la respuesta en una infografía y no inventes datos: horarios, precios y pagos deben venir del contexto/backend actual.',
        '- Si el usuario quiere pagar 100% el día de inicio sin reserva anticipada, NO lo autorices: explica que la reserva requiere pago total o al menos 50% por anticipado y deriva la decisión a una persona.',
        '- Si ya existe una reserva válida de al menos 50%, el saldo sí puede liquidarse antes de iniciar o como máximo el mismo día de inicio.',
        '- Nunca prometas cupo ni confirmes un lugar sin pago anticipado.',
    ]);
}

function hache_sharky_post72_registration_message(array $actionResult, array $business): ?string
{
    if (($actionResult['ok'] ?? false) !== true) return null;
    $result = $actionResult['result'] ?? null;
    if (!is_array($result)) return null;
    $code = strtoupper((string)($result['code'] ?? $actionResult['code'] ?? ''));
    if (!in_array($code, ['CREATED','RECOVERED'], true)) return null;

    $price = (float)($result['price'] ?? 0);
    if ($price <= 0) return null;
    $minimum = $price / 2;
    $money = static fn(float $v): string => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2, '.', ',');

    $lines = [
        '✅ Registro recibido',
        'Tu inscripción quedó pendiente de confirmación/pago.',
        '',
        '💰 Pago',
        '• Total del curso: $'.$money($price).' MXN',
        '• Reserva mínima (50%): $'.$money($minimum).' MXN',
        '• Si reservas con 50%, el saldo puede liquidarse antes de iniciar o como máximo el día de inicio.',
    ];

    $institution = trim((string)($business['sharky_pago_institucion'] ?? ''));
    $beneficiary = trim((string)($business['sharky_pago_beneficiario'] ?? ''));
    $clabe = preg_replace('/\D+/', '', (string)($business['sharky_pago_clabe'] ?? '')) ?: '';
    if ($institution !== '' && $beneficiary !== '' && strlen($clabe) === 18) {
        $lines[] = '';
        $lines[] = 'Transferencia';
        $lines[] = '• Institución: '.$institution;
        $lines[] = '• Beneficiario: '.$beneficiary;
        $lines[] = '• CLABE: '.$clabe;
    }
    $card = (int)($business['sharky_recargo_tarjeta_pct'] ?? 0);
    if ($card > 0) $lines[] = '• Tarjeta: '.$card.'% de recargo';

    $username = trim((string)($result['username'] ?? ''));
    $temporaryPassword = trim((string)($result['temporary_password'] ?? ''));
    if ($username !== '' && $temporaryPassword !== '') {
        $lines[] = '';
        $lines[] = '🔐 Acceso al portal';
        $lines[] = '• Usuario: '.$username;
        $lines[] = '• Contraseña temporal: '.$temporaryPassword;
        $lines[] = '• Cámbiala al iniciar sesión.';
    }

    return implode("\n", $lines);
}
