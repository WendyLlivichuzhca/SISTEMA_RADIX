<?php
/**
 * verificar_pago.php — Verifica un pago USDT-TRC20 en la Red Tron.
 *
 * Flujo:
 *   1. Usuario envía USDT a RADIX_CENTRAL_WALLET en TRON desde SafePal/TronLink.
 *   2. Copia el hash de transacción (txid) y lo pega en el dashboard.
 *   3. Este endpoint consulta TronScan API para confirmar:
 *        - La transacción existe y está confirmada.
 *        - El token es USDT-TRC20 (contrato correcto).
 *        - El destinatario es RADIX_CENTRAL_WALLET.
 *        - El monto coincide con el pago esperado.
 *   4. Si todo coincide → pago marcado 'completado' → avance de tablero.
 */
require_once 'config.php';
require_once 'matrix_logic.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

$tx_hash  = trim($_POST['tx_hash'] ?? '');
$pago_id  = intval($_POST['pago_id'] ?? 0);

// Formato de Hash en Tron: 64 caracteres hex (sin 0x usualmente, pero aceptamos ambos)
$tx_hash = preg_replace('/^0x/', '', $tx_hash);
if (empty($tx_hash) || !preg_match('/^[a-fA-F0-9]{64}$/', $tx_hash)) {
    sendResponse(['error' => 'Hash de transacción inválido. Debe ser un TXID de Tron (64 caracteres hexadecimales).'], 400);
}
if ($pago_id <= 0) {
    sendResponse(['error' => 'ID de pago inválido.'], 400);
}

try {
    // 1. Obtener el pago pendiente (incluye campos de pago parcial)
    $stmt = $pdo->prepare("
        SELECT p.id, p.monto, p.id_emisor, p.id_receptor, p.beneficiario_usuario_id,
               p.wallet_destino_real, p.estado, p.tx_hash, p.tx_hash_2,
               COALESCE(p.monto_pagado, 0) as monto_pagado
        FROM pagos p
        JOIN usuarios emisor ON p.id_emisor = emisor.id
        WHERE p.id = ?
          AND emisor.wallet_address = ?
          AND p.estado = 'pendiente'
          AND p.tipo   = 'regalo'
        LIMIT 1
    ");
    $stmt->execute([$pago_id, $_SESSION['radix_wallet']]);
    $pago = $stmt->fetch();

    if (!$pago) {
        sendResponse(['error' => 'Pago no encontrado o ya fue procesado.'], 404);
    }

    // 2. Verificar que este txhash no fue usado antes (en tx_hash ni en tx_hash_2)
    $stmt = $pdo->prepare("SELECT id FROM pagos WHERE tx_hash = ? OR tx_hash_2 = ? LIMIT 1");
    $stmt->execute([$tx_hash, $tx_hash]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Este hash ya fue usado para confirmar otro pago.'], 409);
    }

    // Verificar que el pago no tenga ya 2 hashes registrados
    if (!empty($pago['tx_hash_2'])) {
        sendResponse(['error' => 'Este pago ya tiene 2 transacciones registradas. No se permiten más hashes.'], 409);
    }

    // Verificar que el nuevo hash sea diferente al primer hash (si existe)
    if (!empty($pago['tx_hash']) && $pago['tx_hash'] === $tx_hash) {
        sendResponse(['error' => 'Este hash ya fue registrado como primer pago. Usa una transacción diferente.'], 409);
    }

    // 3. Consultar TronScan API
    $url = "https://apilist.tronscan.org/api/transaction-info?hash=" . $tx_hash;

    $ctx      = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: RadixSystem/1.0\r\n"]]);
    $response = file_get_contents($url, false, $ctx);

    if ($response === false) {
        sendResponse(['error' => 'No se pudo conectar con TronScan. Intenta en unos minutos.'], 503);
    }

    $tron = json_decode($response, true);

    if (empty($tron) || isset($tron['error'])) {
        sendResponse(['error' => 'TronScan no encontró la transacción. Asegúrate de que el TXID sea correcto.'], 404);
    }

    // 4. Validar que sea una transferencia TRC20 de USDT
    $transfer = null;
    if (!empty($tron['trc20TransferInfo'])) {
        foreach ($tron['trc20TransferInfo'] as $t) {
            if ($t['contract_address'] === USDT_TRC20_CONTRACT) {
                $transfer = $t;
                break;
            }
        }
    }

    if (!$transfer) {
        sendResponse(['error' => 'La transacción no contiene una transferencia de USDT-TRC20.'], 422);
    }

    // 5. Validar destinatario (Acepta pagos desde cualquier billetera)
    $wallet_destino_real = $pago['wallet_destino_real'] ?: RADIX_CENTRAL_WALLET;
    if ($transfer['to_address'] !== $wallet_destino_real) {
        sendResponse(['error' => 'El destinatario de la transacción no es la billetera oficial de RADIX.'], 422);
    }

    // Nota: No se verifica la billetera emisora para permitir pagos desde
    // exchanges (Binance, etc.) u otras billeteras externas.

    // 6. Calcular montos (USDT TRC-20 tiene 6 decimales)
    $decimales       = intval($transfer['decimals'] ?? 6);
    $monto_recibido  = floatval($transfer['amount_str']) / pow(10, $decimales);
    $monto_esperado  = floatval($pago['monto']); // 10.00
    $monto_ya_pagado = floatval($pago['monto_pagado']); // 0 si es el primer hash
    $tiene_primer_hash = !empty($pago['tx_hash']);
    $monto_total     = $monto_ya_pagado + $monto_recibido;

    // 7. Confirmaciones (TronScan devuelve status o confirmations)
    $esta_confirmada = ($tron['confirmed'] === true || ($tron['status'] ?? '') === 'CONFIRMED');
    if (!$esta_confirmada) {
        sendResponse(['error' => "La transacción aún no está confirmada en la red Tron. Espera un momento."], 202);
    }

    // 8. Lógica de Pagos Parciales + Crédito por Excedente (máximo 2 hashes)
    if ($monto_total >= $monto_esperado - 0.01) {

        // ✅ PAGO COMPLETO — el total entre los hashes alcanza o supera los $10
        $excedente = max(0, round($monto_total - $monto_esperado, 2));

        $pdo->beginTransaction();

        if ($tiene_primer_hash) {
            // Segundo hash completó el pago
            $stmt = $pdo->prepare("UPDATE pagos SET estado = 'completado', tx_hash_2 = ?, monto_pagado = ?, fecha_confirmacion = NOW() WHERE id = ?");
            $stmt->execute([$tx_hash, $monto_total, $pago_id]);
            $detalle_log = "Hash1: {$pago['tx_hash']} | Hash2: $tx_hash | Total: $" . number_format($monto_total, 2) . " USDT";
        } else {
            // Primer hash (exacto o con excedente)
            $stmt = $pdo->prepare("UPDATE pagos SET estado = 'completado', tx_hash = ?, monto_pagado = ?, fecha_confirmacion = NOW() WHERE id = ?");
            $stmt->execute([$tx_hash, $monto_total, $pago_id]);
            $detalle_log = "TXID: $tx_hash | Monto: $" . number_format($monto_total, 2) . " USDT | Destino: $wallet_destino_real";
        }

        // Si pagó de más, guardar el excedente como crédito en su cuenta
        if ($excedente > 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET credito_saldo = credito_saldo + ? WHERE id = ?");
            $stmt->execute([$excedente, $pago['id_emisor']]);
            $detalle_log .= " | Excedente acreditado: $" . number_format($excedente, 2);
        }

        // Activar el tablero A solo cuando el pago de entrada queda confirmado.
        // Si el usuario ya tiene un tablero activo/completado, no duplicamos registros.
        $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? LIMIT 1");
        $stmt->execute([$pago['id_emisor']]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo, estado)
                VALUES (?, 'A', 1, 'en_progreso')
            ");
            $stmt->execute([$pago['id_emisor']]);
        }

        $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, 'PAGO_CONFIRMADO_TRON', 'pagos', ?)");
        $stmt->execute([$pago['id_emisor'], $detalle_log]);

        $pdo->commit();

        // Disparar avance del PATRÓN (id_receptor)
        $beneficiario_logico_id = $pago['beneficiario_usuario_id'] ?: $pago['id_receptor'];
        verificarAvanceTablero($beneficiario_logico_id, $pdo);

        // Mensaje según si hubo excedente o no
        $mensaje = '✅ Pago verificado correctamente. Tu tablero avanzará en breve.';
        if ($excedente > 0) {
            $mensaje .= ' Se acreditaron $' . number_format($excedente, 2) . ' USDT extra a tu cuenta, que se sumarán a tus ganancias al completar la Fase 0.';
        }

        sendResponse([
            'success'          => true,
            'monto_confirmado' => number_format($monto_esperado, 2),
            'credito'          => $excedente > 0 ? number_format($excedente, 2) : null,
            'message'          => $mensaje,
            'tx_hash'          => $tx_hash,
        ]);

    } else {

        // ⚠️ PAGO PARCIAL — no alcanzó los $10
        if ($tiene_primer_hash) {
            // Ya tiene el primer hash y el segundo tampoco alcanzó — límite de 2 hashes superado
            sendResponse([
                'error' => "Con los 2 hashes sumaste $" . number_format($monto_total, 2) . " USDT y no alcanza para completar $" . number_format($monto_esperado, 2) . " USDT. Contacta al soporte."
            ], 422);
        } else {
            // Primer hash con monto parcial — guardar y pedir el segundo hash
            $stmt = $pdo->prepare("UPDATE pagos SET tx_hash = ?, monto_pagado = ? WHERE id = ?");
            $stmt->execute([$tx_hash, $monto_recibido, $pago_id]);

            sendResponse([
                'success'        => false,
                'parcial'        => true,
                'monto_recibido' => number_format($monto_recibido, 2),
                'monto_faltante' => number_format($monto_esperado - $monto_recibido, 2),
                'message'        => '⚠️ Pago parcial registrado: $' . number_format($monto_recibido, 2) . ' USDT recibidos. Te faltan $' . number_format($monto_esperado - $monto_recibido, 2) . ' USDT. Envía el monto restante y pega el nuevo hash aquí.',
            ]);
        }
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("RADIX verificar_pago ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error interno al procesar el pago. Intenta de nuevo.'], 500);
}
