<?php
require 'radix_api/config.php';
$wallet = 'TQ2Raqj1R4fokgHEKYdDKx8APN9FFuqe41';
$stmt = $pdo->prepare("SELECT id, nickname, wallet_address, tipo_usuario, nivel FROM usuarios WHERE wallet_address = ?");
$stmt->execute([$wallet]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "--- USUARIO ---\n";
print_r($user);

if ($user) {
    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE id_emisor = ?");
    $stmt->execute([$user['id']]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n--- PAGOS EMITIDOS ---\n";
    print_r($pagos);
}
