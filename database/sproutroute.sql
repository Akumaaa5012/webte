CREATE DATABASE IF NOT EXISTS sproutroute
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sproutroute;

-- 1. Users
CREATE TABLE user (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,

    PRIMARY KEY (user_id),
    UNIQUE KEY uq_user_username (username),
    UNIQUE KEY uq_user_email (email),

    CONSTRAINT chk_user_role CHECK (role IN ('farmer', 'restaurant', 'logistics'))
) ENGINE=InnoDB;

-- 2. Categories
CREATE TABLE categories (
   category_id INT NOT NULL AUTO_INCREMENT,
   name VARCHAR(50) NOT NULL,

   PRIMARY KEY (category_id),
   UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB;

-- 3. Farms
CREATE TABLE farms (
   farm_id INT NOT NULL AUTO_INCREMENT,
   user_id INT NOT NULL,
   farm_name VARCHAR(100) NOT NULL,
   owner_name VARCHAR(100) NOT NULL,
   contact_no VARCHAR(15) NOT NULL,
   address VARCHAR(255) NOT NULL,

   PRIMARY KEY (farm_id),
   UNIQUE KEY uq_farms_user (user_id),

   CONSTRAINT fk_farms_user FOREIGN KEY (user_id)
   REFERENCES user (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 4. Restaurants
CREATE TABLE restaurants (
   restaurant_id INT NOT NULL AUTO_INCREMENT,
   user_id INT NOT NULL,
   res_name VARCHAR(100) NOT NULL,
   res_owner_name VARCHAR(100) NOT NULL,
   res_contact_no VARCHAR(15) NOT NULL,
   res_address VARCHAR(255) NOT NULL,

   PRIMARY KEY (restaurant_id),
   UNIQUE KEY uq_restaurants_user (user_id),

   CONSTRAINT fk_restaurants_user FOREIGN KEY (user_id)
   REFERENCES user (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 5. Logistics providers
CREATE TABLE logistics_providers (
   provider_id INT NOT NULL AUTO_INCREMENT,
   user_id INT DEFAULT NULL,
   farm_id INT DEFAULT NULL,
   provider_name VARCHAR(100) NOT NULL,
   provider_type VARCHAR(20) NOT NULL,
   contact_no VARCHAR(15) NOT NULL,

   PRIMARY KEY (provider_id),
   KEY idx_provider_user (user_id),
   KEY idx_provider_farm (farm_id),

   CONSTRAINT chk_provider_type CHECK (provider_type IN ('third_party', 'farm_managed', 'farm')),
   CONSTRAINT fk_provider_user FOREIGN KEY (user_id)
   REFERENCES user (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
   CONSTRAINT fk_provider_farm FOREIGN KEY (farm_id)
   REFERENCES farms (farm_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 6. Products
CREATE TABLE products (
    product_id INT NOT NULL AUTO_INCREMENT,
    farm_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    price_per_kg DECIMAL(10,2) NOT NULL,
    min_order_kg DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    stock_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    harvest_date DATE NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    KEY idx_product_farm (farm_id),
    KEY idx_product_category (category_id),

    CONSTRAINT chk_product_price CHECK (price_per_kg >= 0),
    CONSTRAINT chk_product_min_order CHECK (min_order_kg > 0),
    CONSTRAINT chk_product_stock CHECK (stock_kg >= 0),
    CONSTRAINT fk_product_farm FOREIGN KEY (farm_id)
    REFERENCES farms (farm_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id)
    REFERENCES categories (category_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 7. Recurring schedules
CREATE TABLE recurring_schedules (
   schedule_id INT NOT NULL AUTO_INCREMENT,
   restaurant_id INT NOT NULL,
   farm_id INT NOT NULL,
   frequency VARCHAR(20) NOT NULL,
   preferred_day VARCHAR(15) DEFAULT NULL,
   preferred_time TIME NOT NULL,
   start_date DATE NOT NULL,
   end_date DATE DEFAULT NULL,
   next_delivery_date DATE NOT NULL,
   status VARCHAR(20) NOT NULL DEFAULT 'active',

   PRIMARY KEY (schedule_id),
   KEY idx_schedule_restaurant (restaurant_id),
   KEY idx_schedule_farm (farm_id),

   CONSTRAINT chk_schedule_frequency CHECK (frequency IN ('daily', 'weekly', 'monthly')),
   CONSTRAINT chk_schedule_status CHECK (status IN ('active', 'paused', 'cancelled', 'completed')),
   CONSTRAINT chk_schedule_dates CHECK (end_date IS NULL OR end_date >= start_date),
   CONSTRAINT fk_schedule_restaurant FOREIGN KEY (restaurant_id)
   REFERENCES restaurants (restaurant_id) ON DELETE RESTRICT ON UPDATE CASCADE,
   CONSTRAINT fk_schedule_farm FOREIGN KEY (farm_id)
   REFERENCES farms (farm_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 8. Orders
CREATE TABLE orders (
   order_id INT NOT NULL AUTO_INCREMENT,
   client_uuid CHAR(36) NOT NULL,
   restaurant_id INT NOT NULL,
   farm_id INT NOT NULL,
   schedule_id INT DEFAULT NULL,
   status VARCHAR(20) NOT NULL DEFAULT 'pending',
   total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
   requested_date DATE NOT NULL,
   ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   synced_at DATETIME DEFAULT NULL,
   stock_deducted TINYINT(1) NOT NULL DEFAULT 0,

   PRIMARY KEY (order_id),
   UNIQUE KEY uq_orders_client_uuid (client_uuid),
   KEY idx_orders_restaurant (restaurant_id),
   KEY idx_orders_farm (farm_id),
   KEY idx_orders_schedule (schedule_id),

   CONSTRAINT chk_orders_status CHECK (status IN ('pending', 'confirmed', 'rejected', 'cancelled', 'in_transit', 'delivered', 'completed')),
   CONSTRAINT chk_orders_total CHECK (total_amount >= 0),
   CONSTRAINT chk_stock_deducted CHECK (stock_deducted IN (0, 1)),
   CONSTRAINT fk_orders_restaurant FOREIGN KEY (restaurant_id)
   REFERENCES restaurants (restaurant_id) ON DELETE RESTRICT ON UPDATE CASCADE,
   CONSTRAINT fk_orders_farm FOREIGN KEY (farm_id)
   REFERENCES farms (farm_id) ON DELETE RESTRICT ON UPDATE CASCADE,
   CONSTRAINT fk_orders_schedule FOREIGN KEY (schedule_id)
   REFERENCES recurring_schedules (schedule_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 9. Recurring items
CREATE TABLE recurring_items (
   recurring_item_id INT NOT NULL AUTO_INCREMENT,
   schedule_id INT NOT NULL,
   product_id INT NOT NULL,
   quantity_kg DECIMAL(10,2) NOT NULL,

   PRIMARY KEY (recurring_item_id),
   UNIQUE KEY uq_recurring_schedule_product (schedule_id, product_id),
   KEY idx_recurring_product (product_id),

   CONSTRAINT chk_recurring_qty CHECK (quantity_kg > 0),
   CONSTRAINT fk_recurring_item_schedule FOREIGN KEY (schedule_id)
   REFERENCES recurring_schedules (schedule_id) ON DELETE CASCADE ON UPDATE CASCADE,
   CONSTRAINT fk_recurring_item_product FOREIGN KEY (product_id)
   REFERENCES products (product_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 10. Order items
CREATE TABLE order_items (
    item_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_kg DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,

    PRIMARY KEY (item_id),
    UNIQUE KEY uq_order_product (order_id, product_id),
    KEY idx_order_item_product (product_id),

    CONSTRAINT chk_order_item_qty CHECK (quantity_kg > 0),
    CONSTRAINT chk_order_item_price CHECK (unit_price >= 0),
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id)
    REFERENCES orders (order_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_item_product FOREIGN KEY (product_id)
    REFERENCES products (product_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 11. Order status log
CREATE TABLE order_status_log (
    log_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (log_id),
    KEY idx_order_log_order (order_id),

    CONSTRAINT chk_order_log_status CHECK (status IN ('pending', 'confirmed', 'rejected', 'cancelled', 'in_transit', 'delivered', 'completed')),
    CONSTRAINT fk_order_log_order FOREIGN KEY (order_id)
    REFERENCES orders (order_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- 12. Deliveries
CREATE TABLE deliveries (
    delivery_id INT NOT NULL AUTO_INCREMENT,
    order_id INT NOT NULL,
    provider_id INT DEFAULT NULL,
    delivery_lat DECIMAL(9,6) DEFAULT NULL,
    delivery_lng DECIMAL(9,6) DEFAULT NULL,
    pickup_at DATETIME DEFAULT NULL,
    estimated_arrival DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    PRIMARY KEY (delivery_id),
    UNIQUE KEY uq_delivery_order (order_id),
    KEY idx_delivery_provider (provider_id),
    CONSTRAINT chk_delivery_lat CHECK (delivery_lat IS NULL OR delivery_lat BETWEEN -90 AND 90),
    CONSTRAINT chk_delivery_lng CHECK (delivery_lng IS NULL OR delivery_lng BETWEEN -180 AND 180),
    CONSTRAINT chk_delivery_status CHECK (status IN ('pending', 'assigned', 'picked_up', 'in_transit', 'delivered', 'cancelled', 'failed')),
    CONSTRAINT fk_delivery_order FOREIGN KEY (order_id)
    REFERENCES orders (order_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_delivery_provider FOREIGN KEY (provider_id)
    REFERENCES logistics_providers (provider_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;


INSERT INTO user (user_id, username, email, password, role) VALUES
    (1, 'farmer_benguet',   'farmer1@example.test', '$2y$10$KK1aLYoVSYpW5fZgIQHV5Ohxk09yONLSES48sgjtc1xx1VQ5614Ae', 'farmer'),
    (2, 'farmer_mountain',  'farmer2@example.test', '$2y$10$KK1aLYoVSYpW5fZgIQHV5Ohxk09yONLSES48sgjtc1xx1VQ5614Ae', 'farmer'),
    (3, 'resto_baguio',     'resto@example.test',   '$2y$10$KK1aLYoVSYpW5fZgIQHV5Ohxk09yONLSES48sgjtc1xx1VQ5614Ae', 'restaurant'),
    (4, 'logistics_demo',   'driver@example.test',  '$2y$10$KK1aLYoVSYpW5fZgIQHV5Ohxk09yONLSES48sgjtc1xx1VQ5614Ae', 'logistics'),
    (5, 'resto_second',     'resto2@example.test',  '$2y$10$KK1aLYoVSYpW5fZgIQHV5Ohxk09yONLSES48sgjtc1xx1VQ5614Ae', 'restaurant'),
    (6, 'logistics_second', 'driver2@example.test', '$2y$10$KK1aLYoVSYpW5fZgIQHV5Ohxk09yONLSES48sgjtc1xx1VQ5614Ae', 'logistics');

INSERT INTO categories (category_id, name) VALUES
    (1, 'Vegetables'),
    (2, 'Leafy Greens'),
    (3, 'Root Crops'),
    (6, 'Fruits'),
    (9, 'Spices and herbs');

INSERT INTO farms (farm_id, user_id, farm_name, owner_name, contact_no, address) VALUES
    (1, 1, 'Benguet Fresh Farm', 'Juan Cruz', '09123456789', 'La Trinidad, Benguet'),
    (2, 2, 'Mountain Harvest Farm', 'Maria Santos', '09987654321', 'Atok, Benguet');

INSERT INTO restaurants (restaurant_id, user_id, res_name, res_owner_name, res_contact_no, res_address) VALUES
    (1, 5, 'Pine City Bistro', 'Carla Dela Cruz', '09170000005', 'Baguio City'),
    (2, 3, 'Baguio Kitchen', 'Ana Reyes', '09112223334', 'Baguio City');

INSERT INTO logistics_providers (provider_id, user_id, farm_id, provider_name, provider_type, contact_no) VALUES
    (1, 6, NULL, 'Cordillera Produce Express', 'third_party', '09170000006'),
    (2, 4, NULL, 'Baguio Delivery Service', 'third_party', '09175556666');

INSERT INTO products
(product_id, farm_id, category_id, name, price_per_kg, min_order_kg, stock_kg, harvest_date, photo) VALUES
    (1, 1, 1, 'Carrots',     80.00, 2.00, 50.00, '2026-10-25', NULL),
    (2, 1, 1, 'Tomatoes',    90.00, 2.00, 40.00, '2026-10-25', NULL),
    (3, 1, 3, 'Potato',      65.00, 2.00, 60.00, '2026-10-25', NULL),
    (4, 2, 2, 'Lettuce',     70.00, 1.00, 30.00, '2026-10-25', NULL),
    (5, 2, 6, 'Strawberry', 180.00, 1.00, 20.00, '2026-10-25', NULL),
    (6, 2, 9, 'Ginger',     120.00, 1.00, 25.00, '2026-10-25', NULL);

