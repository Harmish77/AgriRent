create table users
(
    user_id int AUTO_INCREMENT PRIMARY KEY,
    Name varchar(50) NOT NULL,
    Email VARCHAR(90) NOT NULL,
    Phone CHAR(10) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    User_type CHAR(1) NOT NULL CHECK(User_type IN ('O','F','A'))
    );


CREATE TABLE user_addresses (
    address_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(20) NOT NULL,
    state VARCHAR(25) NOT NULL,
    Pin_code CHAR(6) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE subscription_plans (
    plan_id INT AUTO_INCREMENT PRIMARY KEY,
    Plan_name VARCHAR(50) NOT NULL,
    Plan_type CHAR(1) NOT NULL,
    price DECIMAL(10,2) NOT NULL
);

CREATE TABLE user_subscriptions (
    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    Status CHAR(1) DEFAULT 'E',
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(plan_id)
);

CREATE TABLE payments (
    Payment_id INT AUTO_INCREMENT PRIMARY KEY,
    Subscription_id INT NOT NULL,
    Amount DECIMAL(10,2) NOT NULL,
    transaction_id VARCHAR(100) UNIQUE,
    Status CHAR(1) DEFAULT 'P',
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Subscription_id) REFERENCES user_subscriptions(subscription_id)
);

CREATE TABLE equipment_categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(50) NOT NULL,
    Description TEXT
);

CREATE TABLE equipment_subcategories (
    Subcategory_id INT AUTO_INCREMENT PRIMARY KEY,
    Category_id INT NOT NULL,
    Subcategory_name VARCHAR(70) NOT NULL,
    Description TEXT,
    FOREIGN KEY (Category_id) REFERENCES equipment_categories(category_id)
);

CREATE TABLE equipment (
    Equipment_id INT AUTO_INCREMENT PRIMARY KEY,
    Owner_id INT NOT NULL,
    Subcategories_id INT NOT NULL,
    Title VARCHAR(50) NOT NULL,
    Brand VARCHAR(50) NOT NULL,
    Model VARCHAR(50) NOT NULL,
    Year INT,
    Description TEXT NOT NULL,
    Hourly_rate DECIMAL(10,2),
    Daily_rate DECIMAL(10,2),
    listed_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    Approval_status CHAR(3) DEFAULT 'PEN',
    FOREIGN KEY (Owner_id) REFERENCES users(user_id),
    FOREIGN KEY (Subcategories_id) REFERENCES equipment_subcategories(Subcategory_id)
);

CREATE TABLE equipment_images (
    image_id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(Equipment_id)
);

CREATE TABLE equipment_bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    customer_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    Hours INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status CHAR(3) DEFAULT 'PEN',
    FOREIGN KEY (equipment_id) REFERENCES equipment(Equipment_id),
    FOREIGN KEY (customer_id) REFERENCES users(user_id)
);

CREATE TABLE product_categories (
    Category_id INT AUTO_INCREMENT PRIMARY KEY,
    Category_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT
);

CREATE TABLE product_subcategories (
    Subcategory_id INT AUTO_INCREMENT PRIMARY KEY,
    Category_id INT NOT NULL,
    Subcategory_name VARCHAR(70) NOT NULL,
    Description TEXT,
    FOREIGN KEY (Category_id) REFERENCES product_categories(Category_id)
);

CREATE TABLE product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    Subcategory_id INT NOT NULL,
    Name VARCHAR(50) NOT NULL,
    Description TEXT,
    Price DECIMAL(10,2) NOT NULL,
    Quantity DECIMAL(10,2) NOT NULL,
    Unit CHAR(1) NOT NULL,
    listed_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    Approval_status CHAR(3) DEFAULT 'PEN',
    FOREIGN KEY (seller_id) REFERENCES users(user_id),
    FOREIGN KEY (Subcategory_id) REFERENCES product_subcategories(Subcategory_id)
);

CREATE TABLE product_orders (
    Order_id INT AUTO_INCREMENT PRIMARY KEY,
    Product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    delivery_address INT NOT NULL,
    Status CHAR(3) DEFAULT 'PEN',
    order_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Product_id) REFERENCES product(product_id),
    FOREIGN KEY (buyer_id) REFERENCES users(user_id),
    FOREIGN KEY (delivery_address) REFERENCES user_addresses(address_id)
);

CREATE TABLE messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    Content TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(user_id),
    FOREIGN KEY (receiver_id) REFERENCES users(user_id)
);

CREATE TABLE reviews (
    Review_id INT AUTO_INCREMENT PRIMARY KEY,
    Reviewer_id INT NOT NULL,
    Review_type CHAR(1) NOT NULL,
    ID INT NOT NULL,
    Rating INT NOT NULL,
    comment TEXT,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Reviewer_id) REFERENCES users(user_id)
);

CREATE TABLE complaints (
    Complaint_id INT AUTO_INCREMENT PRIMARY KEY,
    User_id INT NOT NULL,
    Complaint_type CHAR(1) NOT NULL,
    ID INT NOT NULL,
    Description TEXT NOT NULL,
    Status CHAR(1) DEFAULT 'O',
    FOREIGN KEY (User_id) REFERENCES users(user_id)
);
