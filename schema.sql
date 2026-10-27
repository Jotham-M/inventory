CREATE DATABASE IF NOT EXISTS norton_inventory;
USE norton_inventory;

-- Administrator-only access. Public account registration is not provided.
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_admins_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_attempts_ip_created (ip_hash, created_at)
) ENGINE=InnoDB;

-- Core Inventory Items (Goods, Consumables, Assets)
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    category ENUM('goods', 'consumables', 'assets') NOT NULL,
    unit VARCHAR(20) DEFAULT 'pcs',
    quantity INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 5,
    location VARCHAR(100) DEFAULT 'Main Storeroom',
    supplier VARCHAR(100) DEFAULT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_items_category (category),
    INDEX idx_items_stock_level (quantity, reorder_level),
    INDEX idx_items_created (created_at)
) ENGINE=InnoDB;

-- Extended Details for Specific Tracked Assets
CREATE TABLE IF NOT EXISTS asset_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    asset_tag VARCHAR(50) NOT NULL UNIQUE,
    assigned_to VARCHAR(100) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    condition_status ENUM('Excellent', 'Good', 'Needs Repair', 'Scrapped') DEFAULT 'Good',
    purchase_date DATE DEFAULT NULL,
    UNIQUE KEY uq_asset_details_item (item_id),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Stock Movements (Audit Trail for In/Out Goods)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    type ENUM('in', 'out') NOT NULL,
    quantity INT NOT NULL,
    issued_to_or_from VARCHAR(100) DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transactions_created (created_at),
    INDEX idx_transactions_type_created (type, created_at),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;