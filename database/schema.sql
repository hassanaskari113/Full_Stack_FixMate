-- =====================================================================
-- FixMate database schema  (MySQL 8.0.16+)
-- 13 tables: 11 original entities + 2 many-to-many junction tables
-- WARNING: this file DROPS and recreates all tables (dev convenience).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS review_images;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS offers;
DROP TABLE IF EXISTS request_images;
DROP TABLE IF EXISTS service_requests;
DROP TABLE IF EXISTS provider_profile_service_areas;
DROP TABLE IF EXISTS provider_profile_services;
DROP TABLE IF EXISTS provider_profiles;
DROP TABLE IF EXISTS service_areas;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS service_categories;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. users   (customers, providers and admins)
--    role: C = Customer, P = Provider.  Admin access uses is_admin.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(150)    NOT NULL,
    first_name      VARCHAR(150)    NOT NULL DEFAULT '',
    last_name       VARCHAR(150)    NOT NULL DEFAULT '',
    email           VARCHAR(254)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    profile_picture VARCHAR(255)    NOT NULL DEFAULT '',
    phone_number    VARCHAR(20)     NULL DEFAULT NULL,
    role            CHAR(1)         NOT NULL DEFAULT 'C',
    is_admin        TINYINT(1)      NOT NULL DEFAULT 0,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_login      DATETIME        NULL DEFAULT NULL,
    joined_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone_number),
    CONSTRAINT chk_users_role CHECK (role IN ('C', 'P'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. service_categories
-- ---------------------------------------------------------------------
CREATE TABLE service_categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)     NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. services
-- ---------------------------------------------------------------------
CREATE TABLE services (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(50)     NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_services_name (name),
    KEY idx_services_category (category_id),
    CONSTRAINT fk_services_category
        FOREIGN KEY (category_id) REFERENCES service_categories (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. service_areas
-- ---------------------------------------------------------------------
CREATE TABLE service_areas (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(50)     NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_areas_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. provider_profiles   (1:1 with users)
--    verification_status: P = Pending, A = Approved, R = Rejected
-- ---------------------------------------------------------------------
CREATE TABLE provider_profiles (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT UNSIGNED NOT NULL,
    bio                 TEXT            NOT NULL DEFAULT (''),
    experience          INT UNSIGNED    NOT NULL,
    verification_status CHAR(1)         NOT NULL DEFAULT 'P',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_provider_user (user_id),
    KEY idx_provider_status (verification_status),
    CONSTRAINT fk_provider_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_provider_status CHECK (verification_status IN ('P', 'A', 'R'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. provider_profile_services   (many-to-many junction)
-- ---------------------------------------------------------------------
CREATE TABLE provider_profile_services (
    provider_profile_id BIGINT UNSIGNED NOT NULL,
    service_id          BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (provider_profile_id, service_id),
    KEY idx_pps_service (service_id),
    CONSTRAINT fk_pps_provider
        FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_pps_service
        FOREIGN KEY (service_id) REFERENCES services (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. provider_profile_service_areas   (many-to-many junction)
-- ---------------------------------------------------------------------
CREATE TABLE provider_profile_service_areas (
    provider_profile_id BIGINT UNSIGNED NOT NULL,
    service_area_id     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (provider_profile_id, service_area_id),
    KEY idx_ppa_area (service_area_id),
    CONSTRAINT fk_ppa_provider
        FOREIGN KEY (provider_profile_id) REFERENCES provider_profiles (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ppa_area
        FOREIGN KEY (service_area_id) REFERENCES service_areas (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. service_requests
--    status: O = Open, C = Closed, E = Expired
-- ---------------------------------------------------------------------
CREATE TABLE service_requests (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    description TEXT            NOT NULL,
    min_budget  DECIMAL(7,2)    NOT NULL,
    max_budget  DECIMAL(7,2)    NOT NULL,
    urgency     DATETIME        NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    status      CHAR(1)         NOT NULL DEFAULT 'O',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    location    TEXT            NOT NULL,
    PRIMARY KEY (id),
    KEY idx_requests_customer (customer_id),
    KEY idx_requests_status_category (status, category_id),
    KEY idx_requests_category (category_id),
    CONSTRAINT fk_requests_customer
        FOREIGN KEY (customer_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_requests_category
        FOREIGN KEY (category_id) REFERENCES service_categories (id)
        ON DELETE CASCADE,
    CONSTRAINT chk_requests_status CHECK (status IN ('O', 'C', 'E'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. request_images
-- ---------------------------------------------------------------------
CREATE TABLE request_images (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    image      VARCHAR(255)    NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_request_images_request (request_id),
    CONSTRAINT fk_request_images_request
        FOREIGN KEY (request_id) REFERENCES service_requests (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. offers
--     status: P = Pending, A = Accepted, R = Rejected, E = Expired
-- ---------------------------------------------------------------------
CREATE TABLE offers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_request_id  BIGINT UNSIGNED NOT NULL,
    service_provider_id BIGINT UNSIGNED NOT NULL,
    price               DECIMAL(7,2)    NOT NULL,
    arrival_time        DATETIME        NOT NULL,
    status              CHAR(1)         NOT NULL DEFAULT 'P',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY one_offer_per_request (service_request_id, service_provider_id),
    KEY idx_offers_provider (service_provider_id),
    CONSTRAINT fk_offers_request
        FOREIGN KEY (service_request_id) REFERENCES service_requests (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_offers_provider
        FOREIGN KEY (service_provider_id) REFERENCES provider_profiles (id)
        ON DELETE CASCADE,
    CONSTRAINT positive_price CHECK (price > 0),
    CONSTRAINT chk_offers_status CHECK (status IN ('P', 'A', 'R', 'E'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. bookings   (1:1 with offers)
--     status: CONF = Confirmed, COMP = Completed, CANC = Cancelled
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id            BIGINT UNSIGNED NOT NULL,
    scheduled_time      DATETIME        NOT NULL,
    final_price         DECIMAL(7,2)    NOT NULL,
    status              VARCHAR(4)      NOT NULL DEFAULT 'CONF',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancellation_reason TEXT            NOT NULL DEFAULT (''),
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_offer (offer_id),
    KEY idx_bookings_status (status),
    CONSTRAINT fk_bookings_offer
        FOREIGN KEY (offer_id) REFERENCES offers (id)
        ON DELETE CASCADE,
    CONSTRAINT positive_final_price CHECK (final_price > 0),
    CONSTRAINT chk_bookings_status CHECK (status IN ('CONF', 'COMP', 'CANC'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. reviews   (1:1 with bookings)
-- ---------------------------------------------------------------------
CREATE TABLE reviews (
    id         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED  NOT NULL,
    text       TEXT             NOT NULL,
    rating     TINYINT UNSIGNED NOT NULL,
    created_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reviews_booking (booking_id),
    CONSTRAINT fk_reviews_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE CASCADE,
    CONSTRAINT rating_between_1_and_5 CHECK (rating >= 1 AND rating <= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. review_images
-- ---------------------------------------------------------------------
CREATE TABLE review_images (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    image      VARCHAR(255)    NOT NULL,
    review_id  BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_review_images_review (review_id),
    CONSTRAINT fk_review_images_review
        FOREIGN KEY (review_id) REFERENCES reviews (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
