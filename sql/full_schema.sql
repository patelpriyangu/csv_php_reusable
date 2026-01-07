CREATE DATABASE IF NOT EXISTS csv_import_export_engine;
USE csv_import_export_engine;

CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    product_name VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 0,
    price DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS import_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL COMMENT 'e.g. inventory, users, library',
    filename VARCHAR(255) NOT NULL,
    file_size_mb DECIMAL(10, 2) DEFAULT 0,
    total_rows INT DEFAULT 0,
    valid_rows INT DEFAULT 0,
    error_rows INT DEFAULT 0,
    status ENUM('in_progress', 'completed', 'failed') DEFAULT 'in_progress',
    error_details JSON DEFAULT NULL COMMENT 'Stores detailed errors per row',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    duration_seconds INT DEFAULT 0,
    INDEX idx_module (module),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at)
);