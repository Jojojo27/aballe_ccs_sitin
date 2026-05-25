-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 25, 2026 at 10:16 AM
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
-- Database: `ccs_sit_in`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('super_admin','admin') DEFAULT 'admin',
  `profile_pic` varchar(255) DEFAULT 'default-admin.png',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_by` varchar(50) DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `message`, `created_by`, `created_at`) VALUES
(4, 'Welcome back Student!', NULL, '2026-04-11 07:09:20'),
(5, 'Don\'t forget to clean and close your pc\'s after use.', NULL, '2026-04-11 09:05:59'),
(6, 'Welcome', NULL, '2026-04-11 09:57:07');

-- --------------------------------------------------------

--
-- Table structure for table `community_posts`
--

CREATE TABLE `community_posts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `community_posts`
--

INSERT INTO `community_posts` (`id`, `user_id`, `content`, `created_at`) VALUES
(1, 11, 'Feedback for Lab 530 (Python Programming) on Apr 11, 2026 | Rating: 5/5\nits good', '2026-04-11 16:08:50'),
(2, 11, 'Feedback for Lab 527 (Network Security) on Apr 11, 2026 | Rating: 5/5\neverything was fine', '2026-04-11 17:36:25');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sit_in_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `user_id`, `sit_in_id`, `rating`, `message`, `created_at`) VALUES
(1, 11, 8, 5, 'its good', '2026-04-11 08:08:50'),
(2, 11, 10, 5, 'everything was fine', '2026-04-11 09:36:25');

-- --------------------------------------------------------

--
-- Table structure for table `instructors`
--

CREATE TABLE `instructors` (
  `id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `last_name` varchar(60) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laboratories`
--

CREATE TABLE `laboratories` (
  `id` int(11) NOT NULL,
  `lab_number` varchar(10) NOT NULL,
  `pc_count` int(11) DEFAULT 50,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `laboratories`
--

INSERT INTO `laboratories` (`id`, `lab_number`, `pc_count`, `created_at`, `updated_at`) VALUES
(1, '523', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(2, '524', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(3, '525', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(4, '526', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(5, '527', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(6, '528', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(7, '529', 50, '2026-05-25 02:59:51', '2026-05-25 02:59:51'),
(8, '530', 20, '2026-05-25 02:59:51', '2026-05-25 03:44:41');

-- --------------------------------------------------------

--
-- Table structure for table `lab_class`
--

CREATE TABLE `lab_class` (
  `id` int(11) NOT NULL,
  `laboratory` varchar(10) NOT NULL,
  `date` date NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `instructor_id` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `type` enum('announcement','reservation','system') DEFAULT 'system',
  `is_read` tinyint(4) DEFAULT 0,
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `link`, `created_at`) VALUES
(1, 11, 'New Announcement', 'Welcome back Student!', 'announcement', 1, 'student_dashboard.php', '2026-04-11 07:09:20'),
(2, 12, 'New Announcement', 'Welcome back Student!', 'announcement', 0, 'student_dashboard.php', '2026-04-11 07:09:20'),
(3, 13, 'New Announcement', 'Don\'t forget to clean and close your pc\'s after use.', 'announcement', 1, 'student_dashboard.php', '2026-04-11 09:05:59'),
(4, 11, 'New Announcement', 'Don\'t forget to clean and close your pc\'s after use.', 'announcement', 1, 'student_dashboard.php', '2026-04-11 09:05:59'),
(5, 12, 'New Announcement', 'Don\'t forget to clean and close your pc\'s after use.', 'announcement', 0, 'student_dashboard.php', '2026-04-11 09:05:59'),
(6, 13, 'New Announcement', 'Welcome', 'announcement', 0, 'student_dashboard.php', '2026-04-11 09:57:07'),
(7, 11, 'New Announcement', 'Welcome', 'announcement', 0, 'student_dashboard.php', '2026-04-11 09:57:07'),
(8, 12, 'New Announcement', 'Welcome', 'announcement', 0, 'student_dashboard.php', '2026-04-11 09:57:07');

-- --------------------------------------------------------

--
-- Table structure for table `pc_maintenance`
--

CREATE TABLE `pc_maintenance` (
  `id` int(11) NOT NULL,
  `laboratory` varchar(10) NOT NULL,
  `pc_no` int(11) NOT NULL,
  `date` date NOT NULL,
  `previous_status` varchar(20) DEFAULT 'Vacant',
  `is_active` tinyint(1) DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pc_usage`
--

CREATE TABLE `pc_usage` (
  `id` int(11) NOT NULL,
  `laboratory` varchar(10) NOT NULL,
  `pc_no` int(11) NOT NULL,
  `sitin_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `post_comments`
--

CREATE TABLE `post_comments` (
  `id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `content` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `post_reactions`
--

CREATE TABLE `post_reactions` (
  `id` int(11) NOT NULL,
  `post_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('like','heart') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `purpose` varchar(100) NOT NULL,
  `laboratory` varchar(10) NOT NULL,
  `time_in` time NOT NULL,
  `time_out` time NOT NULL,
  `date` date NOT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sit_in_history`
--

CREATE TABLE `sit_in_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `purpose` varchar(100) NOT NULL,
  `laboratory` varchar(10) NOT NULL,
  `time_in` time NOT NULL,
  `time_out` time DEFAULT NULL,
  `date` date NOT NULL,
  `status` varchar(20) DEFAULT 'Completed',
  `admin_feedback` text DEFAULT NULL,
  `admin_feedback_type` enum('Positive','Negative') DEFAULT NULL,
  `admin_rating` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sit_in_history`
--

INSERT INTO `sit_in_history` (`id`, `user_id`, `purpose`, `laboratory`, `time_in`, `time_out`, `date`, `status`, `admin_feedback`, `admin_feedback_type`, `admin_rating`) VALUES
(7, 12, 'C Programming', '525', '14:50:00', '14:46:29', '2026-03-25', 'Completed', NULL, NULL, NULL),
(8, 11, 'Python Programming', '530', '15:46:00', '15:46:37', '2026-04-11', 'Completed', NULL, NULL, NULL),
(9, 13, 'Database Design', '530', '17:06:00', '17:09:19', '2026-04-11', 'Completed', NULL, NULL, NULL),
(10, 11, 'Network Security', '527', '17:30:00', '17:35:34', '2026-04-11', 'Completed', NULL, NULL, NULL),
(11, 11, 'C Programming', '526', '06:24:00', '13:42:32', '2026-05-25', 'Completed', 'ghghgh', 'Positive', 10);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `id_number` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `course` varchar(20) NOT NULL,
  `year_level` varchar(10) NOT NULL,
  `email` varchar(100) NOT NULL,
  `address` text NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_pic` varchar(255) DEFAULT 'default-avatar.png',
  `remaining_sessions` int(11) DEFAULT 30,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `id_number`, `first_name`, `last_name`, `middle_name`, `course`, `year_level`, `email`, `address`, `password`, `profile_pic`, `remaining_sessions`, `created_at`) VALUES
(11, '23772445', 'Joan', 'Aballe', 'SUSON', 'BSIT', '3', 'aballejoan7@gmail.com', 'Inayawan, Cebu City', '$2y$10$yfExXZpSKGNiPuJXq3DxV.sUkgWAi/sn9Qb9/7.hs6e.fMBoQPj06', 'uploads/profile_11_1774419699.jpg', 27, '2026-03-25 06:21:28'),
(12, '3677937', 'John Jeff', 'Salimbangon', 'P', 'BSIT', '2', 'jeffsalimbangon@gmail.com', 'Cebu City', '$2y$10$agz9Wp7.EhENZDwdW8y0.uTb1bnlAcHKLpNTxKxRKx8bXpV.u5Mze', 'default-avatar.png', 29, '2026-03-25 06:43:56'),
(13, '010105', 'Noe', 'Tobes', 'Alads', 'BSIT', '3', 'Noe@gmail.com', 'Pardo,Cebu City', '$2y$10$GLY6sgqrxpUT0qeRKRfFuOu.4/jJgf.yVVEnGgKsoFqIRSu8npXVC', 'uploads/profile_13_1775898166.jpg', 29, '2026-04-11 09:00:37'),
(14, '1010', 'Marinelle', 'Cebe', 'Ambot', 'BSIT', '3', 'cebe@gmail.com', 'Pardo, Cebu City', '$2y$10$HHbbh9UukAlV103iEJiy4eu2P7ven2tOln0oZJrww6ydzIOONrDGO', 'default-avatar.png', 30, '2026-05-25 07:28:14'),
(15, '2020', 'Josh', 'Padernal', 'Mario', 'BSCS', '2', 'josh@gmail.com', 'inayawan,cebu city', '$2y$10$/2gSAQr4CPPOzx.idGIdz..jqzRRdsl2heFdUJuOySPwNB1BsLor6', 'default-avatar.png', 30, '2026-05-25 07:29:04');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `community_posts`
--
ALTER TABLE `community_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_sit_in_id` (`sit_in_id`);

--
-- Indexes for table `instructors`
--
ALTER TABLE `instructors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `laboratories`
--
ALTER TABLE `laboratories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lab_number` (`lab_number`);

--
-- Indexes for table `lab_class`
--
ALTER TABLE `lab_class`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_lab_class` (`laboratory`,`date`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pc_maintenance`
--
ALTER TABLE `pc_maintenance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_pc_maintenance` (`laboratory`,`pc_no`,`date`);

--
-- Indexes for table `pc_usage`
--
ALTER TABLE `pc_usage`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sitin_id` (`sitin_id`);

--
-- Indexes for table `post_comments`
--
ALTER TABLE `post_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`);

--
-- Indexes for table `post_reactions`
--
ALTER TABLE `post_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reaction` (`post_id`,`user_id`,`type`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `sit_in_history`
--
ALTER TABLE `sit_in_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_number` (`id_number`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `community_posts`
--
ALTER TABLE `community_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `instructors`
--
ALTER TABLE `instructors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laboratories`
--
ALTER TABLE `laboratories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `lab_class`
--
ALTER TABLE `lab_class`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `pc_maintenance`
--
ALTER TABLE `pc_maintenance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pc_usage`
--
ALTER TABLE `pc_usage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `post_comments`
--
ALTER TABLE `post_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `post_reactions`
--
ALTER TABLE `post_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sit_in_history`
--
ALTER TABLE `sit_in_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `community_posts`
--
ALTER TABLE `community_posts`
  ADD CONSTRAINT `community_posts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pc_usage`
--
ALTER TABLE `pc_usage`
  ADD CONSTRAINT `pc_usage_ibfk_1` FOREIGN KEY (`sitin_id`) REFERENCES `sit_in_history` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `post_comments`
--
ALTER TABLE `post_comments`
  ADD CONSTRAINT `post_comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `post_reactions`
--
ALTER TABLE `post_reactions`
  ADD CONSTRAINT `post_reactions_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `community_posts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sit_in_history`
--
ALTER TABLE `sit_in_history`
  ADD CONSTRAINT `sit_in_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
