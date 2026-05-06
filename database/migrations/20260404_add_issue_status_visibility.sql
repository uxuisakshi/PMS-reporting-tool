ALTER TABLE issue_statuses
    ADD COLUMN visible_to_client TINYINT(1) NOT NULL DEFAULT 1 AFTER is_qa,
    ADD COLUMN visible_to_internal TINYINT(1) NOT NULL DEFAULT 1 AFTER visible_to_client;