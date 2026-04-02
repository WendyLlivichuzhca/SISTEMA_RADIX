-- RADIX
-- Asegura que los pagos cuyo beneficiario logico es un clon
-- queden marcados como flujo del sistema.

START TRANSACTION;

UPDATE pagos p
JOIN usuarios u ON u.id = COALESCE(p.beneficiario_usuario_id, p.id_receptor)
SET p.propietario_flujo = 'sistema'
WHERE u.tipo_usuario = 'clon';

COMMIT;
