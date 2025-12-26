-- Copyright (C) 2024
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- Update for version 2.1.0: Add native Dolibarr category support

-- Add fk_category column to template table if not exists
ALTER TABLE llx_multidoctemplate_template ADD COLUMN fk_category INTEGER DEFAULT NULL AFTER tag;
