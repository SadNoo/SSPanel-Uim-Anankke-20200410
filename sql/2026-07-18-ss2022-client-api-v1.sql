-- SS2022 single-port nodes contain host, port and a 32-byte Base64 server key.
ALTER TABLE `ss_node`
    CHANGE `server` `server` VARCHAR(255) NOT NULL;

-- Native-client tokens are stored as SHA-256 hashes. Raw tokens are returned once.
CREATE TABLE IF NOT EXISTS `client_api_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `family_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `platform` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `device_name` VARCHAR(128) NOT NULL DEFAULT '',
    `created_at` BIGINT NOT NULL,
    `expires_at` BIGINT NOT NULL,
    `revoked_at` BIGINT NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `client_api_tokens_token_hash_unique` (`token_hash`),
    KEY `client_api_tokens_user_family_index` (`user_id`, `family_id`),
    KEY `client_api_tokens_expiry_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
