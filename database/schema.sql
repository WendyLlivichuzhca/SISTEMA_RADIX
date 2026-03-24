-- ==========================================================
-- ESTRUCTURA DE BASE DE DATOS PROFESIONAL PARA RADIX
-- Versión: 2.0 (Producción)
-- ==========================================================

CREATE DATABASE IF NOT EXISTS radix_db;
USE radix_db;

-- 1. CONFIGURACIÓN DEL SISTEMA
-- Almacena variables que puedes cambiar sin tocar el código
CREATE TABLE IF NOT EXISTS configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(50) UNIQUE NOT NULL,
    valor VARCHAR(255) NOT NULL,
    descripcion TEXT
);

INSERT INTO configuracion (clave, valor, descripcion) VALUES 
('monto_regalo', '5.00', 'Monto en USDT necesario para entrar'),
('fee_sistema', '1.00', 'Comisión cobrada por la plataforma'),
('max_referidos', '3', 'Límite de la matriz lateral');

-- 2. USUARIOS
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wallet_address VARCHAR(255) UNIQUE NOT NULL,
    nickname VARCHAR(50),
    patrocinador_id INT,
    estado ENUM('activo', 'suspendido', 'pendiente') DEFAULT 'activo',
    ip_registro VARCHAR(45),
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patrocinador_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- 3. NIVELES / TABLEROS
-- Rastrea en qué tablero está el usuario (Radix Seed, Radix Core, Radix Pro)
CREATE TABLE IF NOT EXISTS tableros_progreso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    tablero_tipo ENUM('A', 'B', 'C') DEFAULT 'A',
    estado ENUM('en_progreso', 'completado') DEFAULT 'en_progreso',
    fecha_inicio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_fin TIMESTAMP NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- 4. MATRIZ DE REFERIDOS (3xN)
CREATE TABLE IF NOT EXISTS referidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_padre INT NOT NULL,
    id_hijo INT NOT NULL,
    posicion INT CHECK (posicion BETWEEN 1 AND 3),
    nivel_en_red INT NOT NULL, -- Nivel jerárquico real
    fecha_union TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_padre) REFERENCES usuarios(id),
    FOREIGN KEY (id_hijo) REFERENCES usuarios(id),
    UNIQUE(id_padre, posicion)
);

-- 5. TRANSACCIONES Y PAGOS
-- Control riguroso de cada movimiento de dinero simulado o real
CREATE TABLE IF NOT EXISTS pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_emisor INT NOT NULL,
    id_receptor INT NOT NULL,
    monto DECIMAL(10, 2) NOT NULL,
    hash_transaccion VARCHAR(255) UNIQUE NULL, -- Para cuando se use Blockchain real
    tipo ENUM('regalo', 'fee', 'reentrada') NOT NULL,
    estado ENUM('pendiente', 'validando', 'completado', 'fallido') DEFAULT 'completado',
    fecha_pago TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_emisor) REFERENCES usuarios(id),
    FOREIGN KEY (id_receptor) REFERENCES usuarios(id)
);

-- 6. ADMINISTRADORES
CREATE TABLE IF NOT EXISTS administradores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('superadmin', 'soporte') DEFAULT 'soporte',
    ultima_conexion TIMESTAMP NULL
);

-- 7. BITÁCORA DE AUDITORÍA (SEGURIDAD)
-- Registra quién hizo qué en el sistema para evitar fraudes
CREATE TABLE IF NOT EXISTS auditoria_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    accion VARCHAR(255) NOT NULL,
    tabla_afectada VARCHAR(50),
    detalles TEXT,
    ip_address VARCHAR(45),
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indices para optimizar la velocidad de búsqueda
CREATE INDEX idx_wallet ON usuarios(wallet_address);
CREATE INDEX idx_padre ON referidos(id_padre);
CREATE INDEX idx_hijo ON referidos(id_hijo);
