-- ============================================================
--  Daily Task Manager — Database Schema
--  Database : inv_test  (MariaDB 10.4)
--  Charset  : utf8mb4 / utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `inv_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `inv_test`;

-- ------------------------------------------------------------
--  1. tbl_account  — login credentials, role, account status
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tbl_account`;
CREATE TABLE `tbl_account` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(55)      NOT NULL,
    `password`   VARCHAR(255)     NOT NULL,           -- bcrypt hash via password_hash()
    `role`       ENUM('User','Admin')
                                  NOT NULL DEFAULT 'User',
    `status`     ENUM('Active','Inactive','Disabled')
                                  NOT NULL DEFAULT 'Active',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,  -- set when an admin resets the password
    `last_login` TIMESTAMP        NULL     DEFAULT NULL,
    `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `uk_username` (`username` ASC) USING BTREE,
    INDEX `idx_role`   (`role`   ASC) USING BTREE,
    INDEX `idx_status` (`status` ASC) USING BTREE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  2. tbl_info  — personal / contact details (1-to-1 with account)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tbl_info`;
CREATE TABLE `tbl_info` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED  NOT NULL,
    `first_name` VARCHAR(100)  DEFAULT NULL,
    `middle_name` VARCHAR(100) DEFAULT NULL,
    `last_name`  VARCHAR(100)  DEFAULT NULL,
    `age`        TINYINT UNSIGNED DEFAULT NULL,
    `phone`      VARCHAR(30)   DEFAULT NULL,          -- 09XXXXXXXXX format
    `avatar`     VARCHAR(255)  DEFAULT NULL,          -- filename in uploads/avatars/
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE INDEX `uk_user_profile` (`user_id` ASC) USING BTREE,
    CONSTRAINT `fk_info_account`
        FOREIGN KEY (`user_id`)
        REFERENCES `tbl_account` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  3. tbl_tasks  — daily task entries
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tbl_tasks`;
CREATE TABLE `tbl_tasks` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED  NOT NULL,
    `title`        VARCHAR(150)  NOT NULL,
    `description`  TEXT          DEFAULT NULL,
    `priority`     ENUM('Low','Medium','High')
                                 NOT NULL DEFAULT 'Medium',
    `status`       ENUM('Pending','In Progress','Completed','Archived')
                                 NOT NULL DEFAULT 'Pending',
    `start_date`   DATE          DEFAULT NULL,
    `due_date`     DATE          DEFAULT NULL,
    `completed_at` TIMESTAMP     NULL DEFAULT NULL,   -- set when status → Completed
    `is_deleted`   TINYINT(1)    NOT NULL DEFAULT 0,  -- soft delete flag
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_user`    (`user_id`    ASC) USING BTREE,
    INDEX `idx_status`  (`status`     ASC) USING BTREE,
    INDEX `idx_due`     (`due_date`   ASC) USING BTREE,
    INDEX `idx_deleted` (`is_deleted` ASC) USING BTREE,
    CONSTRAINT `fk_tasks_account`
        FOREIGN KEY (`user_id`)
        REFERENCES `tbl_account` (`id`)
        ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  4. tbl_activity_logs  — audit trail of user actions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `tbl_activity_logs`;
CREATE TABLE `tbl_activity_logs` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED    NOT NULL,
    `action`       VARCHAR(100)    NOT NULL,   -- e.g. 'task_created', 'task_deleted'
    `reference_id` INT UNSIGNED    DEFAULT NULL, -- ID of the related task/record
    `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`) USING BTREE,
    INDEX `idx_user` (`user_id` ASC) USING BTREE,
    CONSTRAINT `fk_logs_account`
        FOREIGN KEY (`user_id`)
        REFERENCES `tbl_account` (`id`)
        ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
--  5. vw_user  — account + profile details in one row
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW `vw_user` AS
SELECT a.id, a.username, a.role, a.status, a.last_login, a.created_at,
       i.first_name, i.middle_name, i.last_name, i.age, i.phone, i.avatar
FROM `tbl_account` a
JOIN `tbl_info` i ON a.id = i.user_id;

-- ============================================================
--  Seed Data
-- ============================================================
INSERT IGNORE INTO `tbl_account` (`id`, `username`, `password`, `role`, `status`)
VALUES
    (1, 'admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'Active');
-- Password above is bcrypt of "password" — change immediately after first login.

INSERT IGNORE INTO `tbl_info` (`user_id`, `first_name`, `last_name`)
VALUES (1, 'Admin', 'User');
