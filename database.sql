-- ============================================================
-- ParkNPlace Production Database Schema
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+05:30";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS parknplace
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE parknplace;

-- Drop tables
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS spaces;
DROP TABLE IF EXISTS users;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
id INT AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(100) NOT NULL,
email VARCHAR(150) NOT NULL UNIQUE,
password VARCHAR(255) NOT NULL,
role ENUM('admin','owner','tenant') NOT NULL DEFAULT 'tenant',
phone VARCHAR(20),
status ENUM('active','inactive','blocked') DEFAULT 'active',
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SPACES
-- ============================================================
CREATE TABLE spaces (
id INT AUTO_INCREMENT PRIMARY KEY,
owner_id INT NOT NULL,

```
title VARCHAR(200) NOT NULL,
description TEXT,

type ENUM('Home','Parking','Shop') NOT NULL,

price DECIMAL(10,2) NOT NULL,
deposit DECIMAL(10,2) DEFAULT 0,

area DECIMAL(10,2),

address TEXT,
city VARCHAR(100),
state VARCHAR(100),
pincode VARCHAR(10),

latitude DECIMAL(10,8),
longitude DECIMAL(11,8),

pricing_model ENUM('hourly','daily','monthly')
    DEFAULT 'monthly',

vehicle_type ENUM('2-wheeler','4-wheeler','any','none')
    DEFAULT 'none',

has_ev BOOLEAN DEFAULT FALSE,

target_audience ENUM(
    'student',
    'it_professional',
    'family',
    'couple',
    'none'
) DEFAULT 'none',

rooms INT DEFAULT NULL,
bathrooms INT DEFAULT NULL,

image_url VARCHAR(500),

status ENUM('pending','verified','rejected')
    DEFAULT 'pending',

views INT DEFAULT 0,

created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT fk_space_owner
    FOREIGN KEY(owner_id)
    REFERENCES users(id)
    ON DELETE CASCADE
```

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MESSAGES
-- ============================================================
CREATE TABLE messages (
id INT AUTO_INCREMENT PRIMARY KEY,

```
sender_id INT NOT NULL,
receiver_id INT NOT NULL,
property_id INT NOT NULL,

message TEXT NOT NULL,

is_read BOOLEAN DEFAULT FALSE,

created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

CONSTRAINT fk_message_sender
    FOREIGN KEY(sender_id)
    REFERENCES users(id)
    ON DELETE CASCADE,

CONSTRAINT fk_message_receiver
    FOREIGN KEY(receiver_id)
    REFERENCES users(id)
    ON DELETE CASCADE,

CONSTRAINT fk_message_space
    FOREIGN KEY(property_id)
    REFERENCES spaces(id)
    ON DELETE CASCADE
```

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BOOKINGS
-- ============================================================
CREATE TABLE bookings (
id INT AUTO_INCREMENT PRIMARY KEY,

```
tenant_id INT NOT NULL,
owner_id INT NOT NULL,
property_id INT NOT NULL,

amount DECIMAL(10,2) NOT NULL,

duration DECIMAL(10,2) DEFAULT 1,

status ENUM(
    'pending',
    'confirmed',
    'cancelled',
    'completed'
) DEFAULT 'pending',

tenant_name VARCHAR(100),
tenant_phone VARCHAR(20),

created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

CONSTRAINT fk_booking_tenant
    FOREIGN KEY(tenant_id)
    REFERENCES users(id)
    ON DELETE CASCADE,

CONSTRAINT fk_booking_owner
    FOREIGN KEY(owner_id)
    REFERENCES users(id)
    ON DELETE CASCADE,

CONSTRAINT fk_booking_space
    FOREIGN KEY(property_id)
    REFERENCES spaces(id)
    ON DELETE CASCADE
```

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX idx_users_role ON users(role);

CREATE INDEX idx_spaces_owner ON spaces(owner_id);
CREATE INDEX idx_spaces_type ON spaces(type);
CREATE INDEX idx_spaces_status ON spaces(status);

CREATE INDEX idx_messages_sender ON messages(sender_id);
CREATE INDEX idx_messages_receiver ON messages(receiver_id);

CREATE INDEX idx_bookings_tenant ON bookings(tenant_id);
CREATE INDEX idx_bookings_owner ON bookings(owner_id);

-- ============================================================
-- DEFAULT ADMIN
-- Password = password
-- ============================================================

INSERT INTO users
(name,email,password,role,phone,status)
VALUES
(
'System Administrator',
'[admin@parknplace.com](mailto:admin@parknplace.com)',
'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
'admin',
'+919999999999',
'active'
);

COMMIT;
