<?php
/**
 * solicitar_retiro.php — RADIX Multi-Fase
 * Registra una solicitud de retiro por fase específica.
 * Requiere que el usuario haya completado el Tablero C de esa fase.
 *
 * PROTECCIONES:
 * - Todo el flujo crítico corre dentro de una transacción con FOR UPDATE
 *   para evitar que doble clic o solicitudes simultáneas generen dos retiros
 *   duplicados del mismo saldo.
 * - El saldo se recalcula DENTRO de la transacción para que el número sea
 *   consistente con el estado real de la DB en ese instante.
 * - Se descuentan retiros PENDIENTES además de los ya procesados, evitando
 *   que nuevas ganancias de ciclos posteriores queden atrapadas en una
 *   solicitud ya enviada.
 */
require_once 'config.php';
session_start();

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$wallet      = $_SESSION['radix_wallet'];
$fase_numero = intval($_POST['fase_numero'] ?? 0);
// monto_solicitado es opcional. Si no se envía, se retira el saldo completo disponible.
// Si se envía, debe ser >= $10 y <= saldo disponible.
$monto_solicitado_raw = $_POST['monto'] ?? null;
$monto_solicitado     = ($monto_solicitado_raw !== null && $monto_solicitado_raw !== '')
                        ? round((float)$monto_solicitado_raw, 2)
                        : null;

if ($fase_numero < 0 || $fase_numero > 3) {
    sendResponse(['error' => 'Fase no válida.'], 400);
}

if ($monto_solicitado !== null && $monto_solicitado < 10) {
    sendResponse(['error' => 'El monto mínimo de retiro es $10.00 USDT.'], 400);
}

try {
    // 1. Obtener usuario (fuera de transacción — solo lectura rápida)
    $stmt = $pdo->prepare("SELECT id, tipo_usuario, credito_saldo FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();
    if (!$user) sendResponse(['error' => 'Usuario no encontrado'], 404);

    $user_id = $user['id'];

    if (($user['tipo_usuario'] ?? '') !== 'real') {
        sendResponse(['error' => 'Solo los usuarios reales pueden solicitar retiros.'], 403);
    }

    // 2. Verificar que completó el Tablero C (check rápido antes de abrir transacción)
    $stmt = $pdo->prepare("
        SELECT id FROM tableros_progreso
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND tablero_tipo = 'C'
          AND estado = 'completado'
        LIMIT 1
    ");
    $stmt->execute([$user_id, $fase_numero]);
    if (!$stmt->fetch()) {
        sendResponse([
            'error' => "Debes completar la Fase $fase_numero (Tableros A → B → C) antes de poder retirar de esa fase.",
        ], 403);
    }

    // ── INICIO DE TRANSACCIÓN ────────────────────────────────────────────────
    // A partir de aquí todo corre dentro de la transacción para garantizar
    // consistencia y evitar duplicados por doble clic o solicitudes paralelas.
    $pdo->beginTransaction();

    // 3. Verificar que no haya retiro PENDIENTE — con FOR UPDATE para bloquear
    //    la fila mientras procesamos, evitando que dos solicitudes simultáneas
    //    pasen este check al mismo tiempo.
    $stmt = $pdo->prepare("
        SELECT id, monto FROM retiros
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND estado = 'pendiente'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$user_id, $fase_numero]);
    $retiro_pendiente = $stmt->fetch();

    if ($retiro_pendiente) {
        $pdo->rollBack();
        sendResponse([
            'error' => "Ya tienes un retiro pendiente de \$" . number_format($retiro_pendiente['monto'], 2)
                     . " USDT en Fase $fase_numero. Espera a que sea procesado antes de solicitar otro.",
        ], 400);
    }

    // 4. Recalcular saldo DENTRO de la transacción para consistencia total.
    //    Descontamos también retiros PENDIENTES (no solo los ya procesados)
    //    para que el monto sea exactamente lo disponible en este instante.

    // 4a. Ganancias brutas de esta fase
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monto), 0) as total
        FROM pagos
        WHERE id_receptor = ?
          AND propietario_flujo = 'usuario'
          AND estado = 'completado'
          AND tipo = 'ganancia_tablero'
          AND fase_numero = ?
    ");
    $stmt->execute([$user_id, $fase_numero]);
    $bruto = (float)($stmt->fetch()['total'] ?? 0);

    // 4b. Deducciones (semillas + reentradas + utilidad_master)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monto), 0) as total
        FROM pagos
        WHERE id_emisor = ?
          AND propietario_flujo = 'usuario'
          AND estado = 'completado'
          AND (tipo LIKE 'salto_fase_%' OR tipo = 'reentrada' OR tipo = 'utilidad_master')
          AND fase_numero = ?
    ");
    $stmt->execute([$user_id, $fase_numero]);
    $deducciones = (float)($stmt->fetch()['total'] ?? 0);

    // 4c. Crédito por excedente de pago — solo aplica en Fase 0
    $credito = 0.0;
    if ($fase_numero === 0) {
        $credito = (float)($user['credito_saldo'] ?? 0);
    }

    // 4d. Ya retirado o en proceso (procesado + pendiente) de esta fase.
    //     Incluir pendientes evita que nuevas ganancias de ciclos recientes
    //     queden "atrapadas" en una solicitud anterior aún no procesada.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monto), 0) as total
        FROM retiros
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND estado IN ('procesado', 'pendiente')
    ");
    $stmt->execute([$user_id, $fase_numero]);
    $ya_comprometido = (float)($stmt->fetch()['total'] ?? 0);

    $saldo_disponible = round($bruto - $deducciones + $credito - $ya_comprometido, 2);

    if ($saldo_disponible < 10) {
        $pdo->rollBack();
        sendResponse([
            'error' => "No tienes saldo suficiente en Fase $fase_numero para retirar (mínimo \$10.00). Disponible: \$" . number_format($saldo_disponible, 2),
        ], 400);
    }

    // Determinar el monto final a retirar:
    // - Si el usuario eligió un monto personalizado, usarlo (siempre que no supere el disponible).
    // - Si no eligió, retirar el saldo completo disponible.
    if ($monto_solicitado !== null) {
        if ($monto_solicitado > $saldo_disponible) {
            $pdo->rollBack();
            sendResponse([
                'error' => "El monto solicitado (\$" . number_format($monto_solicitado, 2) . ") supera tu saldo disponible en Fase $fase_numero (\$" . number_format($saldo_disponible, 2) . ").",
            ], 400);
        }
        $monto_retiro = $monto_solicitado;
    } else {
        $monto_retiro = $saldo_disponible;
    }

    // 5. Registrar solicitud de retiro
    $stmt = $pdo->prepare("
        INSERT INTO retiros (usuario_id, fase_numero, monto, wallet_destino)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $fase_numero, $monto_retiro, $wallet]);

    // 6. Log de auditoría
    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
        VALUES (?, ?, 'SOLICITUD_RETIRO', 'retiros', ?)
    ");
    $stmt->execute([
        $user_id,
        $fase_numero,
        "Solicitud de retiro de \${$monto_retiro} USDT de Fase {$fase_numero} a wallet {$wallet}"
        . ($monto_solicitado !== null ? " (monto personalizado; disponible: \${$saldo_disponible})" : " (saldo completo)"),
    ]);

    $pdo->commit();
    // ── FIN DE TRANSACCIÓN ───────────────────────────────────────────────────

    sendResponse([
        'success'          => true,
        'fase_numero'      => $fase_numero,
        'monto'            => $monto_retiro,
        'saldo_disponible' => $saldo_disponible,
        'mensaje'          => "✅ Solicitud de retiro de \$" . number_format($monto_retiro, 2) . " USDT de Fase {$fase_numero} enviada. Será procesada en menos de 24h.",
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("RADIX solicitar_retiro ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
