<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-start-authority.php';

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

    $startDay = '(?:el\s+)?(?:mismo\s+)?dia\s+(?:que\s+)?(?:empieza|inicia|comienza)|primer\s+dia|cuando\s+(?:empiece|inicie|comience|llegue)|al\s+(?:iniciar|comenzar|empezar)|lunes\s+cuando\s+llegue|ese\s+dia';
    $fullPayment = '(?:pagar|pago|pagaria|pagare|liquidar|liquido)';
    $fullAmount = '(?:todo|el\s+total|total|completo|completa|100\s*%|cien\s+por\s+ciento)';
    $amountEnd = '(?:\b|(?=\s|$))';
    if (preg_match('/\b'.$fullPayment.'\b.{0,22}\b'.$fullAmount.$amountEnd.'.{0,45}(?:'.$startDay.')/u', $t) === 1) return true;
    if (preg_match('/(?:'.$startDay.').{0,45}\b'.$fullPayment.'\b.{0,22}\b'.$fullAmount.$amountEnd.'/u', $t) === 1) return true;

    // Frase común sin "todo": "quiero entrar al curso y pagar ese día".
    if (preg_match('/\b(entrar|asistir|ir)\b.{0,28}\b(curso|clase|intensivo)\b.{0,32}\b(pagar|pago)\b.{0,18}\b(ese\s+dia|cuando\s+llegue|primer\s+dia)\b/u', $t) === 1) return true;

    return false;
}

function hache_sharky_post72_whatsapp_style_policy(): string
{
    return implode("\n", [
        'PRESENTACIÓN ESTRUCTURADA EN WHATSAPP:',
        '- Responde primero a lo que la persona pidió y mantén la respuesta corta. En orientación comercial, evita muros de texto y catálogos completos si no son necesarios.',
        '- Si el usuario pide varias cosas a la vez, conserva todos los datos inequívocos que ya dio, responde primero lo que preguntó y pregunta solo por un dato faltante real. No vuelvas a preguntar algo que ya vino en el mismo mensaje o burst.',
        '- Cuando el usuario diga “ambas”, “los dos”, “las dos” o “todos” sobre opciones que acabas de ofrecer, responde al conjunto solicitado; no vuelvas a convertirlo en una elección binaria.',
        '- No repitas como explicación lo que el usuario acaba de confirmar, salvo que sea imprescindible para desambiguar.',
        '- El anuncio de origen y entry_interest son contexto, no una selección confirmada. No conviertas el clic de un anuncio en una decisión del prospecto.',
        '- Si commercial_context.swim_level todavía no está confirmado, prioriza conocer el nivel antes de pedir que elija programa. No uses “¿intensivo o regulares?” como primera pregunta de descubrimiento. Si la persona ya expresó una preferencia, consérvala sin discutir y completa primero el nivel.',
        '- Si commercial_context.swim_level es beginner, o background es self_taught/no_formal y todavía corresponde recomendar, el curso intensivo es la recomendación primaria de Hache Natación porque concentra práctica de lunes a viernes durante 3 semanas para construir bases. Recomiéndalo de forma breve; no prometas resultados. Si después de conocer la recomendación la persona elige explícitamente clases regulares, respeta su decisión y continúa con regulares sin insistir.',
        '- Si commercial_context.entry_source es meta_ad y entry_interest es intensive, puedes mencionar que llegó por el intensivo, pero no asumas que esa es su elección final ni vuelvas a preguntarle por el programa si ya lo confirmó explícitamente.',
        '- Una negación se aplica a su objeto inmediato: “no puedo hoy”, “no en la mañana”, “no, mejor Monteverde” o “no intensivo, regular” NO significan rechazo global ni cierre de conversación.',
        '- En autocorrecciones como “perdón, Palapas”, “mejor Monteverde” o “dije 8 pero mejor 7”, domina la corrección final. No mantengas dos elecciones contradictorias.',
        '- Si el usuario dice que ya había dado un dato, consulta la memoria comercial estructurada antes de volver a preguntarlo. Si el dato está guardado, continúa desde ahí; si no está guardado, no finjas recordarlo.',
        '- No termines cada respuesta con una pregunta por costumbre. Después de resolver una consulta concreta, puedes cerrar y dejar el siguiente movimiento al usuario.',
        '- No pidas colonia, zona, domicilio, ubicación de casa o trabajo solo para mantener la conversación. Pide un dato de proximidad únicamente si el usuario solicita ayuda para comparar distancias o decidir qué sede le queda más cerca.',
        '- Después de entregar una o ambas ubicaciones solicitadas, no preguntes “¿desde qué zona vienes?”. Cierra de forma natural dejando un próximo paso útil, por ejemplo: “Cuando quieras seguimos con horarios o precios.”',
        '- Si el usuario responde solo con agradecimiento, acuse o cierre breve (por ejemplo “gracias”, “ok”, “sale”, “perfecto”, 👍, 👌 o 🙏) y no hay una operación determinística pendiente, responde como máximo con un cierre breve o no agregues otra pregunta comercial.',
        '- Si el usuario expresa que lo pensará, lo revisará y avisará, que confirmará después, o que solo quería información, respeta el cierre y no abras una nueva pregunta comercial. Conserva el contexto para cuando vuelva.',
        '- Si el usuario pide explícitamente que no le escriban o contacten, no intentes vender ni convencerlo. Los seguimientos automáticos deben respetar la autoridad de opt-out del backend.',
        '- Cuando haya muchos horarios, fechas o variantes, resume lo esencial y ofrece ampliar después en vez de volcar toda la base de datos.',
        '- Si muestras horarios, precios, formas de pago o datos estructurados, sepáralos por sede o categoría.',
        '- No pongas muchas horas corridas en una sola línea: cada horario debe ir en una viñeta breve cuando haya varios.',
        '- Separa claramente precios de horarios y usa saltos de línea para lectura rápida en móvil.',
        '- En orientación comercial para prospectos, usa de 2 a 5 emojis funcionales y naturales por respuesta (por ejemplo 🏊‍♂️, 📍, 💰, 🕒, ✅ o ✍️) para dar un tono más alegre sin saturar.',
        '- En mensajes operativos para alumnos registrados, mantén los emojis más contenidos: normalmente 1 a 3 cuando aporten claridad.',
        '- No conviertas la respuesta en una infografía y no inventes datos: horarios, precios y pagos deben venir del contexto/backend actual.',
        '- Si el usuario quiere pagar 100% el día de inicio sin reserva anticipada, NO lo autorices: explica que la reserva requiere pago total o al menos 50% por anticipado y deriva la decisión a una persona.',
        '- Si ya existe una reserva válida de al menos 50%, el saldo sí puede liquidarse antes de iniciar o como máximo el mismo día de inicio.',
        '- Nunca prometas cupo ni confirmes un lugar sin pago anticipado.',
        '',
        hache_sharky_start_authority_policy(),
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
        $lines[] = '';
        $lines[] = '📋 CLABE para copiar';
        $lines[] = $clabe;
        $lines[] = 'Mantén pulsada la CLABE para copiarla.';
    }

    $username = trim((string)($result['username'] ?? ''));
    $temporaryPassword = trim((string)($result['temporary_password'] ?? ''));
    if ($username !== '' && $temporaryPassword !== '') {
        $lines[] = '';
        $lines[] = '🔐 Acceso al portal Hache Natación';
        $lines[] = '• Portal: https://hnatacion.com/index.php';
        $lines[] = '• Usuario: '.$username;
        $lines[] = '• Contraseña temporal: '.$temporaryPassword;
        $lines[] = '• Cámbiala al iniciar sesión.';
    }

    return implode("\n", $lines);
}
