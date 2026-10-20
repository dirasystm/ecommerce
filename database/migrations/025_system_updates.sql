-- Basemall 025: website updates installed from the admin (System → Website updates). Additive.
-- One row per uploaded update package: what it changed, who installed it, and whether it was rolled back.
-- The detailed journal (file lists, backups, how to undo database additions) lives in storage/updates/<uuid>/.

INSERT IGNORE INTO permissions (slug, description) VALUES
('system.update', 'Install website updates from a package and roll them back');

CREATE TABLE system_updates (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    uuid            CHAR(32)        NOT NULL,
    status          VARCHAR(20)     NOT NULL,
    package_name    VARCHAR(190)    NOT NULL,
    package_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    package_sha256  CHAR(64)        NULL,
    files_changed   INT UNSIGNED    NOT NULL DEFAULT 0,
    files_added     INT UNSIGNED    NOT NULL DEFAULT 0,
    db_changes      INT UNSIGNED    NOT NULL DEFAULT 0,
    error           VARCHAR(1000)   NULL,
    uploaded_by     BIGINT UNSIGNED NULL,
    installed_by    BIGINT UNSIGNED NULL,
    rolled_back_by  BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    installed_at    DATETIME        NULL,
    completed_at    DATETIME        NULL,
    rolled_back_at  DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_su_uuid (uuid),
    KEY ix_su_status (status, created_at),
    CONSTRAINT fk_su_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_su_installed_by FOREIGN KEY (installed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_su_rolled_back_by FOREIGN KEY (rolled_back_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
