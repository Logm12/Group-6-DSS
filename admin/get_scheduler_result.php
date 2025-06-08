<?php
// htdocs/DSS/admin/get_scheduler_result.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php'; // For is_logged_in(), etc.

// Set header early, but after includes/session_start to avoid issues
if (!headers_sent()) {
    header('Content-Type: application/json');
}

$response = ['status' => 'error_initial_result', 'message' => 'Result script encountered an issue.'];

try {
    if (!is_logged_in() || get_current_user_role() !== 'admin') {
        throw new Exception("Unauthorized access. Admin role required to fetch results.");
    }

    $output_filename_relative_from_session = $_SESSION['scheduler_output_file'] ?? null;
    $final_output_filename_relative = null;

    if (isset($_GET['file'])) {
        $output_filename_from_get_param = basename($_GET['file']); // Sanitize to prevent path traversal

        // Validate filename format
        if (!preg_match('/^final_schedule_output_[a-zA-Z0-9_]+\.json$/', $output_filename_from_get_param)) {
             throw new Exception("Invalid output filename format received in GET parameter.");
        }

        // Prefer session if it matches, otherwise use validated GET param
        if ($output_filename_relative_from_session && str_ends_with($output_filename_relative_from_session, $output_filename_from_get_param)) {
            $final_output_filename_relative = $output_filename_relative_from_session;
        } else {
            // Construct relative path from python_algorithm directory
            $final_output_filename_relative = 'output_data' . DIRECTORY_SEPARATOR . $output_filename_from_get_param;
            // Optionally, update session if it was mismatched or missing, though this script's primary role is reading.
            // $_SESSION['scheduler_output_file'] = $final_output_filename_relative; 
        }
    } elseif ($output_filename_relative_from_session) {
        // Fallback to session if GET param is not provided
        $final_output_filename_relative = $output_filename_relative_from_session;
    } else {
        throw new Exception("Output file reference not found in session or GET parameter.");
    }


    $python_algorithm_base_dir = realpath(__DIR__ . '/../python_algorithm');
    if (!$python_algorithm_base_dir) {
        throw new Exception("Python algorithm base directory could not be resolved.");
    }
    // The $final_output_filename_relative should already contain 'output_data/' prefix
    $output_file_absolute_path = $python_algorithm_base_dir . DIRECTORY_SEPARATOR . $final_output_filename_relative;

    if (file_exists($output_file_absolute_path)) {
        $json_content_from_file = @file_get_contents($output_file_absolute_path);
        if ($json_content_from_file === false) {
            throw new Exception("Could not read result file: " . htmlspecialchars($output_file_absolute_path));
        }
        
        $decoded_data_from_file = json_decode($json_content_from_file, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // The Python script's output (decoded_data_from_file) already contains 'status', 'message', etc.
            // Wrap it inside a 'success' response from this PHP script.
            $response = ['status' => 'success', 'message' => 'Successfully retrieved Python output.', 'data' => $decoded_data_from_file]; 
        } else {
            throw new Exception("Failed to decode JSON from result file. JSON Error: " . json_last_error_msg() . ". Raw content (first 500 chars): " . substr(htmlspecialchars($json_content_from_file), 0, 500));
        }
    } else {
        throw new Exception("Result file not found at the expected location: " . htmlspecialchars($output_file_absolute_path));
    }

} catch (Exception $e) {
    $response['status'] = 'error_result_script'; // Indicates error within this PHP script
    $response['message'] = "Error in get_scheduler_result.php: " . $e->getMessage();
    error_log("get_scheduler_result.php EXCEPTION: " . $e->getMessage() . "\nFile: " . $e->getFile() . "\nLine: " . $e->getLine());
}

// Close session after use
if (session_id() !== '') { 
    session_write_close();
}

// Ensure JSON header is set if not already sent (e.g., by an error)
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;
?>