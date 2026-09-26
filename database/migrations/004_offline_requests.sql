CREATE TABLE offline_requests (
    id BINARY(16) PRIMARY KEY,
    product_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id BINARY(16) NOT NULL,
    evidence_encrypted MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    encryption_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    projection_json LONGTEXT NOT NULL,
    imported_by BINARY(16) NOT NULL,
    imported_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_offline_request (product_id,request_id),
    KEY ix_offline_imported (imported_at),
    CONSTRAINT fk_offline_request FOREIGN KEY (product_id,request_id) REFERENCES license_requests(product_id,request_id),
    CONSTRAINT fk_offline_importer FOREIGN KEY (imported_by) REFERENCES admin_users(id),
    CONSTRAINT ck_offline_projection CHECK (JSON_VALID(projection_json)),
    CONSTRAINT ck_offline_encryption CHECK (encryption_version=1)
) ENGINE=InnoDB;

CREATE TABLE offline_decisions (
    offline_id BINARY(16) PRIMARY KEY,
    decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    license_id BINARY(16) NULL,
    revision_id BINARY(16) NULL,
    admin_id BINARY(16) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    CONSTRAINT fk_decision_offline FOREIGN KEY (offline_id) REFERENCES offline_requests(id),
    CONSTRAINT fk_decision_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
    CONSTRAINT fk_decision_revision FOREIGN KEY (revision_id) REFERENCES license_revisions(revision_id),
    CONSTRAINT fk_decision_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
    CONSTRAINT ck_offline_decision CHECK ((decision='approved' AND license_id IS NOT NULL AND revision_id IS NOT NULL) OR (decision='rejected' AND license_id IS NULL AND revision_id IS NULL))
) ENGINE=InnoDB;

CREATE TABLE license_transfers (
    id BINARY(16) PRIMARY KEY,
    license_id BINARY(16) NOT NULL,
    outgoing_activation_id BINARY(16) NOT NULL,
    incoming_activation_id BINARY(16) NULL,
    admin_id BINARY(16) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    offline_limit_accepted BOOLEAN NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_transfer_outgoing (outgoing_activation_id),
    CONSTRAINT fk_transfer_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
    CONSTRAINT fk_transfer_outgoing FOREIGN KEY (outgoing_activation_id,license_id) REFERENCES activations(activation_id,license_id),
    CONSTRAINT fk_transfer_incoming FOREIGN KEY (incoming_activation_id,license_id) REFERENCES activations(activation_id,license_id),
    CONSTRAINT fk_transfer_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
    CONSTRAINT ck_transfer_ack CHECK (offline_limit_accepted=1),
    CONSTRAINT ck_transfer_distinct CHECK (incoming_activation_id IS NULL OR incoming_activation_id<>outgoing_activation_id)
) ENGINE=InnoDB;
