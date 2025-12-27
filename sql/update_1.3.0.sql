-- MultiDocTemplate update script for version 1.3.0+
-- Allow fk_template to be NULL for direct uploads

ALTER TABLE llx_multidoctemplate_archive MODIFY fk_template INTEGER DEFAULT NULL;

-- Add category filter field to templates table
ALTER TABLE llx_multidoctemplate_template ADD COLUMN fk_category INTEGER DEFAULT NULL AFTER tag;
