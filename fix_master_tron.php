<?php
require_once 'radix_api/config.php';

try {
    // Actualizar la wallet del Maestro (ID 1) a la de Tron para permitir el login
    // El usuario nos dio: TKqTCwyVnJRqLkUF1ibAT8yL6TCKgCuU9c
    $tron_wallet = 'TDLFwy5swL2B8stX6tgUgQr2BjB1DFdwoU';
    
    $stmt = $pdo->prepare("UPDATE usuarios SET wallet_address = ? WHERE id = 1");
    $stmt->execute([$tron_wallet]);
    
    echo "✅ Wallet del Master actualizada a $tron_wallet\n";
    
    // También verificar si existe una fila en tableros_progreso para el Master
    $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = 1 AND tablero_tipo = 'A'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->query("INSERT INTO tableros_progreso (usuario_id, tablero_tipo) VALUES (1, 'A')");
        echo "✅ Tablero inicial creado para el Master\n";
    }

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
