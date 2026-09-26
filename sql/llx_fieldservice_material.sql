CREATE TABLE llx_fieldservice_material(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	fk_fichinter INTEGER NOT NULL,
	fk_product INTEGER NOT NULL,
	fk_entrepot INTEGER NOT NULL,
	qty DOUBLE(24,8) DEFAULT 0 NOT NULL,
	fk_unit INTEGER,
	date_use DATETIME,
	status SMALLINT DEFAULT 0 NOT NULL,
	origin_type VARCHAR(32),
	fk_origin_line INTEGER,
	description TEXT,
	fk_user_create INTEGER,
	fk_user_modif INTEGER,
	date_creation DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
