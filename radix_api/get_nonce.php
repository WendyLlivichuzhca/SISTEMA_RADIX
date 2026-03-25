<?php
/**
 * get_nonce.php — Genera un desafío único (nonce) para verificar ownership de wallet.
 * El usuario firma este nonce con su clave privada en SafePal/MetaMask.
 * Esto PRUEBA que la persona realmente controla esa wallet.
 */
require_once 'config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$wallet = trim($_GET['wallet'] ?? '');

// Regex para Tron (empieza con T, 34 caracteres) o BSC (0x..., 42 caracteres)
if (empty($wallet) || !preg_match('/^(0x[a-fA-F0-9]{40}|T[a-zA-Z0-9]{33})$/', $wallet)) {
    sendResponse(['error' => 'Wallet inválida. Debe ser una dirección de Tron (T...) o BSC (0x...).'], 400);
}

// Nonce aleatorio — expira en 5 minutos
$nonce     = bin2hex(random_bytes(16));
$mensaje   = "Bienvenido a RADIX.\n\nFirma este mensaje para verificar tu identidad.\n\nNonce: $nonce\nWallet: $wallet";
$expira_en = time() + 300; // 5 minutos

// Guardar en sesión (temporal, solo para esta verificación)
$_SESSION['radix_nonce']          = $nonce;
$_SESSION['radix_nonce_wallet']   = $wallet;
$_SESSION['radix_nonce_expira']   = $expira_en;

sendResponse([
    'success' => true,
    'mensaje' => $mensaje,
    'expira'  => $expira_en
]);
