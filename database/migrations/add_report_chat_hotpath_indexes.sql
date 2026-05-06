-- Hot-path indexes for report and chat APIs
-- Run once: php database/migrate.php

ALTER TABLE project_time_logs
    ADD INDEX IF NOT EXISTS idx_ptl_user_logdate_project (user_id, log_date, project_id);

ALTER TABLE issues
    ADD INDEX IF NOT EXISTS idx_issues_reporter_created (reporter_id, created_at);

ALTER TABLE chat_messages
    ADD INDEX IF NOT EXISTS idx_chat_project_page_id (project_id, page_id, id),
    ADD INDEX IF NOT EXISTS idx_chat_project_id (project_id, id);
