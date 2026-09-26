CREATE TABLE signing_keys (
 kid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 public_key BINARY(32) NOT NULL UNIQUE,
 private_ref VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 state VARCHAR(16) NOT NULL DEFAULT 'staged', created_at DATETIME(6) NOT NULL,
 CONSTRAINT chk_signing_environment CHECK(environment IN ('production','development','testing')),
 CONSTRAINT chk_signing_purpose CHECK(purpose IN ('license','manifest')),
 CONSTRAINT chk_signing_state CHECK(state IN ('staged','signing','verify_only')),
 UNIQUE KEY uq_signing_scope_key(environment,purpose,kid)
) ENGINE=InnoDB;
CREATE TABLE signing_scopes (
 environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 active_kid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 PRIMARY KEY(environment,purpose),
 FOREIGN KEY(environment,purpose,active_kid) REFERENCES signing_keys(environment,purpose,kid)
) ENGINE=InnoDB;
CREATE TABLE activations (
 activation_id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL,
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 installation_id BINARY(16) NOT NULL, installation_public_key BINARY(32) NOT NULL,
 fingerprint_version VARCHAR(8) CHARACTER SET ascii NOT NULL,
 fingerprint_hash VARCHAR(71) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) NOT NULL, activated_at DATETIME(6) NOT NULL,
 ended_at DATETIME(6) NULL, current_revision_id BINARY(16) NULL,
 active_license_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN state='active' THEN license_id ELSE NULL END) STORED,
 UNIQUE KEY uq_active_installation(active_license_id),
 UNIQUE KEY uq_activation_license(activation_id,license_id),
 FOREIGN KEY(license_id,product_id) REFERENCES licenses(license_id,product_id),
 CONSTRAINT chk_activation_state CHECK(state IN ('active','deactivated','revoked')),
 CONSTRAINT chk_activation_end CHECK((state='active' AND ended_at IS NULL) OR (state<>'active' AND ended_at IS NOT NULL)),
 INDEX ix_activation_identity(product_id,installation_id)
) ENGINE=InnoDB;
CREATE TABLE license_revisions (
 revision_id BINARY(16) PRIMARY KEY, license_id BINARY(16) NOT NULL,
 activation_id BINARY(16) NOT NULL, license_revision BIGINT UNSIGNED NOT NULL,
 kid VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 license_status VARCHAR(16) NOT NULL, issued_at DATETIME(6) NOT NULL,
 payload_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 license_jws TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 jws_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(activation_id,license_id) REFERENCES activations(activation_id,license_id),
 FOREIGN KEY(kid) REFERENCES signing_keys(kid),
 UNIQUE KEY uq_license_revision(license_id,license_revision),
 UNIQUE KEY uq_activation_revision(activation_id,revision_id),
 CONSTRAINT chk_revision_positive CHECK(license_revision>0),
 CONSTRAINT chk_revision_json CHECK(JSON_VALID(payload_json)),
 CONSTRAINT chk_revision_status CHECK(license_status IN ('active','revoked'))
) ENGINE=InnoDB;
ALTER TABLE activations ADD CONSTRAINT fk_current_revision FOREIGN KEY(activation_id,current_revision_id) REFERENCES license_revisions(activation_id,revision_id);
CREATE TABLE activation_challenges (
 challenge_id BINARY(16) PRIMARY KEY, action VARCHAR(16) NOT NULL,
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 installation_id BINARY(16) NOT NULL, activation_id BINARY(16) NULL,
 nonce VARCHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at DATETIME(6) NOT NULL, expires_at DATETIME(6) NOT NULL,
 consumed_at DATETIME(6) NULL, consumed_request_id BINARY(16) NULL,
 CONSTRAINT chk_challenge_action CHECK(action IN ('activate','refresh','deactivate')),
 CONSTRAINT chk_challenge_identity CHECK((action='activate' AND activation_id IS NULL) OR (action<>'activate' AND activation_id IS NOT NULL)),
 CONSTRAINT chk_challenge_consumption CHECK((consumed_at IS NULL AND consumed_request_id IS NULL) OR (consumed_at IS NOT NULL AND consumed_request_id IS NOT NULL)),
 INDEX ix_challenge_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE license_requests (
 product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id BINARY(16) NOT NULL, channel VARCHAR(16) NOT NULL, action VARCHAR(16) NOT NULL,
 input_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 digest_key_version INT NOT NULL DEFAULT 1,
 installation_id BINARY(16) NOT NULL, activation_id BINARY(16) NULL,
 public_key BINARY(32) NOT NULL, proof_message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 response_status SMALLINT UNSIGNED NULL,
 response_body LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
 created_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL,
 PRIMARY KEY(product_id,request_id),
 CONSTRAINT chk_request_channel CHECK(channel IN ('online','offline')),
 CONSTRAINT chk_request_response CHECK((response_status IS NULL AND response_body IS NULL AND completed_at IS NULL) OR (response_status IS NOT NULL AND response_body IS NOT NULL AND completed_at IS NOT NULL AND JSON_VALID(response_body)))
) ENGINE=InnoDB;
