<?php
// htdocs/DSS/admin/scheduler.php
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/functions.php'; 
include_once __DIR__ . '/../includes/admin_sidebar_menu.php';
require_role(['admin'], '../login.php'); // Chỉ cho phép vai trò 'admin'

$page_title = "Automatic Scheduler"; // Sẽ được ghi đè bởi logic trong admin_sidebar_menu.php nếu có

// --- Python Execution Configuration ---
$python_executable = 'python'; // IMPORTANT: Ensure this is correct for your server environment
$python_script_name = 'main_solver.py';

// Determine project root and python algorithm path
$project_root_dss = dirname(dirname(__FILE__)); // DSS/
$python_algorithm_path = $project_root_dss . DIRECTORY_SEPARATOR . 'python_algorithm' . DIRECTORY_SEPARATOR;

// --- Default Optimization Presets ---
if (!defined('PRESET_FAST_CP_TIME')) define('PRESET_FAST_CP_TIME', 30);
if (!defined('PRESET_FAST_GA_GENS')) define('PRESET_FAST_GA_GENS', 20);
if (!defined('PRESET_FAST_GA_POP')) define('PRESET_FAST_GA_POP', 20);
if (!defined('PRESET_BALANCED_CP_TIME')) define('PRESET_BALANCED_CP_TIME', 60);
if (!defined('PRESET_BALANCED_GA_GENS')) define('PRESET_BALANCED_GA_GENS', 50);
if (!defined('PRESET_BALANCED_GA_POP')) define('PRESET_BALANCED_GA_POP', 30);
if (!defined('PRESET_QUALITY_CP_TIME')) define('PRESET_QUALITY_CP_TIME', 120);
if (!defined('PRESET_QUALITY_GA_GENS')) define('PRESET_QUALITY_GA_GENS', 100);
if (!defined('PRESET_QUALITY_GA_POP')) define('PRESET_QUALITY_GA_POP', 50);

$default_ga_crossover_rate = 0.85;
$default_ga_mutation_rate = 0.15;
$default_ga_tournament_size = 5;
$default_ga_allow_hc_violations = false;
$default_priority_student_clash = 'medium';
$default_priority_lecturer_load_break = 'medium';
$default_priority_classroom_util = 'medium';

// --- Initialize Form Variables & State ---
$selected_semester_id = $_POST['semester_id'] ?? $_GET['semester_id'] ?? null; // Persist selection
if ($selected_semester_id) $selected_semester_id = intval($selected_semester_id);

$form_cp_time_limit = $_POST['cp_time_limit_override'] ?? PRESET_BALANCED_CP_TIME;
$form_ga_generations = $_POST['ga_generations_override'] ?? PRESET_BALANCED_GA_GENS;
$form_ga_population_size = $_POST['ga_population_size_override'] ?? PRESET_BALANCED_GA_POP;
$form_selected_preset = $_POST['optimization_preset'] ?? 'balanced';
$form_priority_student_clash = $_POST['priority_student_clash'] ?? $default_priority_student_clash;
$form_priority_lecturer_load_break = $_POST['priority_lecturer_load_break'] ?? $default_priority_lecturer_load_break;
$form_priority_classroom_util = $_POST['priority_classroom_util'] ?? $default_priority_classroom_util;

$scheduling_log_display = [];
$schedule_results_display = null;
$error_message_php = '';
$success_message_php = '';
$python_run_stdout = '';

// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['start_scheduling'])) {
    if (!empty($selected_semester_id)) { // selected_semester_id is already set from above
        
        $cp_time_limit_posted = isset($_POST['cp_time_limit_override']) && is_numeric($_POST['cp_time_limit_override']) && $_POST['cp_time_limit_override'] !== '' ? intval($_POST['cp_time_limit_override']) : null;
        $ga_generations_posted = isset($_POST['ga_generations_override']) && is_numeric($_POST['ga_generations_override']) && $_POST['ga_generations_override'] !== '' ? intval($_POST['ga_generations_override']) : null;
        $ga_population_size_posted = isset($_POST['ga_population_size_override']) && is_numeric($_POST['ga_population_size_override']) && $_POST['ga_population_size_override'] !== '' ? intval($_POST['ga_population_size_override']) : null;

        if ($form_selected_preset !== 'custom') {
            switch ($form_selected_preset) {
                case 'fast':
                    $cp_time_limit = $cp_time_limit_posted ?? PRESET_FAST_CP_TIME;
                    $ga_generations = $ga_generations_posted ?? PRESET_FAST_GA_GENS;
                    $ga_population_size = $ga_population_size_posted ?? PRESET_FAST_GA_POP;
                    break;
                case 'quality':
                    $cp_time_limit = $cp_time_limit_posted ?? PRESET_QUALITY_CP_TIME;
                    $ga_generations = $ga_generations_posted ?? PRESET_QUALITY_GA_GENS;
                    $ga_population_size = $ga_population_size_posted ?? PRESET_QUALITY_GA_POP;
                    break;
                default: // balanced
                    $cp_time_limit = $cp_time_limit_posted ?? PRESET_BALANCED_CP_TIME;
                    $ga_generations = $ga_generations_posted ?? PRESET_BALANCED_GA_GENS;
                    $ga_population_size = $ga_population_size_posted ?? PRESET_BALANCED_GA_POP;
            }
        } else { 
            $cp_time_limit = $cp_time_limit_posted ?? PRESET_BALANCED_CP_TIME; // Fallback if custom but empty
            $ga_generations = $ga_generations_posted ?? PRESET_BALANCED_GA_GENS;
            $ga_population_size = $ga_population_size_posted ?? PRESET_BALANCED_GA_POP;
        }
        
        $form_cp_time_limit = $cp_time_limit; // Update for display
        $form_ga_generations = $ga_generations;
        $form_ga_population_size = $ga_population_size;

        $scheduling_log_display[] = "Scheduling process initiated for Semester ID: " . $selected_semester_id;
        // ... (logging parameters as before) ...

        $python_input_filename = 'scheduler_input_config.json'; 
        $python_output_filename = 'final_schedule_output.json'; 
        
        $run_unique_id = uniqid('schedprog_');
        $progress_log_filename_for_python = 'progress_' . $run_unique_id . '.txt';
        $python_progress_file_path_relative = 'output_data' . DIRECTORY_SEPARATOR . $progress_log_filename_for_python;

        $input_config_data_for_python = [
            'semester_id_to_load' => $selected_semester_id,
            'cp_time_limit_seconds' => $cp_time_limit,
            'ga_generations' => $ga_generations,
            'ga_population_size' => $ga_population_size,
            'ga_crossover_rate' => $default_ga_crossover_rate, 
            'ga_mutation_rate' => $default_ga_mutation_rate,
            'ga_tournament_size' => $default_ga_tournament_size,
            'ga_allow_hard_constraint_violations' => $default_ga_allow_hc_violations,
            'priority_student_clash' => $form_priority_student_clash,          
            'priority_lecturer_load_break' => $form_priority_lecturer_load_break, 
            'priority_classroom_util' => $form_priority_classroom_util,
            'progress_log_file_path_from_php' => $python_progress_file_path_relative 
        ];
        $input_json_content = json_encode($input_config_data_for_python, JSON_PRETTY_PRINT);

        if ($input_json_content === false) {
            $error_message_php = "Error creating JSON input for Python.";
            $scheduling_log_display[] = "[PHP ERROR] " . $error_message_php;
        } else {
            $scheduling_log_display[] = "Input configuration for Python generated.";
            $scheduling_log_display[] = "Executing Python scheduler (" . $python_script_name . ")... Please wait.";
            
            if (ob_get_level() == 0) ob_start();
            echo "<div class='container-fluid px-4'><p id='initial-wait-message' class='alert alert-info mt-3'>Processing... This may take several minutes. Detailed progress will appear after completion.</p></div>";
            flush(); ob_flush();

            $python_script_absolute_path = $python_algorithm_path . $python_script_name;
            
            $estimated_ga_time_per_gen = 0.8; 
            $estimated_ga_time = $ga_generations * $estimated_ga_time_per_gen;
            $php_timeout = $cp_time_limit + $estimated_ga_time + 180;

            $python_result = call_python_scheduler(
                $python_executable, $python_script_absolute_path, $input_json_content,
                $python_input_filename, $python_output_filename, (int)$php_timeout
            );
            
            echo "<script>var el = document.getElementById('initial-wait-message'); if(el) el.style.display = 'none';</script>";

            if (!empty($python_result['debug_stdout'])) { $python_run_stdout = $python_result['debug_stdout']; }
            if (!empty($python_result['debug_stderr'])) {
                $scheduling_log_display[] = "PYTHON STDERR:<pre>" . htmlspecialchars($python_result['debug_stderr']) . "</pre>";
                if(empty($error_message_php)) $error_message_php = "Python script reported errors. Check the detailed Python output below.";
            }

            // Process final JSON output from Python
            if (isset($python_result['data']) && is_array($python_result['data']) && isset($python_result['data']['status'])) {
                $schedule_data_from_python = $python_result['data'];
                $scheduling_log_display[] = "Python script final status: " . htmlspecialchars($schedule_data_from_python['status']);
                $scheduling_log_display[] = "Python script final message: " . nl2br(htmlspecialchars($schedule_data_from_python['message']));

                if (strpos($schedule_data_from_python['status'], 'success') === 0 && isset($schedule_data_from_python['final_schedule']) && is_array($schedule_data_from_python['final_schedule'])) {
                    $schedule_results_display = $schedule_data_from_python['final_schedule'];
                    $num_scheduled = count($schedule_results_display);
                    $success_message_php = $schedule_data_from_python['message'] ?? "Scheduling completed successfully.";
                    
                    if ($num_scheduled > 0) {
                        // ... (DB INSERT LOGIC - unverändert) ...
                    } else {
                         $scheduling_log_display[] = "Python script reported success but returned an empty schedule.";
                         if(empty($error_message_php)) $error_message_php = $schedule_data_from_python['message'] ?? "Algorithm did not generate any schedule items.";
                    }
                } else { 
                    $error_message_php = $schedule_data_from_python['message'] ?? "Python script finished but did not produce a valid final schedule or reported an internal error.";
                }
            } elseif ($python_result['status'] !== 'success') { 
                 $error_message_php = $python_result['message'] ?? "Python script execution failed or returned invalid data structure.";
                 if (isset($python_result['data']['message']) && is_string($python_result['data']['message'])) { 
                     $error_message_php .= " Python detail: " . htmlspecialchars($python_result['data']['message']); 
                 } elseif (isset($python_result['data']) && is_array($python_result['data'])) {
                     $error_message_php .= " Python data: <pre>" . htmlspecialchars(json_encode($python_result['data'])) . "</pre>";
                 }
            }
        }
    } elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
        $error_message_php = "Please select a semester.";
    }
}
?>
<style>
    /* ... (CSS giữ nguyên) ... */
</style>

<div class="container-fluid px-4">
    <h1 class="mt-4"><?php echo htmlspecialchars($page_title); ?></h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>admin/index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Run Scheduler</li>
    </ol>

    <?php if (!empty($error_message_php)): ?>
        <div class="alert alert-danger" role="alert"><?php echo nl2br(htmlspecialchars($error_message_php)); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message_php)): ?>
        <div class="alert alert-success" role="alert"><?php echo nl2br(htmlspecialchars($success_message_php)); ?></div>
    <?php endif; ?>

    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="schedulerForm">
        <input type="hidden" name="semester_id" value="<?php echo htmlspecialchars($selected_semester_id); ?>" id="hidden_semester_id_for_post">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-filter me-1"></i>Filters & Main Settings</div>
            <div class="card-body">
                 <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="semester_id_selector" class="form-label fw-bold">Select Semester (*):</label>
                        <select name="semester_id_selector" id="semester_id_selector" class="form-select" required>
                            <option value="">-- Select Semester --</option>
                            <?php echo function_exists('generate_select_options') ? generate_select_options($conn, 'Semesters', 'SemesterID', 'SemesterName', $selected_semester_id, "EndDate >= CURDATE() OR StartDate >= CURDATE()", "StartDate DESC", "-- Select Semester --") : "<option value=''>Error loading</option>"; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="optimization_preset" class="form-label fw-bold">Optimization Profile:</label>
                        <select name="optimization_preset" id="optimization_preset" class="form-select">
                            <option value="fast" <?php selected_if_match($form_selected_preset, 'fast'); ?>>Fast (Basic Quality)</option>
                            <option value="balanced" <?php selected_if_match($form_selected_preset, 'balanced'); ?>>Balanced (Recommended)</option>
                            <option value="quality" <?php selected_if_match($form_selected_preset, 'quality'); ?>>High Quality (Slower)</option>
                            <option value="custom" <?php selected_if_match($form_selected_preset, 'custom'); ?>>Custom Parameters...</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div id="custom_parameters_card" class="card mb-4 <?php echo ($form_selected_preset !== 'custom' ? 'd-none' : ''); ?>">
            <div class="card-header"><i class="fas fa-sliders-h me-1"></i>Algorithm Parameters (Custom)</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <label for="cp_time_limit_override" class="form-label">Initial Solution Time (CP, seconds):</label>
                        <input type="number" name="cp_time_limit_override" id="cp_time_limit_override" class="form-control" value="<?php echo htmlspecialchars($form_cp_time_limit); ?>" min="10" step="10" placeholder="<?php echo PRESET_BALANCED_CP_TIME; ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="ga_generations_override" class="form-label">Refinement Iterations (GA):</label>
                        <input type="number" name="ga_generations_override" id="ga_generations_override" class="form-control" value="<?php echo htmlspecialchars($form_ga_generations); ?>" min="10" step="10" placeholder="<?php echo PRESET_BALANCED_GA_GENS; ?>">
                    </div>
                     <div class="col-md-4">
                        <label for="ga_population_size_override" class="form-label">Solutions per Iteration (GA):</label>
                        <input type="number" name="ga_population_size_override" id="ga_population_size_override" class="form-control" value="<?php echo htmlspecialchars($form_ga_population_size); ?>" min="10" step="5" placeholder="<?php echo PRESET_BALANCED_GA_POP; ?>">
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-balance-scale me-1"></i>Soft Constraint Priorities</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 priority-group">
                        <label for="priority_student_clash" class="form-label">Student Schedule Conflicts:</label>
                        <select name="priority_student_clash" id="priority_student_clash" class="form-select">
                            <option value="low" <?php selected_if_match($form_priority_student_clash, 'low'); ?>>Low</option>
                            <option value="medium" <?php selected_if_match($form_priority_student_clash, 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected_if_match($form_priority_student_clash, 'high'); ?>>High</option>
                        </select>
                    </div>
                    <div class="col-md-4 priority-group">
                        <label for="priority_lecturer_load_break" class="form-label">Lecturer Workload & Breaks:</label>
                        <select name="priority_lecturer_load_break" id="priority_lecturer_load_break" class="form-select">
                             <option value="low" <?php selected_if_match($form_priority_lecturer_load_break, 'low'); ?>>Low</option>
                            <option value="medium" <?php selected_if_match($form_priority_lecturer_load_break, 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected_if_match($form_priority_lecturer_load_break, 'high'); ?>>High</option>
                        </select>
                    </div>
                    <div class="col-md-4 priority-group">
                        <label for="priority_classroom_util" class="form-label">Classroom Utilization:</label>
                        <select name="priority_classroom_util" id="priority_classroom_util" class="form-select">
                            <option value="low" <?php selected_if_match($form_priority_classroom_util, 'low'); ?>>Low</option>
                            <option value="medium" <?php selected_if_match($form_priority_classroom_util, 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected_if_match($form_priority_classroom_util, 'high'); ?>>High</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="text-center mb-4">
            <button type="submit" name="start_scheduling" class="btn btn-primary btn-lg px-5 py-3">
                <i class="fas fa-play me-2"></i> Start Scheduling Process
            </button>
        </div>
    </form>

    <!-- Log Area -->
    <?php if (isset($_POST['start_scheduling']) && !empty($selected_semester_id)): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-stream me-1"></i>Scheduling Process Log</div>
        <div class="card-body">
            <?php if(!empty($scheduling_log_display)): ?>
                <div class="scheduling-log-container mb-3">
                    <h6>PHP Process Log:</h6>
                    <?php foreach ($scheduling_log_display as $log_entry): ?><div><?php echo $log_entry; ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if(!empty($python_run_stdout)): ?>
                 <h6>Python Script Output (stdout):</h6>
                <div id="python-stdout-log">
                    <?php echo nl2br(htmlspecialchars($python_run_stdout)); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Results Table -->
    <?php if ($schedule_results_display !== null ): ?>
        <?php if (is_array($schedule_results_display) && count($schedule_results_display) > 0): ?>
            <div class="card"><div class="card-header"><i class="fas fa-calendar-alt me-1"></i>Final Schedule (<?php echo count($schedule_results_display); ?> classes)</div><div class="card-body"><div class="table-responsive">
                <table class="table table-bordered table-striped table-hover " id="scheduleResultsTable">
                    <thead><tr><th>#</th><th>Course ID</th><th>Course Name</th><th>Lecturer</th><th>Room</th><th>Day</th><th>Date</th><th>Start</th><th>End</th></tr></thead>
                    <tbody>
                        <?php
                        $course_cache_disp = []; $lecturer_cache_disp = []; $classroom_cache_disp = []; $timeslot_cache_disp = [];
                        $stt_disp = 1;
                        foreach ($schedule_results_display as $event_disp):
                            $c_id_disp = $event_disp['course_id'] ?? 'N/A';
                            $l_id_disp = isset($event_disp['lecturer_id']) ? intval($event_disp['lecturer_id']) : null;
                            $r_id_disp = isset($event_disp['classroom_id']) ? intval($event_disp['classroom_id']) : null;
                            $t_id_disp = isset($event_disp['timeslot_id']) ? intval($event_disp['timeslot_id']) : null;

                            $course_name_display = $event_disp['course_name'] ?? $c_id_disp;
                            $lecturer_name_display = $event_disp['lecturer_name'] ?? 'ID:'.$l_id_disp;
                            $room_code_display = $event_disp['room_code'] ?? 'ID:'.$r_id_disp;
                            
                            $day_display = 'N/A'; $date_display = 'N/A'; $start_display = 'N/A'; $end_display = 'N/A';
                            if (isset($event_disp['timeslot_info']) && is_string($event_disp['timeslot_info'])) {
                                // Format: "Mon 2024-09-02 (07:00:00-08:40:00)"
                                $ts_parts = explode(' ', $event_disp['timeslot_info'], 3);
                                if (count($ts_parts) === 3) {
                                    $day_display = htmlspecialchars(get_vietnamese_day_of_week($ts_parts[0]));
                                    $date_display = htmlspecialchars(format_date_for_display($ts_parts[1]));
                                    $time_range = str_replace(['(',')'],'',$ts_parts[2]);
                                    $time_halves = explode('-', $time_range);
                                    $start_display = htmlspecialchars(format_time_for_display($time_halves[0] ?? ''));
                                    $end_display = htmlspecialchars(format_time_for_display($time_halves[1] ?? ''));
                                }
                            }
                        ?>
                        <tr>
                            <td><?php echo $stt_disp++; ?></td>
                            <td><?php echo htmlspecialchars($c_id_disp); ?></td>
                            <td><?php echo htmlspecialchars($course_name_display); ?></td>
                            <td><?php echo htmlspecialchars($lecturer_name_display); ?></td>
                            <td><?php echo htmlspecialchars($room_code_display); ?></td>
                            <td><?php echo $day_display; ?></td>
                            <td><?php echo $date_display; ?></td>
                            <td><?php echo $start_display; ?></td>
                            <td><?php echo $end_display; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div></div>
        <?php elseif (isset($_POST['start_scheduling'])): ?>
             <div class="alert alert-warning mt-4">No schedule generated or found matching the criteria. Check the process log for details.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

</main>
</div> 
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" charset="utf8" src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const presetSelect = document.getElementById('optimization_preset');
    const advancedOptionsCard = document.getElementById('custom_parameters_card'); // Sửa ID
    const cpTimeInput = document.getElementById('cp_time_limit_override');
    const gaGensInput = document.getElementById('ga_generations_override');
    const gaPopInput = document.getElementById('ga_population_size_override');
    const semesterSelector = document.getElementById('semester_id_selector');
    const hiddenSemesterIdInput = document.getElementById('hidden_semester_id_for_post');

    const presets = {
        fast: { cp: <?php echo PRESET_FAST_CP_TIME; ?>, ga_gens: <?php echo PRESET_FAST_GA_GENS; ?>, ga_pop: <?php echo PRESET_FAST_GA_POP; ?> },
        balanced: { cp: <?php echo PRESET_BALANCED_CP_TIME; ?>, ga_gens: <?php echo PRESET_BALANCED_GA_GENS; ?>, ga_pop: <?php echo PRESET_BALANCED_GA_POP; ?> },
        quality: { cp: <?php echo PRESET_QUALITY_CP_TIME; ?>, ga_gens: <?php echo PRESET_QUALITY_GA_GENS; ?>, ga_pop: <?php echo PRESET_QUALITY_GA_POP; ?> }
    };

    function updateAdvancedFieldsState(selectedPresetValue) {
        if (selectedPresetValue === 'custom') {
            if(advancedOptionsCard) advancedOptionsCard.classList.remove('d-none');
        } else {
            if(advancedOptionsCard) advancedOptionsCard.classList.add('d-none');
            if (presets[selectedPresetValue]) {
                if(cpTimeInput) cpTimeInput.value = presets[selectedPresetValue].cp;
                if(gaGensInput) gaGensInput.value = presets[selectedPresetValue].ga_gens;
                if(gaPopInput) gaPopInput.value = presets[selectedPresetValue].ga_pop;
            }
        }
    }

    if (presetSelect) {
        presetSelect.addEventListener('change', function() {
            updateAdvancedFieldsState(this.value);
        });
        updateAdvancedFieldsState(presetSelect.value); // Initial state
    }

    if (semesterSelector && hiddenSemesterIdInput) {
        // Đồng bộ giá trị semester_id từ dropdown vào input ẩn của form POST
        semesterSelector.addEventListener('change', function() {
            hiddenSemesterIdInput.value = this.value;
        });
        // Đặt giá trị ban đầu cho input ẩn nếu semester đã được chọn (ví dụ, sau khi POST)
        if (semesterSelector.value) {
             hiddenSemesterIdInput.value = semesterSelector.value;
        }
    }
    
    if (document.getElementById('scheduleResultsTable')) {
        try {
            new DataTable('#scheduleResultsTable', { 
                pageLength: 25, 
                order: [[6, 'asc'], [7, 'asc']],
                language: { search: "Search:", lengthMenu: "Show _MENU_ entries", info: "Showing _START_ to _END_ of _TOTAL_ entries", infoEmpty: "No entries found", infoFiltered: "(filtered from _MAX_ total entries)", paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" } }
            });
        } catch (e) { console.error("DataTable Error: ", e); }
    }
    
    const sidebarToggleMobileBtn = document.getElementById('sidebarToggleMobile');
    const mainSidebar = document.getElementById('mainSidebar');
    if (sidebarToggleMobileBtn && mainSidebar) {
        sidebarToggleMobileBtn.addEventListener('click', function() {
            mainSidebar.classList.toggle('active');
        });
    }
});
</script>
</body>
</html>