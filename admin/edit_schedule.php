<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
<?php
include 'db_connect.php';
include 'admin_sidebar_menu.php';

if (!isset($_GET['id'])) {
    header("Location: manage_schedule.php");
    exit();
}
$id = intval($_GET['id']);

// Lấy dữ liệu lịch học
$stmt = $conn->prepare("SELECT * FROM schedule WHERE ScheduleID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
if (!$schedule) {
    echo "<div class='main-content'><h2>Schedule not found.</h2></div>";
    exit();
}

// Danh sách các lựa chọn
$courses = $conn->query("SELECT * FROM courses ORDER BY CourseName ASC");
$lecturers = $conn->query("SELECT * FROM lecturers ORDER BY LecturerName ASC");
$classrooms = $conn->query("SELECT * FROM classrooms ORDER BY RoomName ASC");
$semesters = $conn->query("SELECT * FROM semesters ORDER BY SemesterName ASC");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $course = $_POST['course'];
    $lecturer = $_POST['lecturer'];
    $classroom = $_POST['classroom'];
    $semester = $_POST['semester'];
    $day = $_POST['day'];
    $start = $_POST['start'];
    $end = $_POST['end'];

    $stmt = $conn->prepare("UPDATE schedule SET CourseID=?, LecturerID=?, ClassroomID=?, SemesterID=?, DayOfWeek=?, StartTime=?, EndTime=? WHERE ScheduleID=?");
    $stmt->bind_param("siissssi", $course, $lecturer, $classroom, $semester, $day, $start, $end, $id);
    $stmt->execute();

    header("Location: manage_schedule.php");
    exit();
}
?>

<div class="main-content">
    <div class="form-card">
        <h2>Edit Schedule</h2>
        <form method="POST" class="form-content">
            <label for="course">Course</label>
            <select name="course" id="course" required>
                <?php while ($c = $courses->fetch_assoc()): ?>
                    <option value="<?= $c['CourseID'] ?>" <?= $c['CourseID'] == $schedule['CourseID'] ? 'selected' : '' ?>>
                        <?= $c['CourseName'] ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label for="lecturer">Lecturer</label>
            <select name="lecturer" id="lecturer" required>
                <?php while ($l = $lecturers->fetch_assoc()): ?>
                    <option value="<?= $l['LecturerID'] ?>" <?= $l['LecturerID'] == $schedule['LecturerID'] ? 'selected' : '' ?>>
                        <?= $l['LecturerName'] ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label for="classroom">Classroom</label>
            <select name="classroom" id="classroom" required>
                <?php while ($r = $classrooms->fetch_assoc()): ?>
                    <option value="<?= $r['ClassroomID'] ?>" <?= $r['ClassroomID'] == $schedule['ClassroomID'] ? 'selected' : '' ?>>
                        <?= $r['RoomName'] ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label for="semester">Semester</label>
            <select name="semester" id="semester" required>
                <?php while ($s = $semesters->fetch_assoc()): ?>
                    <option value="<?= $s['SemesterID'] ?>" <?= $s['SemesterID'] == $schedule['SemesterID'] ? 'selected' : '' ?>>
                        <?= $s['SemesterName'] ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label for="day">Day of Week</label>
            <input type="text" name="day" id="day" value="<?= $schedule['DayOfWeek'] ?>" required>

            <label for="start">Start Time</label>
            <input type="time" name="start" id="start" value="<?= $schedule['StartTime'] ?>" required>

            <label for="end">End Time</label>
            <input type="time" name="end" id="end" value="<?= $schedule['EndTime'] ?>" required>

            <button type="submit" class="btn-submit">Update Schedule</button>
        </form>
    </div>
</div>

</body>
</html>