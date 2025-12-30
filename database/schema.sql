-- Create Database
CREATE DATABASE IF NOT EXISTS ecommerce_db;
USE ecommerce_db;

-- 1. User Table
CREATE TABLE IF NOT EXISTS User (
    user_id INT AUTO_INCREMENT PRIMARY KEY, -- 使用者編號,自動編號
    username VARCHAR(50) NOT NULL UNIQUE, -- 使用者名稱,不可重複
    email VARCHAR(100) NOT NULL UNIQUE, -- 使用者信箱,不可重複
    password_hash VARCHAR(255) NOT NULL, -- 使用者密碼,存加密後雜湊值
    registration_date DATETIME DEFAULT CURRENT_TIMESTAMP -- 使用者註冊日期
) ENGINE=InnoDB; -- 使用InnoDB引擎

-- 2. Category Table
CREATE TABLE IF NOT EXISTS Category (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    parent_category_id INT,
    FOREIGN KEY (parent_category_id) REFERENCES Category(category_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3. Product Table
CREATE TABLE IF NOT EXISTS Product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,      -- 商品 ID (主鍵, 自動遞增)
    name VARCHAR(255) NOT NULL,                     -- 商品名稱 (必填)
    description TEXT,                               -- 商品描述 details
    price DECIMAL(10, 2) NOT NULL,                  -- 商品價格 (最多 10 位數, 包含 2 位小數)
    stock_quantity INT NOT NULL DEFAULT 0,          -- 庫存數量 (預設為 0)
    category_id INT,                                -- 分類 ID (外鍵)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,  -- 建立時間 (預設為當前時間)
    
    -- 外鍵關聯到 Category 表，當分類被刪除時，將此欄位設為 NULL (保留商品)
    FOREIGN KEY (category_id) REFERENCES Category(category_id) ON DELETE SET NULL,
    
    -- Index for filtering (過濾用索引)
    INDEX idx_price (price),                        -- 價格索引 (加快依價格查詢/排序的速度)
    INDEX idx_category (category_id),               -- 分類索引 (加快依分類篩選商品的速度)
    
    -- Fulltext index for search (全文搜尋索引)
    FULLTEXT INDEX idx_search (name, description)   -- 支援名稱與描述的全文關鍵字搜尋
) ENGINE=InnoDB;

-- 4. Cart Table
CREATE TABLE IF NOT EXISTS Cart (
    cart_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. CartItem Table
CREATE TABLE IF NOT EXISTS CartItem (
    cart_item_id INT AUTO_INCREMENT PRIMARY KEY,
    cart_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    FOREIGN KEY (cart_id) REFERENCES Cart(cart_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES Product(product_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. Order Table
CREATE TABLE IF NOT EXISTS `Order` (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10, 2) NOT NULL,
    status ENUM('PENDING', 'PROCESSING', 'SHIPPED', 'COMPLETED', 'CANCELLED') DEFAULT 'PENDING',
    shipping_address TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES User(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7. OrderItem Table
CREATE TABLE IF NOT EXISTS OrderItem (
    order_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price_snapshot DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES `Order`(order_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES Product(product_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8. OrderStatusHistory Table (for Audit)
CREATE TABLE IF NOT EXISTS OrderStatusHistory (
    history_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    old_status VARCHAR(20),
    new_status VARCHAR(20),
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES `Order`(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Trigger mechanism for OrderStatusHistory
DELIMITER //
CREATE TRIGGER trigger_order_status_update
AFTER UPDATE ON `Order`
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO OrderStatusHistory (order_id, old_status, new_status, changed_at)
        VALUES (NEW.order_id, OLD.status, NEW.status, NOW());
    END IF;
END;
//
DELIMITER ;

-- Optional: Seed data
INSERT INTO Category (category_name) VALUES ('Electronics'), ('Books'), ('Clothing');

INSERT INTO Product (name, description, price, stock_quantity, category_id) VALUES 
('Smartphone', 'Latest model smartphone with high res camera', 699.00, 50, 1),
('Laptop', 'High performance laptop for gaming', 1200.00, 20, 1),
('Novel', 'Best selling mystery novel', 15.00, 100, 2),
('T-Shirt', 'Cotton t-shirt', 20.00, 200, 3);

-- 9. ChatRoom Table (Added for Chat Feature)
CREATE TABLE IF NOT EXISTS ChatRoom (
    chat_room_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES `Order`(order_id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES User(user_id),
    FOREIGN KEY (seller_id) REFERENCES User(user_id),
    UNIQUE KEY unique_chat_per_order_seller (order_id, seller_id)
) ENGINE=InnoDB;

-- 10. ChatMessage Table (Added for Chat Feature)
CREATE TABLE IF NOT EXISTS ChatMessage (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    chat_room_id INT NOT NULL,
    sender_id INT, -- Nullable for SYSTEM messages
    message_type ENUM('TEXT', 'IMAGE', 'SYSTEM') DEFAULT 'TEXT',
    content TEXT,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chat_room_id) REFERENCES ChatRoom(chat_room_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES User(user_id),
    INDEX idx_chat_read (chat_room_id, is_read)
) ENGINE=InnoDB;
