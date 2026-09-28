-- ============================================================
-- ControlFuel - Esquema de Base de Datos (MySQL / XAMPP)
-- ============================================================

CREATE DATABASE IF NOT EXISTS controlfuel
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE controlfuel;

-- ------------------------------------------------------------
-- 1. Usuarios del sistema (administrador / operador)
-- ------------------------------------------------------------
CREATE TABLE usuarios (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(100) NOT NULL,
    usuario         VARCHAR(50)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    rol             ENUM('administrador', 'operador') NOT NULL DEFAULT 'operador',
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    fecha_creacion  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_login    DATETIME     NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. Tarjetas RFID registradas
-- ------------------------------------------------------------
CREATE TABLE tarjetas_rfid (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    uid_rfid        VARCHAR(50)  NOT NULL UNIQUE,
    titular         VARCHAR(100) NOT NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    fecha_registro  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registrado_por  INT          NULL,
    CONSTRAINT fk_tarjeta_usuario
        FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. Configuración general del sistema (fila única, id = 1)
-- ------------------------------------------------------------
CREATE TABLE configuracion (
    id                  INT PRIMARY KEY DEFAULT 1,
    modo_actual         ENUM('normal', 'crisis') NOT NULL DEFAULT 'normal',
    limite_crisis_litros DECIMAL(6,2) NOT NULL DEFAULT 5.00,
    capacidad_tanque_litros DECIMAL(10,2) NOT NULL,
    nivel_critico_pct   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    actualizado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_config_id CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO configuracion (id, modo_actual, limite_crisis_litros, capacidad_tanque_litros, nivel_critico_pct)
VALUES (1, 'normal', 5.00, 10000.00, 0.00);

-- ------------------------------------------------------------
-- 4. Lecturas del sensor ultrasónico (histórico del tanque)
--    Usado para el gráfico de "consumo últimos 7 días" y el
--    indicador de nivel/volumen en tiempo real.
-- ------------------------------------------------------------
CREATE TABLE lecturas_tanque (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    fecha_hora      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nivel_pct       DECIMAL(5,2)  NOT NULL,
    volumen_litros  DECIMAL(10,2) NOT NULL,
    INDEX idx_lecturas_fecha (fecha_hora)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. Despachos de combustible
-- ------------------------------------------------------------
CREATE TABLE despachos (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    fecha_hora      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tarjeta_id      INT NOT NULL,
    litros          DECIMAL(6,2) NOT NULL,
    modo            ENUM('normal', 'crisis') NOT NULL,
    estado          ENUM('completado', 'rechazado') NOT NULL,
    motivo          VARCHAR(150) NULL,
    operador_id     INT NULL,
    CONSTRAINT fk_despacho_tarjeta
        FOREIGN KEY (tarjeta_id) REFERENCES tarjetas_rfid(id),
    CONSTRAINT fk_despacho_operador
        FOREIGN KEY (operador_id) REFERENCES usuarios(id)
        ON DELETE SET NULL,
    INDEX idx_despachos_fecha (fecha_hora)
) ENGINE=InnoDB;
