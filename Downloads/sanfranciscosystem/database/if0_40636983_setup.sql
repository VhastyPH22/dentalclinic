-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 21, 2026 at 07:50 AM
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
-- Database: `if0_40636983_setup`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `procedure_id` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT 1,
  `downpayment_ref` varchar(100) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `is_seen` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `patient_id`, `patient_name`, `procedure_id`, `status_id`, `downpayment_ref`, `appointment_date`, `appointment_time`, `rejection_reason`, `is_seen`) VALUES
(313, 8, 'cruz test', 59, 4, 'test', '2026-01-20', '09:00:00', NULL, 0),
(314, 8, 'cruz test', 60, 4, 'test', '2026-01-20', '09:00:00', NULL, 1),
(315, 8, 'cruz test', 81, 4, '124151', '2026-01-20', '09:00:00', NULL, 0),
(316, 8, 'cruz test', 59, 4, 'dwadasd', '2026-01-20', '09:00:00', NULL, 0),
(317, 8, 'cruz test', 60, 4, 'test', '2026-01-20', '09:00:00', NULL, 0),
(318, 8, 'cruz test', 60, 2, 'test', '2026-01-21', '09:00:00', NULL, 1),
(319, 8, 'cruz test', 60, 2, 'dawdasd', '2026-01-21', '11:00:00', NULL, 1),
(320, 8, 'cruz test', 60, 2, 'test', '2026-01-21', '09:00:00', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `availability_blocks`
--

CREATE TABLE `availability_blocks` (
  `block_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lookup_categories`
--

CREATE TABLE `lookup_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lookup_categories`
--

INSERT INTO `lookup_categories` (`id`, `category_name`) VALUES
(1, 'billing'),
(2, 'appointment'),
(3, 'wait-time'),
(4, 'treatment'),
(5, 'staff'),
(6, 'facility'),
(7, 'other');

-- --------------------------------------------------------

--
-- Table structure for table `lookup_payment_methods`
--

CREATE TABLE `lookup_payment_methods` (
  `id` int(11) NOT NULL,
  `method_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lookup_payment_methods`
--

INSERT INTO `lookup_payment_methods` (`id`, `method_name`) VALUES
(1, 'Cash'),
(2, 'Gcash'),
(3, 'Bank transfer');

-- --------------------------------------------------------

--
-- Table structure for table `lookup_payment_types`
--

CREATE TABLE `lookup_payment_types` (
  `id` int(11) NOT NULL,
  `type_label` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lookup_payment_types`
--

INSERT INTO `lookup_payment_types` (`id`, `type_label`) VALUES
(1, 'FULLPAYMENT'),
(2, 'DOWN PAYMENT'),
(3, 'INSTALLMENT PAYMENT');

-- --------------------------------------------------------

--
-- Table structure for table `lookup_statuses`
--

CREATE TABLE `lookup_statuses` (
  `id` int(11) NOT NULL,
  `status_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lookup_statuses`
--

INSERT INTO `lookup_statuses` (`id`, `status_name`) VALUES
(2, 'Confirmed'),
(3, 'Completed'),
(4, 'Paid'),
(7, 'Cancelled');

-- --------------------------------------------------------

--
-- Table structure for table `patient_complaints`
--

CREATE TABLE `patient_complaints` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `status_id` int(11) NOT NULL DEFAULT 1,
  `visit_reason` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `dentist_response` text DEFAULT NULL,
  `is_seen` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_inquiries`
--

CREATE TABLE `patient_inquiries` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `status_id` int(11) NOT NULL DEFAULT 1,
  `contact_info` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `dentist_response` text DEFAULT NULL,
  `is_seen` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_profiles`
--

CREATE TABLE `patient_profiles` (
  `user_id` int(11) NOT NULL,
  `dob` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `chief_complaint` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patient_profiles`
--

INSERT INTO `patient_profiles` (`user_id`, `dob`, `phone`, `address`, `occupation`, `marital_status`, `gender`, `chief_complaint`) VALUES
(30, '2026-01-11', '09658050291', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method_id` int(11) DEFAULT NULL,
  `payment_type_id` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT 3,
  `reference_no` varchar(100) DEFAULT NULL,
  `payment_type` varchar(50) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `patient_id`, `amount`, `payment_date`, `payment_method_id`, `payment_type_id`, `status_id`, `reference_no`, `payment_type`, `payment_method`) VALUES
(332, 8, 400.00, '2026-01-19', 1, 1, 3, 'INV-63724', NULL, NULL),
(333, 8, 18500.00, '2026-01-19', 1, 1, 3, 'INV-39134', NULL, NULL),
(334, 8, 400.00, '2026-01-19', 1, 1, 3, 'INV-90101', NULL, NULL),
(335, 8, 500.00, '2026-01-19', 1, 1, 3, 'INV-80505', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `procedures`
--

CREATE TABLE `procedures` (
  `id` int(11) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `procedure_name` varchar(255) NOT NULL,
  `standard_cost` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procedures`
--

INSERT INTO `procedures` (`id`, `category`, `procedure_name`, `standard_cost`) VALUES
(59, 'Consultation', 'Online Consultation', 400.00),
(60, 'Consultation', 'Face to Face Consultation', 500.00),
(61, 'Oral Prophylaxis', 'Oral Prophylaxis (Light)', 1000.00),
(62, 'Oral Prophylaxis', 'Oral Prophylaxis (Moderate)', 1500.00),
(63, 'Oral Prophylaxis', 'Oral Prophylaxis (Heavy)', 2000.00),
(64, 'Fluoride', 'Fluoride Treatment (U & L)', 1500.00),
(65, 'Restorative', 'Light Cured Composite (per surface)', 1000.00),
(66, 'Restorative', 'Temporary Filling (per tooth)', 500.00),
(67, 'Restorative', 'Pits and Fissures', 1200.00),
(68, 'Restorative', 'Direct Composite', 5000.00),
(69, 'Restorative', 'Indirect Composite', 10000.00),
(70, 'Surgery', 'Simple Extraction (per tooth)', 1000.00),
(71, 'Surgery', 'Odontectomy (Minimum)', 10000.00),
(72, 'Surgery', 'Frenectomy', 8000.00),
(73, 'Surgery', 'Surgical Soft Tissue Resection / Gingivectomy', 10000.00),
(74, 'Surgery', 'Alveolectomy / Exostosis', 15000.00),
(75, 'Surgery', 'Implant (per implant)', 80000.00),
(76, 'Veneers', 'Porcelain Veneer (Signum)', 20000.00),
(77, 'Veneers', 'Porcelain Veneer (E-max)', 25000.00),
(78, 'Veneers', 'Porcelain Veneer (Zirconia)', 30000.00),
(79, 'Endodontics', 'Root Canal Treatment (per canal)', 8000.00),
(80, 'Prosthodontics', 'Fixed Acrylic Jacket Crown', 5000.00),
(81, 'Prosthodontics', 'PFM (Non-Precious Metal)', 18000.00),
(82, 'Prosthodontics', 'PFM (Titite / Titanium)', 25000.00),
(83, 'Prosthodontics', 'E-max Crown', 25000.00),
(84, 'Prosthodontics', 'Zirconia Crown', 30000.00),
(85, 'Prosthodontics', 'Recementation of Crown', 1000.00),
(86, 'Dentures', 'Acrylic Anterior Only (per arch)', 8000.00),
(87, 'Dentures', 'Anterior and Posterior Denture (per arch)', 15000.00),
(88, 'Dentures', 'Posterior Only Denture (per arch)', 8000.00),
(89, 'Dentures', 'One-Piece RPD (with metal)', 20000.00),
(90, 'Dentures', 'Partial Flexible Denture (per arch)', 18000.00),
(91, 'Dentures', 'Combination (Metal & Flexible)', 25000.00),
(92, 'Dentures', 'Acrylic Complete Denture (U & L)', 30000.00),
(93, 'Dentures', 'Porcelain Complete Denture (U & L)', 45000.00),
(94, 'Radiology', 'Periapical X-ray', 500.00),
(95, 'Radiology', 'Panoramic X-ray', 1200.00),
(96, 'Radiology', 'Cephalometric X-ray', 1200.00),
(97, 'Orthodontics', 'Conventional Braces (U & L)', 50000.00),
(98, 'Orthodontics', 'Self-Ligating Braces (U & L)', 150000.00),
(99, 'Orthodontics', 'Ceramic Braces (U & L)', 80000.00),
(100, 'Orthodontics', 'Aligners (U & L)', 100000.00),
(101, 'Orthodontics', 'Conventional Retainers (per arch)', 5000.00),
(102, 'Orthodontics', 'Invisible / Lingual Retainers (per arch)', 6000.00),
(103, 'Orthodontics', 'Re-bonding of Bracket (per bracket)', 500.00),
(104, 'Orthodontics', 'Bracket Replacement (per bracket)', 1000.00),
(105, 'Orthodontics', 'TADS (per device)', 10000.00),
(106, 'Orthodontics', 'Ortho Exposure', 8000.00),
(107, 'Bleaching', 'Conventional Bleaching (U & L)', 12000.00),
(108, 'Bleaching', 'Light Activated Bleaching (U & L)', 20000.00),
(109, 'Perio', 'Deep Scaling (per quadrant)', 3000.00),
(110, 'Perio', 'Perio Surgery (per quadrant)', 10000.00),
(111, 'Perio', 'Perio Probing', 5000.00),
(112, 'TMJ', 'TMJ Appliance / Splint (per arch)', 30000.00);

-- --------------------------------------------------------

--
-- Table structure for table `treatment_records`
--

CREATE TABLE `treatment_records` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `procedure_id` int(11) NOT NULL,
  `dentist_id` int(11) NOT NULL,
  `treatment_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `actual_cost` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `treatment_records`
--

INSERT INTO `treatment_records` (`id`, `appointment_id`, `patient_id`, `procedure_id`, `dentist_id`, `treatment_date`, `notes`, `actual_cost`) VALUES
(121, 291, 8, 61, 2, '2026-01-18', '', 1000.00),
(122, 292, 8, 97, 2, '2026-01-18', '', 50000.00),
(123, 293, 8, 90, 2, '2026-01-18', '', 18000.00),
(124, 297, 8, 60, 2, '2026-01-19', '', 500.00),
(125, 299, 8, 91, 2, '2026-01-19', '', 25000.00),
(126, 298, 8, 92, 2, '2026-01-19', '', 30000.00),
(127, 305, 8, 86, 2, '2026-01-19', '', 8000.00),
(128, 307, 8, 89, 2, '2026-01-19', '', 20000.00),
(129, 306, 8, 91, 2, '2026-01-19', '', 25000.00),
(130, 309, 8, 97, 2, '2026-01-19', '', 50000.00),
(131, 311, 8, 60, 2, '2026-01-19', '', 500.00),
(132, 313, 8, 59, 2, '2026-01-19', '', 400.00),
(133, 314, 8, 60, 2, '2026-01-19', '', 500.00),
(134, 315, 8, 81, 2, '2026-01-19', '', 18000.00),
(135, 316, 8, 59, 2, '2026-01-19', '', 400.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(255) NULL,
  `token_expiry` datetime NULL,
  `role` enum('patient','dentist','assistant') DEFAULT 'patient',
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `verification_logs`
--

CREATE TABLE IF NOT EXISTS `verification_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(255) NOT NULL,
  `code` INT NOT NULL,
  `used` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NULL DEFAULT (DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)),
  KEY `email_idx` (`email`),
  KEY `code_idx` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `username`, `password`, `email`, `role`, `is_archived`, `created_at`) VALUES
(2, 'Francis', 'Santiago', 'dentist_user', '$2y$10$9lKYZAxye2ll2qB7dxw2nOS9Dg7GWib/C5r2vZDLb0g3teXtDr7AO', 'timoteovhasty@gmail.com', 'dentist', 0, '2026-01-01 05:09:34'),
(3, 'Staff', 'Member', 'assistant_user', '$2y$10$9QmIrAtkQBh15gIJku3CLugAdJfuu6cHI/B7/0vHR8eD4RVykt/1a', 'assistant@clinic.com', 'assistant', 0, '2026-01-01 05:09:34'),
(8, 'cruz', 'test', 'cruz', '$2y$10$mFjB3bCPoDWEuwPsJiuvXeOCfFPpVliW7Qh2AsN9yOCfNm11PLEsu', 'admin@gmail.com', 'patient', 0, '2026-01-01 08:05:01'),
(30, 'christian', 'timoteo', 'christian', '$2y$10$4wH0egKrqDX4x6G7i/tZm.WLOPIFl0IRKDZUg0cBC3fG/.oBD0PlS', 'christian@gmail.com', 'patient', 0, '2026-01-18 09:54:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_appt_user` (`patient_id`),
  ADD KEY `fk_appt_proc` (`procedure_id`),
  ADD KEY `fk_appt_status` (`status_id`);

--
-- Indexes for table `availability_blocks`
--
ALTER TABLE `availability_blocks`
  ADD PRIMARY KEY (`block_date`);

--
-- Indexes for table `lookup_categories`
--
ALTER TABLE `lookup_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `lookup_payment_methods`
--
ALTER TABLE `lookup_payment_methods`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `lookup_payment_types`
--
ALTER TABLE `lookup_payment_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `lookup_statuses`
--
ALTER TABLE `lookup_statuses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `patient_complaints`
--
ALTER TABLE `patient_complaints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_complaint_user` (`patient_id`),
  ADD KEY `fk_complaint_cat` (`category_id`),
  ADD KEY `fk_complaint_status` (`status_id`);

--
-- Indexes for table `patient_inquiries`
--
ALTER TABLE `patient_inquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_inquiry_user` (`patient_id`),
  ADD KEY `fk_inquiry_status` (`status_id`);

--
-- Indexes for table `patient_profiles`
--
ALTER TABLE `patient_profiles`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `fk_pay_user` (`patient_id`),
  ADD KEY `fk_pay_method` (`payment_method_id`),
  ADD KEY `fk_pay_type` (`payment_type_id`),
  ADD KEY `fk_pay_status` (`status_id`);

--
-- Indexes for table `procedures`
--
ALTER TABLE `procedures`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `treatment_records`
--
ALTER TABLE `treatment_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_treat_patient` (`patient_id`),
  ADD KEY `fk_treat_dentist` (`dentist_id`),
  ADD KEY `fk_treat_proc` (`procedure_id`),
  ADD KEY `appointment_id` (`appointment_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `verification_logs`
--
ALTER TABLE `verification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email_idx` (`email`),
  ADD KEY `code_idx` (`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=321;

--
-- AUTO_INCREMENT for table `lookup_categories`
--
ALTER TABLE `lookup_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `lookup_payment_methods`
--
ALTER TABLE `lookup_payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `lookup_payment_types`
--
ALTER TABLE `lookup_payment_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `lookup_statuses`
--
ALTER TABLE `lookup_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `patient_complaints`
--
ALTER TABLE `patient_complaints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `patient_inquiries`
--
ALTER TABLE `patient_inquiries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=336;

--
-- AUTO_INCREMENT for table `procedures`
--
ALTER TABLE `procedures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;

--
-- AUTO_INCREMENT for table `treatment_records`
--
ALTER TABLE `treatment_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=136;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `verification_logs`
--
ALTER TABLE `verification_logs`
  MODIFY `id` INT NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appt_proc` FOREIGN KEY (`procedure_id`) REFERENCES `procedures` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appt_status` FOREIGN KEY (`status_id`) REFERENCES `lookup_statuses` (`id`),
  ADD CONSTRAINT `fk_appt_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `patient_complaints`
--
ALTER TABLE `patient_complaints`
  ADD CONSTRAINT `fk_complaint_cat` FOREIGN KEY (`category_id`) REFERENCES `lookup_categories` (`id`),
  ADD CONSTRAINT `fk_complaint_status` FOREIGN KEY (`status_id`) REFERENCES `lookup_statuses` (`id`),
  ADD CONSTRAINT `fk_complaint_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `patient_inquiries`
--
ALTER TABLE `patient_inquiries`
  ADD CONSTRAINT `fk_inquiry_status` FOREIGN KEY (`status_id`) REFERENCES `lookup_statuses` (`id`),
  ADD CONSTRAINT `fk_inquiry_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `patient_profiles`
--
ALTER TABLE `patient_profiles`
  ADD CONSTRAINT `fk_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_pay_method` FOREIGN KEY (`payment_method_id`) REFERENCES `lookup_payment_methods` (`id`),
  ADD CONSTRAINT `fk_pay_status` FOREIGN KEY (`status_id`) REFERENCES `lookup_statuses` (`id`),
  ADD CONSTRAINT `fk_pay_type` FOREIGN KEY (`payment_type_id`) REFERENCES `lookup_payment_types` (`id`),
  ADD CONSTRAINT `fk_pay_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `treatment_records`
--
ALTER TABLE `treatment_records`
  ADD CONSTRAINT `fk_treat_dentist` FOREIGN KEY (`dentist_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_treat_patient` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_treat_proc` FOREIGN KEY (`procedure_id`) REFERENCES `procedures` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
