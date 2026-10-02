-- =====================================================================
-- Migration: confidence check on the automatic document categorization
--
-- Fig. 5 of the paper has a "confident?" decision after classification.
-- Each upload now records how sure the classifier was, what it guessed,
-- and what the faculty member chose. Uploads below CONFIDENCE_THRESHOLD
-- (config/config.php) are flagged and listed for the Admin in
-- admin/classification_review.php, where the category can be corrected
-- and the upload marked as reviewed. This is a review list only: flagged
-- documents are filed and visible like any other upload.
--
--   confidence_score     0.000-1.000; NULL = not scored (uploaded before
--                        this migration, or filed by the catch-up routine)
--   predicted_category   the classifier's guess (NULL = no guess)
--   chosen_category      the category the faculty member picked at upload;
--                        document_type is the current one (the Admin may
--                        correct it)
--   is_low_confidence    1 = below the threshold when uploaded
--   reviewed_at / _by    set when the Admin marks it reviewed
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

ALTER TABLE documents ADD COLUMN confidence_score DECIMAL(4,3) NULL AFTER ocr_confidence_note;
ALTER TABLE documents ADD COLUMN predicted_category VARCHAR(40) NULL AFTER confidence_score;
ALTER TABLE documents ADD COLUMN chosen_category VARCHAR(40) NULL AFTER predicted_category;
ALTER TABLE documents ADD COLUMN is_low_confidence TINYINT(1) NOT NULL DEFAULT 0 AFTER chosen_category;
ALTER TABLE documents ADD COLUMN reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER is_low_confidence;
-- No foreign key on reviewed_by, so a retried run can't fail on a duplicate
-- constraint; the review page LEFT JOINs users for the reviewer's name.
ALTER TABLE documents ADD COLUMN reviewed_by INT NULL AFTER reviewed_at;

CREATE INDEX idx_documents_low_confidence ON documents(is_low_confidence, reviewed_at, confidence_score);
