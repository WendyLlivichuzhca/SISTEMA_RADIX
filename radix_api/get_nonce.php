<?php
/**
 * get_nonce.php - Genera un desafio temporal para que una wallet TRON
 * demuestre que realmente controla la direccion que intenta registrar.
 */
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Metodo no permitido'], 405);
}

$wallet = trim($_GET['wallet'] ?? '');

// RADIX opera sobre TRON en este flujo.
if ($wallet === '' || !preg_match('/^T[a-zA-Z0-9]{33}$/', $wallet)) {
    sendResponse(['error' => 'Wallet invalida. Debe ser una direccion TRON (T...).'], 400);
}

$nonce = bin2hex(random_bytes(16));
$mensaje = "Bienvenido a RADIX.\n\nFirma este mensaje para verificar tu identidad.\n\nNonce: $nonce\nWallet: $wallet";
$expira_en = time() + 300;

$_SESSION['radix_nonce'] = $nonce;
$_SESSION['radix_nonce_wallet'] = $wallet;
$_SESSION['radix_nonce_expira'] = $expira_en;

sendResponse([
    'success' => true,
    'mensaje' => $mensaje,
    'expira' => $expira_en,
]);
?>
