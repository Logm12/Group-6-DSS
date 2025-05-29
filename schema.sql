SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `dss`
--
CREATE DATABASE IF NOT EXISTS `dss` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `dss`;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `classrooms`
--
DROP TABLE IF EXISTS `classrooms`;
CREATE TABLE `classrooms` (
  `ClassroomID` int(11) NOT NULL,
  `RoomCode` varchar(10) NOT NULL,
  `Capacity` int(11) DEFAULT NULL,
  `Type` varchar(50) DEFAULT 'Theory'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `classrooms`
--
INSERT INTO `classrooms` (`ClassroomID`, `RoomCode`, `Capacity`, `Type`) VALUES
(1, '407', 50, 'Theory'), (2, '406', 50, 'Theory'), (3, '504', 50, 'Theory'), (4, '507', 50, 'Theory'),
(5, '604', 50, 'Theory'), (6, '403', 50, 'Theory'), (7, '514', 50, 'Theory'), (8, '512', 50, 'Theory'),
(9, '506', 50, 'Theory'), (10, '402', 50, 'Theory'), (11, '503', 50, 'Theory'), (12, '501', 50, 'Theory'),
(13, '409', 50, 'Theory'), (14, '601', 50, 'Theory'), (15, '405', 50, 'Theory'), (16, '606', 50, 'Theory'),
(17, '401', 50, 'Theory'), (18, '502', 50, 'Theory'), (19, '602', 50, 'Theory'), (20, '408', 50, 'Theory'),
(21, '603', 50, 'Theory'), (22, '513', 50, 'Theory'), (23, '508', 50, 'Theory');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `courses`
--
DROP TABLE IF EXISTS `courses`;
CREATE TABLE `courses` (
  `CourseID` varchar(20) NOT NULL,
  `CourseName` varchar(255) NOT NULL,
  `Credits` int(11) DEFAULT NULL,
  `ExpectedStudents` int(11) DEFAULT NULL,
  `SessionDurationSlots` int(11) DEFAULT NULL -- Bạn có thể cần xem xét lại cột này nếu 1 slot giờ là 1 ca chuẩn
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `courses`
--
INSERT INTO `courses` (`CourseID`, `CourseName`, `Credits`, `ExpectedStudents`, `SessionDurationSlots`) VALUES
('BSA105501', 'Business Culture', NULL, NULL, 1), ('BSA301201', 'Marketing Research', NULL, NULL, 1),
('BSA301202', 'Marketing Research', NULL, NULL, 1), ('BSA301402', 'Service Marketing', NULL, NULL, 1),
('FIB300501', 'Investment and Portfolio Management', NULL, NULL, 1), ('INE105001', 'Microeconomics', NULL, NULL, 1),
('INE105002', 'Microeconomics', NULL, NULL, 1), ('INE105102', 'Macroeconomics', NULL, NULL, 1),
('INE300901', 'International Project Management', NULL, NULL, 1), ('INE306001', 'E-Commerce', NULL, NULL, 1),
('INS109101', 'English Linguistics 2', NULL, NULL, 1), ('INS200903', 'Principles of Accounting', NULL, NULL, 1),
('INS201101', 'Economic Law', NULL, NULL, 1), ('INS201501', 'Basic Finance', NULL, NULL, 1),
('INS201502', 'Basic Finance', NULL, NULL, 1), ('INS201503', 'Basic Finance', NULL, NULL, 1),
('INS201601', 'Risk and Risk Analysis', NULL, NULL, 1), ('INS203702', 'Information Systems and Business Processes', NULL, NULL, 1),
('INS207303', 'Programming 2', NULL, NULL, 1), ('INS207402', 'Discrete Mathematics', NULL, NULL, 1),
('INS209801', 'Principles of Accounting', NULL, NULL, 1), ('INS209804', 'Principles of Accounting', NULL, NULL, 1),
('INS210902', 'Managerial Accounting', NULL, NULL, 1), ('INS211102', 'Business Organization and Management', NULL, NULL, 1),
('INS300202', 'Financial Accounting 2', NULL, NULL, 1), ('INS301601', 'Computerized Accounting Practice', NULL, NULL, 1),
('INS301603', 'Computerized Accounting Practice', NULL, NULL, 1), ('INS30201', 'Global Supply Chain Management', NULL, NULL, 1),
('INS302203', 'International Business Law', NULL, NULL, 1), ('INS302803', 'Risk Management and Insurance', NULL, NULL, 1),
('INS302901', 'Financial Markets and Institutions', NULL, NULL, 1), ('INS303002', 'Financial Statement Analysis', NULL, NULL, 1),
('INS303201', 'International Finance', NULL, NULL, 1), ('INS304901', 'Econometrics', NULL, NULL, 1),
('INS306002', 'Advanced Database Development', NULL, NULL, 1), ('INS306603', 'Business Solutions for Enterprises', NULL, NULL, 1),
('INS306901', 'Decision Support Systems', NULL, NULL, 1), ('INS307403', 'Global Information Systems', NULL, NULL, 1),
('INS308001', 'Artificial Intelligence', NULL, NULL, 1), ('INS309701', 'Accounting I: Financial Accounting', NULL, NULL, 1),
('INS315101', 'Embedded Control Systems', NULL, NULL, 1), ('INS315201', 'Robotics', NULL, NULL, 1),
('INS318901', 'Corporate Finance', NULL, NULL, 1), ('INS323702', 'Electric Motors and Drive Systems', NULL, NULL, 1),
('INS323703', 'Electric Motors and Drive Systems', NULL, NULL, 1), ('INS325101', 'Taxation', NULL, NULL, 1),
('INS325201', 'Financial Accounting 2', NULL, NULL, 1), ('INS325402', 'Introduction to Data Science', NULL, NULL, 1),
('INS327102', 'International Accounting', NULL, NULL, 1), ('INS328003', 'Data Preparation and Visualization', NULL, NULL, 1),
('MAT100501', 'Mathematical Economics', NULL, NULL, 1), ('MAT109204', 'Advanced Mathematics', NULL, NULL, 1),
('PEC100802', 'Marx Leninist Political Economy', NULL, NULL, 1), ('PHI100201', 'Scientific Socialism', NULL, NULL, 1),
('RUS500201', 'Russian Language 1B', NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `instructorunavailableslots`
--
DROP TABLE IF EXISTS `instructorunavailableslots`;
CREATE TABLE `instructorunavailableslots` (
  `UnavailableID` int(11) NOT NULL,
  `LecturerID` int(11) NOT NULL,
  `BusyDayOfWeek` varchar(15) DEFAULT NULL,
  `BusyStartTime` time DEFAULT NULL, -- Cần xem xét việc ánh xạ sang ca chuẩn nếu cần
  `BusyEndTime` time DEFAULT NULL,   -- Cần xem xét việc ánh xạ sang ca chuẩn nếu cần
  `Reason` varchar(255) DEFAULT NULL,
  `SemesterID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `lecturers`
--
DROP TABLE IF EXISTS `lecturers`;
CREATE TABLE `lecturers` (
  `LecturerID` int(11) NOT NULL,
  `LecturerName` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `lecturers`
--
INSERT INTO `lecturers` (`LecturerID`, `LecturerName`) VALUES
(1, 'Truong Cong Doan'), (2, 'Pham Van Dai'), (3, 'Michael Omar'), (4, 'Nguyen Tat Thang'), (5, 'Ho Nguyen Nhu Y'),
(6, 'Hoang Trong Tien'), (7, 'Nguyen Thi Hanh'), (8, 'Ngo Trong Quan'), (9, 'Bui My Trinh'), (10, 'Tran The Nu'),
(11, 'Nguyen Van Tinh'), (12, 'Nguyen Hoang Dung'), (13, 'Nguyen Doan Dong'), (14, 'Nguyen Manh Hai'), (15, 'Do Van Hoan'),
(16, 'Phong Thi Thu Huyen'), (17, 'Tran Duc Phu'), (18, 'Nguyen Thi Kim Duyen'), (19, 'Hoang Ha Anh'), (20, 'Nguyen Hoang Lan'),
(21, 'Hoang Tuyet Minh'), (22, 'Hoang Trieu Hoa'), (23, 'Duong Van Duyen'), (24, 'Trinh Thi Thu Hang'), (25, 'Pham Thi Viet Huong'),
(26, 'Nguyen Duy Thanh'), (27, 'Pham Thi Thanh Thuy'), (28, 'Le Van Dao'), (29, 'Pham Thi Kim Dung'), (30, 'Alexis Rez'),
(31, 'Vu Dieu Thuy'), (32, 'Le Thi Mai'), (33, 'Nghiem Xuan Hoa'), (34, 'Nguyen Ngoc Quy'), (35, 'Vu Minh Quan'),
(36, 'Nguyen Tuan Minh'), (37, 'Le Thi Thu Huong'), (38, 'Duong My Hanh'), (39, 'Nguyen Thi Kim Oanh'), (40, 'Nguyen Thi Nhu Ai'),
(41, 'Nguyen Phu Hung'), (42, 'Bui To Quyen'), (43, 'Do Phuong Huyen'), (44, 'Chu Van Hung'), (45, 'Khuc The Anh'),
(46, 'Chu Huy Anh'), (47, 'Nguyen Thi Thanh Hoai'), (48, 'Nguyen Thi Thanh Phuong');


-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `semesters`
--
DROP TABLE IF EXISTS `semesters`;
CREATE TABLE `semesters` (
  `SemesterID` int(11) NOT NULL,
  `SemesterName` varchar(100) NOT NULL,
  `StartDate` date NOT NULL,
  `EndDate` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `semesters`
--
INSERT INTO `semesters` (`SemesterID`, `SemesterName`, `StartDate`, `EndDate`) VALUES
(1, 'Semester 1', '2025-01-01', '2025-06-30');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `timeslots`
--
DROP TABLE IF EXISTS `timeslots`;
CREATE TABLE `timeslots` (
  `TimeSlotID` int(11) NOT NULL,
  `DayOfWeek` varchar(15) NOT NULL,
  `StartTime` time NOT NULL,
  `EndTime` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `timeslots` (chuẩn hóa 4 ca)
--
INSERT INTO `timeslots` (`TimeSlotID`, `DayOfWeek`, `StartTime`, `EndTime`) VALUES
(1, 'Monday', '07:00:00', '09:40:00'), (2, 'Monday', '09:50:00', '12:30:00'),
(3, 'Monday', '13:00:00', '15:40:00'), (4, 'Monday', '15:50:00', '18:30:00'),
(5, 'Tuesday', '07:00:00', '09:40:00'), (6, 'Tuesday', '09:50:00', '12:30:00'),
(7, 'Tuesday', '13:00:00', '15:40:00'), (8, 'Tuesday', '15:50:00', '18:30:00'),
(9, 'Wednesday', '07:00:00', '09:40:00'), (10, 'Wednesday', '09:50:00', '12:30:00'),
(11, 'Wednesday', '13:00:00', '15:40:00'), (12, 'Wednesday', '15:50:00', '18:30:00'),
(13, 'Thursday', '07:00:00', '09:40:00'), (14, 'Thursday', '09:50:00', '12:30:00'),
(15, 'Thursday', '13:00:00', '15:40:00'), (16, 'Thursday', '15:50:00', '18:30:00'),
(17, 'Friday', '07:00:00', '09:40:00'), (18, 'Friday', '09:50:00', '12:30:00'),
(19, 'Friday', '13:00:00', '15:40:00'), (20, 'Friday', '15:50:00', '18:30:00'),
(21, 'Saturday', '07:00:00', '09:40:00'), (22, 'Saturday', '09:50:00', '12:30:00'),
(23, 'Saturday', '13:00:00', '15:40:00'), (24, 'Saturday', '15:50:00', '18:30:00');

-- --------------------------------------------------------
--
-- Logic ánh xạ `OldTimeSlotID` sang `NewTimeSlotID` (để tham khảo khi kiểm tra dữ liệu `scheduledclasses`)
-- Dựa trên `DayOfWeek` và `StartTime` của slot cũ để xác định `NewTimeSlotID` tương ứng.
-- Ca 1: StartTime < '09:50:00'
-- Ca 2: StartTime >= '09:50:00' AND StartTime < '13:00:00'
-- Ca 3: StartTime >= '13:00:00' AND StartTime < '15:50:00'
-- Ca 4: StartTime >= '15:50:00'
--
-- Ví dụ ánh xạ (Old TimeSlotID -> DayOfWeek_Old, StartTime_Old -> Ca_New -> New TimeSlotID):
-- OldTSID 1 (Tue, 07:00:00) -> Ca 1 (Tue) -> NewTSID 5
-- OldTSID 2 (Tue, 09:50:00) -> Ca 2 (Tue) -> NewTSID 6
-- OldTSID 3 (Wed, 09:50:00) -> Ca 2 (Wed) -> NewTSID 10
-- OldTSID 24 (Fri, 07:00:00) -> Ca 1 (Fri) -> NewTSID 17
-- OldTSID 28 (Sat, 13:00:00) -> Ca 3 (Sat) -> NewTSID 23
-- ... (tiếp tục cho tất cả các old TimeSlotID)
-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `scheduledclasses`
--
DROP TABLE IF EXISTS `scheduledclasses`;
CREATE TABLE `scheduledclasses` (
  `ScheduleID` int(11) NOT NULL,
  `CourseID` varchar(20) NOT NULL,
  `LecturerID` int(11) NOT NULL,
  `ClassroomID` int(11) NOT NULL,
  `TimeSlotID` int(11) NOT NULL, -- Sẽ tham chiếu TimeSlotID mới (1-24)
  `SemesterID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `scheduledclasses` (với TimeSlotID đã ánh xạ sang ca chuẩn)
--
INSERT INTO `scheduledclasses` (`ScheduleID`, `CourseID`, `LecturerID`, `ClassroomID`, `TimeSlotID`, `SemesterID`) VALUES
(113, 'BSA105501', 33, 5, 9, 1),      -- OldTSID 14 (Wed, 07:00) -> Ca 1 (Wed) -> NewTSID 9
(114, 'BSA301201', 18, 21, 17, 1),    -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17
(115, 'BSA301202', 35, 2, 10, 1),     -- OldTSID 3 (Wed, 09:50) -> Ca 2 (Wed) -> NewTSID 10
(116, 'BSA301402', 3, 22, 13, 1),     -- OldTSID 6 (Thu, 07:00) -> Ca 1 (Thu) -> NewTSID 13
(117, 'FIB300501', 26, 21, 6, 1),     -- OldTSID 2 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(118, 'INE105001', 20, 18, 18, 1),    -- OldTSID 27 (Fri, 09:50) -> Ca 2 (Fri) -> NewTSID 18
(119, 'INE105002', 36, 9, 10, 1),     -- OldTSID 3 (Wed, 09:50) -> Ca 2 (Wed) -> NewTSID 10
(120, 'INE105102', 24, 20, 9, 1),     -- OldTSID 14 (Wed, 07:00) -> Ca 1 (Wed) -> NewTSID 9
(121, 'INE300901', 22, 17, 18, 1),    -- OldTSID 27 (Fri, 09:50) -> Ca 2 (Fri) -> NewTSID 18
(122, 'INE306001', 4, 22, 6, 1),      -- OldTSID 23 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(123, 'INS109101', 21, 4, 1, 1),      -- OldTSID 16 (Mon, 07:00) -> Ca 1 (Mon) -> NewTSID 1
(124, 'INS200903', 18, 2, 5, 1),      -- OldTSID 1 (Tue, 07:00) -> Ca 1 (Tue) -> NewTSID 5
(125, 'INS201101', 28, 4, 10, 1),     -- OldTSID 3 (Wed, 09:50) -> Ca 2 (Wed) -> NewTSID 10
(126, 'INS201501', 37, 7, 6, 1),      -- OldTSID 23 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(127, 'INS201502', 45, 23, 14, 1),    -- OldTSID 4 (Thu, 09:50) -> Ca 2 (Thu) -> NewTSID 14
(128, 'INS201503', 2, 8, 13, 1),      -- OldTSID 6 (Thu, 07:00) -> Ca 1 (Thu) -> NewTSID 13
(130, 'INS203702', 26, 19, 18, 1),    -- OldTSID 27 (Fri, 09:50) -> Ca 2 (Fri) -> NewTSID 18
(131, 'INS207303', 12, 22, 14, 1),    -- OldTSID 4 (Thu, 09:50) -> Ca 2 (Thu) -> NewTSID 14
(132, 'INS207402', 10, 3, 2, 1),      -- OldTSID 19 (Mon, 09:50) -> Ca 2 (Mon) -> NewTSID 2
(133, 'INS209801', 19, 21, 1, 1),     -- OldTSID 5 (Mon, 07:00) -> Ca 1 (Mon) -> NewTSID 1
(135, 'INS210902', 19, 4, 17, 1),     -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17
(136, 'INS211102', 9, 3, 14, 1),      -- OldTSID 4 (Thu, 09:50) -> Ca 2 (Thu) -> NewTSID 14
(137, 'INS300202', 18, 22, 1, 1),     -- OldTSID 16 (Mon, 07:00) -> Ca 1 (Mon) -> NewTSID 1
(138, 'INS301601', 25, 12, 2, 1),     -- OldTSID 19 (Mon, 09:50) -> Ca 2 (Mon) -> NewTSID 2
(139, 'INS301603', 18, 2, 14, 1),     -- OldTSID 4 (Thu, 09:50) -> Ca 2 (Thu) -> NewTSID 14
(140, 'INS30201', 42, 15, 1, 1),      -- OldTSID 5 (Mon, 07:00) -> Ca 1 (Mon) -> NewTSID 1
(142, 'INS302803', 32, 15, 2, 1),     -- OldTSID 19 (Mon, 09:50) -> Ca 2 (Mon) -> NewTSID 2
(143, 'INS302901', 38, 7, 17, 1),     -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17
(144, 'INS303002', 7, 8, 5, 1),       -- OldTSID 1 (Tue, 07:00) -> Ca 1 (Tue) -> NewTSID 5
(145, 'INS303201', 32, 4, 5, 1),      -- OldTSID 1 (Tue, 07:00) -> Ca 1 (Tue) -> NewTSID 5
(146, 'INS304901', 29, 23, 13, 1),    -- OldTSID 21 (Thu, 07:00) -> Ca 1 (Thu) -> NewTSID 13
(147, 'INS306002', 43, 4, 18, 1),     -- OldTSID 27 (Fri, 09:50) -> Ca 2 (Fri) -> NewTSID 18
(148, 'INS306603', 21, 9, 17, 1),     -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17
(149, 'INS306901', 44, 1, 10, 1),     -- OldTSID 3 (Wed, 09:50) -> Ca 2 (Wed) -> NewTSID 10
(150, 'INS307403', 36, 14, 17, 1),    -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17
(151, 'INS308001', 39, 13, 6, 1),     -- OldTSID 2 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(152, 'INS309701', 22, 2, 6, 1),      -- OldTSID 23 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(153, 'INS315101', 42, 2, 6, 1),      -- OldTSID 2 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(154, 'INS315201', 4, 23, 9, 1),      -- OldTSID 14 (Wed, 07:00) -> Ca 1 (Wed) -> NewTSID 9
(155, 'INS318901', 35, 17, 2, 1),     -- OldTSID 19 (Mon, 09:50) -> Ca 2 (Mon) -> NewTSID 2
(156, 'INS323702', 19, 5, 13, 1),     -- OldTSID 6 (Thu, 07:00) -> Ca 1 (Thu) -> NewTSID 13
(158, 'INS325101', 7, 8, 17, 1),      -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17
(159, 'INS325201', 6, 6, 2, 1),       -- OldTSID 19 (Mon, 09:50) -> Ca 2 (Mon) -> NewTSID 2
(161, 'INS327102', 38, 12, 5, 1),     -- OldTSID 1 (Tue, 07:00) -> Ca 1 (Tue) -> NewTSID 5
(162, 'INS328003', 5, 14, 18, 1),     -- OldTSID 27 (Fri, 09:50) -> Ca 2 (Fri) -> NewTSID 18
(163, 'MAT100501', 48, 20, 6, 1),     -- OldTSID 23 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(164, 'MAT109204', 22, 10, 1, 1),     -- OldTSID 16 (Mon, 07:00) -> Ca 1 (Mon) -> NewTSID 1
(165, 'PEC100802', 33, 21, 6, 1),     -- OldTSID 17 (Tue, 09:50) -> Ca 2 (Tue) -> NewTSID 6
(166, 'PHI100201', 37, 10, 13, 1),    -- OldTSID 18 (Thu, 07:55) -> Ca 1 (Thu) -> NewTSID 13
(167, 'RUS500201', 4, 22, 17, 1);     -- OldTSID 24 (Fri, 07:00) -> Ca 1 (Fri) -> NewTSID 17

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `students`
--
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `StudentID` varchar(20) NOT NULL,
  `StudentName` varchar(100) NOT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Program` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `students`
--
INSERT INTO `students` (`StudentID`, `StudentName`, `Email`, `Program`) VALUES
('20070024', 'Nguyen Minh Duy', NULL, NULL), ('20070226', 'Khuat Thi Khanh Linh', NULL, NULL),
('20070499', 'Tran Ngoc Khanh', NULL, NULL), ('20070521', 'Vu Nguyen Nguyet Linh', NULL, NULL),
('20070527', 'Ninh Phuong Ly', NULL, NULL), ('20070666', 'Hoang Quynh Anh', NULL, NULL),
('20070799', 'Nguyen Lam Truong', NULL, NULL), ('20070847', 'Tuong Duc Kien', NULL, NULL),
('21070014', 'Nguyen Gia Han', NULL, NULL), ('21070071', 'Le Hong Minh', NULL, NULL),
('21070200', 'Do Ngoc Minh', NULL, NULL), ('21070275', 'Phung Trung Kien', NULL, NULL),
('21070312', 'Hoang Minh Ha', NULL, NULL), ('21070354', 'Tran Thi Tra My', NULL, NULL),
('21070410', 'Le Thuy Huyen', NULL, NULL), ('21070466', 'Dang Thi Hoa', NULL, NULL),
('21070479', 'Nguyen Thi Quynh Nga', NULL, NULL), ('21070508', 'Le Tran Tuyet Mai', NULL, NULL),
('21070596', 'Nguyen Thanh Long', NULL, NULL), ('21070782', 'Vu Cong Minh', NULL, NULL),
('22070129', 'Pham Gia Khanh', NULL, NULL), ('22070247', 'Ta Khac Dong', NULL, NULL),
('22070497', 'Nguyen Tung Duong', NULL, NULL), ('22070562', 'Nguyen Mai Duyen', NULL, NULL),
('22070651', 'Ha Tuan Hiep', NULL, NULL), ('22070717', 'Bui Phuong Mai', NULL, NULL),
('22070784', 'Nguyen Thuy Linh', NULL, NULL), ('22070820', 'Pham Cong Minh', NULL, NULL),
('22070949', 'Nguyen Do Khanh Linh', NULL, NULL), ('22071018', 'Pham Quang Minh', NULL, NULL),
('22071035', 'Do Minh Khanh', NULL, NULL), ('23070200', 'Dinh Manh Hung', NULL, NULL),
('23070201', 'Nguyen Huy Hieu', NULL, NULL), ('23070710', 'TRAN THI PHUONG ANH', NULL, NULL),
('23071014', 'Nguyen Thi Gia Linh', NULL, NULL);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `studentenrollments`
--
DROP TABLE IF EXISTS `studentenrollments`;
CREATE TABLE `studentenrollments` (
  `EnrollmentID` int(11) NOT NULL,
  `StudentID` varchar(20) NOT NULL,
  `CourseID` varchar(20) NOT NULL,
  `SemesterID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `studentenrollments`
--
INSERT INTO `studentenrollments` (`EnrollmentID`, `StudentID`, `CourseID`, `SemesterID`) VALUES
(1, '20070666', 'INS308001', 1), (2, '20070666', 'INS306002', 1), (3, '20070666', 'INS306901', 1),
(4, '20070666', 'INS307403', 1), (5, '23070710', 'MAT100501', 1), (6, '23070710', 'INS30201', 1),
(7, '23070710', 'INS203702', 1), (8, '23070710', 'PHI100201', 1), (9, '23070710', 'INS302203', 1),
(10, '23070710', 'BSA301201', 1), (11, '20070521', 'INS300202', 1), (12, '21070275', 'INS323703', 1),
(13, '21070275', 'INS315101', 1), (14, '20070847', 'INS207303', 1), (15, '20070847', 'RUS500201', 1),
(16, '20070847', 'INS207402', 1), (17, '22071035', 'MAT109204', 1), (18, '22071035', 'INS201101', 1),
(19, '22071035', 'INS211102', 1), (20, '22071035', 'INS327102', 1), (21, '22071035', 'INS201501', 1),
(22, '23070200', 'INS109101', 1), (23, '23070200', 'PEC100802', 1), (24, '23070200', 'PHI100201', 1),
(25, '23070200', 'INE105002', 1), (26, '21070596', 'INS315201', 1), (27, '21070596', 'INS323702', 1),
(28, '21070596', 'INS315101', 1), (29, '22070717', 'INS325402', 1), (30, '22070717', 'INS306603', 1),
(31, '22070717', 'INS328003', 1), (32, '22070717', 'INS304901', 1), (33, '22070717', 'INS308001', 1),
(34, '20070527', 'BSA105501', 1), (35, '20070527', 'BSA301202', 1), (36, '20070527', 'BSA301402', 1),
(37, '21070508', 'BSA301402', 1), (38, '21070508', 'INE105001', 1), (39, '21070200', 'INE105102', 1),
(40, '21070200', 'INE300901', 1), (41, '21070200', 'INE306001', 1), (42, '21070071', 'FIB300501', 1),
(43, '21070071', 'INS200903', 1), (44, '21070071', 'INS201502', 1), (45, '21070071', 'INS201601', 1),
(46, '21070071', 'INS209801', 1), (47, '22070820', 'INS201503', 1), (48, '22070820', 'INS209804', 1),
(49, '22070820', 'INS210902', 1), (50, '20070799', 'INS301601', 1), (51, '22070247', 'MAT100501', 1),
(52, '22070247', 'BSA301202', 1), (53, '22070247', 'INS306002', 1), (54, '22070497', 'INS302803', 1),
(55, '20070024', 'INS306901', 1), (56, '20070024', 'INS210902', 1), (57, '20070024', 'INS300202', 1),
(58, '20070024', 'INS203702', 1), (59, '20070024', 'INS301603', 1), (60, '22070562', 'INS306603', 1),
(61, '22070562', 'INS209801', 1), (62, '21070312', 'INS327102', 1), (63, '21070312', 'INS300202', 1),
(64, '21070014', 'INS210902', 1), (65, '21070014', 'INS303201', 1), (66, '21070014', 'INS315101', 1),
(67, '21070014', 'BSA105501', 1), (68, '21070014', 'BSA301202', 1), (69, '22070651', 'INS307403', 1),
(70, '22070651', 'INS211102', 1), (71, '22070651', 'INS325402', 1), (72, '22070651', 'INS308001', 1),
(73, '22070651', 'INS201503', 1), (74, '23070201', 'INE300901', 1), (75, '23070201', 'INS201601', 1),
(76, '23070201', 'INS211102', 1), (77, '21070466', 'INS315101', 1), (78, '21070466', 'BSA301202', 1),
(79, '21070466', 'INS318901', 1), (80, '21070466', 'INE105102', 1), (81, '21070410', 'INS300202', 1),
(82, '21070410', 'PEC100802', 1), (83, '22070129', 'INS328003', 1), (84, '22070129', 'INS315101', 1),
(85, '20070499', 'MAT100501', 1), (86, '20070499', 'BSA301402', 1), (87, '20070499', 'INS201101', 1),
(88, '20070499', 'INS302901', 1), (89, '20070226', 'INS308001', 1), (90, '20070226', 'INE105002', 1),
(91, '20070226', 'INS304901', 1), (92, '20070226', 'MAT109204', 1), (93, '22070949', 'INS307403', 1),
(94, '22070949', 'INS201502', 1), (95, '22070949', 'INS302203', 1), (96, '22070949', 'INS306002', 1),
(97, '22070949', 'INS109101', 1), (98, '22070784', 'INS323702', 1), (99, '22070784', 'MAT109204', 1),
(100, '22070784', 'FIB300501', 1), (101, '22070784', 'INS303002', 1), (102, '23071014', 'INS201501', 1),
(103, '22071018', 'INS109101', 1), (104, '22071018', 'INE300901', 1), (105, '22071018', 'INS302203', 1),
(106, '21070782', 'PEC100802', 1), (107, '21070354', 'INS325101', 1), (108, '21070479', 'INS200903', 1),
(109, '21070479', 'INE300901', 1), (110, '21070479', 'INS306603', 1), (111, '21070479', 'INS309701', 1),
(112, '21070479', 'INS325201', 1);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `UserID` int(11) NOT NULL,
  `Username` varchar(50) NOT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `Role` enum('admin','instructor','student') NOT NULL,
  `FullName` varchar(100) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `LinkedEntityID` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Đang đổ dữ liệu cho bảng `users`
--
INSERT INTO `users` (`UserID`, `Username`, `PasswordHash`, `Role`, `FullName`, `Email`, `LinkedEntityID`) VALUES
(1, 'admin', '$2y$10$pNBhvHGdbFB/LIipagPxnut7TiUEDB85OfBNlRFTAmdlMQ0ItBpU.', 'admin', 'name', 'admin123@gmail.com', NULL);

--
-- Chỉ mục cho các bảng đã đổ
--

ALTER TABLE `classrooms`
  ADD PRIMARY KEY (`ClassroomID`),
  ADD UNIQUE KEY `RoomCode` (`RoomCode`);

ALTER TABLE `courses`
  ADD PRIMARY KEY (`CourseID`);

ALTER TABLE `instructorunavailableslots`
  ADD PRIMARY KEY (`UnavailableID`),
  ADD KEY `idx_ius_lecturer` (`LecturerID`),
  ADD KEY `idx_ius_semester` (`SemesterID`);

ALTER TABLE `lecturers`
  ADD PRIMARY KEY (`LecturerID`),
  ADD UNIQUE KEY `LecturerName` (`LecturerName`);

ALTER TABLE `scheduledclasses`
  ADD PRIMARY KEY (`ScheduleID`),
  -- Ràng buộc UNIQUE đã được xóa để cho phép "xung đột" cho việc tối ưu hóa
  ADD KEY `idx_sc_course` (`CourseID`),
  ADD KEY `idx_sc_timeslot` (`TimeSlotID`),
  ADD KEY `idx_sc_semester` (`SemesterID`),
  ADD KEY `idx_sc_lecturer` (`LecturerID`),
  ADD KEY `idx_sc_classroom` (`ClassroomID`);

ALTER TABLE `semesters`
  ADD PRIMARY KEY (`SemesterID`),
  ADD UNIQUE KEY `SemesterName` (`SemesterName`);

ALTER TABLE `studentenrollments`
  ADD PRIMARY KEY (`EnrollmentID`),
  ADD UNIQUE KEY `unique_student_course_semester` (`StudentID`,`CourseID`,`SemesterID`),
  ADD KEY `idx_se_course` (`CourseID`),
  ADD KEY `idx_se_semester` (`SemesterID`),
  ADD KEY `idx_se_student` (`StudentID`);

ALTER TABLE `students`
  ADD PRIMARY KEY (`StudentID`),
  ADD UNIQUE KEY `idx_students_email` (`Email`);

ALTER TABLE `timeslots`
  ADD PRIMARY KEY (`TimeSlotID`),
  ADD UNIQUE KEY `unique_timeslot_day_start_end` (`DayOfWeek`,`StartTime`,`EndTime`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD UNIQUE KEY `idx_users_email` (`Email`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--
ALTER TABLE `classrooms` MODIFY `ClassroomID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;
ALTER TABLE `instructorunavailableslots` MODIFY `UnavailableID` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `lecturers` MODIFY `LecturerID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;
ALTER TABLE `scheduledclasses` MODIFY `ScheduleID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=168;
ALTER TABLE `semesters` MODIFY `SemesterID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `studentenrollments` MODIFY `EnrollmentID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;
ALTER TABLE `timeslots` MODIFY `TimeSlotID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;
ALTER TABLE `users` MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Các ràng buộc cho các bảng đã đổ
--
ALTER TABLE `instructorunavailableslots`
  ADD CONSTRAINT `fk_ius_lecturer` FOREIGN KEY (`LecturerID`) REFERENCES `lecturers` (`LecturerID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ius_semester` FOREIGN KEY (`SemesterID`) REFERENCES `semesters` (`SemesterID`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `scheduledclasses`
  ADD CONSTRAINT `fk_sc_course` FOREIGN KEY (`CourseID`) REFERENCES `courses` (`CourseID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sc_lecturer` FOREIGN KEY (`LecturerID`) REFERENCES `lecturers` (`LecturerID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sc_classroom` FOREIGN KEY (`ClassroomID`) REFERENCES `classrooms` (`ClassroomID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sc_timeslot` FOREIGN KEY (`TimeSlotID`) REFERENCES `timeslots` (`TimeSlotID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sc_semester` FOREIGN KEY (`SemesterID`) REFERENCES `semesters` (`SemesterID`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `studentenrollments`
  ADD CONSTRAINT `fk_se_student` FOREIGN KEY (`StudentID`) REFERENCES `students` (`StudentID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_se_course` FOREIGN KEY (`CourseID`) REFERENCES `courses` (`CourseID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_se_semester` FOREIGN KEY (`SemesterID`) REFERENCES `semesters` (`SemesterID`) ON DELETE SET NULL ON UPDATE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;