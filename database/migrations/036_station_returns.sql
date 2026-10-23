-- Ecommerce 036: parcels returned from pickup stations. An order not collected within the hold period (or sent back by
-- staff) is cancelled and its parcel recorded here until it is received back at the warehouse; its stock stays held
-- until then. Adds one table and two settings only.

CREATE TABLE station_returns (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id        BIGINT UNSIGNED NOT NULL,
    pickup_point_id INT UNSIGNED    NULL,
    reason          VARCHAR(30)     NOT NULL DEFAULT 'not_collected',   -- not_collected | refused | other
    note            VARCHAR(255)    NULL,
    status          VARCHAR(20)     NOT NULL DEFAULT 'awaiting_return', -- awaiting_return | received
    started_by      BIGINT UNSIGNED NULL,                               -- NULL: started automatically
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_at     DATETIME        NULL,
    received_by     BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_station_return_order (order_id),
    KEY ix_station_return_status (status, created_at),
    CONSTRAINT fk_station_return_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('collection_auto_return', '1'), ('collection_reminder_hours', '24');
