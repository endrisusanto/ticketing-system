-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 06, 2025 at 02:25 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ticketing_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `issues`
--

CREATE TABLE `issues` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `image_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`image_paths`)),
  `condition` enum('Urgent','High','Normal','Low') NOT NULL,
  `status` enum('Open','In Progress','Resolved','Closed') NOT NULL DEFAULT 'Open',
  `drafter_id` int(11) NOT NULL,
  `pic_emails` text DEFAULT NULL,
  `cc_emails` text DEFAULT NULL,
  `bcc_emails` text DEFAULT NULL,
  `access_token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issues`
--

INSERT INTO `issues` (`id`, `title`, `description`, `location`, `image_paths`, `condition`, `status`, `drafter_id`, `pic_emails`, `cc_emails`, `bcc_emails`, `access_token`, `created_at`, `updated_at`) VALUES
(1, 'Panas', 'AC panas', 'PE SW Lab', '[\"uploads\\/1759730593_1759717340_1759713907_1759712254_1759463397_6a18cae4f8d14ca406cebca932519954.jpg_720x720q80.jpg\",\"uploads\\/1759730593_1759718233_1759717340_1759713907_1759712254_1759463397_6a18cae4f8d14ca406cebca932519954.jpg_720x720q80.jpg\"]', 'Normal', 'In Progress', 1, 'endrisusantomyid@gmail.com', '', '', '08bd5e43059e7157975f26a984383540d5e44ddf228014e4979f7cedcc58d9cc', '2025-10-06 06:03:13', '2025-10-06 06:03:55'),
(2, '343', '34', '34', '[\"uploads\\/1759732637_1759717340_1759713907_1759712254_1759463397_6a18cae4f8d14ca406cebca932519954.jpg_720x720q80.jpg\"]', 'Urgent', 'Open', 1, 'endrisusantomyid@gmail.com', '', '', '6b117e1be53ffb6854d819cbafdbe8a5c7f5ab3136bd671021da175058169f8f', '2025-10-06 06:37:17', '2025-10-06 06:37:17');

-- --------------------------------------------------------

--
-- Table structure for table `issue_updates`
--

CREATE TABLE `issue_updates` (
  `id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `notes` text NOT NULL,
  `created_by` varchar(100) NOT NULL,
  `attachments` text DEFAULT NULL,
  `is_status_change` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issue_updates`
--

INSERT INTO `issue_updates` (`id`, `issue_id`, `notes`, `created_by`, `attachments`, `is_status_change`, `created_at`) VALUES
(1, 1, 'Status changed from Open to In Progress by endrisusantomyid@gmail.com', 'endrisusantomyid@gmail.com', NULL, 1, '2025-10-06 06:03:55'),
(2, 1, 'oke otw', 'endrisusantomyid@gmail.com', NULL, 0, '2025-10-06 06:04:00'),
(3, 1, 's', 'seintest06@gmail.com', NULL, 0, '2025-10-06 06:46:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verification_token` varchar(64) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `created_at`, `verification_token`, `is_verified`) VALUES
(1, 'Endri Susanto', 'seintest06@gmail.com', '$2y$10$mXQC28eJepsW1qhbR/emE.8S5wzn7MBn35xYHmVB69K73P4LuuoCq', '2025-10-06 06:02:05', NULL, 1),
(2, 'endrisusantomyid', 'endrisusantomyid@gmail.com', '$2y$10$aj1q3/8AmH/Wq16tpo3vy.yUTrKICO80OHpPHAGZI8b37JXIgpmYe', '2025-10-06 06:02:29', NULL, 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `issues`
--
ALTER TABLE `issues`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `access_token` (`access_token`),
  ADD KEY `drafter_id` (`drafter_id`);

--
-- Indexes for table `issue_updates`
--
ALTER TABLE `issue_updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `issue_id` (`issue_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `issues`
--
ALTER TABLE `issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `issue_updates`
--
ALTER TABLE `issue_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `issues`
--
ALTER TABLE `issues`
  ADD CONSTRAINT `issues_ibfk_1` FOREIGN KEY (`drafter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `issue_updates`
--
ALTER TABLE `issue_updates`
  ADD CONSTRAINT `issue_updates_ibfk_1` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
