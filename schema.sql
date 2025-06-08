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
--
INSERT INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID, NumStudents) VALUES
('INS308001', 1, 1, 5, 1, 45),   -- Artificial Intelligence, Truong Cong Doan, Room 407, Tue, 07:00-09:40
('INS306002', 2, 2, 6, 1, 45),   -- Advanced Database Development, Pham Van Dai, Room 406, Tue, 09:50-12:30
('INS306901', 3, 3, 10, 1, 45),  -- Decision Support Systems, Michael Omar, Room 504, Wed, 09:50-12:30
('INS307403', 3, 4, 14, 1, 45),  -- Global Information Systems, Michael Omar, Room 507, Thu, 09:50-12:30
('MAT100501', 4, 5, 1, 1, 45),   -- Mathematical Economics, Nguyen Tat Thang, Room 604, Mon, 07:00-09:40
('INS30201', 5, 6, 5, 1, 45),   -- Global Supply Chain Management, Ho Nguyen Nhu Y, Room 403, Tue, 07:00-09:40
('INS203702', 6, 7, 13, 1, 45),  -- Information Systems and Business Processes, Hoang Trong Tien, Room 514, Thu, 07:00-09:40
('PHI100201', 7, 8, 15, 1, 45),  -- Scientific Socialism, Nguyen Thi Hanh, Room 512, Thu, 13:00-15:40
('INS302203', 8, 9, 19, 1, 45),  -- International Business Law, Ngo Trong Quan, Room 506, Fri, 13:00-15:40
('BSA301201', 9, 10, 20, 1, 45), -- Marketing Research, Bui My Trinh, Room 402, Fri, 15:50-18:30
('INS300202', 10, 11, 19, 1, 45), -- Financial Accounting 2, Tran The Nu, Room 503, Fri, 13:00-15:40
('INS323703', 11, 12, 10, 1, 45), -- Electric Motors and Drive Systems, Nguyen Van Tinh, Room 501, Wed, 09:50-12:30
('INS315101', 12, 6, 13, 1, 45),  -- Embedded Control Systems, Nguyen Hoang Dung, Room 403, Thu, 07:00-09:40
('INS207303', 13, 13, 5, 1, 45),  -- Programming 2, Nguyen Doan Dong, Room 409, Tue, 07:00-09:40
('RUS500201', 14, 14, 8, 1, 45),  -- Russian Language 1B, Nguyen Manh Hai, Room 601, Tue, 15:50-18:30
('INS207402', 15, 12, 12, 1, 45), -- Discrete Mathematics, Do Van Hoan, Room 501, Wed, 15:50-18:30
('MAT109204', 16, 5, 3, 1, 45),   -- Advanced Mathematics, Phong Thi Thu Huyen, Room 604, Mon, 13:00-15:40
('INS201101', 17, 2, 13, 1, 45),  -- Economic Law, Tran Duc Phu, Room 406, Thu, 07:00-09:40
('INS211102', 18, 15, 6, 1, 45),  -- Business Organization and Management, Nguyen Thi Kim Duyen, Room 405, Tue, 09:50-12:30
('INS327102', 19, 16, 9, 1, 45),  -- International Accounting, Hoang Ha Anh, Room 606, Wed, 07:00-09:40
('INS201501', 20, 2, 16, 1, 45),  -- Basic Finance, Nguyen Hoang Lan, Room 406, Thu, 15:50-18:30
('INS109101', 21, 17, 1, 1, 45),  -- English Linguistics 2, Hoang Tuyet Minh, Room 401, Mon, 07:00-09:40
('PEC100802', 22, 6, 6, 1, 45),   -- Marx Leninist Political Economy, Hoang Trieu Hoa, Room 403, Tue, 09:50-12:30
('PHI100201', 23, 11, 13, 1, 45), -- Scientific Socialism (Duong Van Duyen), Room 503, Thu, 07:00-09:40
('INE105002', 24, 18, 20, 1, 45), -- Microeconomics, Trinh Thi Thu Hang, Room 502, Fri, 15:50-18:30
('INS315201', 11, 13, 1, 1, 45),  -- Robotics, Nguyen Van Tinh, Room 409, Mon, 07:00-09:40
('INS323702', 11, 15, 9, 1, 45),  -- Electric Motors and Drive Systems, Nguyen Van Tinh, Room 405, Wed, 07:00-09:40
('INS315101', 12, 13, 13, 1, 45), -- Embedded Control Systems, Nguyen Hoang Dung, Room 409, Thu, 07:00-09:40 (This is the second entry for this course by this lecturer on this day, but different room as per Excel)
('INS325402', 25, 1, 2, 1, 45),   -- Introduction to Data Science, Pham Thi Viet Huong, Room 407, Mon, 09:50-12:30
('INS306603', 26, 2, 3, 1, 45),   -- Business Solutions for Enterprises, Nguyen Duy Thanh, Room 406, Mon, 13:00-15:40
('INS328003', 27, 16, 12, 1, 45), -- Data Preparation and Visualization, Pham Thi Thanh Thuy, Room 606, Wed, 15:50-18:30
('INS304901', 28, 10, 13, 1, 45), -- Econometrics, Le Van Dao, Room 402, Thu, 07:00-09:40
('INS308001', 29, 19, 20, 1, 45), -- Artificial Intelligence, Pham Thi Kim Dung, Room 602, Fri, 15:50-18:30
('BSA105501', 30, 20, 2, 1, 45),  -- Business Culture, Alexis Rez, Room 408, Mon, 09:50-12:30
('BSA301202', 9, 21, 19, 1, 45),  -- Marketing Research, Bui My Trinh, Room 603, Fri, 13:00-15:40
('BSA301402', 31, 20, 6, 1, 45),  -- Service Marketing, Vu Dieu Thuy, Room 408, Tue, 09:50-12:30
('BSA301402', 32, 1, 11, 1, 45),  -- Service Marketing, Le Thi Mai, Room 407, Wed, 13:00-15:40
('INE105001', 24, 6, 19, 1, 45),  -- Microeconomics, Trinh Thi Thu Hang, Room 403, Fri, 13:00-15:40
('INE105102', 33, 6, 2, 1, 45),   -- Macroeconomics, Nghiem Xuan Hoa, Room 403, Mon, 09:50-12:30
('INE300901', 34, 9, 12, 1, 45),  -- International Project Management, Nguyen Ngoc Quy, Room 506, Wed, 15:50-18:30
('INE306001', 35, 6, 3, 1, 45),   -- E-Commerce, Vu Minh Quan, Room 403, Mon, 13:00-15:40
('FIB300501', 36, 18, 5, 1, 45),  -- Investment and Portfolio Management, Nguyen Tuan Minh, Room 502, Tue, 07:00-09:40
('INS200903', 10, 6, 6, 1, 45),   -- Principles of Accounting, Tran The Nu, Room 403, Tue, 09:50-12:30
('INS201502', 37, 15, 11, 1, 45), -- Basic Finance, Le Thi Thu Huong, Room 405, Wed, 13:00-15:40
('INS201601', 36, 3, 2, 1, 45),   -- Risk and Risk Analysis, Nguyen Tuan Minh, Room 504, Mon, 09:50-12:30
('INS209801', 10, 17, 17, 1, 45), -- Principles of Accounting, Tran The Nu, Room 401, Fri, 07:00-09:40
('INS201503', 20, 20, 11, 1, 45), -- Basic Finance, Nguyen Hoang Lan, Room 408, Wed, 13:00-15:40
('INS209804', 38, 12, 3, 1, 45),  -- Principles of Accounting, Duong My Hanh, Room 501, Mon, 13:00-15:40
('INS210902', 39, 10, 7, 1, 45),  -- Managerial Accounting, Nguyen Thi Kim Oanh, Room 402, Tue, 13:00-15:40
('INS301601', 40, 13, 15, 1, 45), -- Computerized Accounting Practice, Nguyen Thi Nhu Ai, Room 409, Thu, 13:00-15:40
('INS302803', 41, 1, 18, 1, 45),  -- Risk Management and Insurance, Nguyen Phu Hung, Room 407, Fri, 09:50-12:30
('INS301603', 42, 22, 23, 1, 45), -- Computerized Accounting Practice, Bui To Quyen, Room 513, Sat, 13:00-15:40
('INS303201', 43, 18, 10, 1, 45), -- International Finance, Do Phuong Huyen, Room 502, Wed, 09:50-12:30
('INS318901', 44, 9, 8, 1, 45),   -- Corporate Finance, Chu Van Hung, Room 506, Tue, 15:50-18:30
('INS302901', 45, 1, 9, 1, 45),   -- Financial Markets and Institutions, Khuc The Anh, Room 407, Wed, 07:00-09:40
('INS303002', 46, 23, 18, 1, 45), -- Financial Statement Analysis, Chu Huy Anh, Room 508, Fri, 09:50-12:30
('INS325101', 47, 5, 9, 1, 45),   -- Taxation, Nguyen Thi Thanh Hoai, Room 604, Wed, 07:00-09:40
('INS309701', 48, 12, 18, 1, 45), -- Accounting I: Financial Accounting, Nguyen Thi Thanh Phuong, Room 501, Fri, 09:50-12:30
('INS325201', 46, 15, 14, 1, 45); -- Financial Accounting 2, Chu Huy Anh, Room 405, Thu, 09:50-12:30

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
CREATE TABLE StudentPersonalSchedules (
    StudentPersonalScheduleID INT AUTO_INCREMENT PRIMARY KEY,
    StudentID VARCHAR(20) NOT NULL,
    SemesterID INT NOT NULL,
    ScheduleName VARCHAR(255) NOT NULL,
    ScheduleData JSON NOT NULL, -- Hoặc TEXT nếu phiên bản MySQL cũ hơn không hỗ trợ JSON tốt
    IsActive BOOLEAN DEFAULT 0, -- 0 for false, 1 for true
    SavedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (StudentID) REFERENCES Students(StudentID) ON DELETE CASCADE,
    FOREIGN KEY (SemesterID) REFERENCES Semesters(SemesterID) ON DELETE CASCADE,
    UNIQUE KEY uq_student_semester_schedulename (StudentID, SemesterID, ScheduleName) -- Optional: ensure unique names per student/semester
);
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

ALTER TABLE ScheduledClasses
ADD COLUMN NumStudents INT DEFAULT NULL AFTER SemesterID;
ALTER TABLE Courses
ADD COLUMN MajorCategory VARCHAR(100) NULL DEFAULT NULL COMMENT 'Stores the major category like Economics, Technology, Language';

UPDATE Courses SET MajorCategory = 'Economics' WHERE CourseID IN (
    'INS309701', 'MAT109204', 'INS201501', 'INS201502', 'INS201503', 'BSA105501', 
    'INS211102', 'INS306603', 'INS318901', 'INE306001', 'INS304901', 'INS201101', 
    'INS300202', 'INS325201', 'INS302901', 'INS303002', 'INS30201', 'INS327102', 
    'INS302203', 'INS303201', 'INE300901', 'FIB300501', 'INE105102', 'INS210902', 
    'BSA301201', 'BSA301202', 'MAT100501', 'INE105001', 'INE105002', 
    'INS200903', 'INS209801', 'INS209804', 'INS201601', 'INS302803', 'BSA301402', 
    'INS325101'
);

UPDATE Courses SET MajorCategory = 'Technology' WHERE CourseID IN (
    'INS306002', 'INS308001', 'INS301601', 'INS301603', 'INS328003', 'INS306901', 
    'INS207402', 'INS323702', 'INS323703', 'INS315101', 'INS307403', 'INS203702', 
    'INS325402', 'INS207303', 'INS315201'
);

UPDATE Courses SET MajorCategory = 'Language' WHERE CourseID IN (
    'INS109101', 'RUS500201'
);

UPDATE Courses SET MajorCategory = 'Philosophy' WHERE CourseID IN (
    'PHI100201', 'PEC100802'
);