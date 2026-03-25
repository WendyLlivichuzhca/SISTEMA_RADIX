<?php
/**
 * session_login.php - Inicia sesión segura para un usuario validado.
 * Recibe la wallet por POST, verifica que exista en DB y guarda en $_SESSION.
 * NUNCA expone la wallet en la URL del dashboard.
 */
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$wallet = trim($_POST['wallet'] ?? '');

if (empty($wallet) || (!str_starts_with($wallet, '0x') && !str_starts_with($wallet, 'T'))) {
    sendResponse(['error' => 'Wallet inválida'], 400);
}

// ── Verificar que la wallet fue autenticada criptográficamente en esta sesión ──
// registro.php establece este flag solo tras verificar la firma del nonce.
// Esto impide que alguien llame directamente a este endpoint con cualquier wallet.
$verificada   = $_SESSION['radix_wallet_verificada'] ?? '';
$verificada_at = (int)($_SESSION['radix_verificada_at'] ?? 0);
$ventana_valida = (time() - $verificada_at) < 300; // 5 minutos

if ($verificada !== $wallet || !$ventana_valida) {
    sendResponse(['error' => 'Verificación de billetera requerida. Por favor, reconecta tu wallet.'], 401);
}

// Consumir el flag (un solo uso, evita reutilización)
unset($_SESSION['radix_wallet_verificada'], $_SESSION['radix_verificada_at']);

try {
    $stmt = $pdo->prepare("SELECT id, nickname, tipo_usuario FROM usuarios WHERE wallet_address = ?");
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(['error' => 'Usuario no encontrado'], 404);
    }

    // Guardar en sesión (nunca en URL)
    $_SESSION['radix_wallet']   = $wallet;
    $_SESSION['radix_user_id']  = $user['id'];
    $_SESSION['radix_nickname'] = $user['nickname'];
    $_SESSION['tipo_usuario']   = $user['tipo_usuario'];

    sendResponse(['success' => true]);

} catch (PDOException $e) {
    error_log("RADIX session_login ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error de conexión al servidor. Intenta de nuevo.'], 500);
}
?>