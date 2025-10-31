CREATE TABLE `hotline_users` (
  `id` int(11) NOT NULL,
  `server_unique_id` varchar(64) NOT NULL,
  `server_name` varchar(255) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `user_icon_id` smallint(6) NOT NULL,
  `timestamp` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
