-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 28, 2026 at 01:19 AM
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
-- Database: `hospital_db_final`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_book_appointment` (IN `p_patient_id` INT, IN `p_service_id` INT, IN `p_appointment_type` ENUM('Consultation','Follow-up','Emergency'), IN `p_concern` TEXT)   BEGIN
  INSERT INTO appointments
    (patient_id, service_id, appointment_type, concern, status)
  VALUES
    (p_patient_id, p_service_id, p_appointment_type, p_concern, 'Pending');

  SELECT LAST_INSERT_ID() AS new_appointment_id,
         'Appointment request submitted. Pending schedule assignment.' AS message;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_cancel_appointment` (IN `p_appointment_id` INT, IN `p_patient_id` INT)   BEGIN
  DECLARE v_status VARCHAR(50);

  SELECT status INTO v_status
  FROM appointments
  WHERE appointment_id = p_appointment_id
    AND (patient_id = p_patient_id OR p_patient_id IS NULL);

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Error: Appointment not found';
  ELSEIF v_status = 'Completed' THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Error: Cannot cancel a completed appointment';
  ELSEIF v_status = 'Cancelled' THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Error: Appointment is already cancelled';
  ELSE
    UPDATE appointments
    SET status     = 'Cancelled',
        updated_at = NOW()
    WHERE appointment_id = p_appointment_id;
    SELECT 'Appointment cancelled successfully.' AS message;
  END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_complete_appointment` (IN `p_appointment_id` INT, IN `p_doctor_id` INT, IN `p_diagnosis` TEXT, IN `p_notes` TEXT, IN `p_prescription` TEXT)   BEGIN
  DECLARE v_patient_id INT;

  -- Validate doctor owns this appointment
  SELECT patient_id INTO v_patient_id
  FROM appointments
  WHERE appointment_id = p_appointment_id
    AND doctor_id      = p_doctor_id
    AND status         = 'Scheduled';

  IF v_patient_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Error: Appointment not found or not assigned to this doctor';
  END IF;

  -- Update appointment (trigger will handle billing)
  UPDATE appointments
  SET status       = 'Completed',
      notes        = p_notes,
      prescription = p_prescription,
      updated_at   = NOW()
  WHERE appointment_id = p_appointment_id;

  -- Insert permanent medical record
  INSERT INTO medical_records
    (appointment_id, patient_id, doctor_id, diagnosis, prescription, notes)
  VALUES
    (p_appointment_id, v_patient_id, p_doctor_id, p_diagnosis, p_prescription, p_notes);

  SELECT 'Appointment completed and medical record saved.' AS message;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_generate_revenue_report` ()   BEGIN
  -- Summary by payment status
  SELECT
    payment_status,
    COUNT(*)              AS total_bills,
    SUM(appointment_fee)  AS total_billed,
    SUM(amount_paid)      AS total_collected
  FROM billings
  GROUP BY payment_status;

  -- Revenue per doctor (using the existing function)
  SELECT
    d.doctor_id,
    d.doctor_name,
    d.specialty,
    dep.department_name,
    fn_calculate_doctor_revenue(d.doctor_id) AS total_revenue,
    fn_count_doctor_appointments(d.doctor_id, CURDATE()) AS todays_appointments
  FROM doctors d
  JOIN departments dep ON dep.department_id = d.department_id
  ORDER BY total_revenue DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_get_admin_dashboard_stats` ()   BEGIN
  SELECT
    fn_get_appointment_count_by_status('Pending')   AS total_pending,
    fn_get_appointment_count_by_status('Scheduled') AS total_scheduled,
    fn_get_appointment_count_by_status('Completed') AS total_completed,
    fn_get_appointment_count_by_status('Cancelled') AS total_cancelled,
    (SELECT COUNT(*) FROM billings WHERE payment_status = 'Unpaid') AS total_unpaid_bills,
    (SELECT COALESCE(SUM(amount_paid),0) FROM billings WHERE payment_status = 'Paid') AS total_revenue,
    (SELECT COUNT(*) FROM patients) AS total_patients,
    (SELECT COUNT(*) FROM doctors)  AS total_doctors;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_get_doctor_schedule` (IN `p_doctor_id` INT, IN `p_date` DATE)   BEGIN
  SELECT
    a.appointment_id,
    a.appointment_time,
    a.status,
    a.appointment_type,
    a.concern,
    a.notes,
    a.prescription,
    p.patient_name,
    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
    p.contact_number,
    p.gender
  FROM appointments a
  JOIN patients p ON a.patient_id = p.patient_id
  WHERE a.doctor_id        = p_doctor_id
    AND a.appointment_date = p_date
    AND a.status          != 'Cancelled'
  ORDER BY a.appointment_time ASC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_get_patient_appointments` (IN `p_patient_id` INT)   BEGIN
  SELECT
    a.appointment_id,
    a.appointment_date,
    a.appointment_time,
    a.status,
    a.appointment_type,
    a.concern,
    a.notes,
    a.prescription,
    a.created_at,
    d.doctor_name,
    d.specialty,
    dep.department_name,
    s.base_fee           AS consultation_fee,
    b.payment_status,
    b.billing_id,
    b.amount_paid,
    b.paid_at
  FROM appointments a
  LEFT JOIN doctors      d   ON d.doctor_id      = a.doctor_id
  LEFT JOIN departments  dep ON dep.department_id = d.department_id
  LEFT JOIN services     s   ON s.service_id      = a.service_id
  LEFT JOIN billings     b   ON b.appointment_id  = a.appointment_id
  WHERE a.patient_id = p_patient_id
  ORDER BY a.created_at DESC;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_process_payment` (IN `p_appointment_id` INT, IN `p_amount_paid` DECIMAL(10,2), IN `p_payment_method` VARCHAR(100))   BEGIN
  UPDATE billings
  SET payment_status = 'Paid',
      amount_paid    = p_amount_paid,
      payment_method = p_payment_method
  WHERE appointment_id = p_appointment_id
    AND payment_status = 'Unpaid';

  IF ROW_COUNT() > 0 THEN
    -- Return receipt data
    SELECT
      b.billing_id,
      b.billing_date,
      b.appointment_fee,
      b.amount_paid,
      b.payment_method,
      b.paid_at,
      p.patient_name,
      d.doctor_name,
      d.specialty,
      a.appointment_date,
      a.appointment_time,
      'Payment successful' AS message
    FROM billings b
    JOIN appointments a ON a.appointment_id = b.appointment_id
    JOIN patients     p ON p.patient_id     = b.patient_id
    JOIN doctors      d ON d.doctor_id      = a.doctor_id
    WHERE b.appointment_id = p_appointment_id;
  ELSE
    SELECT 'No unpaid bill found for this appointment' AS message;
  END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_register_patient` (IN `p_name` VARCHAR(255), IN `p_dob` DATE, IN `p_contact` VARCHAR(20), IN `p_gender` ENUM('Male','Female','Other'), IN `p_address` TEXT, IN `p_email` VARCHAR(255), IN `p_password_hash` VARCHAR(255))   BEGIN
  DECLARE v_new_patient_id INT;

  -- Insert into patients
  INSERT INTO patients
    (patient_name, date_of_birth, contact_number, gender, address)
  VALUES
    (p_name, p_dob, p_contact, p_gender, p_address);

  SET v_new_patient_id = LAST_INSERT_ID();

  -- Create linked user account
  INSERT INTO users (email, password_hash, role, patient_id)
  VALUES (p_email, p_password_hash, 'patient', v_new_patient_id);

  SELECT v_new_patient_id AS new_patient_id,
         LAST_INSERT_ID() AS new_user_id,
         'Patient registered successfully' AS message;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_register_user` (IN `p_email` VARCHAR(255), IN `p_password_hash` VARCHAR(255), IN `p_role` ENUM('doctor','admin'), IN `p_doctor_id` INT)   BEGIN
  INSERT INTO users (email, password_hash, role, doctor_id)
  VALUES (p_email, p_password_hash, p_role, p_doctor_id);

  SELECT LAST_INSERT_ID() AS new_user_id,
         'User registered successfully' AS message;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_calculate_doctor_revenue` (`p_doctor_id` INT) RETURNS DECIMAL(10,2)  BEGIN
  DECLARE total_revenue DECIMAL(10,2);
  SELECT COALESCE(SUM(b.appointment_fee), 0) INTO total_revenue
  FROM billings b
  INNER JOIN appointments a ON b.appointment_id = a.appointment_id
  WHERE a.doctor_id       = p_doctor_id
    AND b.payment_status  = 'Paid';
  RETURN total_revenue;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `fn_count_doctor_appointments` (`p_doctor_id` INT, `p_date` DATE) RETURNS INT(11)  BEGIN
  DECLARE appt_count INT;
  SELECT COUNT(*) INTO appt_count
  FROM appointments
  WHERE doctor_id        = p_doctor_id
    AND appointment_date = p_date
    AND status          != 'Cancelled';
  RETURN appt_count;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `fn_get_appointment_count_by_status` (`p_status` VARCHAR(50)) RETURNS INT(11)  BEGIN
  DECLARE v_count INT;
  SELECT COUNT(*) INTO v_count
  FROM appointments
  WHERE status = p_status;
  RETURN v_count;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `fn_get_consultation_fee` (`p_specialty` VARCHAR(255)) RETURNS DECIMAL(10,2)  BEGIN
  DECLARE v_fee DECIMAL(10,2);
  SELECT base_fee INTO v_fee
  FROM services
  WHERE specialty = p_specialty
  LIMIT 1;
  RETURN COALESCE(v_fee, 500.00);
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `fn_get_patient_info` (`p_patient_id` INT) RETURNS VARCHAR(400) CHARSET utf8mb4 COLLATE utf8mb4_general_ci  BEGIN
  DECLARE patient_info VARCHAR(400);
  SELECT CONCAT(
    patient_name,
    ' (Age: ', TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()),
    ', Gender: ', COALESCE(gender, 'N/A'),
    ', Contact: ', COALESCE(contact_number, 'N/A'),
    ')'
  )
  INTO patient_info
  FROM patients
  WHERE patient_id = p_patient_id;
  RETURN COALESCE(patient_info, 'Patient not found');
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `fn_get_patient_unpaid_balance` (`p_patient_id` INT) RETURNS DECIMAL(10,2)  BEGIN
  DECLARE total_unpaid DECIMAL(10,2);
  SELECT COALESCE(SUM(appointment_fee), 0) INTO total_unpaid
  FROM billings
  WHERE patient_id     = p_patient_id
    AND payment_status = 'Unpaid';
  RETURN total_unpaid;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `fn_is_slot_available` (`p_doctor_id` INT, `p_date` DATE, `p_time` TIME) RETURNS TINYINT(1)  BEGIN
  DECLARE slot_count INT;
  SELECT COUNT(*) INTO slot_count
  FROM appointments
  WHERE doctor_id        = p_doctor_id
    AND appointment_date = p_date
    AND appointment_time = p_time
    AND status NOT IN ('Cancelled');
  RETURN slot_count = 0;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `appointment_date` date DEFAULT NULL,
  `appointment_time` time DEFAULT NULL,
  `status` enum('Pending','Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `appointment_type` enum('Consultation','Follow-up','Emergency') NOT NULL DEFAULT 'Consultation',
  `concern` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `prescription` text DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `appointment_date`, `appointment_time`, `status`, `appointment_type`, `concern`, `notes`, `prescription`, `patient_id`, `doctor_id`, `service_id`, `created_at`, `updated_at`) VALUES
(1, '2026-02-23', '08:00:00', 'Completed', 'Consultation', 'qwe', 'qweqw', 'qwe', 13, 1, 1, '2026-02-23 00:42:31', '2026-02-23 00:48:33'),
(2, '2026-02-24', '13:00:00', 'Completed', 'Consultation', 'asddfsdf', 'adsas', 'adaads', 13, 2, 1, '2026-02-23 00:54:24', '2026-02-23 00:57:24'),
(3, '2026-02-23', '08:30:00', 'Completed', 'Consultation', 'asdasdasd', 'jhjh', 'jhgjhg', 13, 1, 1, '2026-02-23 01:07:51', '2026-02-23 01:13:54'),
(4, '2026-02-24', '13:30:00', 'Completed', 'Consultation', 'dadsa', 'sdf', 'sdfds', 13, 2, 1, '2026-02-23 01:36:55', '2026-02-23 23:19:34'),
(5, '2026-02-24', '08:00:00', 'Completed', 'Consultation', 'hgjhgjhgj', 'fgfgf', 'fgfgf', 14, 7, 4, '2026-02-23 11:29:05', '2026-02-23 11:32:40'),
(6, '2026-02-23', '09:00:00', 'Cancelled', 'Emergency', 'basta emergency', NULL, NULL, 15, 1, 1, '2026-02-23 11:43:11', '2026-02-23 13:51:13'),
(7, NULL, NULL, 'Cancelled', 'Consultation', 'rtrt', NULL, NULL, 15, NULL, 1, '2026-02-24 12:08:17', '2026-02-24 12:08:41'),
(8, NULL, NULL, 'Cancelled', 'Consultation', 'kkjk', NULL, NULL, 15, NULL, 1, '2026-02-24 14:01:58', '2026-02-24 14:04:08'),
(9, '2026-02-25', '08:00:00', 'Scheduled', 'Consultation', 'asdasd', NULL, NULL, 13, 1, 1, '2026-02-25 21:34:29', '2026-02-25 21:54:43');

--
-- Triggers `appointments`
--
DELIMITER $$
CREATE TRIGGER `trg_after_appointment_cancel` AFTER UPDATE ON `appointments` FOR EACH ROW BEGIN
  IF NEW.status = 'Cancelled' AND OLD.status != 'Cancelled' THEN
    UPDATE billings
    SET payment_status = 'Cancelled'
    WHERE appointment_id = NEW.appointment_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_after_appointment_complete` AFTER UPDATE ON `appointments` FOR EACH ROW BEGIN
  DECLARE v_fee DECIMAL(10,2);
  IF NEW.status = 'Completed' AND OLD.status != 'Completed' THEN
    SELECT s.base_fee INTO v_fee
    FROM services s
    INNER JOIN doctors d ON d.specialty = s.specialty
    WHERE d.doctor_id = NEW.doctor_id
    LIMIT 1;

    IF v_fee IS NULL THEN SET v_fee = 500.00; END IF;

    IF EXISTS (SELECT 1 FROM billings WHERE appointment_id = NEW.appointment_id) THEN
      UPDATE billings
      SET payment_status = 'Unpaid',
          appointment_fee = v_fee,
          billing_date    = CURDATE()
      WHERE appointment_id = NEW.appointment_id;
    ELSE
      INSERT INTO billings
        (patient_id, appointment_id, appointment_fee, payment_status, billing_date)
      VALUES
        (NEW.patient_id, NEW.appointment_id, v_fee, 'Unpaid', CURDATE());
    END IF;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_before_appointment_insert` BEFORE INSERT ON `appointments` FOR EACH ROW BEGIN
  DECLARE booking_count INT;
  SELECT COUNT(*) INTO booking_count
  FROM appointments
  WHERE doctor_id       = NEW.doctor_id
    AND appointment_date = NEW.appointment_date
    AND appointment_time = NEW.appointment_time
    AND status NOT IN ('Cancelled');
  IF booking_count > 0 THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Error: Doctor already has an appointment at this time';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_validate_appointment` BEFORE INSERT ON `appointments` FOR EACH ROW BEGIN
  IF NEW.appointment_date < CURDATE() THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Error: Cannot schedule appointments in the past';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `billings`
--

CREATE TABLE `billings` (
  `billing_id` int(11) NOT NULL,
  `appointment_fee` decimal(10,2) DEFAULT NULL,
  `amount_paid` decimal(10,2) DEFAULT 0.00,
  `payment_status` enum('Unpaid','Paid','Cancelled') NOT NULL DEFAULT 'Unpaid',
  `payment_method` varchar(100) DEFAULT NULL,
  `billing_date` date DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billings`
--

INSERT INTO `billings` (`billing_id`, `appointment_fee`, `amount_paid`, `payment_status`, `payment_method`, `billing_date`, `paid_at`, `patient_id`, `appointment_id`, `created_at`) VALUES
(1, 2500.00, 3000.00, 'Paid', 'Cash', '2026-02-23', '2026-02-23 00:49:32', 13, 1, '2026-02-23 00:48:33'),
(2, 2500.00, 3000.00, 'Paid', 'Cash', '2026-02-23', '2026-02-23 00:59:18', 13, 2, '2026-02-23 00:57:24'),
(3, 2500.00, 3000.00, 'Paid', 'Cash', '2026-02-23', '2026-02-23 01:15:17', 13, 3, '2026-02-23 01:13:54'),
(4, 800.00, 1000.00, 'Paid', 'Cash', '2026-02-23', '2026-02-23 11:34:47', 14, 5, '2026-02-23 11:32:40'),
(5, 450.00, 500.00, 'Paid', 'GCash', '2026-02-23', '2026-02-23 13:51:30', 15, 6, '2026-02-23 13:51:13'),
(6, 2500.00, 0.00, 'Unpaid', NULL, '2026-02-23', NULL, 13, 4, '2026-02-23 23:19:34'),
(7, 200.00, 500.00, 'Paid', 'Cash', '2026-02-24', '2026-02-24 12:09:55', 15, 7, '2026-02-24 12:08:41'),
(8, 200.00, 0.00, 'Unpaid', NULL, '2026-02-24', NULL, 15, 8, '2026-02-24 14:04:08');

--
-- Triggers `billings`
--
DELIMITER $$
CREATE TRIGGER `trg_after_billing_payment` BEFORE UPDATE ON `billings` FOR EACH ROW BEGIN
  IF NEW.payment_status = 'Paid' AND OLD.payment_status != 'Paid' THEN
    SET NEW.paid_at = NOW();
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL,
  `office_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `office_name`) VALUES
(1, 'Cardiology', 'Heart Care Office'),
(2, 'Neurology', 'Brain and Nerve Center'),
(3, 'Pediatrics', 'Child Health Office'),
(4, 'General Medicine', 'Family Clinic');

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

CREATE TABLE `doctors` (
  `doctor_id` int(11) NOT NULL,
  `doctor_name` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `specialty` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`doctor_id`, `doctor_name`, `first_name`, `last_name`, `specialty`, `contact_number`, `department_id`, `created_at`) VALUES
(1, 'Dr. Manuel Agustin', 'Manuel', 'Agustin', 'Cardiologist', '09991234501', 1, '2026-02-22 09:18:39'),
(2, 'Dr. Angela Morales', 'Angela', 'Morales', 'Cardiologist', '09991234506', 1, '2026-02-22 09:18:39'),
(3, 'Dr. Ricardo Santos', 'Ricardo', 'Santos', 'Neurologist', '09991234507', 2, '2026-02-22 09:18:39'),
(4, 'Dr. Elena Basco', 'Elena', 'Basco', 'Neurologist', '09991234510', 2, '2026-02-22 09:18:39'),
(5, 'Dr. Ramon Diaz', 'Ramon', 'Diaz', 'Pediatrician', '09991234503', 3, '2026-02-22 09:18:39'),
(6, 'Dr. Fiona Reyes', 'Fiona', 'Reyes', 'Pediatrician', '09991234508', 3, '2026-02-22 09:18:39'),
(7, 'Dr. Clara Bautista', 'Clara', 'Bautista', 'General Practitioner', '09991234504', 4, '2026-02-22 09:18:39'),
(8, 'Dr. Victor Lim', 'Victor', 'Lim', 'General Practitioner', '09991234509', 4, '2026-02-22 09:18:39');

-- --------------------------------------------------------

--
-- Table structure for table `doctor_schedules`
--

CREATE TABLE `doctor_schedules` (
  `schedule_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `slot_minutes` int(11) NOT NULL DEFAULT 30,
  `is_available` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctor_schedules`
--

INSERT INTO `doctor_schedules` (`schedule_id`, `doctor_id`, `day_of_week`, `start_time`, `end_time`, `slot_minutes`, `is_available`) VALUES
(1, 1, 'Monday', '08:00:00', '12:00:00', 30, 1),
(2, 1, 'Wednesday', '08:00:00', '12:00:00', 30, 1),
(3, 1, 'Friday', '08:00:00', '12:00:00', 30, 1),
(4, 2, 'Tuesday', '13:00:00', '17:00:00', 30, 1),
(5, 2, 'Thursday', '13:00:00', '17:00:00', 30, 1),
(6, 2, 'Saturday', '08:00:00', '12:00:00', 30, 1),
(7, 3, 'Monday', '09:00:00', '13:00:00', 45, 1),
(8, 3, 'Thursday', '09:00:00', '13:00:00', 45, 1),
(9, 4, 'Tuesday', '14:00:00', '18:00:00', 45, 1),
(10, 4, 'Friday', '14:00:00', '18:00:00', 45, 1),
(11, 5, 'Monday', '08:00:00', '12:00:00', 20, 1),
(12, 5, 'Tuesday', '08:00:00', '12:00:00', 20, 1),
(13, 5, 'Wednesday', '08:00:00', '12:00:00', 20, 1),
(14, 6, 'Wednesday', '10:00:00', '14:00:00', 20, 1),
(15, 6, 'Friday', '10:00:00', '14:00:00', 20, 1),
(16, 6, 'Saturday', '09:00:00', '13:00:00', 20, 1),
(17, 7, 'Tuesday', '08:00:00', '11:00:00', 30, 1),
(18, 7, 'Thursday', '08:00:00', '11:00:00', 30, 1),
(19, 7, 'Saturday', '08:00:00', '11:00:00', 30, 1),
(20, 8, 'Monday', '13:00:00', '17:00:00', 30, 1),
(21, 8, 'Wednesday', '13:00:00', '17:00:00', 30, 1),
(22, 8, 'Friday', '13:00:00', '17:00:00', 30, 1);

-- --------------------------------------------------------

--
-- Table structure for table `medical_records`
--

CREATE TABLE `medical_records` (
  `record_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `diagnosis` text DEFAULT NULL,
  `prescription` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_records`
--

INSERT INTO `medical_records` (`record_id`, `appointment_id`, `patient_id`, `doctor_id`, `diagnosis`, `prescription`, `notes`, `created_at`) VALUES
(1, 1, 13, 1, 'qweqw', 'qwe', 'qweqw', '2026-02-23 00:48:33'),
(2, 2, 13, 2, 'asd', 'adaads', 'adsas', '2026-02-23 00:57:24'),
(3, 3, 13, 1, 'hhgjhgj', 'jhgjhg', 'jhjh', '2026-02-23 01:13:54'),
(4, 5, 14, 7, 'fhgfhgfhgf', 'fgfgf', 'fgfgf', '2026-02-23 11:32:40'),
(5, 4, 13, 2, 'sdfsd', 'sdfds', 'sdf', '2026-02-23 23:19:34');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `patient_name` varchar(255) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`patient_id`, `patient_name`, `date_of_birth`, `contact_number`, `address`, `gender`, `created_at`) VALUES
(1, 'Juan Dela Cruz', '1995-04-15', '09171234567', NULL, 'Male', '2026-02-22 09:18:39'),
(2, 'Maria Santos', '2000-07-10', '09181234568', NULL, 'Female', '2026-02-22 09:18:39'),
(3, 'Jose Rizal', '1985-06-19', '09191234569', NULL, 'Male', '2026-02-22 09:18:39'),
(4, 'Ana Lopez', '1997-01-25', '09201234570', NULL, 'Female', '2026-02-22 09:18:39'),
(5, 'Mark Villanueva', '1989-12-12', '09211234571', NULL, 'Male', '2026-02-22 09:18:39'),
(6, 'Grace Lim', '2003-09-08', '09221234572', NULL, 'Female', '2026-02-22 09:18:39'),
(7, 'Carlo Mendoza', '1975-03-30', '09231234573', NULL, 'Male', '2026-02-22 09:18:39'),
(8, 'Jasmine Cruz', '1992-11-02', '09241234574', NULL, 'Female', '2026-02-22 09:18:39'),
(9, 'Allan Reyes', '1998-05-21', '09251234575', NULL, 'Male', '2026-02-22 09:18:39'),
(10, 'Sophia Tan', '1996-08-14', '09261234576', NULL, 'Female', '2026-02-22 09:18:39'),
(11, 'Juanita Cruz', '1990-05-20', '09170001122', NULL, 'Female', '2026-02-22 09:18:39'),
(12, 'Baby Joy', '2024-01-01', '09320332456', NULL, 'Female', '2026-02-22 09:18:39'),
(13, 'kinkin ruzzel', '2005-02-22', '123334321', NULL, 'Male', '2026-02-22 23:45:02'),
(14, 'john doe', '2019-03-06', '0645454', NULL, 'Male', '2026-02-23 11:27:55'),
(15, 'Samantha Virtudazo', '2006-02-01', '090644869285', NULL, 'Female', '2026-02-23 11:41:43');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `service_id` int(11) NOT NULL,
  `specialty` varchar(255) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `base_fee` decimal(10,2) NOT NULL DEFAULT 500.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `specialty`, `service_name`, `base_fee`) VALUES
(1, 'Cardiologist', 'Cardiology Consultation', 2500.00),
(2, 'Neurologist', 'Neurology Consultation', 2200.00),
(3, 'Pediatrician', 'Pediatrics Consultation', 1500.00),
(4, 'General Practitioner', 'General Consultation', 800.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('patient','doctor','admin') NOT NULL DEFAULT 'patient',
  `patient_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `email`, `password_hash`, `role`, `patient_id`, `doctor_id`, `is_active`, `created_at`) VALUES
(1, 'admin@hospital.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', NULL, NULL, 1, '2026-02-22 09:18:39'),
(2, 'dr.manuel.agustin@hospital.com', '$2y$10$EE8uFWoHh.NlveabytytWuB.fbKkWp82Kiq2Xp0Ker02QhLL8KnTC', 'doctor', NULL, 1, 1, '2026-02-22 17:31:52'),
(3, 'dr.angela.morales@hospital.com', '$2y$10$v.Ks8/JAIF9kpYcNb0ju4e7UfQs3ERDKNuROp1jtHNXf9sOyq.3xe', 'doctor', NULL, 2, 1, '2026-02-22 17:31:52'),
(4, 'dr.ricardo.santos@hospital.com', '', 'doctor', NULL, 3, 1, '2026-02-22 17:31:52'),
(5, 'dr.elena.basco@hospital.com', '', 'doctor', NULL, 4, 1, '2026-02-22 17:31:52'),
(6, 'dr.ramon.diaz@hospital.com', '', 'doctor', NULL, 5, 1, '2026-02-22 17:31:52'),
(7, 'dr.fiona.reyes@hospital.com', '', 'doctor', NULL, 6, 1, '2026-02-22 17:31:52'),
(8, 'dr.clara.bautista@hospital.com', '$2y$10$STPVlCNTXxYoObucSEeVDOXXRaYA76tMh1uWqWUAhXZMZZe.uEUe.', 'doctor', NULL, 7, 1, '2026-02-22 17:31:52'),
(9, 'dr.victor.lim@hospital.com', '', 'doctor', NULL, 8, 1, '2026-02-22 17:31:52'),
(10, 'admin@pulse.com', '$2y$10$VZ25DP.gVaCGQCSCokLpf.3qrzx2e.T.NGanKnNqgVUnisM2lze6u', 'admin', NULL, NULL, 1, '2026-02-22 21:28:03'),
(11, 'ruzzel@gmail.com', '$2y$10$O2qqeT.ckxsEnw0Bcj9vgeITo3E7yfu6J3jHQ/kKetNv29JmqIxNy', 'patient', 13, NULL, 1, '2026-02-22 23:45:02'),
(12, 'doe@gmail.com', '$2y$10$xtRbhedpgJx4p77MCCG.NuHMHXx7A5IWDwVzMAY.3QpNx5c6gvkJy', 'patient', 14, NULL, 1, '2026-02-23 11:27:55'),
(13, 'slav@gmail.com', '$2y$10$cG6b/bnxKYfVgqcyWHj1juwo5VwJEGU5GDs5TyHSjTiOiDvNyx9vm', 'patient', 15, NULL, 1, '2026-02-23 11:41:43');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_appointment_details`
-- (See below for the actual view)
--
CREATE TABLE `v_appointment_details` (
`appointment_id` int(11)
,`appointment_date` date
,`appointment_time` time
,`status` enum('Pending','Scheduled','Completed','Cancelled')
,`appointment_type` enum('Consultation','Follow-up','Emergency')
,`concern` text
,`notes` text
,`prescription` text
,`created_at` datetime
,`patient_id` int(11)
,`patient_name` varchar(255)
,`patient_contact` varchar(20)
,`gender` enum('Male','Female','Other')
,`patient_age` bigint(21)
,`doctor_id` int(11)
,`doctor_name` varchar(255)
,`specialty` varchar(255)
,`department_id` int(11)
,`department_name` varchar(255)
,`service_id` int(11)
,`service_name` varchar(255)
,`consultation_fee` decimal(10,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_billing_summary`
-- (See below for the actual view)
--
CREATE TABLE `v_billing_summary` (
`billing_id` int(11)
,`billing_date` date
,`appointment_fee` decimal(10,2)
,`amount_paid` decimal(10,2)
,`payment_status` enum('Unpaid','Paid','Cancelled')
,`payment_method` varchar(100)
,`paid_at` datetime
,`created_at` datetime
,`patient_id` int(11)
,`patient_name` varchar(255)
,`patient_age` bigint(21)
,`doctor_name` varchar(255)
,`specialty` varchar(255)
,`department_name` varchar(255)
,`appointment_date` date
,`appointment_time` time
,`appointment_type` enum('Consultation','Follow-up','Emergency')
,`appointment_status` enum('Pending','Scheduled','Completed','Cancelled')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_doctor_weekly_slots`
-- (See below for the actual view)
--
CREATE TABLE `v_doctor_weekly_slots` (
`schedule_id` int(11)
,`doctor_id` int(11)
,`doctor_name` varchar(255)
,`specialty` varchar(255)
,`department_name` varchar(255)
,`day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')
,`start_time` time
,`end_time` time
,`slot_minutes` int(11)
,`is_available` tinyint(1)
);

-- --------------------------------------------------------

--
-- Structure for view `v_appointment_details`
--
DROP TABLE IF EXISTS `v_appointment_details`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_appointment_details`  AS SELECT `a`.`appointment_id` AS `appointment_id`, `a`.`appointment_date` AS `appointment_date`, `a`.`appointment_time` AS `appointment_time`, `a`.`status` AS `status`, `a`.`appointment_type` AS `appointment_type`, `a`.`concern` AS `concern`, `a`.`notes` AS `notes`, `a`.`prescription` AS `prescription`, `a`.`created_at` AS `created_at`, `p`.`patient_id` AS `patient_id`, `p`.`patient_name` AS `patient_name`, `p`.`contact_number` AS `patient_contact`, `p`.`gender` AS `gender`, timestampdiff(YEAR,`p`.`date_of_birth`,curdate()) AS `patient_age`, `d`.`doctor_id` AS `doctor_id`, `d`.`doctor_name` AS `doctor_name`, `d`.`specialty` AS `specialty`, `dep`.`department_id` AS `department_id`, `dep`.`department_name` AS `department_name`, `s`.`service_id` AS `service_id`, `s`.`service_name` AS `service_name`, `s`.`base_fee` AS `consultation_fee` FROM ((((`appointments` `a` left join `patients` `p` on(`p`.`patient_id` = `a`.`patient_id`)) left join `doctors` `d` on(`d`.`doctor_id` = `a`.`doctor_id`)) left join `departments` `dep` on(`dep`.`department_id` = `d`.`department_id`)) left join `services` `s` on(`s`.`service_id` = `a`.`service_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_billing_summary`
--
DROP TABLE IF EXISTS `v_billing_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_billing_summary`  AS SELECT `b`.`billing_id` AS `billing_id`, `b`.`billing_date` AS `billing_date`, `b`.`appointment_fee` AS `appointment_fee`, `b`.`amount_paid` AS `amount_paid`, `b`.`payment_status` AS `payment_status`, `b`.`payment_method` AS `payment_method`, `b`.`paid_at` AS `paid_at`, `b`.`created_at` AS `created_at`, `p`.`patient_id` AS `patient_id`, `p`.`patient_name` AS `patient_name`, timestampdiff(YEAR,`p`.`date_of_birth`,curdate()) AS `patient_age`, `d`.`doctor_name` AS `doctor_name`, `d`.`specialty` AS `specialty`, `dep`.`department_name` AS `department_name`, `a`.`appointment_date` AS `appointment_date`, `a`.`appointment_time` AS `appointment_time`, `a`.`appointment_type` AS `appointment_type`, `a`.`status` AS `appointment_status` FROM ((((`billings` `b` left join `appointments` `a` on(`a`.`appointment_id` = `b`.`appointment_id`)) left join `patients` `p` on(`p`.`patient_id` = `b`.`patient_id`)) left join `doctors` `d` on(`d`.`doctor_id` = `a`.`doctor_id`)) left join `departments` `dep` on(`dep`.`department_id` = `d`.`department_id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_doctor_weekly_slots`
--
DROP TABLE IF EXISTS `v_doctor_weekly_slots`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_doctor_weekly_slots`  AS SELECT `ds`.`schedule_id` AS `schedule_id`, `ds`.`doctor_id` AS `doctor_id`, `d`.`doctor_name` AS `doctor_name`, `d`.`specialty` AS `specialty`, `dep`.`department_name` AS `department_name`, `ds`.`day_of_week` AS `day_of_week`, `ds`.`start_time` AS `start_time`, `ds`.`end_time` AS `end_time`, `ds`.`slot_minutes` AS `slot_minutes`, `ds`.`is_available` AS `is_available` FROM ((`doctor_schedules` `ds` join `doctors` `d` on(`d`.`doctor_id` = `ds`.`doctor_id`)) join `departments` `dep` on(`dep`.`department_id` = `d`.`department_id`)) WHERE `ds`.`is_available` = 1 ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `idx_appointment_date` (`appointment_date`);

--
-- Indexes for table `billings`
--
ALTER TABLE `billings`
  ADD PRIMARY KEY (`billing_id`),
  ADD UNIQUE KEY `uq_appointment_billing` (`appointment_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `appointment_id` (`appointment_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`);

--
-- Indexes for table `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`doctor_id`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `doctor_schedules`
--
ALTER TABLE `doctor_schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Indexes for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `billings`
--
ALTER TABLE `billings`
  MODIFY `billing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `doctors`
--
ALTER TABLE `doctors`
  MODIFY `doctor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `doctor_schedules`
--
ALTER TABLE `doctor_schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `medical_records`
--
ALTER TABLE `medical_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `billings`
--
ALTER TABLE `billings`
  ADD CONSTRAINT `billings_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `billings_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `doctors`
--
ALTER TABLE `doctors`
  ADD CONSTRAINT `doctors_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `doctor_schedules`
--
ALTER TABLE `doctor_schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD CONSTRAINT `records_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `records_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `records_ibfk_3` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`doctor_id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
