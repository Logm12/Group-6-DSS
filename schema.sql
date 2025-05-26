schema.SQL đã có, với code như sau (đã test trên myphp và đã chạy được
hãy ghi nhớ:
USE dss;
DROP TABLE IF EXISTS ScheduledClasses;
DROP TABLE IF EXISTS StudentEnrollments; -- Bảng này không trực tiếp có trong data bạn gửi, nhưng cần cho ràng buộc mềm SV
DROP TABLE IF EXISTS InstructorUnavailableSlots; -- Tương tự
DROP TABLE IF EXISTS TimeSlots;
DROP TABLE IF EXISTS Classrooms;
DROP TABLE IF EXISTS Courses;
DROP TABLE IF EXISTS Lecturers;
DROP TABLE IF EXISTS Students;
DROP TABLE IF EXISTS Users;
DROP TABLE IF EXISTS Semesters;

-- 1. Bảng Semesters (Học kỳ)
CREATE TABLE Semesters (
SemesterID INT AUTO_INCREMENT PRIMARY KEY,
SemesterName VARCHAR(100) NOT NULL UNIQUE, -- Ví dụ: "Học kỳ 1 Năm học 2024-2025"
StartDate DATE NOT NULL,
EndDate DATE NOT NULL
);

-- 2. Bảng Users (Người dùng)
CREATE TABLE Users (
UserID INT AUTO_INCREMENT PRIMARY KEY,
Username VARCHAR(50) NOT NULL UNIQUE,
PasswordHash VARCHAR(255) NOT NULL,
Role ENUM('admin', 'instructor', 'student') NOT NULL,
FullName VARCHAR(100),
Email VARCHAR(100) UNIQUE,
LinkedEntityID VARCHAR(20) NULL -- Có thể là StudentID hoặc mã GV tự định nghĩa
);

-- 3. Bảng Students (Sinh viên)
CREATE TABLE Students (
StudentID VARCHAR(20) PRIMARY KEY, -- Mã sinh viên
StudentName VARCHAR(100) NOT NULL,
Email VARCHAR(100) UNIQUE,
Program VARCHAR(100) -- Ngành học
-- Thêm các cột khác nếu cần
);

-- 4. Bảng Lecturers (Giảng viên)
CREATE TABLE Lecturers (
LecturerID INT AUTO_INCREMENT PRIMARY KEY,
LecturerName VARCHAR(100) NOT NULL UNIQUE,
Email VARCHAR(100) UNIQUE,
Department VARCHAR(100)
-- Thêm các cột khác nếu cần
);

-- 5. Bảng Courses (Môn học)
CREATE TABLE Courses (
CourseID VARCHAR(20) PRIMARY KEY, -- Mã môn học
CourseName VARCHAR(255) NOT NULL,
Credits INT,
ExpectedStudents INT,
SessionDurationSlots INT -- Số tiết liền kề cần thiết (ví dụ: 3 tiết)
-- Thêm các cột khác nếu cần
);

-- 6. Bảng Classrooms (Phòng học)
CREATE TABLE Classrooms (
ClassroomID INT AUTO_INCREMENT PRIMARY KEY,
RoomCode VARCHAR(10) NOT NULL UNIQUE, -- Mã phòng
Capacity INT,
Type VARCHAR(50) DEFAULT 'Theory' -- Ví dụ: Theory, Lab
-- Thêm các cột khác nếu cần
);

-- 7. Bảng TimeSlots (Khung giờ)
-- Lưu ý: Để đơn giản, bảng này sẽ lưu các khung giờ cụ thể đã được xếp.
-- Trong một hệ thống thực tế, bạn có thể có bảng TimeSlotPatterns (Thứ 2, tiết 1-3)
-- và bảng ScheduledTimeSlots để link Pattern đó với một ngày cụ thể.
-- Ở đây, tôi sẽ kết hợp thông tin DayOfWeek, SessionDate, StartTime, EndTime để tạo TimeSlot.
CREATE TABLE TimeSlots (
TimeSlotID INT AUTO_INCREMENT PRIMARY KEY,
DayOfWeek VARCHAR(15) NOT NULL, -- Monday, Tuesday,...
SessionDate DATE NOT NULL,      -- Ngày cụ thể của buổi học
StartTime TIME NOT NULL,
EndTime TIME NOT NULL,
UNIQUE KEY unique_timeslot (DayOfWeek, SessionDate, StartTime, EndTime) -- Đảm bảo mỗi khung giờ là duy nhất
);

-- 8. Bảng ScheduledClasses (Lịch học đã xếp)
-- Bảng này là kết quả chính của hệ thống DSS
CREATE TABLE ScheduledClasses (
ScheduleID INT AUTO_INCREMENT PRIMARY KEY,
CourseID VARCHAR(20) NOT NULL,
LecturerID INT NOT NULL,
ClassroomID INT NOT NULL,
TimeSlotID INT NOT NULL,
SemesterID INT, -- Liên kết với học kỳ nào
FOREIGN KEY (CourseID) REFERENCES Courses(CourseID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (LecturerID) REFERENCES Lecturers(LecturerID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (ClassroomID) REFERENCES Classrooms(ClassroomID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (TimeSlotID) REFERENCES TimeSlots(TimeSlotID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (SemesterID) REFERENCES Semesters(SemesterID) ON DELETE SET NULL ON UPDATE CASCADE,
-- Ràng buộc để đảm bảo một giảng viên không dạy 2 lớp cùng lúc tại 1 TimeSlotID
UNIQUE KEY unique_instructor_timeslot (LecturerID, TimeSlotID),
-- Ràng buộc để đảm bảo một phòng học không được sử dụng bởi 2 lớp cùng lúc tại 1 TimeSlotID
UNIQUE KEY unique_classroom_timeslot (ClassroomID, TimeSlotID)
);

-- 9. Bảng StudentEnrollments (Sinh viên đăng ký môn học)
-- Bảng này quan trọng cho ràng buộc mềm "Giảm thiểu trùng lịch cho sinh viên"
CREATE TABLE StudentEnrollments (
EnrollmentID INT AUTO_INCREMENT PRIMARY KEY,
StudentID VARCHAR(20) NOT NULL,
CourseID VARCHAR(20) NOT NULL,
SemesterID INT, -- Đăng ký cho học kỳ nào
FOREIGN KEY (StudentID) REFERENCES Students(StudentID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (CourseID) REFERENCES Courses(CourseID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (SemesterID) REFERENCES Semesters(SemesterID) ON DELETE SET NULL ON UPDATE CASCADE,
UNIQUE KEY unique_student_course_semester (StudentID, CourseID, SemesterID)
);

-- 10. Bảng InstructorUnavailableSlots (Khung giờ bận của giảng viên)
CREATE TABLE InstructorUnavailableSlots (
UnavailableID INT AUTO_INCREMENT PRIMARY KEY,
LecturerID INT NOT NULL,
-- Có thể dùng TimeSlotID nếu các khung giờ bận trùng với các TimeSlot định nghĩa sẵn
-- Hoặc định nghĩa riêng StartDateTime, EndDateTime cho linh hoạt
BusyDayOfWeek VARCHAR(15), -- e.g., 'Monday'
BusyStartTime TIME,
BusyEndTime TIME,
Reason VARCHAR(255),
SemesterID INT,
FOREIGN KEY (LecturerID) REFERENCES Lecturers(LecturerID) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (SemesterID) REFERENCES Semesters(SemesterID) ON DELETE SET NULL ON UPDATE CASCADE
);
-- 1. Semesters (Dữ liệu mẫu) --
INSERT IGNORE INTO Semesters (SemesterID, SemesterName, StartDate, EndDate) VALUES (1, 'Học kỳ Import từ Excel', '2025-01-01', '2025-06-30');

-- 2. Students --
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070666', 'Hoang Quynh Anh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('23070710', 'TRAN THI PHUONG ANH');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070521', 'Vu Nguyen Nguyet Linh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070275', 'Phung Trung Kien');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070847', 'Tuong Duc Kien');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22071035', 'Do Minh Khanh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('23070200', 'Dinh Manh Hung');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070596', 'Nguyen Thanh Long');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070717', 'Bui Phuong Mai');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070527', 'Ninh Phuong Ly');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070508', 'Le Tran Tuyet Mai');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070200', 'Do Ngoc Minh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070071', 'Le Hong Minh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070820', 'Pham Cong Minh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070799', 'Nguyen Lam Truong');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070247', 'Ta Khac Dong');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070497', 'Nguyen Tung Duong');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070024', 'Nguyen Minh Duy');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070562', 'Nguyen Mai Duyen');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070312', 'Hoang Minh Ha');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070014', 'Nguyen Gia Han');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070651', 'Ha Tuan Hiep');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('23070201', 'Nguyen Huy Hieu');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070466', 'Dang Thi Hoa');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070410', 'Le Thuy Huyen');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070129', 'Pham Gia Khanh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070499', 'Tran Ngoc Khanh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('20070226', 'Khuat Thi Khanh Linh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070949', 'Nguyen Do Khanh Linh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22070784', 'Nguyen Thuy Linh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('23071014', 'Nguyen Thi Gia Linh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('22071018', 'Pham Quang Minh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070782', 'Vu Cong Minh');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070354', 'Tran Thi Tra My');
INSERT IGNORE INTO Students (StudentID, StudentName) VALUES ('21070479', 'Nguyen Thi Quynh Nga');

-- 3. Lecturers --
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Truong Cong Doan');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Pham Van Dai');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Michael Omar');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Tat Thang');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Ho Nguyen Nhu Y');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Hoang Trong Tien');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Thi Hanh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Ngo Trong Quan');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Bui My Trinh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Tran The Nu');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Van Tinh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Hoang Dung');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Doan Dong');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Manh Hai');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Do Van Hoan');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Phong Thi Thu Huyen');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Tran Duc Phu');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Thi Kim Duyen');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Hoang Ha Anh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Hoang Lan');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Hoang Tuyet Minh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Hoang Trieu Hoa');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Duong Van Duyen');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Trinh Thi Thu Hang');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Pham Thi Viet Huong');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Duy Thanh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Pham Thi Thanh Thuy');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Le Van Dao');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Pham Thi Kim Dung');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Alexis Rez');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Vu Dieu Thuy');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Le Thi Mai');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nghiem Xuan Hoa');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Ngoc Quy');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Vu Minh Quan');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Tuan Minh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Le Thi Thu Huong');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Duong My Hanh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Thi Kim Oanh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Thi Nhu Ai');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Phu Hung');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Bui To Quyen');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Do Phuong Huyen');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Chu Van Hung');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Khuc The Anh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Chu Huy Anh');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Thi Thanh Hoai');
INSERT IGNORE INTO Lecturers (LecturerName) VALUES ('Nguyen Thi Thanh Phuong');

-- 4. Courses --
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS308001', 'Artificial Intelligence', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS306002', 'Advanced Database Development', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS306901', 'Decision Support Systems', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS307403', 'Global Information Systems', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('MAT100501', 'Mathematical Economics', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS30201', 'Global Supply Chain Management', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS203702', 'Information Systems and Business Processes', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('PHI100201', 'Scientific Socialism', 2);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS302203', 'International Business Law', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('BSA301201', 'Marketing Research', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS300202', 'Financial Accounting 2', 4);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS323703', 'Electric Motors and Drive Systems', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS315101', 'Embedded Control Systems', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS207303', 'Programming 2', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('RUS500201', 'Russian Language 1B', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS207402', 'Discrete Mathematics', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('MAT109204', 'Advanced Mathematics', 4);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS201101', 'Economic Law', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS211102', 'Business Organization and Management', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS327102', 'International Accounting', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS201501', 'Basic Finance', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS109101', 'English Linguistics 2', 4);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('PEC100802', 'Marx Leninist Political Economy', 2);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INE105002', 'Microeconomics', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS315201', 'Robotics', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS323702', 'Electric Motors and Drive Systems', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS325402', 'Introduction to Data Science', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS306603', 'Business Solutions for Enterprises', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS328003', 'Data Preparation and Visualization', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS304901', 'Econometrics', 4);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('BSA105501', 'Business Culture', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('BSA301202', 'Marketing Research', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('BSA301402', 'Service Marketing', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INE105001', 'Microeconomics', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INE105102', 'Macroeconomics', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INE300901', 'International Project Management', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INE306001', 'E-Commerce', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('FIB300501', 'Investment and Portfolio Management', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS200903', 'Principles of Accounting', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS201502', 'Basic Finance', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS201601', 'Risk and Risk Analysis', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS209801', 'Principles of Accounting', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS201503', 'Basic Finance', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS209804', 'Principles of Accounting', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS210902', 'Managerial Accounting', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS301601', 'Computerized Accounting Practice', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS302803', 'Risk Management and Insurance', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS301603', 'Computerized Accounting Practice', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS303201', 'International Finance', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS318901', 'Corporate Finance', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS302901', 'Financial Markets and Institutions', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS303002', 'Financial Statement Analysis', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS325101', 'Taxation', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS309701', 'Accounting I: Financial Accounting', 3);
INSERT IGNORE INTO Courses (CourseID, CourseName, SessionDurationSlots) VALUES ('INS325201', 'Financial Accounting 2', 3);

-- 5. Classrooms --
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('407', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('406', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('504', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('507', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('604', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('403', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('514', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('512', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('506', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('402', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('503', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('501', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('409', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('601', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('405', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('606', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('401', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('502', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('602', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('408', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('603', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('513', 50);
INSERT IGNORE INTO Classrooms (RoomCode, Capacity) VALUES ('508', 50);

-- 6. TimeSlots --
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Tuesday', '2025-03-01', '07:00:00', '09:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Tuesday', '2025-06-04', '09:50:00', '12:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Wednesday', '2025-06-04', '09:50:00', '12:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-06-04', '09:50:00', '12:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Monday', '2025-03-01', '07:00:00', '09:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-03-01', '07:00:00', '09:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-08-07', '13:00:00', '14:45:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Friday', '2025-09-07', '13:00:00', '15:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Friday', '2025-12-10', '15:50:00', '18:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Friday', '2025-10-07', '13:00:00', '16:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Tuesday', '2025-12-10', '15:50:00', '18:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Wednesday', '2025-12-10', '15:50:00', '18:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Monday', '2025-10-07', '13:00:00', '16:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Wednesday', '2025-03-01', '07:00:00', '09:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-12-10', '15:50:00', '18:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Monday', '2025-04-01', '07:00:00', '10:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Tuesday', '2025-05-04', '09:50:00', '11:35:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-03-02', '07:55:00', '09:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Monday', '2025-06-04', '09:50:00', '12:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Monday', '2025-09-07', '13:00:00', '15:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-04-01', '07:00:00', '10:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Wednesday', '2025-09-07', '13:00:00', '15:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Tuesday', '2025-06-03', '09:50:00', '12:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Friday', '2025-03-01', '07:00:00', '09:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Tuesday', '2025-09-07', '13:00:00', '15:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Thursday', '2025-09-07', '13:00:00', '15:40:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Friday', '2025-06-04', '09:50:00', '12:30:00');
INSERT IGNORE INTO TimeSlots (DayOfWeek, SessionDate, StartTime, EndTime) VALUES ('Saturday', '2025-09-07', '13:00:00', '15:40:00');

-- 7. StudentEnrollments & ScheduledClasses --
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070666', 'INS308001', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS308001',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Truong Cong Doan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070666', 'INS306002', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306002',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Van Dai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070666', 'INS306901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Michael Omar'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '504'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070666', 'INS307403', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS307403',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Michael Omar'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '507'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070710', 'MAT100501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'MAT100501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tat Thang'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070710', 'INS30201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS30201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Ho Nguyen Nhu Y'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070710', 'INS203702', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS203702',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Trong Tien'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '514'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070710', 'PHI100201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'PHI100201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Hanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '512'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-08-07' AND ts.StartTime='13:00:00' AND ts.EndTime='14:45:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070710', 'INS302203', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS302203',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Ngo Trong Quan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070710', 'BSA301201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Bui My Trinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '402'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070521', 'INS300202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS300202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '503'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070275', 'INS323703', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS323703',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Van Tinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '501'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070275', 'INS315101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS315101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070847', 'INS207303', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS207303',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Doan Dong'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '409'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070847', 'RUS500201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'RUS500201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Manh Hai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '601'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070847', 'INS207402', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS207402',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Do Van Hoan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '501'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071035', 'MAT109204', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'MAT109204',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Phong Thi Thu Huyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071035', 'INS201101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran Duc Phu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071035', 'INS211102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS211102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Kim Duyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071035', 'INS327102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS327102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Ha Anh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '606'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071035', 'INS201501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Lan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070200', 'INS109101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS109101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Tuyet Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '401'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-04-01' AND ts.StartTime='07:00:00' AND ts.EndTime='10:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070200', 'PEC100802', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'PEC100802',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Trieu Hoa'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-05-04' AND ts.StartTime='09:50:00' AND ts.EndTime='11:35:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070200', 'PHI100201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'PHI100201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Duong Van Duyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '503'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-02' AND ts.StartTime='07:55:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070200', 'INE105002', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE105002',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Trinh Thi Thu Hang'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '502'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070596', 'INS315201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS315201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Van Tinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '409'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070596', 'INS323702', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS323702',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Van Tinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070596', 'INS315101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS315101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '409'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070717', 'INS325402', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS325402',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Thi Viet Huong'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070717', 'INS306603', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306603',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Duy Thanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070717', 'INS328003', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS328003',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Thi Thanh Thuy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '606'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070717', 'INS304901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS304901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Le Van Dao'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '402'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-04-01' AND ts.StartTime='07:00:00' AND ts.EndTime='10:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070717', 'INS308001', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS308001',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Thi Kim Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '602'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070527', 'BSA105501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA105501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Alexis Rez'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '408'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070527', 'BSA301202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Bui My Trinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '603'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070527', 'BSA301402', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301402',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Vu Dieu Thuy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '408'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070508', 'BSA301402', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301402',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Le Thi Mai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070508', 'INE105001', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE105001',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Trinh Thi Thu Hang'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070200', 'INE105102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE105102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nghiem Xuan Hoa'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070200', 'INE300901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE300901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Ngoc Quy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070200', 'INE306001', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE306001',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Vu Minh Quan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070071', 'FIB300501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'FIB300501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tuan Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '502'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070071', 'INS200903', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS200903',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-03' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070071', 'INS201502', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201502',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Le Thi Thu Huong'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070071', 'INS201601', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201601',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tuan Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '504'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070071', 'INS209801', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS209801',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '401'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070820', 'INS201503', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201503',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Lan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '408'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070820', 'INS209804', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS209804',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Duong My Hanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '501'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070820', 'INS210902', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS210902',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Kim Oanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '402'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070799', 'INS301601', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS301601',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Nhu Ai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '409'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070247', 'MAT100501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'MAT100501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tat Thang'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070247', 'BSA301202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Bui My Trinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '603'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070247', 'INS306002', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306002',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Van Dai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070497', 'INS302803', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS302803',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Phu Hung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070024', 'INS306901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Michael Omar'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '504'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070024', 'INS210902', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS210902',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Kim Oanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '402'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070024', 'INS300202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS300202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '503'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070024', 'INS203702', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS203702',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Trong Tien'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '514'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070024', 'INS301603', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS301603',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Bui To Quyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '513'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Saturday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070562', 'INS306603', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306603',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Duy Thanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070562', 'INS209801', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS209801',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '401'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070312', 'INS327102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS327102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Ha Anh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '606'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070312', 'INS300202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS300202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '503'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070014', 'INS210902', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS210902',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Kim Oanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '402'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070014', 'INS303201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS303201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Do Phuong Huyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '502'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070014', 'INS315101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS315101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '409'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070014', 'BSA105501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA105501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Alexis Rez'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '408'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070014', 'BSA301202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Bui My Trinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '603'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070651', 'INS307403', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS307403',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Michael Omar'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '507'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070651', 'INS211102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS211102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Kim Duyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070651', 'INS325402', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS325402',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Thi Viet Huong'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070651', 'INS308001', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS308001',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Thi Kim Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '602'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070651', 'INS201503', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201503',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Lan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '408'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070201', 'INE300901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE300901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Ngoc Quy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070201', 'INS201601', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201601',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tuan Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '504'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23070201', 'INS211102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS211102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Kim Duyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070466', 'INS315101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS315101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '409'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070466', 'BSA301202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Bui My Trinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '603'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070466', 'INS318901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS318901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Chu Van Hung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070466', 'INE105102', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE105102',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nghiem Xuan Hoa'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070410', 'INS300202', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS300202',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '503'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070410', 'PEC100802', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'PEC100802',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Trieu Hoa'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-05-04' AND ts.StartTime='09:50:00' AND ts.EndTime='11:35:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070129', 'INS328003', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS328003',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Thi Thanh Thuy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '606'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070129', 'INS315101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS315101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Dung'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070499', 'MAT100501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'MAT100501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tat Thang'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070499', 'BSA301402', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'BSA301402',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Vu Dieu Thuy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '408'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070499', 'INS201101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran Duc Phu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070499', 'INS302901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS302901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Khuc The Anh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070226', 'INS308001', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS308001',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Truong Cong Doan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '407'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070226', 'INE105002', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE105002',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Trinh Thi Thu Hang'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '502'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070226', 'INS304901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS304901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Le Van Dao'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '402'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-04-01' AND ts.StartTime='07:00:00' AND ts.EndTime='10:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('20070226', 'MAT109204', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'MAT109204',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Phong Thi Thu Huyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070949', 'INS307403', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS307403',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Michael Omar'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '507'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070949', 'INS201502', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201502',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Le Thi Thu Huong'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070949', 'INS302203', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS302203',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Ngo Trong Quan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070949', 'INS306002', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306002',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Pham Van Dai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070949', 'INS109101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS109101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Tuyet Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '401'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-04-01' AND ts.StartTime='07:00:00' AND ts.EndTime='10:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070784', 'INS323702', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS323702',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Van Tinh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070784', 'MAT109204', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'MAT109204',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Phong Thi Thu Huyen'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-10-07' AND ts.StartTime='13:00:00' AND ts.EndTime='16:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070784', 'FIB300501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'FIB300501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Tuan Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '502'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22070784', 'INS303002', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS303002',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Chu Huy Anh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '508'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('23071014', 'INS201501', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS201501',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Hoang Lan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071018', 'INS109101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS109101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Tuyet Minh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '401'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-04-01' AND ts.StartTime='07:00:00' AND ts.EndTime='10:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071018', 'INE300901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE300901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Ngoc Quy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('22071018', 'INS302203', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS302203',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Ngo Trong Quan'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070782', 'PEC100802', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'PEC100802',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Hoang Trieu Hoa'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-05-04' AND ts.StartTime='09:50:00' AND ts.EndTime='11:35:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070354', 'INS325101', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS325101',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Thanh Hoai'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '604'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-03-01' AND ts.StartTime='07:00:00' AND ts.EndTime='09:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070479', 'INS200903', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS200903',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Tran The Nu'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '403'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Tuesday' AND ts.SessionDate='2025-06-03' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070479', 'INE300901', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INE300901',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Ngoc Quy'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '506'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Wednesday' AND ts.SessionDate='2025-12-10' AND ts.StartTime='15:50:00' AND ts.EndTime='18:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070479', 'INS306603', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS306603',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Duy Thanh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '406'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Monday' AND ts.SessionDate='2025-09-07' AND ts.StartTime='13:00:00' AND ts.EndTime='15:40:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070479', 'INS309701', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS309701',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Nguyen Thi Thanh Phuong'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '501'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Friday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;
INSERT IGNORE INTO StudentEnrollments (StudentID, CourseID, SemesterID) VALUES ('21070479', 'INS325201', 1);
INSERT IGNORE INTO ScheduledClasses (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID) SELECT     'INS325201',     (SELECT lec.LecturerID FROM Lecturers lec WHERE lec.LecturerName = 'Chu Huy Anh'),     (SELECT cr.ClassroomID FROM Classrooms cr WHERE cr.RoomCode = '405'),     (SELECT ts.TimeSlotID FROM TimeSlots ts WHERE ts.DayOfWeek='Thursday' AND ts.SessionDate='2025-06-04' AND ts.StartTime='09:50:00' AND ts.EndTime='12:30:00'),     1;