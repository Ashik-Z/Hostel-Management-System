DROP DATABASE IF EXISTS hostel_management;
CREATE DATABASE hostel_management;
USE hostel_management;

CREATE TABLE Phone (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    Phone VARCHAR(15) UNIQUE NOT NULL
);

CREATE TABLE User (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    Email VARCHAR(100) UNIQUE NOT NULL,
    Reg_Date DATE NOT NULL,
    Gender VARCHAR(20),
    D_birth DATE,
    Phone_ID INT,
    F_name VARCHAR(100) NOT NULL,
    M_name VARCHAR(100),
    L_name VARCHAR(100),
    Street VARCHAR(150),
    Area VARCHAR(100),
    Zip_code VARCHAR(10),
    
    FOREIGN KEY (Phone_ID) REFERENCES Phone(ID) ON DELETE SET NULL,
    INDEX idx_email (Email),
    INDEX idx_phone (Phone_ID)
);

CREATE TABLE Client (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    Student_ID INT NOT NULL,
    Guardian_name VARCHAR(100) NOT NULL,
    Guardian_Phone VARCHAR(15),
    
    FOREIGN KEY (Student_ID) REFERENCES User(ID) ON DELETE CASCADE,
    UNIQUE KEY unique_student_client (Student_ID),
    INDEX idx_student (Student_ID)
);

CREATE TABLE Manager (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    Salary DECIMAL(10, 2),
    Hire_date DATE NOT NULL,
    INDEX idx_hire_date (Hire_date)
);

CREATE TABLE Notice_board (
    Notice_ID INT PRIMARY KEY AUTO_INCREMENT,
    Description TEXT,
    Title VARCHAR(200) NOT NULL,
    Date DATE NOT NULL,
    Expiry_date DATE,
    Manager_ID INT NOT NULL,
    
    FOREIGN KEY (Manager_ID) REFERENCES Manager(ID) ON DELETE RESTRICT,
    INDEX idx_manager (Manager_ID),
    INDEX idx_date (Date),
    INDEX idx_expiry (Expiry_date)
);

CREATE TABLE Visitors_log (
    Visitor_ID INT PRIMARY KEY AUTO_INCREMENT,
    Client_id INT NOT NULL,
    Visitor_Name VARCHAR(100),
    Visitor_Phone VARCHAR(15),
    Entry_time TIME,
    Exit_time TIME,
    
    FOREIGN KEY (Client_id) REFERENCES Client(ID) ON DELETE CASCADE,
    INDEX idx_client (Client_id)
);

CREATE TABLE Room_Swap_Req (
    Swap_ID INT PRIMARY KEY AUTO_INCREMENT,
    Manager_ID INT NOT NULL,
    Request_Date DATE NOT NULL,
    Reason VARCHAR(255),
    Swap_status VARCHAR(50),
    
    FOREIGN KEY (Manager_ID) REFERENCES Manager(ID) ON DELETE RESTRICT,
    INDEX idx_manager (Manager_ID),
    INDEX idx_status (Swap_status),
    INDEX idx_request_date (Request_Date)
);

CREATE TABLE Payment_Record (
    TX_ID INT PRIMARY KEY AUTO_INCREMENT,
    Payment_Amount DECIMAL(10, 2) NOT NULL,
    Payment_Method VARCHAR(50),
    Payment_Date DATE NOT NULL,
    Student_ID INT NOT NULL,
    Client_ID INT,
    
    FOREIGN KEY (Student_ID) REFERENCES User(ID) ON DELETE CASCADE,
    FOREIGN KEY (Client_ID) REFERENCES Client(ID) ON DELETE SET NULL,
    INDEX idx_student (Student_ID),
    INDEX idx_client (Client_ID),
    INDEX idx_payment_date (Payment_Date)
);

CREATE TABLE Meal_Booking (
    Meal_Booking_ID INT PRIMARY KEY AUTO_INCREMENT,
    Type VARCHAR(50),
    Date DATE NOT NULL,
    Total_cost DECIMAL(10, 2),
    Client_ID INT NOT NULL,
    
    FOREIGN KEY (Client_ID) REFERENCES Client(ID) ON DELETE CASCADE,
    INDEX idx_client (Client_ID),
    INDEX idx_date (Date)
);

CREATE TABLE Complaints (
    Complaint_ID INT PRIMARY KEY AUTO_INCREMENT,
    Complaint_Date DATE NOT NULL,
    Complaint_Text TEXT NOT NULL,
    Status VARCHAR(50),
    Manager_ID INT NOT NULL,
    Client_ID INT NOT NULL,
    
    FOREIGN KEY (Manager_ID) REFERENCES Manager(ID) ON DELETE RESTRICT,
    FOREIGN KEY (Client_ID) REFERENCES Client(ID) ON DELETE CASCADE,
    INDEX idx_manager (Manager_ID),
    INDEX idx_client (Client_ID),
    INDEX idx_status (Status),
    INDEX idx_complaint_date (Complaint_Date)
);

CREATE TABLE Accountings (
    Package_ID INT PRIMARY KEY AUTO_INCREMENT,
    Package_Name VARCHAR(100) NOT NULL,
    Room_Type VARCHAR(50),
    Duration INT,
    Price DECIMAL(10, 2),
    Manager_ID INT NOT NULL,
    
    FOREIGN KEY (Manager_ID) REFERENCES Manager(ID) ON DELETE RESTRICT,
    INDEX idx_manager (Manager_ID),
    INDEX idx_room_type (Room_Type)
);

CREATE TABLE Room (
    Floor_num INT NOT NULL,
    Room_num INT NOT NULL,
    Status VARCHAR(50),
    Capacity INT,
    Room_type VARCHAR(50),
    
    PRIMARY KEY (Floor_num, Room_num),
    INDEX idx_status (Status),
    INDEX idx_room_type (Room_type)
);

CREATE TABLE Stays_IN (
    client_id INT,
    Room_Num INT,
    Floor_NUM INT,
    
    PRIMARY KEY (client_id, Room_Num, Floor_NUM),
    FOREIGN KEY (client_id) REFERENCES Client(ID) ON DELETE CASCADE,
    FOREIGN KEY (Floor_NUM, Room_Num) REFERENCES Room(Floor_num, Room_num) ON DELETE CASCADE,
    INDEX idx_client (client_id)
);

CREATE TABLE Booking_Allocation (
    Booking_ID INT PRIMARY KEY AUTO_INCREMENT,
    Bed_num INT,
    Booking_status VARCHAR(50),
    Booking_date DATE NOT NULL,
    Check_in_date DATE,
    Check_out_time TIME,
    client_id INT NOT NULL,
    
    FOREIGN KEY (client_id) REFERENCES Client(ID) ON DELETE CASCADE,
    INDEX idx_client (client_id),
    INDEX idx_status (Booking_status),
    INDEX idx_booking_date (Booking_date)
);
