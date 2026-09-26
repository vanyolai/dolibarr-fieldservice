ALTER TABLE llx_fieldservice_material ADD INDEX idx_fieldservice_material_entity (entity);
ALTER TABLE llx_fieldservice_material ADD INDEX idx_fieldservice_material_fichinter (fk_fichinter);
ALTER TABLE llx_fieldservice_material ADD INDEX idx_fieldservice_material_product (fk_product);
ALTER TABLE llx_fieldservice_material ADD INDEX idx_fieldservice_material_entrepot (fk_entrepot);
ALTER TABLE llx_fieldservice_material ADD INDEX idx_fieldservice_material_status (status);
ALTER TABLE llx_fieldservice_material ADD INDEX idx_fieldservice_material_origin (origin_type, fk_origin_line);
