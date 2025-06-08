<?php
// htdocs/DSS/student/save_student_schedule_ajax.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';

$response = [
    'status' => 'error_initial_save_script',
    'message' => 'Save schedule script encountered an issue before processing.'
];

try {
    if (!is_logged_in() || get_current_user_role() !== 'student') {
        throw new Exception("Unauthorized access. Student login required.");
    }
    $current_student_id = get_current_user_linked_entity_id();
    if (!$current_student_id) {
        throw new Exception("Student ID not found in session. Please re-login.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. This endpoint only accepts POST requests.");
    }

    $raw_input_data = file_get_contents('php://input');
    if ($raw_input_data === false) {
        throw new Exception("Could not read the request body from client.");
    }

    $request_data_from_client = json_decode($raw_input_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received. Error: " . json_last_error_msg());
    }

    $selected_schedule_events = $request_data_from_client['selected_schedule_events'] ?? null;
    $target_semester_id = $request_data_from_client['semester_id'] ?? null;
    $schedule_option_name = sanitize_input($request_data_from_client['schedule_name'] ?? ('My Saved Schedule ' . date('Y-m-d H:i')));

    if (empty($selected_schedule_events) || !is_array($selected_schedule_events)) {
        throw new Exception("No schedule events provided to save.");
    }
    if (empty($target_semester_id) || !filter_var($target_semester_id, FILTER_VALIDATE_INT) || (int)$target_semester_id <= 0) {
        throw new Exception("A valid Semester ID is required to save the schedule.");
    }
    $target_semester_id = (int)$target_semester_id;

    if (!$conn) {
        throw new Exception("Database connection is not available.");
    }

    $conn->begin_transaction();

    try {
        // Deactivate any existing active schedules for this student & semester
        $stmt_deactivate_old = $conn->prepare("UPDATE StudentPersonalSchedules SET IsActive = 0 WHERE StudentID = ? AND SemesterID = ? AND IsActive = 1");
        if (!$stmt_deactivate_old) throw new Exception("DB Prepare Error (deactivate): " . $conn->error);
        $stmt_deactivate_old->bind_param("si", $current_student_id, $target_semester_id);
        if (!$stmt_deactivate_old->execute()) throw new Exception("DB Execute Error (deactivate): " . $stmt_deactivate_old->error);
        $stmt_deactivate_old->close();

        $schedule_data_json_to_save = json_encode($selected_schedule_events);
        if ($schedule_data_json_to_save === false) {
            throw new Exception("Failed to encode schedule events to JSON. Error: " . json_last_error_msg());
        }

        // Insert the new personal schedule and mark it as active
        $stmt_insert_schedule = $conn->prepare(
            "INSERT INTO StudentPersonalSchedules (StudentID, SemesterID, ScheduleName, ScheduleData, IsActive)
             VALUES (?, ?, ?, ?, 1)"
        );
        if (!$stmt_insert_schedule) {
            throw new Exception("DB Prepare Error (insert personal schedule): " . $conn->error);
        }

        $stmt_insert_schedule->bind_param("siss",
            $current_student_id,
            $target_semester_id,
            $schedule_option_name,
            $schedule_data_json_to_save
        );

        if (!$stmt_insert_schedule->execute()) {
            // Check for unique constraint violation (StudentID, SemesterID, ScheduleName)
            if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                 throw new Exception("A schedule with the name '" . htmlspecialchars($schedule_option_name) . "' already exists for this semester. Please use a different name.");
            }
            throw new Exception("DB Execute Error (insert personal schedule): " . $stmt_insert_schedule->error);
        }
        $new_personal_schedule_id = $stmt_insert_schedule->insert_id;
        $stmt_insert_schedule->close();

        $conn->commit();

        $response['status'] = 'success_schedule_saved';
        $response['message'] = "Your schedule '" . htmlspecialchars($schedule_option_name) . "' has been saved successfully!";
        $response['saved_schedule_id'] = $new_personal_schedule_id;

    } catch (Exception $db_exception) {
        $conn->rollback();
        throw $db_exception;
    }

} catch (Exception $e) {
    $response['status'] = 'error_php_save_process';
    $response['message'] = "PHP Error: " . $e->getMessage();
    error_log("save_student_schedule_ajax.php EXCEPTION: " . $e->getMessage());
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