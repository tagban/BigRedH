CREATE TABLE `hotline_files` (
  `id` bigint(20) NOT NULL,
  `server_id` varchar(255) NOT NULL,
  `full_path` text NOT NULL,
  `parent_path` text NOT NULL,
  `name` varchar(255) NOT NULL,
  `size` bigint(20) DEFAULT 0,
  `is_folder` tinyint(1) DEFAULT 0,
  `type_code` varchar(10) DEFAULT NULL,
  `creator_code` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
