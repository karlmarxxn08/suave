DROP DATABASE IF EXISTS milktea_pos;
CREATE DATABASE milktea_pos;
USE milktea_pos;

-- ==========================================
-- 1. BASE SYSTEM TABLES (CORE OPERATIONAL)
-- ==========================================

-- Available Menu Products
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    base_price DECIMAL(10, 2) NOT NULL,
    category VARCHAR(50) DEFAULT 'Milk Tea'
);

-- Master Orders Table
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    total_amount DECIMAL(10, 2) NOT NULL,
    cash_received DECIMAL(10, 2) NOT NULL,
    change_amount DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Order Breakdown Line Items
CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    size VARCHAR(10) NOT NULL,          -- 'Regular' or 'Large'
    sugar_level VARCHAR(10) NOT NULL,   -- '0%', '50%', '100%'
    addons_json TEXT,                   -- Stores arrays like ["Pearls", "Pudding"]
    subtotal DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);


-- ==========================================
-- 2. INVENTORY & STOCK MANAGEMENT TABLES 
-- ==========================================

-- Raw Ingredients Stock Registry
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(100) NOT NULL,
    stock_quantity DECIMAL(10, 2) NOT NULL, -- e.g., 5000.00 grams or 200.00 pieces
    unit VARCHAR(20) NOT NULL                -- 'grams', 'pieces', 'ml'
);

-- Recipe Mapping Table (Links a menu item choice to raw ingredients)
CREATE TABLE product_recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    inventory_id INT NOT NULL,
    usage_amount DECIMAL(10, 2) NOT NULL,   -- Amount deducted per cup served
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
);


-- ==========================================
-- 3. SEED INITIAL OPERATIONAL DATA
-- ==========================================

-- Populate Expanded Menu Product Varieties
INSERT INTO products (name, base_price, category) VALUES 
('Classic Milk Tea', 90.00, 'Milk Tea'),
('Taro Milk Tea', 100.00, 'Milk Tea'),
('Okinawa Milk Tea', 105.00, 'Milk Tea'),
('Hokkaido Milk Tea', 105.00, 'Milk Tea'),
('Wintermelon Milk Tea', 95.00, 'Milk Tea'),
('Matcha Latte', 110.00, 'Rock Salt Cheese'),
('Cocoa Rock Salt Cheese', 115.00, 'Rock Salt Cheese'),
('Strawberry Fruit Tea', 95.00, 'Fruit Tea'),
('Mango Passion Fruit Tea', 100.00, 'Fruit Tea'),
('Brown Sugar Boba', 120.00, 'Milk Tea');

-- Populate Raw Materials Inventory (Initial Stock Setup)
INSERT INTO inventory (item_name, stock_quantity, unit) VALUES
('Classic Tea Base', 10000.00, 'ml'),     -- 10 Liters prepared liquid
('Taro Powder', 2500.00, 'grams'),         -- 2.5 kg powder container
('Brown Sugar Syrup', 3000.00, 'ml'),     -- 3 Liters syrup bottles
('Tapioca Pearls (Cooked)', 4000.00, 'grams'), 
('Cream Cheese Foam', 2000.00, 'ml'),
('Fruit Tea Syrup base', 5000.00, 'ml'),
('Regular Cups 16oz', 500.00, 'pieces'),
('Large Cups 22oz', 500.00, 'pieces'),
('Boba Straws', 1000.00, 'pieces');

-- Map Recipes (Example: Linking Menu Products to Inventory Deductions)
INSERT INTO product_recipes (product_id, inventory_id, usage_amount) VALUES
-- Classic Milk Tea uses: 1 16oz Cup, 1 Straw, 200ml Tea Base
(1, 7, 1.00),   
(1, 9, 1.00),   
(1, 1, 200.00),

-- Taro Milk Tea uses: 1 16oz Cup, 1 Straw, 150ml Tea Base, 35g Taro Powder
(2, 7, 1.00),
(2, 9, 1.00),
(2, 1, 150.00),
(2, 2, 35.00),

-- Brown Sugar Boba uses: 1 16oz Cup, 1 Straw, 50g Pearls, 40ml Brown Sugar Syrup
(10, 7, 1.00),
(10, 9, 1.00),
(10, 4, 50.00),
(10, 3, 40.00);