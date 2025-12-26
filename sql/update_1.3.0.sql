-- MultiDocTemplate update script for version 1.3.0+
-- Allow fk_template to be NULL for direct uploads

ALTER TABLE llx_multidoctemplate_archive MODIFY fk_template INTEGER DEFAULT NULL;
