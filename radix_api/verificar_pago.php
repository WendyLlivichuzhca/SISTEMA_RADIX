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
    // 1. Obtener el pago pendiente
    $stmt = $pdo->prepare("
        SELECT p.id, p.monto, p.id_emisor, p.id_receptor, p.estado
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

    // 2. Verificar que este txhash no fue usado antes
    $stmt = $pdo->prepare("SELECT id FROM pagos WHERE tx_hash = ? LIMIT 1");
    $stmt->execute([$tx_hash]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Este hash ya fue usado para confirmar otro pago.'], 409);
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
    if ($transfer['to_address'] !== RADIX_CENTRAL_WALLET) {
        sendResponse(['error' => 'El destinatario de la transacción no es la billetera oficial de RADIX.'], 422);
    }

    // Nota: No se verifica la billetera emisora para permitir pagos desde
    // exchanges (Binance, etc.) u otras billeteras externas.

    // 6. Validar monto (USDT TRC-20 tiene 6 decimales)
    $decimales      = intval($transfer['decimals'] ?? 6);
    $monto_recibido = floatval($transfer['amount_str']) / pow(10, $decimales);
    $monto_esperado = floatval($pago['monto']);

    if (abs($monto_recibido - $monto_esperado) > 0.01) {
        sendResponse([
            'error' => "El monto recibido ($" . number_format($monto_recibido, 2) . " USDT) no coincide con el esperado ($" . number_format($monto_esperado, 2) . " USDT)."
        ], 422);
    }

    // 7. Confirmaciones (TronScan devuelve status o confirmations)
    $esta_confirmada = ($tron['confirmed'] === true || ($tron['status'] ?? '') === 'CONFIRMED');
    if (!$esta_confirmada) {
        sendResponse(['error' => "La transacción aún no está confirmada en la red Tron. Espera un momento."], 202);
    }

    // ✅ Todo válido — marcar pago como completado
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE pagos SET estado = 'completado', tx_hash = ?, fecha_confirmacion = NOW() WHERE id = ?");
    $stmt->execute([$tx_hash, $pago_id]);

    $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, 'PAGO_CONFIRMADO_TRON', 'pagos', ?)");
    $stmt->execute([$pago['id_emisor'], "TXID: $tx_hash | Monto: $" . number_format($monto_recibido, 2) . " USDT | Destino: Central"]);

    $pdo->commit();

    // Disparar avance del PATRÓN (id_receptor)
    verificarAvanceTablero($pago['id_receptor'], $pdo);

    sendResponse([
        'success'          => true,
        'monto_confirmado' => number_format($monto_recibido, 2),
        'message'          => '✅ Pago verificado correctamente. Tu tablero avanzará en breve.',
        'tx_hash'          => $tx_hash,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("RADIX verificar_pago ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error interno al procesar el pago. Intenta de nuevo.'], 500);
}