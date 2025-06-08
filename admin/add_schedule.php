<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php
include 'db_connect.php';
include 'admin_sidebar_menu.php';

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

    $stmt = $conn->prepare("INSERT INTO schedule (CourseID, LecturerID, ClassroomID, SemesterID, DayOfWeek, StartTime, EndTime)
                            VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siissss", $course, $lecturer, $classroom, $semester, $day, $start, $end);
    $stmt->execute();

    header("Location: manage_schedule.php");
    exit();
}
?>

<div class="main-content">
    <div class="form-card">
        <h2>Add Schedule</h2>
        <form method="POST" class="form-content">
            <label for="course">Course</label>
            <select name="course" id="course" required>
                <option value="">-- Select Course --</option>
                <?php while ($c = $courses->fetch_assoc()): ?>
                    <option value="<?= $c['CourseID'] ?>"><?= $c['CourseName'] ?></option>
                <?php endwhile; ?>
            </select>

            <label for="lecturer">Lecturer</label>
            <select name="lecturer" id="lecturer" required>
                <option value="">-- Select Lecturer --</option>
                <?php while ($l = $lecturers->fetch_assoc()): ?>
                    <option value="<?= $l['LecturerID'] ?>"><?= $l['LecturerName'] ?></option>
                <?php endwhile; ?>
            </select>

            <label for="classroom">Classroom</label>
            <select name="classroom" id="classroom" required>
                <option value="">-- Select Room --</option>
                <?php while ($r = $classrooms->fetch_assoc()): ?>
                    <option value="<?= $r['ClassroomID'] ?>"><?= $r['RoomName'] ?></option>
                <?php endwhile; ?>
            </select>

            <label for="semester">Semester</label>
            <select name="semester" id="semester" required>
                <option value="">-- Select Semester --</option>
                <?php while ($s = $semesters->fetch_assoc()): ?>
                    <option value="<?= $s['SemesterID'] ?>"><?= $s['SemesterName'] ?></option>
                <?php endwhile; ?>
            </select>

            <label for="day">Day of Week</label>
            <input type="text" name="day" id="day" required>

            <label for="start">Start Time</label>
            <input type="time" name="start" id="start" required>

            <label for="end">End Time</label>
            <input type="time" name="end" id="end" required>

            <button type="submit" class="btn-submit">Add Schedule</button>
        </form>
    </div>
</div>

</body>
</html>