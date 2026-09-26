CREATE TABLE llx_fieldservice_material_alloc(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_material INTEGER NOT NULL,
	qty DOUBLE(24,8) DEFAULT 0 NOT NULL,
	batch VARCHAR(128),
	eatby DATETIME,
	sellby DATETIME,
	fk_stock_movement INTEGER,
	fk_reverse_stock_movement INTEGER,
	date_posted DATETIME,
	fk_user_posted INTEGER,
	date_creation DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
