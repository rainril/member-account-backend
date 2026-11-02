-- Run against the hosted member database before deploying session restoration.
-- Only token hashes are stored; existing member records are not modified.
CREATE TABLE IF NOT EXISTS MemberSessions (
    SessionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    MemberID INT NOT NULL,
    TokenHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ExpiresAt DATETIME NOT NULL,
    CreatedAt DATETIME NOT NULL,
    UNIQUE KEY member_sessions_token_hash_unique (TokenHash),
    KEY member_sessions_member_index (MemberID),
    KEY member_sessions_expiry_index (ExpiresAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
