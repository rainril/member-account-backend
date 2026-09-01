-- ============================================================
-- user_targets.sql
-- Adds UserTargets table (daily calorie/macro goals + target weight,
-- one row per member). Run this in phpMyAdmin against memberaccount_db.
-- ============================================================

CREATE TABLE UserTargets (
    TargetID            INT AUTO_INCREMENT PRIMARY KEY,
    MemberID            INT NOT NULL UNIQUE,
    DailyCalorieTarget  INT NOT NULL,
    DailyProteinTarget  DECIMAL(5,1) DEFAULT NULL,  -- grams
    DailyCarbsTarget    DECIMAL(5,1) DEFAULT NULL,  -- grams
    DailyFatsTarget     DECIMAL(5,1) DEFAULT NULL,  -- grams
    TargetWeight        DECIMAL(5,2) DEFAULT NULL,  -- kg
    UpdatedAt           DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CreatedAt           DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (MemberID) REFERENCES Members(MemberID) ON DELETE CASCADE
);
