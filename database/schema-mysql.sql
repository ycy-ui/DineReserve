-- DineReserve schema (MySQL 8.0 dialect, deployment target of the TastyIgniter build)
-- Keep in sync with database/schema.sql (SQLite dialect used by the test suite).
-- Applied by: bin/migrate.php --mysql  (or `mysql < database/schema-mysql.sql`)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS restaurants (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NOT NULL DEFAULT '',
    timezone    VARCHAR(64)  NOT NULL DEFAULT 'Asia/Macau',
    currency    CHAR(3)      NOT NULL DEFAULT 'MOP',
    languages   VARCHAR(32)  NOT NULL DEFAULT 'en,zh',
    created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS service_periods (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id       INT NOT NULL,
    name                VARCHAR(40) NOT NULL,
    start_time          CHAR(5) NOT NULL,
    end_time            CHAR(5) NOT NULL,
    avg_dining_minutes  SMALLINT NOT NULL DEFAULT 90,
    days_of_week        VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
    CONSTRAINT fk_sp_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tables (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    code          VARCHAR(16) NOT NULL,
    seats         SMALLINT NOT NULL,
    area          VARCHAR(32) NOT NULL DEFAULT 'main',
    pos_x         SMALLINT NOT NULL DEFAULT 0,
    pos_y         SMALLINT NOT NULL DEFAULT 0,
    status        VARCHAR(16) NOT NULL DEFAULT 'FREE', -- FREE | OCCUPIED | CLEANING | BLOCKED | MAINTENANCE
    UNIQUE KEY uq_table_code (restaurant_id, code),
    CONSTRAINT fk_table_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT chk_table_seats CHECK (seats >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NULL,
    name          VARCHAR(80) NOT NULL,
    email         VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL,
    CONSTRAINT fk_user_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    reference         CHAR(8) NOT NULL UNIQUE,
    restaurant_id     INT NOT NULL,
    table_id          INT NOT NULL,
    booking_date      DATE NOT NULL,
    start_time        CHAR(5) NOT NULL,
    end_time          CHAR(5) NOT NULL,
    party_size        SMALLINT NOT NULL,
    status            VARCHAR(16) NOT NULL,
    guest_name        VARCHAR(80) NOT NULL,
    guest_email       VARCHAR(254) NOT NULL,
    guest_mobile      VARCHAR(32) NULL,
    special_requests  VARCHAR(200) NULL,
    channel           VARCHAR(16) NOT NULL DEFAULT 'online',
    cutoff_at         DATETIME NOT NULL,
    idempotency_token VARCHAR(64) NULL UNIQUE,
    risk_score        TINYINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL,
    updated_at        DATETIME NOT NULL,
    KEY idx_bookings_table_date (table_id, booking_date),
    KEY idx_bookings_restaurant_date (restaurant_id, booking_date),
    KEY idx_bookings_email (guest_email),
    CONSTRAINT fk_booking_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_booking_table FOREIGN KEY (table_id) REFERENCES tables(id),
    CONSTRAINT chk_booking_party CHECK (party_size >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking_events (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    actor      VARCHAR(16) NOT NULL DEFAULT 'system',
    payload    JSON NOT NULL,
    occurred_at DATETIME NOT NULL,
    KEY idx_events_booking (booking_id),
    CONSTRAINT fk_event_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS waitlist_entries (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id     INT NOT NULL,
    service_period_id INT NOT NULL,
    entry_date        DATE NOT NULL,
    party_size        SMALLINT NOT NULL,
    guest_name        VARCHAR(80) NOT NULL,
    guest_contact     VARCHAR(254) NOT NULL,
    status            VARCHAR(16) NOT NULL DEFAULT 'WAITING',
    offer_expires_at  DATETIME NULL,
    offer_table_id    INT NULL,
    offer_time        VARCHAR(5) NULL,
    created_at        DATETIME NOT NULL,
    KEY idx_waitlist_queue (restaurant_id, entry_date, status, created_at),
    CONSTRAINT fk_waitlist_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_waitlist_period FOREIGN KEY (service_period_id) REFERENCES service_periods(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS idempotency_keys (
    token      VARCHAR(64) NOT NULL PRIMARY KEY,
    booking_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_idem_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT NOT NULL,
    channel      VARCHAR(8) NOT NULL,
    template     VARCHAR(40) NOT NULL,
    status       VARCHAR(16) NOT NULL,
    attempts     SMALLINT NOT NULL DEFAULT 0,
    last_error   VARCHAR(255) NULL,
    scheduled_at DATETIME NULL,
    sent_at      DATETIME NULL,
    CONSTRAINT fk_notif_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor       VARCHAR(64) NOT NULL,
    action      VARCHAR(60) NOT NULL,
    entity      VARCHAR(40) NOT NULL,
    entity_id   VARCHAR(40) NULL,
    payload     JSON NOT NULL,
    occurred_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
