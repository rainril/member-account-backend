-- ============================================================
-- Migration: add Gender to Members, add DurationSeconds to
-- PersonalRecords.
--
-- Run this ONCE against memberaccount_db (e.g. via phpMyAdmin's
-- SQL tab, or `mysql -u root memberaccount_db < this_file.sql`).
--
-- Both new columns are nullable, so existing rows are unaffected
-- and no existing feature breaks. New registrations will start
-- populating Gender; existing members can fill it in later via
-- Profile Settings. DurationSeconds is optional per PR (only
-- relevant for time-based exercises like planks/cardio).
-- ============================================================

ALTER TABLE Members
    ADD COLUMN Gender ENUM('Male', 'Female', 'Other') NULL DEFAULT NULL
    AFTER DateOfBirth;

ALTER TABLE PersonalRecords
    ADD COLUMN DurationSeconds INT NULL DEFAULT NULL
    AFTER Reps;
