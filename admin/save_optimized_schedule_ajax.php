<?php
// htdocs/DSS/admin/save_optimized_schedule_ajax.php

// Ensure session is started for authentication
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set content type to JSON for all responses
header('Content-Type: application/json');

// Include necessary files
require_once __DIR__ . '/../includes/functions.php'; 
require_once __DIR__ . '/../includes/db_connect.php'; // $conn database connection

// Initialize a default response structure
$response = [
    'status' => 'error_save_initial', 
    'message' => 'Save schedule script encountered an issue before processing the request.'
];

try {
    // 1. Authentication and Authorization: Only admins can save the system schedule
    if (!is_logged_in() || get_current_user_role() !== 'admin') {
        throw new Exception("Unauthorized access. Administrator privileges required to save the schedule.");
    }

    // 2. Validate Request Method: Only POST requests are accepted
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. This endpoint only accepts POST requests.");
    }
    
    // 3. Get and Decode JSON Input from JavaScript
    $raw_input_data = file_get_contents('php://input');
    if ($raw_input_data === false) {
        throw new Exception("Could not read the request body from the client.");
    }
    
    $request_data_from_client = json_decode($raw_input_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data received from the client. JSON Error: " . json_last_error_msg());
    }

    // 4. Validate Key Input Parameters
    $target_semester_id = $request_data_from_client['semester_id'] ?? null;
    $final_schedule_events_to_save = $request_data_from_client['final_schedule'] ?? null; // Array of scheduled class objects

    if (empty($target_semester_id) || !filter_var($target_semester_id, FILTER_VALIDATE_INT) || (int)$target_semester_id <= 0) {
        throw new Exception("A valid Semester ID is required to save the schedule to the system.");
    }
    $target_semester_id = (int)$target_semester_id;

    if (empty($final_schedule_events_to_save) || !is_array($final_schedule_events_to_save)) {
        // It's possible an empty schedule is valid if all classes were unschedulable by Python.
        // However, if the intention is to save a *generated* schedule, it shouldn't be empty
        // unless the Python output explicitly indicates an empty but successful generation.
        // For now, let's assume an empty schedule means nothing to save, or an issue.
        // If Python can return a valid empty schedule (e.g., for a semester with no courses),
        // this logic might need adjustment to allow saving an "empty" state.
        // For now, treat as an error if it's meant to be a populated schedule.
        // Alternatively, allow saving an empty schedule to clear existing one.
        // Let's proceed with allowing empty to clear, but log it.
        error_log("SaveOptimizedSchedule: Received an empty 'final_schedule' array for semester ID: " . $target_semester_id . ". Existing schedule for this semester will be cleared if 'DELETE FIRST' strategy is used.");
        // If empty schedule is NOT allowed:
        // throw new Exception("No schedule event data provided to save. The schedule list is empty.");
    }

    // 5. Database Operations: Save the optimized schedule
    if (!$conn) {
        throw new Exception("Database connection is not available. Cannot save schedule.");
    }

    $conn->begin_transaction(); // Start a database transaction for atomicity

    try {
        // Strategy: Delete all existing scheduled classes for the target semester first, then insert new ones.
        // This is simpler than trying to update/delete/insert selectively.
        // IMPORTANT: Ensure this is the desired behavior. Add confirmation on client-side.
        
        $stmt_delete_old_schedule = $conn->prepare("DELETE FROM ScheduledClasses WHERE SemesterID = ?");
        if (!$stmt_delete_old_schedule) {
            throw new Exception("DB Prepare Error (delete old schedule): " . $conn->error);
        }
        $stmt_delete_old_schedule->bind_param("i", $target_semester_id);
        if (!$stmt_delete_old_schedule->execute()) {
            throw new Exception("DB Execute Error (delete old schedule): " . $stmt_delete_old_schedule->error);
        }
        $deleted_rows = $stmt_delete_old_schedule->affected_rows;
        $stmt_delete_old_schedule->close();
        error_log("SaveOptimizedSchedule: Deleted " . $deleted_rows . " old scheduled classes for SemesterID: " . $target_semester_id);

        // Now, insert the new schedule events
        $inserted_count = 0;
        if (!empty($final_schedule_events_to_save)) { // Only insert if there's something to insert
            $sql_insert_event = "INSERT INTO ScheduledClasses 
                                (CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID, NumStudents) 
                                VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_insert_event = $conn->prepare($sql_insert_event);
            if (!$stmt_insert_event) {
                throw new Exception("DB Prepare Error (insert new event): " . $conn->error);
            }

            foreach ($final_schedule_events_to_save as $event_to_save) {
                // Validate that each event has the necessary keys from Python output
                // Python output uses: 'course_id_str', 'lecturer_id_db', 'classroom_id_db', 'timeslot_id_db', 'num_students'
                $course_id_event = $event_to_save['course_id_str'] ?? null;
                $lecturer_id_event = $event_to_save['lecturer_id_db'] ?? null; // Note: Python might send DB PKs
                $classroom_id_event = $event_to_save['classroom_id_db'] ?? null;
                $timeslot_id_event = $event_to_save['timeslot_id_db'] ?? null;
                // 'num_students' should be part of the Python event output for each scheduled class
                $num_students_event = $event_to_save['num_students'] ?? ($event_to_save['num_students_for_class'] ?? null); 

                // Basic validation for required fields
                if (empty($course_id_event) || $lecturer_id_event === null || $classroom_id_event === null || $timeslot_id_event === null) {
                    // Log this specific event error, but might try to continue with others or rollback all
                    error_log("SaveOptimizedSchedule: Skipping an event due to missing critical IDs: Course='{$course_id_event}', Lect='{$lecturer_id_event}', Room='{$classroom_id_event}', Slot='{$timeslot_id_event}'");
                    continue; // Skip this invalid event
                }
                // NumStudents can be 0 if a course has 0 expected students, but should generally be positive.
                // If it's crucial to have num_students, add validation here.
                if ($num_students_event === null || !is_numeric($num_students_event) || (int)$num_students_event < 0) {
                    error_log("SaveOptimizedSchedule: Skipping an event due to invalid NumStudents for Course '{$course_id_event}': Value = '{$num_students_event}'");
                    // Decide if this is a critical error that should stop the whole save process
                    // For now, let's assume it might be 0 for some courses and proceed.
                    // If it MUST be > 0, then: throw new Exception("Invalid NumStudents for course {$course_id_event}");
                    $num_students_event_db = 0; // Default to 0 if invalid/missing
                } else {
                    $num_students_event_db = (int)$num_students_event;
                }


                $stmt_insert_event->bind_param("siiisi", 
                    $course_id_event, 
                    $lecturer_id_event, 
                    $classroom_id_event, 
                    $timeslot_id_event, 
                    $target_semester_id,
                    $num_students_event_db
                );
                if (!$stmt_insert_event->execute()) {
                    // If one insert fails, the transaction will be rolled back.
                    throw new Exception("DB Execute Error (insert new event for CourseID: {$course_id_event}): " . $stmt_insert_event->error);
                }
                $inserted_count++;
            }
            $stmt_insert_event->close();
        }

        $conn->commit(); // Commit the transaction if all operations were successful

        $response['status'] = 'success_schedule_saved_to_db';
        if ($inserted_count > 0) {
            $response['message'] = "Optimized schedule with " . $inserted_count . " classes has been successfully saved to the system for the selected semester. (Previously " . $deleted_rows . " classes were cleared).";
        } elseif ($deleted_rows > 0 && $inserted_count == 0 && empty($final_schedule_events_to_save)) {
            $response['message'] = "Successfully cleared " . $deleted_rows . " existing classes for the semester. No new classes were provided to save.";
        } elseif ($deleted_rows == 0 && $inserted_count == 0 && empty($final_schedule_events_to_save)) {
            $response['message'] = "No existing classes to clear and no new classes provided. System state unchanged.";
        }
        else {
             $response['message'] = "No new classes were inserted, but " . $deleted_rows . " old classes were cleared for the semester.";
        }
        $response['inserted_count'] = $inserted_count;
        $response['deleted_count'] = $deleted_rows;

    } catch (Exception $db_exception) {
        $conn->rollback(); // Rollback transaction on any DB error during the process
        // Re-throw to be caught by the outer try-catch, which will set the response status/message
        throw $db_exception; 
    }

} catch (Exception $e) {
    $response['status'] = 'error_php_save_process'; 
    $response['message'] = "PHP Error while attempting to save the schedule: " . $e->getMessage();
    // Log the detailed error on the server for admin/developer to see
    error_log("save_optimized_schedule_ajax.php EXCEPTION: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
    // Optionally include some debug info for client if in development mode
    // $response['debug_info'] = ['file' => $e->getFile(), 'line' => $e->getLine()];
}

// Close session if it was started
if (session_id() !== '') { 
    session_write_close();
}

// Send the final JSON response to the client
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>