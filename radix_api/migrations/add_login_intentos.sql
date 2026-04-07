-- ============================================================
-- RADIX — Tabla login_intentos
-- Rate limiting por IP persistido en DB.
-- Ejecutar una sola vez en producción (corporat_RADIX).
-- ============================================================

CREATE TABLE IF NOT EXISTS login_intentos (
    id             INT          UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip             VARCHAR(45)  NOT NULL,
    endpoint       VARCHAR(30)  NOT NULL DEFAULT 'admin',
    intentos       TINYINT      UNSIGNED NOT NULL DEFAULT 1,
    primer_fallo   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_fallo   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de intentos fallidos de login para rate limiting por IP';

-- Verificación
SELECT 'Tabla login_intentos creada correctamente' AS resultado;
