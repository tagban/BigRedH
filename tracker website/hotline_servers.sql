--
-- Table structure for table `hotline_servers`
--

CREATE TABLE `hotline_servers` (
  `unique_id` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `port` int(11) DEFAULT NULL,
  `user_count` int(11) DEFAULT 0,
  `server_type` varchar(50) DEFAULT NULL,
  `filtered` tinyint(1) DEFAULT 0,
  `filtered_by` varchar(255) DEFAULT NULL,
  `last_checked_in` datetime(6) DEFAULT NULL,
  `mirror_sources` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
