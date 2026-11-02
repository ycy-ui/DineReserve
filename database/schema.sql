-- DineReserve schema (SQLite dialect, used by PHPUnit and local development)
-- The MySQL 8.0 dialect used by the TastyIgniter deployment is database/schema-mysql.sql
-- Every statement is idempotent-safe on a fresh database.

PRAGMA foreign_keys = ON;

CREATE TABLE restaurants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    address     TEXT    NOT NULL DEFAULT '',
    timezone    TEXT    NOT NULL DEFAULT 'Asia/Macau',
    currency    TEXT    NOT NULL DEFAULT 'MOP',
    languages   TEXT    NOT NULL DEFAULT 'en,zh',
    created_at  TEXT    NOT NULL
);

CREATE TABLE service_periods (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    restaurant_id       INTEGER NOT NULL REFERENCES restaurants(id),
    name                TEXT    NOT NULL,
    start_time          TEXT    NOT NULL,              -- HH:MM (restaurant local time)
    end_time            TEXT    NOT NULL,              -- HH:MM, exclusive end of bookable starts
    avg_dining_minutes  INTEGER NOT NULL DEFAULT 90,   -- used to size the booked slot
    days_of_week        TEXT    NOT NULL DEFAULT '1,2,3,4,5,6,7'
);

CREATE TABLE tables (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    restaurant_id INTEGER NOT NULL REFERENCES restaurants(id),
    code          TEXT    NOT NULL,
    seats         INTEGER NOT NULL CHECK (seats >= 1),
    area          TEXT    NOT NULL DEFAULT 'main',
    pos_x         INTEGER NOT NULL DEFAULT 0,
    pos_y         INTEGER NOT NULL DEFAULT 0,
    status        TEXT    NOT NULL DEFAULT 'FREE',     -- FREE | BLOCKED | MAINTENANCE
    UNIQUE (restaurant_id, code)
);

CREATE TABLE users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    restaurant_id  INTEGER REFERENCES restaurants(id),
    name           TEXT    NOT NULL,
    email          TEXT    NOT NULL UNIQUE,
    password_hash  TEXT    NOT NULL,
    is_active      INTEGER NOT NULL DEFAULT 1,
    last_login_at  TEXT,
    created_at     TEXT    NOT NULL
);

CREATE TABLE roles (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    code  TEXT NOT NULL UNIQUE,                        -- diner | host | manager | administrator
    name  TEXT NOT NULL
);

CREATE TABLE user_roles (
    user_id  INTEGER NOT NULL REFERENCES users(id),
    role_id  INTEGER NOT NULL REFERENCES roles(id),
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE bookings (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    reference          TEXT    NOT NULL UNIQUE,        -- 8 chars, unambiguous alphabet
    restaurant_id      INTEGER NOT NULL REFERENCES restaurants(id),
    table_id           INTEGER NOT NULL REFERENCES tables(id),
    booking_date       TEXT    NOT NULL,               -- YYYY-MM-DD (restaurant local date)
    start_time         TEXT    NOT NULL,               -- HH:MM
    end_time           TEXT    NOT NULL,               -- HH:MM
    party_size         INTEGER NOT NULL CHECK (party_size >= 1),
    status             TEXT    NOT NULL,               -- CONFIRMED | CANCELLED | SEATED | NO_SHOW | COMPLETED
    guest_name         TEXT    NOT NULL,
    guest_email        TEXT    NOT NULL,
    guest_mobile       TEXT,
    special_requests   TEXT,
    channel            TEXT    NOT NULL DEFAULT 'online', -- online | phone | walk_in | waitlist
    cutoff_at          TEXT    NOT NULL,               -- ISO 8601 with the restaurant tz offset
    idempotency_token  TEXT    UNIQUE,
    risk_score         INTEGER,                        -- 0..100, REQ-1.8 (W11)
    created_at         TEXT    NOT NULL,
    updated_at         TEXT    NOT NULL
);

CREATE INDEX idx_bookings_table_date ON bookings (table_id, booking_date);
CREATE INDEX idx_bookings_restaurant_date ON bookings (restaurant_id, booking_date);
CREATE INDEX idx_bookings_email ON bookings (guest_email);

CREATE TABLE booking_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id  INTEGER NOT NULL REFERENCES bookings(id),
    event_type  TEXT    NOT NULL,                      -- created | changed | cancelled | seated | no_show | released
    actor       TEXT    NOT NULL DEFAULT 'system',     -- diner | host | system
    payload     TEXT    NOT NULL DEFAULT '{}',         -- JSON snapshot for audit / reporting
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_booking_events_booking ON booking_events (booking_id);

CREATE TABLE waitlist_entries (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    restaurant_id  INTEGER NOT NULL REFERENCES restaurants(id),
    service_period_id INTEGER NOT NULL REFERENCES service_periods(id),
    entry_date     TEXT    NOT NULL,
    party_size     INTEGER NOT NULL,
    guest_name     TEXT    NOT NULL,
    guest_contact  TEXT    NOT NULL,
    status         TEXT    NOT NULL DEFAULT 'WAITING', -- WAITING | OFFERED | SEATED | EXPIRED | PASSED_OVER
    offer_expires_at TEXT,
    created_at     TEXT    NOT NULL
);

CREATE INDEX idx_waitlist_queue ON waitlist_entries (restaurant_id, entry_date, status, created_at);

CREATE TABLE idempotency_keys (
    token       TEXT    PRIMARY KEY,
    booking_id  INTEGER NOT NULL REFERENCES bookings(id),
    created_at  TEXT    NOT NULL
);

CREATE TABLE notification_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id   INTEGER NOT NULL REFERENCES bookings(id),
    channel      TEXT    NOT NULL,                     -- email | sms
    template     TEXT    NOT NULL,
    status       TEXT    NOT NULL,                     -- QUEUED | SENT | FAILED
    attempts     INTEGER NOT NULL DEFAULT 0,
    last_error   TEXT,
    scheduled_at TEXT,
    sent_at      TEXT
);

CREATE TABLE audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    actor       TEXT    NOT NULL,
    action      TEXT    NOT NULL,
    entity      TEXT    NOT NULL,
    entity_id   TEXT,
    payload     TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);
