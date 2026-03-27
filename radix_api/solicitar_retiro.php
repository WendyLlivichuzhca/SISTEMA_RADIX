<?php
/**
 * solicitar_retiro.php — RADIX Phase 0
 * Registra una solicitud de retiro del usuario.
 * MEJORA #4: Página de retiro / historial de ganancias.
 */
require_once 'config.php';
session_start();

if (empty($_SESSION['radix_wallet'])) {
    sendResponse(['error' => 'No autorizado'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$wallet = $_SESSION['radix_wallet'];

try {
    // 1. Obtener usuario
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();
    if (!$user) sendResponse(['error' => 'Usuario no encontrado'], 404);
    $user_id = $user['id'];

    // 2. Verificar que el usuario haya completado la Fase 0 (Tablero C completado)
    // Sin esto, usuarios en Fase 0 podrían retirar créditos o ganancias parciales.
    $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND tablero_tipo = 'C' AND estado = 'completado' LIMIT 1");
    $stmt->execute([$user_id]);
    if (!$stmt->fetch()) {
        sendResponse(['error' => 'Debes completar la Fase 0 (Tableros A → B → C) antes de poder retirar tus ganancias.'], 403);
    }

    // 3. Calcular saldo disponible (ganancias + crédito excedente - deducciones - ya retirado)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_receptor = ? AND estado = 'completado' AND tipo = 'ganancia_tablero'");
    $stmt->execute([$user_id]);
    $bruto = (float)($stmt->fetch()['total'] ?? 0);

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM pagos WHERE id_emisor = ? AND estado = 'completado' AND tipo IN ('salto_fase_1','reentrada')");
    $stmt->execute([$user_id]);
    $deducciones = (float)($stmt->fetch()['total'] ?? 0);

    // Crédito por excedente de pago (cuando pagó más de $10 al entrar)
    $stmt = $pdo->prepare("SELECT COALESCE(credito_saldo, 0) as credito FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $credito = (float)($stmt->fetch()['credito'] ?? 0);

    // Descontar retiros ya aprobados y procesados para evitar doble retiro
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) as total FROM retiros WHERE usuario_id = ? AND estado = 'procesado'");
    $stmt->execute([$user_id]);
    $ya_retirado = (float)($stmt->fetch()['total'] ?? 0);

    $saldo_disponible = $bruto - $deducciones + $credito - $ya_retirado;

    if ($saldo_disponible < 10) {
        sendResponse(['error' => 'No tienes saldo suficiente para retirar (mínimo $10.00).'], 400);
    }

    // 3. Verificar que no tenga ya un retiro pendiente
    $stmt = $pdo->prepare("SELECT id FROM retiros WHERE usuario_id = ? AND estado = 'pendiente'");
    $stmt->execute([$user_id]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Ya tienes un retiro pendiente. Espera a que sea procesado.'], 400);
    }

    // 4. Registrar solicitud
    $stmt = $pdo->prepare("INSERT INTO retiros (usuario_id, monto, wallet_destino) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $saldo_disponible, $wallet]);

    // 5. Log de auditoría
    $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, 'SOLICITUD_RETIRO', 'retiros', ?)");
    $stmt->execute([$user_id, "Solicitud de retiro de \${$saldo_disponible} USDT a wallet {$wallet}"]);

    sendResponse([
        'success' => true,
        'monto'   => $saldo_disponible,
        'mensaje' => "✅ Solicitud de retiro de \${$saldo_disponible} USDT enviada. Será procesada en menos de 24h.",
    ]);

} catch (PDOException $e) {
    error_log("RADIX solicitar_retiro ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor. Intenta de nuevo.'], 500);
}
