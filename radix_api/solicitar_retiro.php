<?php
/**
 * solicitar_retiro.php — RADIX Multi-Fase
 * Registra una solicitud de retiro por fase específica.
 * Requiere que el usuario haya completado el Tablero C de esa fase.
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

if ($fase_numero < 0 || $fase_numero > 3) {
    sendResponse(['error' => 'Fase no válida.'], 400);
}

try {
    // 1. Obtener usuario
    $stmt = $pdo->prepare("SELECT id, tipo_usuario, credito_saldo FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();
    if (!$user) sendResponse(['error' => 'Usuario no encontrado'], 404);

    $user_id = $user['id'];

    if (($user['tipo_usuario'] ?? '') !== 'real') {
        sendResponse(['error' => 'Solo los usuarios reales pueden solicitar retiros.'], 403);
    }

    // 2. Verificar que completó el Tablero C de ESTA fase específica
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

    // 3. Calcular saldo disponible de ESTA fase
    // 3a. Ganancias brutas de esta fase
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

    // 3b. Deducciones de esta fase (semillas + reentradas)
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

    // 3c. Crédito por excedente de pago — solo aplica en Fase 0
    $credito = 0.0;
    if ($fase_numero === 0) {
        $credito = (float)($user['credito_saldo'] ?? 0);
    }

    // 3d. Ya retirado de esta fase específica
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monto), 0) as total
        FROM retiros
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND estado = 'procesado'
    ");
    $stmt->execute([$user_id, $fase_numero]);
    $ya_retirado = (float)($stmt->fetch()['total'] ?? 0);

    $saldo_disponible = $bruto - $deducciones + $credito - $ya_retirado;

    if ($saldo_disponible < 10) {
        sendResponse([
            'error' => "No tienes saldo suficiente en Fase $fase_numero para retirar (mínimo \$10.00). Disponible: \$" . number_format($saldo_disponible, 2),
        ], 400);
    }

    // 4. Verificar que no haya un retiro pendiente de esta misma fase
    $stmt = $pdo->prepare("
        SELECT id FROM retiros
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND estado = 'pendiente'
    ");
    $stmt->execute([$user_id, $fase_numero]);
    if ($stmt->fetch()) {
        sendResponse([
            'error' => "Ya tienes un retiro pendiente de Fase $fase_numero. Espera a que sea procesado.",
        ], 400);
    }

    // 5. Registrar solicitud con fase_numero
    $stmt = $pdo->prepare("
        INSERT INTO retiros (usuario_id, fase_numero, monto, wallet_destino)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $fase_numero, $saldo_disponible, $wallet]);

    // 6. Log de auditoría
    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
        VALUES (?, ?, 'SOLICITUD_RETIRO', 'retiros', ?)
    ");
    $stmt->execute([
        $user_id,
        $fase_numero,
        "Solicitud de retiro de \${$saldo_disponible} USDT de Fase {$fase_numero} a wallet {$wallet}",
    ]);

    sendResponse([
        'success'      => true,
        'fase_numero'  => $fase_numero,
        'monto'        => $saldo_disponible,
        'mensaje'      => "✅ Solicitud de retiro de \$" . number_format($saldo_disponible, 2) . " USDT de Fase {$fase_numero} enviada. Será procesada en menos de 24h.",
    ]);

} catch (PDOException $e) {
    error_log("RADIX solicitar_retiro ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
