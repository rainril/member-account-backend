-- ============================================================
-- 2026_09_17_add_payment_purpose.sql
-- Adds a Purpose column to the `payments` table so admin can tell
-- apart a brand-new registration payment from a plan renewal or an
-- upgrade, all of which currently land in the same table.
--
-- Run this once in phpMyAdmin (or via the mysql CLI) against the
-- `memberaccount` database.
-- ============================================================

ALTER TABLE `payments`
  ADD COLUMN `Purpose` ENUM('New Registration', 'Renewal', 'Upgrade')
    NOT NULL DEFAULT 'New Registration'
    AFTER `Status`;

-- Backfill: every existing payment that is already linked to a
-- membership is assumed to have been a new registration (the only
-- flow that existed before this feature). No further action needed --
-- the DEFAULT above already covers this for existing rows.
