-- Select the database created in your hosting control panel before importing this file.
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','technician','viewer') NOT NULL DEFAULT 'viewer',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(160) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS generators (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NULL,
  asset_code VARCHAR(60) NOT NULL UNIQUE,
  make VARCHAR(100) NOT NULL,
  model VARCHAR(100) NULL,
  serial_number VARCHAR(120) NULL,
  capacity_kva DECIMAL(10,2) NULL,
  location VARCHAR(180) NULL,
  installation_date DATE NULL,
  status ENUM('active','under_maintenance','inactive') NOT NULL DEFAULT 'active',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_generators_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  INDEX idx_generators_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  generator_id BIGINT UNSIGNED NOT NULL,
  assigned_to BIGINT UNSIGNED NULL,
  job_type ENUM('routine','preventive','corrective','emergency') NOT NULL DEFAULT 'routine',
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  scheduled_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  findings TEXT NULL,
  work_performed TEXT NULL,
  next_service_date DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_jobs_generator FOREIGN KEY (generator_id) REFERENCES generators(id) ON DELETE RESTRICT,
  CONSTRAINT fk_jobs_technician FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_jobs_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_jobs_schedule (scheduled_at),
  INDEX idx_jobs_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS spare_parts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  part_name VARCHAR(150) NOT NULL,
  part_number VARCHAR(100) NULL,
  quantity INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 0,
  unit_cost DECIMAL(12,2) NULL,
  supplier VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_parts_name (part_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  details VARCHAR(1000) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;
