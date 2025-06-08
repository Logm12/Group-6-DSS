<?php
// htdocs/DSS/student/get_courses_for_registration_ajax.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

$response = [
    'status' => 'error_initial_script_state',
    'message' => 'Course retrieval script did not fully initialize.',
    'courses' => []
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'student') {
        throw new Exception("Unauthorized access. Student login is required.");
    }
    $current_student_id = get_current_user_linked_entity_id();
    if (!$current_student_id) {
        throw new Exception("Student ID could not be determined from session.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Invalid request method.");
    }

    $semester_id_from_get = $_GET['semester_id'] ?? null;
    $major_category_from_get = isset($_GET['major_category']) ? sanitize_input($_GET['major_category']) : null;

    if (empty($semester_id_from_get) || !filter_var($semester_id_from_get, FILTER_VALIDATE_INT) || (int)$semester_id_from_get <= 0) {
        // While not used in current SQL for course filtering, it's good context
        error_log("GetCoursesAJAX: Warning - Valid Semester ID not provided or invalid.");
        // For now, we don't strictly require it for THIS query, but good to note.
    }
    $selected_semester_id = (int)$semester_id_from_get; // Keep for potential future use

    if (!$conn) { // Check if $conn is null or false
        throw new Exception("Database connection is not available.");
    }
    if ($conn->connect_error) { // Check for connection errors
        throw new Exception("Database connection error: " . $conn->connect_error);
    }


    $courses_data_array = [];
    $sql_params = [$current_student_id]; // Start with student_id for subquery
    $sql_param_types = "s";

    // Base SQL to fetch courses not already taken by the student
    $sql_fetch_available_courses = "
        SELECT
            c.CourseID,
            c.CourseName,
            c.Credits,
            c.ExpectedStudents,
            c.SessionDurationSlots,
            c.MajorCategory 
        FROM Courses c
        WHERE c.CourseID NOT IN (
            SELECT DISTINCT se.CourseID
            FROM StudentEnrollments se
            WHERE se.StudentID = ?
            -- Optionally add AND se.SemesterID = ? if exclusion should be semester-specific
        )
    ";

    // Add MajorCategory filter if provided and not 'all'
    if (!empty($major_category_from_get) && $major_category_from_get !== 'all') {
        $sql_fetch_available_courses .= " AND c.MajorCategory = ?";
        $sql_params[] = $major_category_from_get;
        $sql_param_types .= "s";
    }
    // Consider adding program filter here if $student_program_info is available and relevant
    // e.g., AND c.ProgramID = ? (if Courses table has ProgramID)

    $sql_fetch_available_courses .= " ORDER BY c.MajorCategory, c.CourseName ASC";

    // $response['debug_sql'] = $sql_fetch_available_courses; // For debugging
    // $response['debug_params'] = $sql_params;

    $stmt_fetch_courses = $conn->prepare($sql_fetch_available_courses);
    if (!$stmt_fetch_courses) {
        throw new Exception("DB Prepare Error (fetching courses): " . $conn->error);
    }

    $stmt_fetch_courses->bind_param($sql_param_types, ...$sql_params);

    if (!$stmt_fetch_courses->execute()) {
        throw new Exception("DB Execute Error (fetching courses): " . $stmt_fetch_courses->error);
    }

    $result_courses_from_db = $stmt_fetch_courses->get_result();

    if ($result_courses_from_db) {
        while ($row_course = $result_courses_from_db->fetch_assoc()) {
             if (is_array($row_course)) {
                $courses_data_array[] = [
                    'CourseID' => $row_course['CourseID'] ?? 'N/A',
                    'CourseName' => $row_course['CourseName'] ?? 'N/A',
                    'Credits' => isset($row_course['Credits']) ? (int)$row_course['Credits'] : null,
                    'ExpectedStudents' => isset($row_course['ExpectedStudents']) ? (int)$row_course['ExpectedStudents'] : null,
                    'SessionDurationSlots' => (isset($row_course['SessionDurationSlots']) && $row_course['SessionDurationSlots'] !== null) ? (int)$row_course['SessionDurationSlots'] : 1,
                    'MajorCategory' => $row_course['MajorCategory'] ?? 'N/A'
                ];
            }
        }
        $result_courses_from_db->free();
    }
    $stmt_fetch_courses->close();

    if (!empty($courses_data_array)) {
        $response['status'] = 'success';
        $response['message'] = count($courses_data_array) . " courses found matching your criteria.";
        $response['courses'] = $courses_data_array;
    } else {
        if ($conn->error) {
             throw new Exception("DB error after fetching courses: " . $conn->error);
        }
        $response['status'] = 'success_no_courses';
        $response['message'] = "No courses found matching your current selection. Try different criteria or check if you've already enrolled in available courses.";
        $response['courses'] = [];
    }

} catch (Exception $e) {
    $response['status'] = 'error_php_script_execution';
    $response['message'] = "PHP Error: " . $e->getMessage();
    error_log("get_courses_for_registration_ajax.php EXCEPTION: " . $e->getMessage());
}

if (session_id() !== '') {
    session_write_close();
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>