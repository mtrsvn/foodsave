-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Mar 22, 2026 at 05:01 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `foodsave_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `history`
--

CREATE TABLE `history` (
  `history_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `type` enum('IN','SOLD','RETURN','WASTED') NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `price` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_date` date DEFAULT NULL,
  `sold_at` datetime DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `logged_out_at` datetime DEFAULT NULL,
  `days_remaining` int(11) DEFAULT NULL,
  `return_status` varchar(10) DEFAULT 'NO',
  `return_reason` text DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `inventory`
-- (See below for the actual view)
--
CREATE TABLE `inventory` (
`product_id` int(11)
,`product_name` varchar(255)
,`user_id` int(11)
,`price` decimal(10,2)
,`original_qty` int(11)
,`date_scanned` timestamp
,`expiry_date` date
,`updated_at` timestamp
,`days_remaining` int(7)
,`total_sold` decimal(32,0)
,`total_returned` decimal(32,0)
,`total_wasted` decimal(32,0)
,`remaining_stocks` decimal(34,0)
,`status` varchar(14)
);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notif_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `category` enum('Low Stock','Near Expiry','Expired') NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_date` date DEFAULT NULL,
  `remaining_stocks` int(11) DEFAULT 0,
  `recommendation` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `category` varchar(100) NOT NULL DEFAULT 'Sandwich',
  `quantity` int(11) DEFAULT 1,
  `date_scanned` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_date` date NOT NULL,
  `status` enum('USABLE','EXPIRED') DEFAULT 'USABLE',
  `transaction_id` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_masterlist`
--

CREATE TABLE `product_masterlist` (
  `master_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_masterlist`
--

INSERT INTO `product_masterlist` (`master_id`, `user_id`, `product_name`, `category`, `price`, `created_at`) VALUES
(1, 1, 'Ham Cheesemelt', 'Toasted Sandwich', 59.00, '2026-03-22 07:26:26'),
(2, 1, 'Cream Cheese Pemiento', 'Toasted Sandwich', 90.00, '2026-03-22 10:01:55'),
(3, 1, 'Tuna Cheesemelt', 'Toasted Sandwich', 59.00, '2026-03-22 10:02:31'),
(4, 1, 'Sausage Cheesemelt', 'Toasted Sandwich', 65.00, '2026-03-22 10:02:45'),
(6, 1, 'Grilled Three Cheese', 'Toasted Sandwich', 59.00, '2026-03-22 10:03:43'),
(8, 1, 'Two Cheese Pepperoni', 'Toasted Sandwich', 59.00, '2026-03-22 10:04:13');

-- --------------------------------------------------------

--
-- Table structure for table `returned`
--

CREATE TABLE `returned` (
  `returned_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `returned_item` varchar(255) DEFAULT NULL,
  `returned_quantity` int(11) DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `returned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_scanned` datetime DEFAULT NULL,
  `sold_at` datetime DEFAULT NULL,
  `return_reason` text DEFAULT NULL,
  `transaction_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sold`
--

CREATE TABLE `sold` (
  `sold_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sold_item` varchar(255) DEFAULT NULL,
  `sold_quantity` int(11) DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `sold_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `transaction_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `number` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `camera_permission` tinyint(1) DEFAULT 0,
  `terms_agreed` tinyint(1) DEFAULT 0,
  `email_notif` tinyint(1) DEFAULT 1,
  `expiry_alert_notif` tinyint(1) DEFAULT 1,
  `days_before_expiry` int(11) DEFAULT 2,
  `low_stock_threshold` int(11) DEFAULT 3,
  `expired_notif_delay` int(11) DEFAULT 0,
  `notif_interval_hours` int(11) DEFAULT 12
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `branch_name`, `email`, `number`, `password`, `created_at`, `camera_permission`, `terms_agreed`, `email_notif`, `expiry_alert_notif`, `days_before_expiry`, `low_stock_threshold`, `expired_notif_delay`, `notif_interval_hours`) VALUES
(1, 'Marikina', 'villamorjulianamayhan@gmail.com', '09617502102', '$2y$10$55N0qtmW/kc9vawsc0ms3OX.iycZcyymVwpMQdMBOBWCdWL50ugdi', '2026-03-20 07:09:06', 1, 1, 1, 1, 1, 2, 0, 4),
(4, 'Kalumpang', 'kalumpangbranch@gmail.com', '09627502104', '$2y$10$.bL07ET7DDM3kpqChaxCs.hq8PFDwi2wqKpJ3Kbwx2BukDmTOlycK', '2026-03-20 10:23:01', 1, 1, 0, 1, 2, 3, 0, 12);

-- --------------------------------------------------------

--
-- Table structure for table `wasted`
--

CREATE TABLE `wasted` (
  `wasted_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `wasted_item` varchar(255) DEFAULT NULL,
  `wasted_quantity` int(11) DEFAULT 0,
  `user_id` int(11) NOT NULL,
  `wasted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `transaction_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure for view `inventory`
--
DROP TABLE IF EXISTS `inventory`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `inventory`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`name` AS `product_name`, `p`.`user_id` AS `user_id`, `p`.`price` AS `price`, `p`.`quantity` AS `original_qty`, `p`.`date_scanned` AS `date_scanned`, `p`.`expiry_date` AS `expiry_date`, `p`.`updated_at` AS `updated_at`, to_days(`p`.`expiry_date`) - to_days(curdate()) AS `days_remaining`, coalesce((select sum(`s`.`sold_quantity`) from `sold` `s` where `s`.`product_id` = `p`.`product_id`),0) AS `total_sold`, coalesce((select sum(`r`.`returned_quantity`) from `returned` `r` where `r`.`product_id` = `p`.`product_id`),0) AS `total_returned`, coalesce((select sum(`w`.`wasted_quantity`) from `wasted` `w` where `w`.`product_id` = `p`.`product_id`),0) AS `total_wasted`, `p`.`quantity`- coalesce((select sum(`s`.`sold_quantity`) from `sold` `s` where `s`.`product_id` = `p`.`product_id`),0) - coalesce((select sum(`w`.`wasted_quantity`) from `wasted` `w` where `w`.`product_id` = `p`.`product_id`),0) AS `remaining_stocks`, CASE WHEN `p`.`expiry_date` < curdate() THEN 'EXPIRED' WHEN `p`.`quantity` - coalesce((select sum(`s`.`sold_quantity`) from `sold` `s` where `s`.`product_id` = `p`.`product_id`),0) - coalesce((select sum(`w`.`wasted_quantity`) from `wasted` `w` where `w`.`product_id` = `p`.`product_id`),0) <= 0 THEN 'SOLD OUT' WHEN `p`.`expiry_date` = curdate() THEN 'EXPIRING TODAY' WHEN to_days(`p`.`expiry_date`) - to_days(curdate()) <= `u`.`days_before_expiry` THEN 'EXPIRING SOON' ELSE 'USABLE' END AS `status` FROM (`products` `p` join `users` `u` on(`p`.`user_id` = `u`.`user_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `history`
--
ALTER TABLE `history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `fk_history_user` (`user_id`),
  ADD KEY `fk_history_product` (`product_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notif_id`),
  ADD KEY `fk_notif_user` (`user_id`),
  ADD KEY `fk_notif_prod` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD KEY `fk_user_products` (`user_id`);

--
-- Indexes for table `product_masterlist`
--
ALTER TABLE `product_masterlist`
  ADD PRIMARY KEY (`master_id`),
  ADD UNIQUE KEY `unique_user_product` (`user_id`,`product_name`);

--
-- Indexes for table `returned`
--
ALTER TABLE `returned`
  ADD PRIMARY KEY (`returned_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sold`
--
ALTER TABLE `sold`
  ADD PRIMARY KEY (`sold_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `number` (`number`),
  ADD UNIQUE KEY `unique_branch` (`branch_name`);

--
-- Indexes for table `wasted`
--
ALTER TABLE `wasted`
  ADD PRIMARY KEY (`wasted_id`),
  ADD KEY `product_id` (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `history`
--
ALTER TABLE `history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notif_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `product_masterlist`
--
ALTER TABLE `product_masterlist`
  MODIFY `master_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `returned`
--
ALTER TABLE `returned`
  MODIFY `returned_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sold`
--
ALTER TABLE `sold`
  MODIFY `sold_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `wasted`
--
ALTER TABLE `wasted`
  MODIFY `wasted_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `history`
--
ALTER TABLE `history`
  ADD CONSTRAINT `fk_history_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_prod` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_products` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `product_masterlist`
--
ALTER TABLE `product_masterlist`
  ADD CONSTRAINT `product_masterlist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `returned`
--
ALTER TABLE `returned`
  ADD CONSTRAINT `returned_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `sold`
--
ALTER TABLE `sold`
  ADD CONSTRAINT `sold_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `wasted`
--
ALTER TABLE `wasted`
  ADD CONSTRAINT `wasted_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
