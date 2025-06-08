<?php
include 'db_connect.php';
include 'admin_sidebar_menu.php';

$course_id = isset($_GET['course']) ? $_GET['course'] : '';

// Lấy danh sách môn học cho filter
$courses = $conn->query("SELECT * FROM courses ORDER BY CourseName ASC");

if (!empty($course_id)) {
    $stmt = $conn->prepare("SELECT s.*, c.CourseName, l.LecturerName, r.RoomName, sem.SemesterName
        FROM schedule s
        JOIN courses c ON s.CourseID = c.CourseID
        JOIN lecturers l ON s.LecturerID = l.LecturerID
        JOIN classrooms r ON s.ClassroomID = r.ClassroomID
        JOIN semesters sem ON s.SemesterID = sem.SemesterID
        WHERE s.CourseID = ?
        ORDER BY s.ScheduleID ASC");
    $stmt->bind_param("s", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT s.*, c.CourseName, l.LecturerName, r.RoomName, sem.SemesterName
        FROM schedule s
        JOIN courses c ON s.CourseID = c.CourseID
        JOIN lecturers l ON s.LecturerID = l.LecturerID
        JOIN classrooms r ON s.ClassroomID = r.ClassroomID
        JOIN semesters sem ON s.SemesterID = sem.SemesterID
        ORDER BY s.ScheduleID ASC");
}
?>

<div class="main-content">
    <h2>Schedule List</h2>

    <form method="GET" style="margin-bottom: 20px;">
        <label><strong>Filter by Course:</strong></label>
        <select name="course" onchange="this.form.submit()">
            <option value="">-- All Courses --</option>
            <?php while ($c = $courses->fetch_assoc()): ?>
                <option value="<?= $c['CourseID'] ?>" <?= $course_id == $c['CourseID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['CourseName']) ?>
                </option>
            <?php endwhile; ?>
        </select>
        <a href="add_schedule.php" class="btn-add" style="margin-left: 20px;">Add Schedule</a>
    </form>

    <table class="data-table">
        <thead>
            <tr>
                <th>Course</th>
                <th>Lecturer</th>
                <th>Classroom</th>
                <th>Day</th>
                <th>Time</th>
                <th>Semester</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['CourseName']) ?></td>
                    <td><?= htmlspecialchars($row['LecturerName']) ?></td>
                    <td><?= htmlspecialchars($row['RoomName']) ?></td>
                    <td><?= htmlspecialchars($row['DayOfWeek']) ?></td>
                    <td><?= htmlspecialchars($row['StartTime']) ?> - <?= htmlspecialchars($row['EndTime']) ?></td>
                    <td><?= htmlspecialchars($row['SemesterName']) ?></td>
                    <td>
                        <a href="edit_schedule.php?id=<?= $row['ScheduleID'] ?>">Edit</a> |
                        <a href="delete_schedule.php?id=<?= $row['ScheduleID'] ?>" onclick="return confirm('Xoá lịch học này?')">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="7" style="text-align:center;">No schedule found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
