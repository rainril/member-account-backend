-- ============================================================
-- bmi_food_tracker.sql
-- Adds BodyMetrics (BMI tracker) and FoodLogs (food log) tables.
-- Run this in phpMyAdmin against the memberaccount_db database.
-- ============================================================

CREATE TABLE BodyMetrics (
    MetricID   INT AUTO_INCREMENT PRIMARY KEY,
    MemberID   INT NOT NULL,
    Weight     DECIMAL(5,2) NOT NULL,   -- kg
    Height     DECIMAL(5,2) NOT NULL,   -- cm
    BMI        DECIMAL(4,1) NOT NULL,
    RecordedAt DATE NOT NULL,
    CreatedAt  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MemberID) REFERENCES Members(MemberID) ON DELETE CASCADE,
    INDEX idx_bodymetrics_member_date (MemberID, RecordedAt)
);

CREATE TABLE FoodLogs (
    LogID     INT AUTO_INCREMENT PRIMARY KEY,
    MemberID  INT NOT NULL,
    FoodName  VARCHAR(150) NOT NULL,
    MealType  VARCHAR(20) NOT NULL,     -- Breakfast, Lunch, Dinner, Snack
    Calories  INT NOT NULL,
    Protein   DECIMAL(5,1) DEFAULT NULL, -- grams
    Carbs     DECIMAL(5,1) DEFAULT NULL, -- grams
    Fats      DECIMAL(5,1) DEFAULT NULL, -- grams
    LoggedAt  DATE NOT NULL,
    CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MemberID) REFERENCES Members(MemberID) ON DELETE CASCADE,
    INDEX idx_foodlogs_member_date (MemberID, LoggedAt)
);
