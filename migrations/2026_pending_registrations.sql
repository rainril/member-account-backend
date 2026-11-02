-- Run against the member database before deploying the pending registration flow.
-- Application details are held separately until an administrator approves them.
CREATE TABLE IF NOT EXISTS PendingRegistrations (
    RegistrationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    FirstName VARCHAR(50) NOT NULL,
    LastName VARCHAR(50) NOT NULL,
    Email VARCHAR(100) NOT NULL,
    PasswordHash VARCHAR(255) NOT NULL,
    Phone VARCHAR(20) NULL,
    DateOfBirth DATE NULL,
    Gender ENUM('Male', 'Female', 'Other') NULL,
    Address VARCHAR(255) NULL,
    PlanID INT NOT NULL,
    Amount DECIMAL(10,2) NOT NULL,
    PaymentMethod VARCHAR(50) NOT NULL,
    Receipt MEDIUMBLOB NOT NULL,
    ReceiptMime VARCHAR(32) NOT NULL,
    Status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ReviewedAt TIMESTAMP NULL DEFAULT NULL,
    MemberID INT NULL,
    UNIQUE KEY pending_registrations_email_unique (Email),
    KEY pending_registrations_status_index (Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
