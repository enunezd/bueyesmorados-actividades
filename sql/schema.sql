-- Esquema completo para una instalación NUEVA (y para el entorno local con Docker).
-- Una base que ya existe se actualiza con:  php cli/migrar.php
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS administradores(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  usuario VARCHAR(60) NOT NULL UNIQUE,
  clave_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  intentos_fallidos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_acceso DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS actividades(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  descripcion TEXT NULL,
  fecha_actividad DATE NULL,
  fecha_apertura DATETIME NULL,
  fecha_cierre DATETIME NULL,
  modo_control ENUM('automatico','forzar_abierto','forzar_cerrado') NOT NULL DEFAULT 'automatico',
  estado ENUM('borrador','publicada','archivada') NOT NULL DEFAULT 'borrador',
  mostrar_cerrada TINYINT(1) NOT NULL DEFAULT 1,
  asignacion_auto TINYINT(1) NOT NULL DEFAULT 0,
  asignacion_ejecutada_en DATETIME NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comisiones(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  descripcion TEXT NULL,
  reglas TEXT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(actividad_id) REFERENCES actividades(id) ON DELETE RESTRICT,
  UNIQUE KEY(actividad_id,nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS turnos(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(100) NULL,
  hora_inicio TIME NOT NULL,
  hora_fin TIME NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(actividad_id) REFERENCES actividades(id) ON DELETE RESTRICT,
  UNIQUE KEY(actividad_id,hora_inicio,hora_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cupos(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT UNSIGNED NOT NULL,
  comision_id INT UNSIGNED NOT NULL,
  turno_id INT UNSIGNED NOT NULL,
  capacidad SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  habilitado TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(actividad_id) REFERENCES actividades(id),
  FOREIGN KEY(comision_id) REFERENCES comisiones(id),
  FOREIGN KEY(turno_id) REFERENCES turnos(id),
  UNIQUE KEY(comision_id,turno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lista de familias de la promoción (se importa desde el panel: Familias).
CREATE TABLE IF NOT EXISTS familias(
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  secciones VARCHAR(40) NULL,
  responsable VARCHAR(150) NULL,
  telefono VARCHAR(30) NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_familia_nombre(nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inscripciones(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actividad_id INT UNSIGNED NOT NULL,
  comision_id INT UNSIGNED NOT NULL,
  turno_id INT UNSIGNED NOT NULL,
  cupo_id INT UNSIGNED NOT NULL,
  familia_id INT UNSIGNED NULL,
  familia VARCHAR(150) NOT NULL,
  responsable VARCHAR(150) NOT NULL,
  telefono VARCHAR(30) NOT NULL,
  estudiante VARCHAR(150) NULL,
  grado_seccion VARCHAR(60) NULL,
  observacion VARCHAR(500) NULL,
  estado ENUM('confirmada','cancelada') NOT NULL DEFAULT 'confirmada',
  origen ENUM('familia','admin','automatica') NOT NULL DEFAULT 'familia',
  codigo_confirmacion VARCHAR(20) NOT NULL UNIQUE,
  ip_registro VARCHAR(45) NULL,
  navegador VARCHAR(255) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY(actividad_id) REFERENCES actividades(id),
  FOREIGN KEY(comision_id) REFERENCES comisiones(id),
  FOREIGN KEY(turno_id) REFERENCES turnos(id),
  FOREIGN KEY(cupo_id) REFERENCES cupos(id),
  CONSTRAINT fk_insc_familia FOREIGN KEY(familia_id) REFERENCES familias(id) ON DELETE SET NULL,
  INDEX(cupo_id,estado),
  INDEX idx_insc_familia(actividad_id,familia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Familias que no participan en una actividad concreta (no entran al sorteo).
CREATE TABLE IF NOT EXISTS exoneraciones(
  actividad_id INT UNSIGNED NOT NULL,
  familia_id INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(actividad_id,familia_id),
  FOREIGN KEY(actividad_id) REFERENCES actividades(id) ON DELETE CASCADE,
  FOREIGN KEY(familia_id) REFERENCES familias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  administrador_id INT UNSIGNED NULL,
  accion VARCHAR(80) NOT NULL,
  entidad VARCHAR(80) NOT NULL,
  entidad_id BIGINT UNSIGNED NULL,
  detalle TEXT NULL,
  ip VARCHAR(45) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(administrador_id) REFERENCES administradores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
