ALTER TABLE user_edit_requests
MODIFY COLUMN status ENUM('pending','approved','rejected','used') DEFAULT 'pending';