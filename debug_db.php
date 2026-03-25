<?php
require_once 'radix_api/config.php';
$stmt = $pdo->query("SELECT tipo_usuario, COUNT(*) as cuenta FROM usuarios GROUP BY tipo_usuario");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res);
?>
