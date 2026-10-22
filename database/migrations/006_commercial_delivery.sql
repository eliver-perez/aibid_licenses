ALTER TABLE license_credentials ADD credential_encrypted TEXT NULL, ADD code_format VARCHAR(24) NULL;
ALTER TABLE licenses ADD commercial_reference VARCHAR(190) NOT NULL DEFAULT '', ADD authorization_note VARCHAR(500) NOT NULL DEFAULT '', ADD administrative_version INT UNSIGNED NOT NULL DEFAULT 1;
UPDATE licenses l JOIN license_changes c ON c.license_id=l.license_id AND c.action='license.issued'
SET l.commercial_reference=COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.snapshot_json,'$.commercial_reference')),''), l.authorization_note=c.reason;
ALTER TABLE customers ADD tax_id VARCHAR(32) NOT NULL DEFAULT '';
CREATE TABLE admin_permission_grants (
 admin_id BINARY(16) NOT NULL, permission VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(admin_id,permission), FOREIGN KEY(admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE license_administrative_changes (
 id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL, version INT UNSIGNED NOT NULL,
 field_name VARCHAR(32) NOT NULL, old_value VARCHAR(500) NOT NULL, new_value VARCHAR(500) NOT NULL,
 admin_id BINARY(16) NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(license_id) REFERENCES licenses(license_id), FOREIGN KEY(admin_id) REFERENCES admin_users(id),
 UNIQUE KEY uq_administrative_revision(license_id,version,field_name)
) ENGINE=InnoDB;
CREATE TABLE license_documents (
 id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL, document_type VARCHAR(24) NOT NULL,
 revision INT UNSIGNED NOT NULL, folio VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 verification_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 public_json JSON NOT NULL, snapshot_encrypted MEDIUMTEXT NOT NULL, pdf_encrypted MEDIUMTEXT NOT NULL,
 pdf_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 credential_id BINARY(16) NULL, offline_revision_id BINARY(16) NULL,
 generated_by BINARY(16) NOT NULL, generated_at DATETIME(6) NOT NULL,
 UNIQUE KEY uq_document_revision(license_id,document_type,revision),
 FOREIGN KEY(license_id) REFERENCES licenses(license_id), FOREIGN KEY(generated_by) REFERENCES admin_users(id),
 FOREIGN KEY(credential_id) REFERENCES license_credentials(credential_id), FOREIGN KEY(offline_revision_id) REFERENCES license_revisions(revision_id),
 CONSTRAINT chk_document_type CHECK (document_type IN ('licensing','delivery'))
) ENGINE=InnoDB;
