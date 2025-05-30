<?php
// htdocs/DSS/admin/scheduler.php

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['admin'], '../login.php');

$page_title = "Generate & Optimize Schedule";

// --- Giá trị mặc định cho form (GIỮ NGUYÊN CÁC GIÁ TRỊ MÀ BẠN ĐÃ TEST RA KẾT QUẢ TỐT) ---
$default_python_executable = 'python3'; 
$default_cp_time_limit = 30; 
$default_ga_pop_size = 50; 
$default_ga_generations = 100;
$default_ga_crossover_rate = 0.8;
$default_ga_mutation_rate = 0.2;
$default_ga_tournament_size = 5;
$default_ga_allow_hc_violations = true; // Giá trị này quan trọng để có penalty thấp như output bạn đã có
$default_priority = 'medium';

// Giữ lại giá trị người dùng đã nhập nếu có POST (dù AJAX không nên reload trang, nhưng đây là phòng hờ)
$form_values = [
    'semester_id' => $_POST['semester_id'] ?? '', // Sẽ được JS cập nhật khi chọn
    'python_executable_path' => $_POST['python_executable_path'] ?? $default_python_executable,
    'cp_time_limit_seconds' => $_POST['cp_time_limit_seconds'] ?? $default_cp_time_limit,
    'ga_population_size' => $_POST['ga_population_size'] ?? $default_ga_pop_size,
    'ga_generations' => $_POST['ga_generations'] ?? $default_ga_generations,
    'ga_crossover_rate' => $_POST['ga_crossover_rate'] ?? $default_ga_crossover_rate,
    'ga_mutation_rate' => $_POST['ga_mutation_rate'] ?? $default_ga_mutation_rate,
    'ga_tournament_size' => $_POST['ga_tournament_size'] ?? $default_ga_tournament_size,
    // Xử lý checkbox: nếu form được submit (dù là AJAX), giá trị sẽ là 'true' (string) hoặc không tồn tại
    // Khi trang tải lần đầu (không phải POST), dùng $default_ga_allow_hc_violations
    'ga_allow_hard_constraint_violations' => isset($_POST['run_scheduler']) ? (isset($_POST['ga_allow_hard_constraint_violations'])) : $default_ga_allow_hc_violations,
    'priority_student_clash' => $_POST['priority_student_clash'] ?? $default_priority,
    'priority_lecturer_load_break' => $_POST['priority_lecturer_load_break'] ?? $default_priority,
    'priority_classroom_util' => $_POST['priority_classroom_util'] ?? $default_priority,
];


$semesters_options_html = generate_select_options($conn, 'Semesters', 'SemesterID', 'SemesterName', $form_values['semester_id'], '', 'StartDate DESC');

require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; // Include layout
?>

<div class="container-fluid">
    <h1 class="mt-4"><?php echo htmlspecialchars($page_title); ?></h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>admin/index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Configure and Run Scheduler</li>
    </ol>

    <div id="schedulerMessages"></div> 

    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-cogs me-1"></i> Scheduler Configuration</div>
        <div class="card-body">
            <form id="schedulerConfigForm">
                <!-- === General Settings === -->
                <h5 class="mb-3 text-primary"><i class="fas fa-sliders-h me-2"></i>General Settings</h5>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="semester_id" class="form-label">Target Semester <span class="text-danger">*</span></label>
                        <select class="form-select" id="semester_id" name="semester_id" required aria-describedby="semesterHelp">
                            <option value="">-- Select Semester --</option>
                            <?php echo $semesters_options_html; ?>
                        </select>
                        <small id="semesterHelp" class="form-text text-muted">Select the academic semester for scheduling.</small>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label for="python_executable_path" class="form-label">Python Interpreter Command/Path</label>
                        <input type="text" class="form-control" id="python_executable_path" name="python_executable_path" value="<?php echo htmlspecialchars($form_values['python_executable_path']); ?>" aria-describedby="pythonPathHelp">
                        <small id="pythonPathHelp" class="form-text text-muted">Usually <code>python</code> or <code>python3</code>. Provide a full path if it's not in the system's PATH environment variable.</small>
                    </div>
                </div>
                <hr class="my-4">

                <!-- === Phase 1: CP-SAT Solver Settings === -->
                <h5 class="mb-3 text-primary"><i class="fas fa-puzzle-piece me-2"></i>Phase 1: Initial Schedule Builder (CP-SAT)</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="cp_time_limit_seconds" class="form-label">Max Time for Initial Solution (seconds)</label>
                        <input type="number" class="form-control" id="cp_time_limit_seconds" name="cp_time_limit_seconds" min="5" step="1" value="<?php echo htmlspecialchars($form_values['cp_time_limit_seconds']); ?>" aria-describedby="cpTimeHelp">
                        <small id="cpTimeHelp" class="form-text text-muted">Sets how long the system can spend finding an initial schedule that meets all strict rules. Increase for very complex semesters.</small>
                    </div>
                </div>
                <hr class="my-4">

                <!-- === Phase 2: Genetic Algorithm (GA) Settings === -->
                <h5 class="mb-3 text-primary"><i class="fas fa-dna me-2"></i>Phase 2: Schedule Optimization (Genetic Algorithm)</h5>
                <p class="text-muted small mb-3">These settings control how the algorithm refines the initial schedule to meet preferences and improve quality.</p>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="ga_population_size" class="form-label">Number of Schedule Variations</label>
                        <input type="number" class="form-control" id="ga_population_size" name="ga_population_size" min="10" step="10" value="<?php echo htmlspecialchars($form_values['ga_population_size']); ?>" aria-describedby="gaPopHelp">
                        <small id="gaPopHelp" class="form-text text-muted">How many different schedule versions are kept and evolved in each cycle. Larger values allow for more diverse exploration but slow down the process.</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="ga_generations" class="form-label">Optimization Cycles</label>
                        <input type="number" class="form-control" id="ga_generations" name="ga_generations" min="10" step="10" value="<?php echo htmlspecialchars($form_values['ga_generations']); ?>" aria-describedby="gaGenHelp">
                        <small id="gaGenHelp" class="form-text text-muted">The number of times the algorithm tries to improve the schedules. More cycles can lead to better quality but increase runtime.</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="ga_tournament_size" class="form-label">Selection Strength</label>
                        <input type="number" class="form-control" id="ga_tournament_size" name="ga_tournament_size" min="2" step="1" value="<?php echo htmlspecialchars($form_values['ga_tournament_size']); ?>" aria-describedby="gaTourHelp">
                        <small id="gaTourHelp" class="form-text text-muted">In each cycle, a small group of schedules "compete". This sets the group size. Higher values mean a stronger preference for picking already good schedules.</small>
                    </div>
                </div>
                <div class="row">
                     <div class="col-md-6 mb-3">
                        <label for="ga_crossover_rate" class="form-label">Schedule Combination Rate (0.1 - 1.0)</label>
                        <input type="number" class="form-control" id="ga_crossover_rate" name="ga_crossover_rate" min="0.1" max="1.0" step="0.05" value="<?php echo htmlspecialchars($form_values['ga_crossover_rate']); ?>" aria-describedby="gaCrossHelp">
                        <small id="gaCrossHelp" class="form-text text-muted">The chance that two good schedules will be combined to create new ones. Higher values encourage mixing of schedule parts.</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="ga_mutation_rate" class="form-label">Random Change Rate (0.01 - 1.0)</label>
                        <input type="number" class="form-control" id="ga_mutation_rate" name="ga_mutation_rate" min="0.01" max="1.0" step="0.01" value="<?php echo htmlspecialchars($form_values['ga_mutation_rate']); ?>" aria-describedby="gaMutHelp">
                        <small id="gaMutHelp" class="form-text text-muted">The chance of making small, random adjustments to a schedule. This helps introduce new variations and avoid getting stuck.</small>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12 mb-3 form-check">
                        <input class="form-check-input" type="checkbox" value="true" id="ga_allow_hard_constraint_violations" name="ga_allow_hard_constraint_violations" <?php echo $form_values['ga_allow_hard_constraint_violations'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="ga_allow_hard_constraint_violations" aria-describedby="gaHcHelp">
                            Advanced: Allow Optimizer to Temporarily Break Strict Rules
                        </label>
                        <small id="gaHcHelp" class="form-text text-muted d-block">For testing/experimental purposes. If checked, the optimizer might explore schedules that briefly violate essential rules (e.g., lecturer double-booked). This can sometimes uncover unique solutions for difficult cases but is generally NOT recommended for standard use, as the final schedule might require manual fixing if it still contains such violations. This setting directly impacts the "Hard Constraints Violated" metric and overall penalty.</small>
                    </div>
                </div>

                <hr class="my-4">
                <h5 class="mb-3 text-primary"><i class="fas fa-bullseye me-2"></i>Optimization Goal Priorities</h5>
                 <p class="text-muted small mb-3">Set the importance for different scheduling preferences. The algorithm will try harder to satisfy goals with higher priority, which influences the final "Penalty Score".</p>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="priority_student_clash" class="form-label">Minimize Student Timetable Conflicts</label>
                        <select class="form-select" id="priority_student_clash" name="priority_student_clash" aria-describedby="prioStudentHelp">
                            <option value="low" <?php selected_if_match($form_values['priority_student_clash'], 'low'); ?>>Low</option>
                            <option value="medium" <?php selected_if_match($form_values['priority_student_clash'], 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected_if_match($form_values['priority_student_clash'], 'high'); ?>>High</option>
                            <option value="very_high" <?php selected_if_match($form_values['priority_student_clash'], 'very_high'); ?>>Very High</option>
                        </select>
                        <small id="prioStudentHelp" class="form-text text-muted">How strongly to avoid students having overlapping classes. "Very High" makes this a top priority.</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="priority_lecturer_load_break" class="form-label">Optimize Lecturer Workload & Breaks</label>
                        <select class="form-select" id="priority_lecturer_load_break" name="priority_lecturer_load_break" aria-describedby="prioLecturerHelp">
                           <option value="low" <?php selected_if_match($form_values['priority_lecturer_load_break'], 'low'); ?>>Low</option>
                            <option value="medium" <?php selected_if_match($form_values['priority_lecturer_load_break'], 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected_if_match($form_values['priority_lecturer_load_break'], 'high'); ?>>High</option>
                            <option value="very_high" <?php selected_if_match($form_values['priority_lecturer_load_break'], 'very_high'); ?>>Very High</option>
                        </select>
                         <small id="prioLecturerHelp" class="form-text text-muted">Emphasis on fair teaching loads (not too many or too few classes per lecturer) and ensuring adequate breaks.</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="priority_classroom_util" class="form-label">Optimize Classroom Usage</label>
                        <select class="form-select" id="priority_classroom_util" name="priority_classroom_util" aria-describedby="prioRoomHelp">
                             <option value="low" <?php selected_if_match($form_values['priority_classroom_util'], 'low'); ?>>Low</option>
                            <option value="medium" <?php selected_if_match($form_values['priority_classroom_util'], 'medium'); ?>>Medium</option>
                            <option value="high" <?php selected_if_match($form_values['priority_classroom_util'], 'high'); ?>>High</option>
                            <option value="very_high" <?php selected_if_match($form_values['priority_classroom_util'], 'very_high'); ?>>Very High</option>
                        </select>
                        <small id="prioRoomHelp" class="form-text text-muted">Focus on using classroom space well (e.g., avoiding assigning small classes to very large rooms).</small>
                    </div>
                </div>
                <button type="submit" id="runSchedulerBtn" class="btn btn-primary btn-lg mt-4">
                    <i class="fas fa-cogs me-2"></i>Generate & Optimize Schedule
                </button>
                 <button type="button" id="cancelSchedulerBtn" class="btn btn-danger btn-lg mt-4" style="display:none;">
                    <i class="fas fa-stop-circle me-2"></i>Cancel Generation
                </button>
            </form>
        </div>
    </div>

    <!-- Progress Section -->
    <div id="progressSection" class="card mb-4" style="display:none;">
        <div class="card-header"><i class="fas fa-spinner fa-spin me-1"></i> Scheduler Progress</div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 25px;">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
            <h6>Process Log:</h6>
            <pre id="processLogOutput" class="log-output bg-light p-3 rounded border" style="max-height: 400px; overflow-y: auto; font-family: 'Courier New', Courier, monospace;"></pre>
        </div>
    </div>

    <!-- Results Section -->
    <div id="resultsSection" class="card mb-4" style="display:none;">
        <div class="card-header">
            <i class="fas fa-calendar-alt me-1"></i> Scheduling Results
            <div class="btn-group float-end" role="group" aria-label="View Toggles">
                <input type="radio" class="btn-check" name="viewType" id="viewTableBtn" value="table" autocomplete="off" checked>
                <label class="btn btn-outline-primary btn-sm" for="viewTableBtn"><i class="fas fa-table"></i> Table</label>

                <input type="radio" class="btn-check" name="viewType" id="viewWeeklyBtn" value="weekly" autocomplete="off">
                <label class="btn btn-outline-primary btn-sm" for="viewWeeklyBtn"><i class="fas fa-calendar-week"></i> Weekly Grid</label>

                <input type="radio" class="btn-check" name="viewType" id="viewDailyBtn" value="daily" autocomplete="off">
                <label class="btn btn-outline-primary btn-sm" for="viewDailyBtn"><i class="fas fa-calendar-day"></i> Daily List</label>
            </div>
        </div>
        <div class="card-body">
            <div id="resultMetrics" class="mb-4"></div>
            
            <div id="scheduleTableViewContainer" class="schedule-view-content">
                <h5 class="mt-3 schedule-view-title">Generated Schedule (Table Format):</h5>
                <div id="scheduleTableContainer"><p class='text-muted'>Schedule will be displayed here.</p></div>
            </div>
            <div id="scheduleWeeklyViewContainer" class="schedule-view-content" style="display:none;">
                <h5 class="mt-3 schedule-view-title">Generated Schedule (Weekly Grid View):</h5>
                <div id="scheduleWeeklyVisualContainer"><p class='text-muted'>Weekly schedule will be displayed here.</p></div>
            </div>
            <div id="scheduleDailyViewContainer" class="schedule-view-content" style="display:none;">
                <h5 class="mt-3 schedule-view-title">Generated Schedule (Daily List View):</h5>
                <div id="scheduleDailyVisualContainer"><p class='text-muted'>Daily schedule will be displayed here.</p></div>
            </div>
        </div>
    </div>

</div> 
<!-- Kết thúc container-fluid -->

<?php
// Các thẻ <style> và <script> cụ thể cho trang này sẽ được đặt ở đây.
// File layout admin_sidebar_menu.php chịu trách nhiệm đóng </body> và </html>.
// Nó cũng nên include các JS chung như Bootstrap.
?>

<style>
    .log-output { 
        white-space: pre-wrap; 
        word-wrap: break-word; 
        font-size: 0.85em; 
        border: 1px solid #ccc; 
        background-color: #f8f9fa;
    }
    .schedule-table th, .schedule-table td { 
        vertical-align: middle; 
        text-align: center; 
        font-size: 0.85rem;
        padding: 0.4rem 0.2rem;
    }
    .schedule-table th { 
        background-color: #343a40; 
        color: white; 
    }
    .schedule-view-title { 
        border-bottom: 1px solid #eee; 
        padding-bottom: 0.5rem; 
        margin-bottom: 1rem; 
    }
    
    .schedule-visual-weekly .table { 
        table-layout: fixed; 
        min-width: 800px; 
    } 
    .schedule-visual-weekly th, .schedule-visual-weekly .schedule-time-cell {
        font-size: 0.8rem;
        padding: 0.5rem 0.2rem;
    }
    .schedule-visual-weekly .schedule-slot {
        height: auto; 
        min-height: 80px; 
        vertical-align: top;
        padding: 3px;
        font-size: 0.7rem; 
        position: relative; 
        border: 1px solid #e9ecef; 
    }
    .schedule-visual-weekly .schedule-event {
        font-size: 0.65rem; 
        line-height: 1.1;
        overflow: hidden;
        cursor: default;
        border: 1px solid #bee5eb; 
        background-color: #e0f7fa; 
        color: #0c5460; 
        border-radius: 3px;
        margin-bottom: 2px !important; 
        padding: 2px 3px;
    }
    .schedule-visual-weekly .schedule-event strong.event-course { 
        display: block; 
        font-weight: bold;
        white-space: normal; 
        margin-bottom: 1px;
    }
    .schedule-visual-weekly .schedule-event small {
        display: block;
        white-space: normal; 
        line-height: 1.0;
    }
     .schedule-visual-weekly .schedule-event .event-lecturer,
     .schedule_visual-weekly .schedule-event .event-room {
        font-size: 0.6rem; 
        color: #545b62;
     }

    .daily-schedule-card .list-group-item h6 { font-size: 0.9rem; }
    .daily-schedule-card .list-group-item p small { font-size: 0.8rem; }
    .form-label { font-weight: 500; }
    .form-text.text-muted { font-size: 0.8rem; margin-top: 0.15rem; }
    h5.mb-3.text-primary {
        font-size: 1.15rem;
        color: var(--primary-blue) !important; /* Ensure primary color is used */
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--primary-blue);
        margin-top: 1.75rem; /* More spacing for section titles */
    }
     h5.mb-3.text-primary:first-of-type { /* For the very first H5 */
        margin-top: 0.5rem; /* Less top margin for the first one */
    }

    /* Style for active view button group */
    .btn-group > .btn-check:checked + .btn-outline-primary,
    .btn-group > .btn-check:active + .btn-outline-primary,
    .btn-group > .btn-outline-primary:active,
    .btn-group > .btn-outline-primary.active,
    .btn-group > .btn-outline-primary.dropdown-toggle.show {
        color: #fff;
        background-color: var(--primary-blue, #007bff); 
        border-color: var(--primary-blue, #007bff);
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- TOÀN BỘ JAVASCRIPT GIỮ NGUYÊN NHƯ PHIÊN BẢN TRƯỚC ---
    // (Bao gồm các const khai báo element, các hàm displayMessage, updateProgress, 
    // pollProgress, fetchResults, renderMetrics, renderTableView, renderWeeklyView, 
    // renderDailyView, stopPollingAndReset, và event listener cho configForm)

    // Chỉ cần đảm bảo các ID trong HTML khớp với các getElementById trong JS.
    // Ví dụ: cp_time_limit_seconds, ga_population_size, v.v...
    // Và khi JavaScript tạo configPayload, các key phải khớp với thuộc tính `name` của input.

    // ----- BẮT ĐẦU PHẦN JAVASCRIPT GIỮ NGUYÊN -----
    const configForm = document.getElementById('schedulerConfigForm');
    const runBtn = document.getElementById('runSchedulerBtn');
    const progressSection = document.getElementById('progressSection');
    const progressBar = document.getElementById('progressBar');
    const processLogOutput = document.getElementById('processLogOutput');
    const resultsSection = document.getElementById('resultsSection');
    const resultMetrics = document.getElementById('resultMetrics');
    const schedulerMessages = document.getElementById('schedulerMessages');

    const viewTableBtnRadio = document.getElementById('viewTableBtn');
    const viewWeeklyBtnRadio = document.getElementById('viewWeeklyBtn');
    const viewDailyBtnRadio = document.getElementById('viewDailyBtn');
    
    const tableViewContainer = document.getElementById('scheduleTableViewContainer');
    const weeklyViewContainer = document.getElementById('scheduleWeeklyViewContainer');
    const dailyViewContainer = document.getElementById('scheduleDailyViewContainer');
    
    const scheduleTableContainer = document.getElementById('scheduleTableContainer');
    const scheduleWeeklyVisualContainer = document.getElementById('scheduleWeeklyVisualContainer');
    const scheduleDailyVisualContainer = document.getElementById('scheduleDailyVisualContainer');

    let progressInterval = null;
    let currentRunOutputFilename = null;

    function displayMessage(message, type = 'info') {
        while (schedulerMessages.firstChild) {
            schedulerMessages.removeChild(schedulerMessages.firstChild);
        }
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `${message} <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        schedulerMessages.appendChild(alertDiv);
        if (type === 'success' || type === 'info') {
            setTimeout(() => {
                const currentAlert = schedulerMessages.querySelector(`.alert-${type}`);
                if (currentAlert) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(currentAlert);
                    if (bsAlert) bsAlert.close();
                }
            }, 7000);
        }
    }

    function updateProgress(percent, logText = null) {
        const p = Math.max(0, Math.min(100, percent));
        progressBar.style.width = p + '%';
        progressBar.innerText = p + '%';
        progressBar.setAttribute('aria-valuenow', p);
        if (logText !== null && typeof logText === 'string') {
            processLogOutput.innerHTML = logText.replace(/\n/g, '<br>');
            processLogOutput.scrollTop = processLogOutput.scrollHeight;
        }
    }
    
    function setActiveView(viewName) {
        tableViewContainer.style.display = viewName === 'table' ? 'block' : 'none';
        weeklyViewContainer.style.display = viewName === 'weekly' ? 'block' : 'none';
        dailyViewContainer.style.display = viewName === 'daily' ? 'block' : 'none';
    }

    document.querySelectorAll('input[name="viewType"]').forEach(radio => {
        radio.addEventListener('change', function() {
            setActiveView(this.value);
        });
    });

    function pollProgress() {
        fetch('get_scheduler_progress.php')
            .then(response => {
                if (!response.ok) {
                     return response.text().then(text => {
                        throw new Error(`Polling HTTP error! Status: ${response.status}. Response: ${text.substring(0,300)}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log("Poll progress data:", data);
                if (!data || typeof data.status === 'undefined') {
                    displayMessage('Polling Error: Invalid response from progress checker.', 'danger');
                    stopPollingAndReset(); return;
                }

                if (data.status === 'error_auth' || data.status === 'error_file_ref' || data.status === 'error_progress_script') {
                    displayMessage('Polling Error: ' + (data.message || 'Unknown progress error.'), 'danger');
                    stopPollingAndReset(); return;
                }

                updateProgress(data.progress_percent || 0, data.log_content || processLogOutput.textContent);

                if (data.status === 'completed_success') {
                    displayMessage(data.message || 'Scheduler finished successfully. Fetching final results...', 'success');
                    stopPollingAndReset(false);
                    if (data.output_file) fetchResults(data.output_file);
                    else displayMessage('Completed but output file reference is missing!', 'warning');
                } else if (data.status === 'completed_error' || data.status === 'error_php_fatal' || data.status === 'error_php_setup') { // Thêm error_php_setup
                    displayMessage(data.message || 'Scheduler process finished with errors.', 'danger');
                    stopPollingAndReset(false);
                    resultsSection.style.display = 'block'; // Hiển thị section để có thể thấy log / metrics lỗi
                    if (data.output_file) fetchResults(data.output_file);
                    else { // Nếu không có output file, ít nhất hiển thị log
                        resultMetrics.innerHTML = "<p class='text-danger'>Could not retrieve detailed metrics due to an error.</p>";
                        scheduleTableContainer.innerHTML = "";
                        scheduleWeeklyVisualContainer.innerHTML = "";
                        scheduleDailyVisualContainer.innerHTML = "";
                    }
                } else if (data.status !== 'running_background' && data.status !== 'initiating_background' && data.status !== 'running_php_pre_python' && data.status !== 'python_running') {
                    console.warn("Polling stopped due to non-running status: ", data.status);
                    stopPollingAndReset(false);
                }
            })
            .catch(error => {
                console.error('Polling fetch error:', error);
                displayMessage('Error while polling for progress: ' + error.message, 'danger');
                stopPollingAndReset();
            });
    }
    
    function renderMetrics(metrics) {
        let html = '<h4>Schedule Metrics</h4>';
        if (metrics.overall_performance) {
            html += `<h5>Overall:</h5><ul>
                        <li>Total Execution Time: ${metrics.overall_performance.total_execution_time_seconds !== null ? metrics.overall_performance.total_execution_time_seconds + 's' : 'N/A'}</li>
                        <li>Events in Final Schedule: ${metrics.overall_performance.num_events_in_final_schedule || 0}</li>
                     </ul>`;
        }
        if (metrics.cp_solver_summary) {
            html += `<h5>CP Solver Summary:</h5><ul>
                        <li>Status: ${metrics.cp_solver_summary.solver_status || 'N/A'}</li>
                        <li>Time: ${metrics.cp_solver_summary.solve_time_seconds !==null ? metrics.cp_solver_summary.solve_time_seconds + 's' : 'N/A'} (Build: ${metrics.cp_solver_summary.model_build_time_seconds !==null ? metrics.cp_solver_summary.model_build_time_seconds + 's' : 'N/A'})</li>
                        <li>Items Scheduled: ${metrics.cp_solver_summary.num_items_successfully_scheduled_by_cp || 0} / ${metrics.cp_solver_summary.num_items_targeted_for_cp_solver || 0}</li>
                     </ul>`;
        }
         if (metrics.ga_solver_summary) {
            html += `<h5>GA Solver Summary:</h5><ul>
                        <li>Final Penalty Score: ${metrics.ga_solver_summary.final_penalty_score !== null ? parseFloat(metrics.ga_solver_summary.final_penalty_score).toFixed(2) : 'N/A'}</li>
                        ${metrics.ga_solver_summary.ga_hard_constraints_violated_in_final !== undefined ? `<li>Hard Constraints Violated: ${metrics.ga_solver_summary.ga_hard_constraints_violated_in_final ? '<span class="text-danger fw-bold">Yes</span>' : '<span class="text-success">No</span>'}</li>` : '' }
                     </ul>`;
            if (metrics.ga_solver_summary.detailed_soft_constraint_metrics && Object.keys(metrics.ga_solver_summary.detailed_soft_constraint_metrics).length > 0) {
                html += '<h6>GA Soft Constraint Details:</h6><div class="table-responsive"><table class="table table-sm table-bordered table-striped" style="max-width: 600px;"><thead><tr><th>Constraint</th><th>Count</th><th>Penalty Contribution</th></tr></thead><tbody>';
                for (const sc_name in metrics.ga_solver_summary.detailed_soft_constraint_metrics) {
                    const detail = metrics.ga_solver_summary.detailed_soft_constraint_metrics[sc_name];
                    html += `<tr><td>${sc_name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</td><td>${detail.count || 0}</td><td>${detail.penalty_contribution !== null ? parseFloat(detail.penalty_contribution).toFixed(2) : 'N/A'}</td></tr>`;
                }
                html += '</tbody></table></div>';
            } else if (metrics.ga_solver_summary.final_penalty_score === 0 || metrics.ga_solver_summary.final_penalty_score === null ) {
                 html += '<p class="text-success">No soft constraint violations reported by GA.</p>';
            }
        }
        resultMetrics.innerHTML = html;
    }

    function renderTableView(scheduleData) {
         if (!scheduleData || scheduleData.length === 0) {
            scheduleTableContainer.innerHTML = "<p class='text-muted'>No schedule data to display or an empty schedule was generated.</p>";
            return;
        }
        fetch('render_schedule_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ schedule_events: scheduleData, view_type: 'table_html' })
        })
        .then(response => response.text()) 
        .then(htmlTable => { scheduleTableContainer.innerHTML = htmlTable; })
        .catch(error => { scheduleTableContainer.innerHTML = "<p class='text-danger'>Error rendering schedule table.</p>"; console.error('Error rendering table view:', error);});
    }

    function renderWeeklyView(scheduleData) {
         if (!scheduleData || scheduleData.length === 0) {
            scheduleWeeklyVisualContainer.innerHTML = "<p class='text-muted'>No data for weekly view.</p>"; return;
        }
        fetch('render_schedule_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ schedule_events: scheduleData, view_type: 'weekly_visual_html' })
        })
        .then(response => response.text()) 
        .then(htmlVisual => { scheduleWeeklyVisualContainer.innerHTML = htmlVisual; })
        .catch(error => { scheduleWeeklyVisualContainer.innerHTML = "<p class='text-danger'>Error rendering weekly visual schedule.</p>"; console.error('Error rendering weekly view:', error);});
    }

    function renderDailyView(scheduleData) {
         if (!scheduleData || scheduleData.length === 0) {
            scheduleDailyVisualContainer.innerHTML = "<p class='text-muted'>No data for daily view.</p>"; return;
        }
         fetch('render_schedule_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ schedule_events: scheduleData, view_type: 'daily_visual_html' })
        })
        .then(response => response.text()) 
        .then(htmlVisual => { scheduleDailyVisualContainer.innerHTML = htmlVisual; })
        .catch(error => { scheduleDailyVisualContainer.innerHTML = "<p class='text-danger'>Error rendering daily visual schedule.</p>"; console.error('Error rendering daily view:', error);});
    }
    
    function fetchResults(outputFilename) {
        resultsSection.style.display = 'block';
        setActiveView('table'); 
        if(viewTableBtnRadio) viewTableBtnRadio.checked = true;

        scheduleTableContainer.innerHTML = "<p class='text-info p-2'><i class='fas fa-spinner fa-spin'></i> Loading table view...</p>";
        scheduleWeeklyVisualContainer.innerHTML = "<p class='text-info p-2'><i class='fas fa-spinner fa-spin'></i> Loading weekly view...</p>";
        scheduleDailyVisualContainer.innerHTML = "<p class='text-info p-2'><i class='fas fa-spinner fa-spin'></i> Loading daily view...</p>";
        resultMetrics.innerHTML = "";

        fetch(`get_scheduler_result.php?file=${encodeURIComponent(outputFilename)}`)
            .then(response => {
                if (!response.ok) return response.text().then(text => {throw new Error(`Fetch results HTTP error! Status: ${response.status}. Response: ${text.substring(0,300)}`);});
                return response.json();
            })
            .then(res => {
                console.log("Fetched results data:", res);
                if (res.status === 'success' && res.data) {
                    const py_status = res.data.status || "unknown_python_status";
                    const py_message = res.data.message || "No message from Python execution.";
                    displayMessage(`Python process finished: ${py_message} (Status: ${py_status})`, py_status.includes('success') ? 'success' : 'warning');

                    const schedule = res.data.final_schedule || [];
                    const metrics = res.data.metrics || {};
                    renderMetrics(metrics);
                    renderTableView(schedule); 
                    renderWeeklyView(schedule);
                    renderDailyView(schedule);
                } else {
                    displayMessage('Error processing results: ' + (res.message || 'Unknown error from get_scheduler_result.php'), 'danger');
                    scheduleTableContainer.innerHTML = `<p class='text-danger p-2'>Could not load schedule data. ${res.message || ''}</p>`;
                    scheduleWeeklyVisualContainer.innerHTML = ''; scheduleDailyVisualContainer.innerHTML = '';
                }
            })
            .catch(error => {
                console.error('Fetch results error:', error);
                displayMessage('Error fetching schedule results: ' + error.message, 'danger');
                scheduleTableContainer.innerHTML = `<p class='text-danger p-2'>Could not load schedule due to a network or parsing error.</p>`;
                scheduleWeeklyVisualContainer.innerHTML = ''; scheduleDailyVisualContainer.innerHTML = '';
            });
    }

    function stopPollingAndReset(resetUI = true) {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        runBtn.disabled = false;
    }

    configForm.addEventListener('submit', function (e) {
        e.preventDefault();
        schedulerMessages.innerHTML = ''; 
        resultsSection.style.display = 'none'; 
        setActiveView('table');
        if(viewTableBtnRadio) viewTableBtnRadio.checked = true;
        
        resultMetrics.innerHTML = '';
        scheduleTableContainer.innerHTML = "<p class='text-muted'>Schedule will be displayed here.</p>";
        scheduleWeeklyVisualContainer.innerHTML = "<p class='text-muted'>Weekly schedule will be displayed here.</p>";
        scheduleDailyVisualContainer.innerHTML = "<p class='text-muted'>Daily schedule will be displayed here.</p>";
        processLogOutput.textContent = ''; 

        const formData = new FormData(configForm);
        const configPayload = {};
        for (let [key, value] of formData.entries()) {
            const element = document.getElementsByName(key)[0]; // Lấy element để kiểm tra type
            const type = element ? element.type : 'text';

            if (key === 'ga_allow_hard_constraint_violations') { 
                configPayload[key] = element.checked; // Giá trị boolean từ checkbox
            } else if (type === 'number') {
                 if (value.includes('.')) configPayload[key] = parseFloat(value);
                 else configPayload[key] = parseInt(value);
            } else {
                configPayload[key] = value;
            }
        }
        // Đảm bảo checkbox nếu không check thì gửi false
        if (!formData.has('ga_allow_hard_constraint_violations')) {
             configPayload['ga_allow_hard_constraint_violations'] = false;
        }
        
        if (!configPayload.semester_id) { 
            displayMessage('Please select a target semester.', 'warning');
            return; 
        }
        configPayload.semester_id_to_load = configPayload.semester_id;
        delete configPayload.semester_id;

        runBtn.disabled = true;
        progressSection.style.display = 'block';
        updateProgress(1, 'Initiating scheduler process via AJAX...'); 

        fetch('run_scheduler_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(configPayload)
        })
        .then(response => {
            console.log("Raw response from run_scheduler_ajax.php (initiating):", response);
            if (!response.ok) {
                 return response.text().then(text => {throw new Error(`Server error ${response.status} (initiating): ${text.substring(0,300)}`);});
            }
            return response.json();
        })
        .then(data => {
            console.log("Parsed JSON from run_scheduler_ajax.php (initiating):", data);
            if (data.status === 'background_initiated') { // PHP đã khởi chạy Python nền
                displayMessage(data.message || 'Background process started. Monitoring...', 'info');
                if (progressInterval) clearInterval(progressInterval);
                progressInterval = setInterval(pollProgress, 3000); 
            } 
            // Xử lý trường hợp Python chạy quá nhanh và PHP đã đợi xong
            else if (data.status === 'success' && data.output_file) { 
                 displayMessage(data.message || 'Process completed very quickly.', 'success');
                 updateProgress(100, (data.stdout || '') + (data.stderr || '\nProcess finished.'));
                 if(data.stdout || data.stderr) processLogOutput.innerHTML = ((data.stdout || '') + (data.stderr || '')).replace(/\n/g, '<br>');
                 stopPollingAndReset(false);
                 fetchResults(data.output_file);
            }
            else if (data.status && (data.status.startsWith('error') || data.status.startsWith('completed_error'))) { 
                displayMessage('Error initiating/running scheduler: ' + data.message, 'danger');
                updateProgress(100, (data.stdout || '') + (data.stderr || '\nProcess finished with error.'));
                 if(data.stdout || data.stderr) processLogOutput.innerHTML = ((data.stdout || '') + (data.stderr || '')).replace(/\n/g, '<br>');
                stopPollingAndReset(false);
                if (data.output_file && data.final_data_summary) {
                     fetchResults(data.output_file);
                }
            }
            else { // Trạng thái không mong muốn
                 displayMessage('Unexpected response when initiating scheduler: ' + (data.message || JSON.stringify(data).substring(0,100)), 'warning');
                 stopPollingAndReset();
            }
        })
        .catch(error => {
            console.error('Submit form fetch error:', error);
            displayMessage('Failed to initiate scheduler process: ' + error.message, 'danger');
            stopPollingAndReset();
        });
    });

    // ----- KẾT THÚC PHẦN JAVASCRIPT GIỮ NGUYÊN -----
});
</script>