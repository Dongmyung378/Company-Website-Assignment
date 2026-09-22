<?php
require __DIR__ . '/../../config/connection.php';

$tablesql1 = "
    CREATE TABLE IF NOT EXISTS delivery_driver (
        employee_id INT,
        delivery_region VARCHAR(100) NOT NULL,
        PRIMARY KEY (employee_id),
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
    );
";
$conn->query($tablesql1);

$tablesql2 = "
    CREATE TABLE IF NOT EXISTS center (
        office_id INT,
        storage_capacity INT NOT NULL,
        PRIMARY KEY (office_id),
        FOREIGN KEY (office_id) REFERENCES office(office_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
    );
";
$conn->query($tablesql2);

$tablesql3 = "
    CREATE TABLE IF NOT EXISTS office_detail (
        office_id INT,
        office_description VARCHAR(255) NOT NULL,
        office_address VARCHAR(100),
        office_phone VARCHAR(100),
        office_website VARCHAR(100),
        PRIMARY KEY (office_id),
        FOREIGN KEY (office_id) REFERENCES office(office_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
    );
";
$conn->query($tablesql3);

$tablesql4 = "
    CREATE TABLE IF NOT EXISTS product (
        product_uniq_id VARCHAR(100) PRIMARY KEY,
        product_name VARCHAR(100) NOT NULL
    );
";
$conn->query($tablesql4);

$tablesql5 = "
    CREATE TABLE IF NOT EXISTS product_detail (
        product_uniq_id VARCHAR(100),
        manufacturer VARCHAR(100),
        product_price FLOAT,
        category_and_subcategory VARCHAR(100),
        product_description VARCHAR(255),
        PRIMARY KEY (product_uniq_id),
        FOREIGN KEY (product_uniq_id) REFERENCES product(product_uniq_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
    );
";
$conn->query($tablesql5);

$tablesql6 = "
    CREATE TABLE IF NOT EXISTS center_product (
        center_id INT,
        product_uniq_id VARCHAR(100),
        product_quantity INT,
        PRIMARY KEY (center_id, product_uniq_id), 
        FOREIGN KEY (center_id) REFERENCES center(office_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (product_uniq_id) REFERENCES product(product_uniq_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql6);

$tablesql7 = "
    CREATE TABLE IF NOT EXISTS vehicle (
        vehicle_id INT AUTO_INCREMENT PRIMARY KEY, 
        vehicle_type VARCHAR(100),
        registration_number VARCHAR(50) NOT NULL 
    );
";
$conn->query($tablesql7);

$tablesql8 = "
    CREATE TABLE IF NOT EXISTS customer (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(30) NOT NULL,
        phone_number VARCHAR(30),
        email_address VARCHAR(100) NOT NULL
    );
";
$conn->query($tablesql8);

$tablesql9 = "
    CREATE TABLE IF NOT EXISTS order_address (
        address_id INT AUTO_INCREMENT PRIMARY KEY,
        street VARCHAR(30) NOT NULL,
        city VARCHAR(30) NOT NULL,
        post_code VARCHAR(15) NOT NULL
    );
";
$conn->query($tablesql9);

$tablesql10 = "
    CREATE TABLE IF NOT EXISTS `order` (
        order_id INT AUTO_INCREMENT PRIMARY KEY, 
        order_date DATE NOT NULL,
        order_time TIME NOT NULL, 
        order_status ENUM('Pending', 'Paid', 'Rejected') NOT NULL DEFAULT 'Pending', 
        address_id INT NOT NULL, 
        customer_id INT NOT NULL,
        FOREIGN KEY (address_id) REFERENCES order_address(address_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql10);

$tablesql11 = "
    CREATE TABLE IF NOT EXISTS card_issuer (
        issuer_id INT AUTO_INCREMENT PRIMARY KEY,
        issuer_name VARCHAR(30 ) NOT NULL
    );
";
$conn->query($tablesql11);

$tablesql12 = "
    CREATE TABLE IF NOT EXISTS payment (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        amount INT NOT NULL,
        order_id INT NOT NULL,
        issuer_id INT NOT NULL,
        FOREIGN KEY (order_id) REFERENCES `order`(order_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (issuer_id) REFERENCES card_issuer(issuer_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql12);

$tablesql13 = "
    CREATE TABLE IF NOT EXISTS payment (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        amount DECIMAL(10, 2) NOT NULL,
        order_id INT NOT NULL,
        customer_id INT NOT NULL,
        FOREIGN KEY (order_id) REFERENCES `order`(Order_ID)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customer(Customer_ID)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql13);

$tablesql14 = "
    CREATE TABLE IF NOT EXISTS question (
        question_id INT AUTO_INCREMENT PRIMARY KEY,
        question_text VARCHAR(255) NOT NULL,
        customer_id INT NOT NULL,
        product_uniq_id VARCHAR(100),
        FOREIGN KEY (customer_id) REFERENCES customer(customer_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (product_uniq_id) REFERENCES product(product_uniq_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql14);

$tablesql15 = "
    CREATE TABLE IF NOT EXISTS answer (
        question_id INT NOT NULL,
        answer VARCHAR(255) NOT NULL,
        employee_id INT NOT NULL,
        FOREIGN KEY (question_id) REFERENCES question(question_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql15);

$tablesql16 = "
    CREATE TABLE IF NOT EXISTS order_detail (
        order_detail_id INT AUTO_INCREMENT PRIMARY KEY AUTO_INCREMENT,
        product_quantity INT NOT NULL,
        order_id INT NOT NULL,
        product_uniq_id VARCHAR(100) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES `order`(order_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (product_uniq_id) REFERENCES product(product_uniq_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql16);

$tablesql17 = "
    CREATE TABLE IF NOT EXISTS delivery (
        delivery_id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_date DATE NOT NULL,
        delivery_time TIME NOT NULL,
        delivery_status ENUM('Pending', 'In Transit', 'Delivered', 'Cancelled') NOT NULL DEFAULT 'Pending',
        delivery_rating FLOAT,
        delivery_review VARCHAR(255),
        driver_id INT NOT NULL,
        order_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        FOREIGN KEY (driver_id) REFERENCES delivery_driver(employee_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (order_id) REFERENCES `order`(order_id) 
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (vehicle_id) REFERENCES vehicle(vehicle_id) 
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";
$conn->query($tablesql17);

$conn->close();
