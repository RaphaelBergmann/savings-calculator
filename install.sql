CREATE DATABASE IF NOT EXISTS spar_rechner CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE spar_rechner;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE goals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    target_amount DECIMAL(12,2) NOT NULL,
    start_month DATE NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE monthly_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    valid_from_month DATE NOT NULL,
    monthly_amount DECIMAL(12,2) NOT NULL,
    absolute_percent TINYINT UNSIGNED NOT NULL,
    relative_percent TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_monthly_setting (user_id, valid_from_month),
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE one_time_contributions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    booking_month DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    absolute_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
    relative_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
    target_goal_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_one_time_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_one_time_target_goal FOREIGN KEY (target_goal_id) REFERENCES goals(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE monthly_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    goal_id INT UNSIGNED NULL,
    booking_month DATE NOT NULL,
    source ENUM('automatic','one_time','credit_reallocation','credit_wallet','initial') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alloc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_alloc_goal FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    KEY idx_user_month (user_id, booking_month)
) ENGINE=InnoDB;

CREATE TABLE wallet_credits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    available_amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE monthly_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    booking_month DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_month_run (user_id, booking_month),
    CONSTRAINT fk_monthly_runs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
