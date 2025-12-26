-- Copyright (C) 2024
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- Register 'template' as a new category type in Dolibarr
-- Type code 'template' must be unique and will appear in Tags/Categories
-- Note: This is also handled in modMultiDocTemplate.class.php init() function
INSERT INTO llx_c_type_categ (entity, code, type, label, position, active)
SELECT 1, 'template', 'multidoctemplate', 'Templates', 100, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM llx_c_type_categ WHERE code = 'template');
