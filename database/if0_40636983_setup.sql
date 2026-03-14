-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Mar 13, 2026 at 07:40 PM
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
  `tenant_id` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `procedure_id` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT 1,
  `downpayment_ref` varchar(100) DEFAULT NULL,
  `is_walk_in` tinyint(1) DEFAULT 0,
  `appointment_date` date NOT NULL,
  `appointment_time` time DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `is_seen` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `tenant_id`, `patient_id`, `patient_name`, `procedure_id`, `status_id`, `downpayment_ref`, `is_walk_in`, `appointment_date`, `appointment_time`, `rejection_reason`, `is_seen`) VALUES
(356, 1, 8, 'John Cruz', 93, 1, NULL, 0, '2026-03-15', '09:00:00', NULL, 0),
(357, 1, 8, 'John Cruz', 93, 1, NULL, 0, '2026-03-15', '10:00:00', NULL, 0),
(358, 1, 8, 'John Cruz', 88, 2, 'DP001', 0, '2026-03-14', '02:00:00', NULL, 0),
(359, 1, 8, 'John Cruz', 93, 4, '', 0, '2026-03-13', '11:00:00', NULL, 1),
(360, 1, 8, 'John Cruz', 88, 7, 'DP002', 0, '2026-03-12', '03:00:00', NULL, 1),
(361, 1, 8, 'John Cruz', 93, 2, NULL, 1, '2026-03-13', '01:00:00', NULL, 0),
(362, 1, 8, 'John Cruz', 88, 1, NULL, 0, '2026-03-16', '04:00:00', NULL, 0),
(363, 1, 8, 'John Cruz', 93, 3, 'DP003', 0, '2026-03-13', '09:30:00', NULL, 1),
(364, 1, 8, 'John Cruz', 93, 2, NULL, 0, '2026-03-15', '09:00:00', NULL, 0),
(372, 4, 8, 'John Cruz', 93, 1, NULL, 0, '2026-03-15', '09:00:00', NULL, 0),
(373, 4, 8, 'John Cruz', 93, 1, NULL, 0, '2026-03-15', '10:00:00', NULL, 0),
(374, 4, 8, 'John Cruz', 88, 2, 'DP001', 0, '2026-03-14', '02:00:00', NULL, 0),
(376, 4, 8, 'John Cruz', 88, 7, 'DP002', 0, '2026-03-12', '03:00:00', NULL, 1),
(377, 4, 8, 'John Cruz', 93, 2, NULL, 1, '2026-03-13', '01:00:00', NULL, 0),
(378, 4, 8, 'John Cruz', 88, 1, NULL, 0, '2026-03-16', '04:00:00', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `availability_blocks`
--

CREATE TABLE `availability_blocks` (
  `block_date` date NOT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 1
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
  `tenant_id` int(11) DEFAULT NULL,
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
  `tenant_id` int(11) DEFAULT NULL,
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
  `tenant_id` int(11) DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `marital_status` varchar(20) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `chief_complaint` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
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

-- --------------------------------------------------------

--
-- Table structure for table `procedures`
--

CREATE TABLE `procedures` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `procedure_name` varchar(255) NOT NULL,
  `standard_cost` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `procedures`
--

INSERT INTO `procedures` (`id`, `tenant_id`, `category`, `procedure_name`, `standard_cost`) VALUES
(59, 1, 'Consultation', 'Online Consultation', 400.00),
(60, 1, 'Consultation', 'Face to Face Consultation', 500.00),
(61, 1, 'Oral Prophylaxis', 'Oral Prophylaxis (Light)', 1000.00),
(62, 1, 'Oral Prophylaxis', 'Oral Prophylaxis (Moderate)', 1500.00),
(63, 1, 'Oral Prophylaxis', 'Oral Prophylaxis (Heavy)', 2000.00),
(64, 1, 'Fluoride', 'Fluoride Treatment (U & L)', 1500.00),
(65, 1, 'Restorative', 'Light Cured Composite (per surface)', 1000.00),
(66, 1, 'Restorative', 'Temporary Filling (per tooth)', 500.00),
(67, 1, 'Restorative', 'Pits and Fissures', 1200.00),
(68, 1, 'Restorative', 'Direct Composite', 5000.00),
(69, 1, 'Restorative', 'Indirect Composite', 10000.00),
(70, 1, 'Surgery', 'Simple Extraction (per tooth)', 1000.00),
(71, 1, 'Surgery', 'Odontectomy (Minimum)', 10000.00),
(72, 1, 'Surgery', 'Frenectomy', 8000.00),
(73, 1, 'Surgery', 'Surgical Soft Tissue Resection / Gingivectomy', 10000.00),
(74, 1, 'Surgery', 'Alveolectomy / Exostosis', 15000.00),
(75, 1, 'Surgery', 'Implant (per implant)', 80000.00),
(76, 1, 'Veneers', 'Porcelain Veneer (Signum)', 20000.00),
(77, 1, 'Veneers', 'Porcelain Veneer (E-max)', 25000.00),
(78, 1, 'Veneers', 'Porcelain Veneer (Zirconia)', 30000.00),
(79, 1, 'Endodontics', 'Root Canal Treatment (per canal)', 8000.00),
(80, 1, 'Prosthodontics', 'Fixed Acrylic Jacket Crown', 5000.00),
(81, 1, 'Prosthodontics', 'PFM (Non-Precious Metal)', 18000.00),
(82, 1, 'Prosthodontics', 'PFM (Titite / Titanium)', 25000.00),
(83, 1, 'Prosthodontics', 'E-max Crown', 25000.00),
(84, 1, 'Prosthodontics', 'Zirconia Crown', 30000.00),
(85, 1, 'Prosthodontics', 'Recementation of Crown', 1000.00),
(86, 1, 'Dentures', 'Acrylic Anterior Only (per arch)', 8000.00),
(87, 1, 'Dentures', 'Anterior and Posterior Denture (per arch)', 15000.00),
(88, 1, 'Dentures', 'Posterior Only Denture (per arch)', 8000.00),
(89, 1, 'Dentures', 'One-Piece RPD (with metal)', 20000.00),
(90, 1, 'Dentures', 'Partial Flexible Denture (per arch)', 18000.00),
(91, 1, 'Dentures', 'Combination (Metal & Flexible)', 25000.00),
(92, 1, 'Dentures', 'Acrylic Complete Denture (U & L)', 30000.00),
(93, 1, 'Dentures', 'Porcelain Complete Denture (U & L)', 45000.00),
(94, 1, 'Radiology', 'Periapical X-ray', 500.00),
(95, 1, 'Radiology', 'Panoramic X-ray', 1200.00),
(96, 1, 'Radiology', 'Cephalometric X-ray', 1200.00),
(97, 1, 'Orthodontics', 'Conventional Braces (U & L)', 50000.00),
(98, 1, 'Orthodontics', 'Self-Ligating Braces (U & L)', 150000.00),
(99, 1, 'Orthodontics', 'Ceramic Braces (U & L)', 80000.00),
(100, 1, 'Orthodontics', 'Aligners (U & L)', 100000.00),
(101, 1, 'Orthodontics', 'Conventional Retainers (per arch)', 5000.00),
(102, 1, 'Orthodontics', 'Invisible / Lingual Retainers (per arch)', 6000.00),
(103, 1, 'Orthodontics', 'Re-bonding of Bracket (per bracket)', 500.00),
(104, 1, 'Orthodontics', 'Bracket Replacement (per bracket)', 1000.00),
(105, 1, 'Orthodontics', 'TADS (per device)', 10000.00),
(106, 1, 'Orthodontics', 'Ortho Exposure', 8000.00),
(107, 1, 'Bleaching', 'Conventional Bleaching (U & L)', 12000.00),
(108, 1, 'Bleaching', 'Light Activated Bleaching (U & L)', 20000.00),
(109, 1, 'Perio', 'Deep Scaling (per quadrant)', 3000.00),
(110, 1, 'Perio', 'Perio Surgery (per quadrant)', 10000.00),
(111, 1, 'Perio', 'Perio Probing', 5000.00),
(112, 1, 'TMJ', 'TMJ Appliance / Splint (per arch)', 30000.00);

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` int(11) NOT NULL,
  `clinic_name` varchar(255) NOT NULL,
  `clinic_email` varchar(100) DEFAULT NULL,
  `clinic_phone` varchar(20) DEFAULT NULL,
  `clinic_address` text DEFAULT NULL,
  `clinic_city` varchar(100) DEFAULT NULL,
  `clinic_province` varchar(100) DEFAULT NULL,
  `clinic_postal_code` varchar(20) DEFAULT NULL,
  `clinic_code` varchar(6) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `is_archived` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Clinics/Tenants in the system';

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `clinic_name`, `clinic_email`, `clinic_phone`, `clinic_address`, `clinic_city`, `clinic_province`, `clinic_postal_code`, `clinic_code`, `owner_id`, `status`, `is_archived`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'San Francisco Dental Clinic', 'info@sfclinic.com', '(555) 123-4567', '123 Dental Street', 'San Francisco', 'California', NULL, 'SFCDC1', NULL, 'approved', 0, 1, '2026-03-13 13:07:43', '2026-03-13 13:17:27'),
(4, 'Test', 'obs@gmail.com', '+639558050299', 'wdawdasdadawdwa', 'Pulilan', 'Bulacan', '3005', '3E2713', 105, 'approved', 0, 1, '2026-03-13 13:55:21', '2026-03-13 14:49:15'),
(6, 'family', 'fam@gmail.com', '+639558050299', 'wdawdasdadawdwa', 'Pulilan', 'Bulacan', '3005', 'A307E3', 107, 'approved', 0, 1, '2026-03-13 16:55:05', '2026-03-13 17:25:14');

-- --------------------------------------------------------

--
-- Table structure for table `tenant_audit_logs`
--

CREATE TABLE `tenant_audit_logs` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Audit trail for tenant data changes';

-- --------------------------------------------------------

--
-- Table structure for table `treatment_records`
--

CREATE TABLE `treatment_records` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
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

INSERT INTO `treatment_records` (`id`, `tenant_id`, `appointment_id`, `patient_id`, `procedure_id`, `dentist_id`, `treatment_date`, `notes`, `actual_cost`) VALUES
(140, 1, 359, 8, 93, 2, '2026-03-13', '', 45000.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email_verification_token` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token_expiry` datetime DEFAULT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `role` enum('super_admin','clinic_owner','dentist','staff','patient') DEFAULT 'patient',
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `tenant_id`, `first_name`, `last_name`, `username`, `password`, `email_verification_token`, `email`, `email_verified`, `verification_token_expiry`, `verification_token`, `token_expiry`, `role`, `is_archived`, `created_at`) VALUES
(1, NULL, 'System', 'Administrator', 'superadmin', '$2y$10$l9zs4n/0FxdhVevsN/JrY.NE5LwMteXYFKeSTnhyfiDuryjRKUGg2', NULL, 'superadmin@clinic.com', 1, NULL, NULL, NULL, 'super_admin', 0, '2026-03-13 00:00:00'),
(2, 1, 'Dr. Sarah', 'Johnson', 'dentist', '$2y$10$voC.G8bl7uastt4//z6jBOInDB.wajtfFEsxZlp.t2KoBbv/sbhp6', NULL, 'dentist@clinic.com', 1, NULL, NULL, NULL, 'dentist', 0, '2026-03-13 00:00:00'),
(3, 1, 'Maria', 'Santos', 'assistant', '$2y$10$voC.G8bl7uastt4//z6jBOInDB.wajtfFEsxZlp.t2KoBbv/sbhp6', NULL, 'assistant@clinic.com', 1, NULL, NULL, NULL, 'staff', 0, '2026-03-13 00:00:00'),
(105, 4, 'Franz', 'Nicolas', 'obs', '$2y$10$pGzMvurWqDp6mLV.XlLQbukFID.7LSq56gK4G6wMbWtAd3gGEK3H2', NULL, 'obs@gmail.com', 0, NULL, NULL, NULL, 'dentist', 0, '2026-03-13 13:55:21'),
(106, 5, 'Chris', 'Nicolas', 'chris', '$2y$10$t/VdRiJcYmkpcagLRIilCeAiO/bFsgguLp88/NHBlk/y8eAk0.CBW', NULL, 'Chris@gmail.com', 0, NULL, NULL, NULL, 'dentist', 0, '2026-03-13 14:50:19'),
(107, 6, 'fam', 'Nicolas', 'fam', '$2y$10$TlosTzWDzFZVE5juhXF0gOshajgcSxYX7xjuK0QEEmqphyKASM3s.', NULL, 'fam@gmail.com', 0, NULL, NULL, NULL, 'dentist', 0, '2026-03-13 16:55:05');

-- --------------------------------------------------------

--
-- Table structure for table `verification_logs`
--

CREATE TABLE `verification_logs` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `code` int(11) NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT (current_timestamp() + interval 15 minute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
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
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `fk_complaint_user` (`patient_id`),
  ADD KEY `fk_complaint_cat` (`category_id`),
  ADD KEY `fk_complaint_status` (`status_id`);

--
-- Indexes for table `patient_inquiries`
--
ALTER TABLE `patient_inquiries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `fk_inquiry_user` (`patient_id`),
  ADD KEY `fk_inquiry_status` (`status_id`);

--
-- Indexes for table `patient_profiles`
--
ALTER TABLE `patient_profiles`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_tenant_id` (`tenant_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_no` (`reference_no`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `fk_pay_user` (`patient_id`),
  ADD KEY `fk_pay_method` (`payment_method_id`),
  ADD KEY `fk_pay_type` (`payment_type_id`),
  ADD KEY `fk_pay_status` (`status_id`);

--
-- Indexes for table `procedures`
--
ALTER TABLE `procedures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `clinic_name` (`clinic_name`),
  ADD UNIQUE KEY `clinic_code` (`clinic_code`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `idx_clinic_code` (`clinic_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `tenant_audit_logs`
--
ALTER TABLE `tenant_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `treatment_records`
--
ALTER TABLE `treatment_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
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
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_tenant_id` (`tenant_id`),
  ADD KEY `idx_tenant_role` (`tenant_id`,`role`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=380;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=342;

--
-- AUTO_INCREMENT for table `procedures`
--
ALTER TABLE `procedures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tenant_audit_logs`
--
ALTER TABLE `tenant_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treatment_records`
--
ALTER TABLE `treatment_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=141;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=108;

--
-- AUTO_INCREMENT for table `verification_logs`
--
ALTER TABLE `verification_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `patient_complaints`
--
ALTER TABLE `patient_complaints`
  ADD CONSTRAINT `fk_complaint_cat` FOREIGN KEY (`category_id`) REFERENCES `lookup_categories` (`id`),
  ADD CONSTRAINT `fk_complaint_status` FOREIGN KEY (`status_id`) REFERENCES `lookup_statuses` (`id`),
  ADD CONSTRAINT `fk_complaint_user` FOREIGN KEY (`patient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
