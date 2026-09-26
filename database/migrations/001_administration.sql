CREATE TABLE security_state (id TINYINT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO security_state VALUES (1);
CREATE TABLE admin_users (
 id BINARY(16) PRIMARY KEY, login VARCHAR(190) NOT NULL UNIQUE,
 display_name VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(24) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'active',
 mfa_secret_encrypted TEXT NULL, last_totp_step BIGINT NULL,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT chk_admin_role CHECK (role IN ('superadmin','operator','viewer')),
 CONSTRAINT chk_admin_state CHECK (state IN ('active','disabled'))
) ENGINE=InnoDB;
CREATE TABLE admin_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 admin_id BINARY(16) NULL, stage VARCHAR(16) NOT NULL,
 enrollment_secret TEXT NULL, created_at DATETIME(6) NOT NULL,
 last_seen_at DATETIME(6) NOT NULL, expires_at DATETIME(6) NOT NULL,
 mfa_verified_at DATETIME(6) NULL, revoked_at DATETIME(6) NULL,
 FOREIGN KEY (admin_id) REFERENCES admin_users(id), INDEX ix_session_expiry(expires_at),
 CONSTRAINT chk_session_stage CHECK (stage IN ('anonymous','pending','authenticated')),
 CONSTRAINT chk_session_auth CHECK (stage='anonymous' OR admin_id IS NOT NULL)
) ENGINE=InnoDB;
CREATE TABLE admin_recovery_codes (
 id BINARY(16) PRIMARY KEY, admin_id BINARY(16) NOT NULL,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 used_at DATETIME(6) NULL, FOREIGN KEY (admin_id) REFERENCES admin_users(id),
 UNIQUE KEY uq_recovery(admin_id,code_hash)
) ENGINE=InnoDB;
CREATE TABLE rate_limit_buckets (
 scope VARCHAR(32) CHARACTER SET ascii NOT NULL,
 key_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 window_start BIGINT NOT NULL, count INT UNSIGNED NOT NULL,
 PRIMARY KEY(scope,key_digest,window_start)
) ENGINE=InnoDB;
CREATE TABLE audit_heads (
 stream_id VARCHAR(16) PRIMARY KEY, last_sequence BIGINT UNSIGNED NOT NULL,
 last_event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB;
INSERT INTO audit_heads VALUES ('admin',0,REPEAT('0',64));
CREATE TABLE audit_events (
 sequence BIGINT UNSIGNED PRIMARY KEY, occurred_at DATETIME(6) NOT NULL,
 actor_id BINARY(16) NULL, operation VARCHAR(64) NOT NULL,
 target_type VARCHAR(32) NOT NULL, target_id VARCHAR(190) NULL,
 payload_json LONGTEXT NOT NULL, previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 CONSTRAINT chk_audit_json CHECK (JSON_VALID(payload_json)),
 INDEX ix_audit_time(occurred_at), INDEX ix_audit_target(target_type,target_id),
 INDEX ix_audit_actor(actor_id), INDEX ix_audit_operation(operation)
) ENGINE=InnoDB;
CREATE TABLE admin_operations (
 operation_id BINARY(16) PRIMARY KEY, admin_id BINARY(16) NOT NULL,
 action VARCHAR(64) NOT NULL, input_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json JSON NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY (admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE customers (
 id BINARY(16) PRIMARY KEY, display_name VARCHAR(190) NOT NULL,
 legal_name VARCHAR(190) NULL, state VARCHAR(16) NOT NULL DEFAULT 'active',
 row_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT chk_customer_state CHECK (state IN ('active','archived')),
 INDEX ix_customer_name(display_name)
) ENGINE=InnoDB;
CREATE TABLE customer_contacts (
 id BINARY(16) PRIMARY KEY, customer_id BINARY(16) NOT NULL UNIQUE,
 name VARCHAR(190) NOT NULL, email VARCHAR(190) NULL, phone VARCHAR(60) NULL,
 FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;
CREATE TABLE products (
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 display_name VARCHAR(190) NOT NULL, description VARCHAR(500) NOT NULL DEFAULT '',
 state VARCHAR(16) NOT NULL DEFAULT 'active', row_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 CONSTRAINT chk_product_state CHECK (state IN ('active','archived'))
) ENGINE=InnoDB;
CREATE TABLE product_capabilities (
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 capability_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 display_name VARCHAR(190) NOT NULL,
 PRIMARY KEY(product_id,capability_key), FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB;
CREATE TABLE capability_dependencies (
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 capability_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 required_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(product_id,capability_key,required_key),
 FOREIGN KEY(product_id,capability_key) REFERENCES product_capabilities(product_id,capability_key),
 FOREIGN KEY(product_id,required_key) REFERENCES product_capabilities(product_id,capability_key),
 CONSTRAINT chk_dependency_self CHECK (capability_key<>required_key)
) ENGINE=InnoDB;
CREATE TABLE licenses (
 license_id BINARY(16) PRIMARY KEY, customer_id BINARY(16) NOT NULL,
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 license_type VARCHAR(16) NOT NULL, commercial_status VARCHAR(16) NOT NULL DEFAULT 'issued',
 expires_at DATETIME(6) NULL, grace_days TINYINT UNSIGNED NOT NULL,
 maintenance_until DATETIME(6) NULL, entitled_release_until DATETIME(6) NOT NULL,
 revision_counter BIGINT UNSIGNED NOT NULL DEFAULT 0, row_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
 FOREIGN KEY(customer_id) REFERENCES customers(id), FOREIGN KEY(product_id) REFERENCES products(product_id),
 UNIQUE KEY uq_license_product(license_id,product_id),
 CONSTRAINT chk_license_status CHECK (commercial_status IN ('issued','revoked')),
 CONSTRAINT chk_license_terms CHECK (
  (license_type='perpetual' AND expires_at IS NULL AND grace_days=0)
  OR (license_type='subscription' AND expires_at IS NOT NULL AND grace_days=15 AND maintenance_until IS NULL)
 ), INDEX ix_license_customer(customer_id), INDEX ix_license_product_state(product_id,commercial_status),
 INDEX ix_license_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE license_features (
 license_id BINARY(16) NOT NULL,
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 capability_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 enabled BOOLEAN NOT NULL, PRIMARY KEY(license_id,capability_key),
 FOREIGN KEY(license_id,product_id) REFERENCES licenses(license_id,product_id),
 FOREIGN KEY(product_id,capability_key) REFERENCES product_capabilities(product_id,capability_key),
 CONSTRAINT chk_feature_bool CHECK (enabled IN (0,1))
) ENGINE=InnoDB;
CREATE TABLE license_limits (
 license_id BINARY(16) PRIMARY KEY, max_installations INT NOT NULL DEFAULT 1,
 max_users INT NULL, max_libraries INT NULL, max_documents INT NULL,
 FOREIGN KEY(license_id) REFERENCES licenses(license_id),
 CONSTRAINT chk_limit_installations CHECK (max_installations=1),
 CONSTRAINT chk_limit_users CHECK (max_users IS NULL OR max_users>0),
 CONSTRAINT chk_limit_libraries CHECK (max_libraries IS NULL OR max_libraries>0),
 CONSTRAINT chk_limit_documents CHECK (max_documents IS NULL OR max_documents>0)
) ENGINE=InnoDB;
CREATE TABLE license_credentials (
 credential_id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL,
 key_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 digest_key_version INT NOT NULL DEFAULT 1, state VARCHAR(16) NOT NULL,
 created_at DATETIME(6) NOT NULL, retired_at DATETIME(6) NULL,
 active_license_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN state='active' THEN license_id ELSE NULL END) STORED,
 UNIQUE KEY uq_active_credential(active_license_id), UNIQUE KEY uq_credential_digest(digest_key_version,key_digest),
 FOREIGN KEY(license_id) REFERENCES licenses(license_id),
 CONSTRAINT chk_credential_state CHECK (state IN ('active','retired'))
) ENGINE=InnoDB;
CREATE TABLE license_changes (
 id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL, version INT UNSIGNED NOT NULL,
 action VARCHAR(64) NOT NULL, admin_id BINARY(16) NOT NULL,
 snapshot_json JSON NOT NULL, reason VARCHAR(500) NOT NULL, created_at DATETIME(6) NOT NULL,
 FOREIGN KEY(license_id) REFERENCES licenses(license_id), FOREIGN KEY(admin_id) REFERENCES admin_users(id),
 UNIQUE KEY uq_license_change(license_id,version)
) ENGINE=InnoDB;
CREATE TABLE subscription_periods (
 id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL,
 previous_expires_at DATETIME(6) NULL, new_expires_at DATETIME(6) NOT NULL,
 effective_at DATETIME(6) NOT NULL, commercial_reference VARCHAR(190) NULL,
 admin_id BINARY(16) NOT NULL, reason VARCHAR(500) NOT NULL,
 FOREIGN KEY(license_id) REFERENCES licenses(license_id), FOREIGN KEY(admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
CREATE TABLE maintenance_purchases (
 id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL,
 previous_until DATETIME(6) NULL, new_until DATETIME(6) NOT NULL,
 purchased_at DATETIME(6) NOT NULL, commercial_reference VARCHAR(190) NOT NULL,
 admin_id BINARY(16) NOT NULL, reason VARCHAR(500) NOT NULL,
 FOREIGN KEY(license_id) REFERENCES licenses(license_id), FOREIGN KEY(admin_id) REFERENCES admin_users(id)
) ENGINE=InnoDB;
