<?php
// htdocs/DSS/student/course_registration.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db_connect.php'; // $conn

require_role(['student'], '../login.php');

$page_title = "Course Registration & Schedule Builder";

$current_student_id_page = get_current_user_linked_entity_id();
$student_program_info = null; // Will be fetched

$selected_semester_id_page = null;
$semesters_list_page = [];
$major_categories_list = []; // For the new dropdown

if (isset($conn) && $conn instanceof mysqli && $current_student_id_page) {
    // Get student's program (remains the same)
    $stmt_program = $conn->prepare("SELECT Program FROM Students WHERE StudentID = ?");
    if ($stmt_program) {
        $stmt_program->bind_param("s", $current_student_id_page);
        if ($stmt_program->execute()) {
            $res_program = $stmt_program->get_result();
            if ($prog_row = $res_program->fetch_assoc()) {
                $student_program_info = $prog_row['Program'];
            }
            $res_program->close(); // Close result set
        } else { error_log("CR_Page: Student program query failed: " . $stmt_program->error); }
        $stmt_program->close();
    } else { error_log("CR_Page: Student program prepare failed: " . $conn->error); }

    // Get list of semesters for dropdown (current or future)
    $sql_semesters = "SELECT SemesterID, SemesterName, StartDate, EndDate
                      FROM Semesters
                      WHERE EndDate >= CURDATE() OR StartDate >= CURDATE()
                      ORDER BY StartDate ASC"; // Changed to ASC for chronological order, typically better for selection
    $res_semesters_page = $conn->query($sql_semesters);
    if ($res_semesters_page) {
        while ($row_sem_page = $res_semesters_page->fetch_assoc()) {
            $semesters_list_page[] = $row_sem_page;
        }
        $res_semesters_page->free();
    } else { error_log("CR_Page: Failed to fetch semesters: " . $conn->error); }

    // Determine selected semester (remains mostly the same)
    if (isset($_GET['semester_id']) && filter_var($_GET['semester_id'], FILTER_VALIDATE_INT)) {
        // Validate if the GET semester_id is actually in our list of available semesters
        $temp_selected_id = (int)$_GET['semester_id'];
        foreach($semesters_list_page as $sem_item_verify) {
            if ($sem_item_verify['SemesterID'] == $temp_selected_id) {
                $selected_semester_id_page = $temp_selected_id;
                break;
            }
        }
    }
    // Default semester selection logic
    if (!$selected_semester_id_page && !empty($semesters_list_page)) {
        $today_date_cr = date('Y-m-d');
        foreach ($semesters_list_page as $sem_cr) { // Check for currently active semester first
            if ($today_date_cr >= $sem_cr['StartDate'] && $today_date_cr <= $sem_cr['EndDate']) {
                $selected_semester_id_page = (int)$sem_cr['SemesterID'];
                break;
            }
        }
        if (!$selected_semester_id_page && isset($semesters_list_page[0]['SemesterID'])) { // Fallback to the first (earliest future/current)
            $selected_semester_id_page = (int)$semesters_list_page[0]['SemesterID'];
        }
    }

    $sql_majors = "SELECT DISTINCT MajorCategory FROM Courses WHERE MajorCategory IS NOT NULL AND MajorCategory != '' ORDER BY MajorCategory ASC";
    $res_majors = $conn->query($sql_majors);
    if ($res_majors) {
        while ($row_major = $res_majors->fetch_assoc()) {
            $major_categories_list[] = $row_major['MajorCategory'];
        }
        $res_majors->free();
    } else {
        error_log("CR_Page: Failed to fetch major categories: " . $conn->error);
    }
}

require_once __DIR__ . '/../includes/admin_sidebar_menu.php'; // Or your student_layout.php
?>
<!-- START: Page-specific content for student/course_registration.php -->
<!-- START: Page-specific content for student/course_registration.php -->
<div class="container-fluid">
    <div id="courseRegistrationMessagesCR" class="mt-2"></div> <!-- Moved here for better visibility -->

    <form id="courseRegistrationFormCR">
        <div class="row">
            <!-- Column for Semester, Major and Course Selection -->
            <div class="col-lg-7 col-md-12 mb-4">
                <div class="card shadow-sm course-selection-card">
                    <div class="card-header py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-calendar-alt me-2"></i>Step 1: Select Semester, Major & Courses</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="semester_id_cr_select" class="form-label">Semester:</label>
                                <select name="semester_id" id="semester_id_cr_select" class="form-select" required>
                                    <option value="">-- Select Semester --</option>
                                    <?php if (empty($semesters_list_page)): ?>
                                        <option value="" disabled>No semesters available</option>
                                    <?php else: ?>
                                        <?php foreach ($semesters_list_page as $semester_item_cr_opt): ?>
                                            <option value="<?php echo $semester_item_cr_opt['SemesterID']; ?>" <?php if ($semester_item_cr_opt['SemesterID'] == $selected_semester_id_page) echo 'selected'; ?>>
                                                <?php echo htmlspecialchars($semester_item_cr_opt['SemesterName']); ?>
                                                (<?php echo htmlspecialchars(format_date_for_display($semester_item_cr_opt['StartDate'], 'M Y')); ?> - <?php echo htmlspecialchars(format_date_for_display($semester_item_cr_opt['EndDate'], 'M Y')); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="major_category_cr_select" class="form-label">Filter by Major Category:</label>
                                <select id="major_category_cr_select" name="major_category" class="form-select">
                                    <option value="all">-- All Major Categories --</option>
                                    <?php if (!empty($major_categories_list)): ?>
                                        <?php foreach ($major_categories_list as $major): ?>
                                            <option value="<?php echo htmlspecialchars($major); ?>">
                                                <?php echo htmlspecialchars($major); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                         <option value="" disabled>No categories found</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div id="availableCoursesContainerCR" class="mt-3">
                            <label class="form-label">Available Courses for Registration:</label>
                            <div id="courseListCR" class="p-2 border rounded bg-light" style="max-height: 400px; overflow-y: auto;">
                                <p class="text-center text-muted p-3" id="courseListPlaceholderMsgCR">
                                    <?php if (!$selected_semester_id_page): ?>
                                        Please select a semester to see available courses.
                                    <?php else: ?>
                                        <i class="fas fa-spinner fa-spin me-2"></i> Loading courses...
                                    <?php endif; ?>
                                </p>
                                <!-- Course items will be loaded here by JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Column for Preferences and Selected Courses (Giữ nguyên như Cậu đã gửi) -->
            <div class="col-lg-5 col-md-12 mb-4">
                <!-- Preferences Card -->
                <div class="card shadow-sm preferences-card mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-sliders-h me-2"></i>Step 2: Set Preferences (Optional)</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">These preferences will help tailor your schedule.</p>
                        <div class="mb-3">
                            <label for="pref_time_of_day_cr" class="form-label form-label-sm">Preferred Time of Day:</label>
                            <select id="pref_time_of_day_cr" name="preferences[time_of_day]" class="form-select form-select-sm">
                                <option value="">No Preference</option>
                                <option value="morning">Mornings Only</option>
                                <option value="afternoon">Afternoons Only</option>
                                <option value="no_early_morning">Avoid Early Mornings (e.g., before 9 AM)</option>
                                <option value="no_late_evening">Avoid Late Evenings (e.g., after 5 PM)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="pref_max_consecutive_classes_cr" class="form-label form-label-sm">Max Consecutive Classes:</label>
                            <select id="pref_max_consecutive_classes_cr" name="preferences[max_consecutive_classes]" class="form-select form-select-sm">
                                <option value="">No Limit</option>
                                <option value="2">2 Classes</option>
                                <option value="3">3 Classes</option>
                                <option value="4">4 Classes</option>
                            </select>
                        </div>
                        <div class="mb-2 form-check">
                            <input type="checkbox" class="form-check-input" id="pref_compact_days_cr" name="preferences[compact_days]" value="1">
                            <label class="form-check-label small" for="pref_compact_days_cr">Prefer fewer study days per week</label>
                        </div>
                         <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="pref_friday_off_cr" name="preferences[friday_off]" value="1">
                            <label class="form-check-label small" for="pref_friday_off_cr">Try to keep Fridays free</label>
                        </div>
                    </div>
                </div>

                <!-- Review & Build Card -->
                <div class="card shadow-sm">
                     <div class="card-header py-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="fas fa-check-circle me-2"></i>Step 3: Review & Build</h6>
                    </div>
                    <div class="card-body">
                        <label class="form-label">Courses You've Selected:</label>
                        <div id="selectedCoursesListCR" class="p-2 border rounded mb-3 bg-light" style="min-height: 80px; max-height: 200px; overflow-y:auto;">
                            <p class="text-muted text-center small p-2" id="selectedCoursesPlaceholderCR">No courses selected yet. Pick some from the list!</p>
                        </div>
                        <button type="button" id="buildScheduleBtnCR" class="btn btn-primary w-100" disabled>
                            <i class="fas fa-cogs me-2"></i>Find & Optimize My Schedule
                        </button>
                    </div>
                </div>
            </div>
        </div> <!-- End main form row -->
    </form>
    
    <!-- Progress Section -->
    <div id="studentProgressSectionCR" class="card my-4 shadow-sm" style="display:none;">
        <div class="card-header bg-info-subtle text-info-emphasis"><i class="fas fa-spinner fa-spin me-1"></i> Building Your Schedule... Please Wait</div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 20px;">
                <div id="studentProgressBarCR" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
            <pre id="studentProcessLogCR" class="log-output"></pre>
        </div>
    </div>

    <!-- Results Section -->
    <div id="scheduleResultsContainerCR" class="my-4" style="display:none;">
        <div class="card shadow-sm">
            <div class="card-header py-3">
                <h6 class="m-0 fw-bold text-primary"><i class="fas fa-calendar-check me-1"></i> Suggested Schedule Options</h6>
            </div>
            <div class="card-body">
                 <div id="scheduleOptionsRenderAreaCR">
                    <p class="text-muted text-center p-3" id="scheduleOptionsPlaceholderCR">Your optimized schedule options will appear here.</p>
                </div>
            </div>
        </div>
    </div>

</div> 
<!-- END: Page-specific content -->

<?php
// The layout file (admin_sidebar_menu.php) is responsible for closing </body> and </html>
// and including global JavaScript files like Bootstrap Bundle.
// Page-specific JS will be added below.
?>
<!-- Nối tiếp từ Part 3 của course_registration.php (phần HTML body) -->
<style>
    /* CSS from your previous submission, kept as is */
    .course-selection-card, .preferences-card, .schedule-results-card { margin-bottom: 1.5rem; }
    .course-item { padding: 0.6rem 1rem; border: 1px solid #e0e0e0; margin-bottom: 0.5rem; border-radius: 0.375rem; background-color: #fff; display: flex; align-items: flex-start; transition: background-color 0.15s ease-in-out; }
    .course-item:hover { background-color: #f8f9fa; }
    .course-item .form-check-input { margin-top: 0.35rem; margin-right: 0.75rem; transform: scale(1.1); cursor: pointer; }
    .course-item label { font-weight: 500; cursor: pointer; flex-grow: 1; margin-bottom: 0; }
    .course-item .course-details { font-size: 0.8rem; color: #5a5c69; margin-left: 0; display: block; line-height: 1.3; }
    .course-item .course-credits { font-size: 0.75rem; color: var(--primary-blue, #005c9e); font-weight: bold; }
    #selectedCoursesListCR ul { padding-left: 0; list-style-type: none; margin-bottom: 0; }
    #selectedCoursesListCR li { padding: 0.4rem 0.75rem; margin-bottom: 0.3rem; background-color: #e9ecef; border-radius: 0.25rem; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; }
    #selectedCoursesListCR li .remove-course-btn { color: #dc3545; background: none; border: none; padding: 0 0.3rem; font-size: 1rem; line-height: 1; cursor: pointer; }
    #selectedCoursesListCR li .remove-course-btn:hover { color: #a71d2a; }
    .log-output { white-space: pre-wrap; word-wrap: break-word; font-size: 0.8em; max-height: 250px; overflow-y: auto; background-color: #212529; color: #f8f9fa; border: 1px solid #343a40; padding: 10px; border-radius: 0.25rem; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; }
    .schedule-option-wrapper { background-color: #fdfdfd; border: 1px solid #dee2e6; }
    .schedule-option-wrapper.recommended-option { border-color: var(--bs-success) !important; background-color: var(--bs-success-bg-subtle) !important; box-shadow: 0 0 0 0.25rem rgba(var(--bs-success-rgb), 0.25); }
    .schedule-option-wrapper h4 .badge { font-size: 0.8em; vertical-align: middle; }
    .schedule-event-option { font-size: 0.7rem !important; line-height: 1.2; background-color: var(--bs-primary-bg-subtle) !important; border-color: var(--bs-primary-border-subtle) !important; color: var(--bs-primary-text-emphasis) !important; }
    .schedule-event-option strong.event-course { font-size: 0.72rem !important; }
    .schedule-event-option small.event-lecturer, .schedule-event-option small.event-room { font-size: 0.68rem !important;}
    .select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__rendered { padding-bottom: 0.1rem !important; }
    .select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__choice { background-color: var(--primary-blue); color: white; border: none; font-size: 0.85rem; padding: 0.2rem 0.5rem; }
    .select2-container--bootstrap-5 .select2-selection--multiple .select2-selection__choice__remove { color: white; border-right: 1px solid rgba(255,255,255,0.3); margin-right: 0.5rem; }
    .select2-container--bootstrap-5 .select2-dropdown { border-color: var(--primary-blue); }
    .select2-container--bootstrap-5 .select2-results__option--highlighted { background-color: var(--primary-blue); color: white; }
</style>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const semesterSelectCR = document.getElementById('semester_id_cr_select');
    const majorCategorySelectCR = document.getElementById('major_category_cr_select');
    const courseListDivCR = document.getElementById('courseListCR');
    const courseListPlaceholderMsgCR = document.getElementById('courseListPlaceholderMsgCR');
    const selectedCoursesListDivCR = document.getElementById('selectedCoursesListCR');
    const selectedCoursesPlaceholderCR = document.getElementById('selectedCoursesPlaceholderCR');
    const buildScheduleBtnCR = document.getElementById('buildScheduleBtnCR');

    const studentProgressSectionCR = document.getElementById('studentProgressSectionCR');
    const studentProgressBarCR = document.getElementById('studentProgressBarCR');
    const studentProcessLogCR = document.getElementById('studentProcessLogCR');
    const scheduleResultsContainerCR = document.getElementById('scheduleResultsContainerCR');
    const scheduleOptionsRenderAreaCR = document.getElementById('scheduleOptionsRenderAreaCR');
    const registrationMessagesDivCR = document.getElementById('courseRegistrationMessagesCR');

    let selectedCourseObjects = {};
    let studentProgressInterval = null;
    let currentStudentRunOutputFilename = null;
    let isStudentProcessRunning = false;

    function displayRegMessageCR(message, type = 'info', clearOld = true) {
        if (!registrationMessagesDivCR) return;
        if (clearOld) registrationMessagesDivCR.innerHTML = '';
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show mt-2 rounded-1 py-2`;
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `${message} <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button>`;
        registrationMessagesDivCR.appendChild(alertDiv);
        if (type === 'success' || type === 'info') {
            setTimeout(() => {
                if (alertDiv && alertDiv.parentElement) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alertDiv);
                    if (bsAlert) bsAlert.close();
                }
            }, 8000);
        }
    }

    function updateStudentProgressDisplay(percent, logContent = null) {
        const p = Math.max(0, Math.min(100, percent));
        if (studentProgressBarCR) {
            studentProgressBarCR.style.width = p + '%';
            studentProgressBarCR.textContent = p + '%';
            studentProgressBarCR.setAttribute('aria-valuenow', p);
        }
        if (logContent && typeof logContent === 'string' && studentProcessLogCR) {
            const newLogHtml = logContent.replace(/\n/g, '<br>');
            if (studentProcessLogCR.innerHTML !== newLogHtml) {
                studentProcessLogCR.innerHTML = newLogHtml;
                studentProcessLogCR.scrollTop = studentProcessLogCR.scrollHeight;
            }
        }
    }

    function clearStudentLogContent() {
        if (studentProcessLogCR) studentProcessLogCR.innerHTML = '';
    }

    function fetchAndRenderCourses() {
        const semesterId = semesterSelectCR ? semesterSelectCR.value : null;
        const majorCategory = majorCategorySelectCR ? majorCategorySelectCR.value : 'all';

        if (!semesterId) {
            if(courseListDivCR) courseListDivCR.innerHTML = '';
            if(courseListPlaceholderMsgCR) {
                courseListPlaceholderMsgCR.textContent = 'Please select a semester to see available courses.';
                courseListPlaceholderMsgCR.style.display = 'block';
            }
            selectedCourseObjects = {};
            updateSelectedCoursesDisplayCR();
            if(buildScheduleBtnCR) buildScheduleBtnCR.disabled = true;
            return;
        }

        if(courseListPlaceholderMsgCR) {
            courseListPlaceholderMsgCR.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Fetching courses...';
            courseListPlaceholderMsgCR.style.display = 'block';
        }
        if(courseListDivCR) courseListDivCR.innerHTML = '';

        let fetchUrl = `get_courses_for_registration_ajax.php?semester_id=${encodeURIComponent(semesterId)}`;
        if (majorCategory && majorCategory !== 'all') {
            fetchUrl += `&major_category=${encodeURIComponent(majorCategory)}`;
        }

        fetch(fetchUrl)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok: ' + response.statusText);
                return response.json();
            })
            .then(data => {
                if(courseListPlaceholderMsgCR) courseListPlaceholderMsgCR.style.display = 'none';
                if (data.status === 'success' && data.courses && data.courses.length > 0) {
                    renderCourseListCR(data.courses);
                } else if (data.courses && data.courses.length === 0) {
                    if(courseListPlaceholderMsgCR) {
                         courseListPlaceholderMsgCR.textContent = 'No courses available for the selected semester and/or major category.';
                         courseListPlaceholderMsgCR.style.display = 'block';
                    }
                } else {
                    if(courseListPlaceholderMsgCR) {
                        courseListPlaceholderMsgCR.textContent = data.message || 'Could not load courses.';
                        courseListPlaceholderMsgCR.style.display = 'block';
                    }
                    displayRegMessageCR(data.message || 'Error loading courses.', 'warning');
                }
            })
            .catch(error => {
                console.error('Error fetching courses:', error);
                if(courseListPlaceholderMsgCR) {
                    courseListPlaceholderMsgCR.textContent = 'Failed to load courses. Check connection or try again.';
                    courseListPlaceholderMsgCR.style.display = 'block';
                }
                displayRegMessageCR('Error fetching courses: ' + error.message, 'danger');
            });
    }

    function renderCourseListCR(courses) {
        if(!courseListDivCR) return;
        courseListDivCR.innerHTML = ''; // Clear previous content
        courses.forEach(course => {
            const courseDiv = document.createElement('div');
            courseDiv.className = 'course-item';
            const inputId = `course_checkbox_${course.CourseID.replace(/[^a-zA-Z0-9]/g, "_")}`;
            const isChecked = selectedCourseObjects[course.CourseID] ? 'checked' : '';

            courseDiv.innerHTML = `
                <input class="form-check-input" type="checkbox" value="${course.CourseID}" id="${inputId}" name="selected_courses[]"
                       data-course-name="${escapeHtml(course.CourseName)}"
                       data-course-credits="${course.Credits || 'N/A'}" ${isChecked}>
                <label class="form-check-label w-100" for="${inputId}">
                    ${escapeHtml(course.CourseName)} <small class="text-muted">(${escapeHtml(course.CourseID)})</small>
                    <span class="course-details">
                        Credits: <span class="course-credits">${course.Credits || 'N/A'}</span>
                        ${course.MajorCategory ? `<br><small class="text-info">Category: ${escapeHtml(course.MajorCategory)}</small>` : ''}
                    </span>
                </label>
            `;
            const checkbox = courseDiv.querySelector('input[type="checkbox"]');
            checkbox.addEventListener('change', handleCourseSelectionChangeCR);
            courseListDivCR.appendChild(courseDiv);
        });
    }

    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') {
            try { unsafe = String(unsafe); } catch (e) { return ''; }
        }
        const div = document.createElement('div');
        div.textContent = unsafe;
        return div.innerHTML;
    }

    function handleCourseSelectionChangeCR(event) {
        const courseId = event.target.value;
        const courseName = event.target.dataset.courseName;
        const courseCredits = event.target.dataset.courseCredits;

        if (event.target.checked) {
            selectedCourseObjects[courseId] = { name: courseName, credits: courseCredits, id: courseId };
        } else {
            delete selectedCourseObjects[courseId];
        }
        updateSelectedCoursesDisplayCR();
    }

    function updateSelectedCoursesDisplayCR() {
        if (!selectedCoursesListDivCR || !buildScheduleBtnCR) return;
        const courseIds = Object.keys(selectedCourseObjects);
        if (courseIds.length === 0) {
            selectedCoursesListDivCR.innerHTML = '';
            if(selectedCoursesPlaceholderCR) selectedCoursesPlaceholderCR.style.display = 'block';
            buildScheduleBtnCR.disabled = true;
        } else {
            if(selectedCoursesPlaceholderCR) selectedCoursesPlaceholderCR.style.display = 'none';
            let listHtml = '<ul class="list-unstyled mb-0">';
            courseIds.forEach(id => {
                const course = selectedCourseObjects[id];
                listHtml += `<li>
                                <i class="fas fa-book text-muted me-2"></i>
                                ${escapeHtml(course.name)} <small class="text-muted">(${escapeHtml(id)}) - ${escapeHtml(String(course.credits))} Cr.</small>
                                <button type="button" class="remove-course-btn float-end" data-course-id="${escapeHtml(id)}" aria-label="Remove ${escapeHtml(course.name)}">×</button>
                             </li>`;
            });
            listHtml += '</ul>';
            selectedCoursesListDivCR.innerHTML = listHtml;
            buildScheduleBtnCR.disabled = false;

            selectedCoursesListDivCR.querySelectorAll('.remove-course-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const courseIdToRemove = this.dataset.courseId;
                    const checkboxToRemove = document.getElementById(`course_checkbox_${courseIdToRemove.replace(/[^a-zA-Z0-9]/g, "_")}`);
                    if (checkboxToRemove) checkboxToRemove.checked = false;
                    delete selectedCourseObjects[courseIdToRemove];
                    updateSelectedCoursesDisplayCR();
                });
            });
        }
    }

    if (semesterSelectCR && semesterSelectCR.value) {
        fetchAndRenderCourses();
    }

    if(semesterSelectCR) {
        semesterSelectCR.addEventListener('change', function() {
            fetchAndRenderCourses();
            selectedCourseObjects = {};
            updateSelectedCoursesDisplayCR();
            if(studentProgressSectionCR) studentProgressSectionCR.style.display = 'none';
            if(scheduleResultsContainerCR) scheduleResultsContainerCR.style.display = 'none';
            if(registrationMessagesDivCR) registrationMessagesDivCR.innerHTML = '';
        });
    }

    if(majorCategorySelectCR) {
        majorCategorySelectCR.addEventListener('change', function() {
            fetchAndRenderCourses();
        });
    }

    if(buildScheduleBtnCR) {
        buildScheduleBtnCR.addEventListener('click', function() {
            if (isStudentProcessRunning) {
                displayRegMessageCR("A schedule building process is already running.", "warning");
                return;
            }
            const semesterIdVal = semesterSelectCR ? semesterSelectCR.value : null;
            const selectedCourseIdsArray = Object.keys(selectedCourseObjects);

            if (!semesterIdVal) { displayRegMessageCR('Please select a semester.', 'warning'); return; }
            if (selectedCourseIdsArray.length === 0) { displayRegMessageCR('Please select at least one course.', 'warning'); return; }

            if(registrationMessagesDivCR) registrationMessagesDivCR.innerHTML = '';
            displayRegMessageCR('Initiating schedule builder...', 'info', true);
            if(studentProgressSectionCR) studentProgressSectionCR.style.display = 'block';
            if(studentProgressBarCR) {
                studentProgressBarCR.style.width = '1%';
                studentProgressBarCR.textContent = '1%';
                studentProgressBarCR.className = 'progress-bar progress-bar-striped progress-bar-animated';
            }
            clearStudentLogContent();
            if(studentProcessLogCR) studentProcessLogCR.innerHTML = 'Preparing your request...<br>';
            if(scheduleResultsContainerCR) scheduleResultsContainerCR.style.display = 'none';
            if(scheduleOptionsRenderAreaCR) scheduleOptionsRenderAreaCR.innerHTML = '<p class="text-muted text-center p-3" id="scheduleOptionsPlaceholderCR">Your optimized schedule options will appear here.</p>';
            buildScheduleBtnCR.disabled = true;
            isStudentProcessRunning = true;

            const preferencesPayload = {
                time_of_day: document.getElementById('pref_time_of_day_cr') ? document.getElementById('pref_time_of_day_cr').value : "",
                max_consecutive_classes: document.getElementById('pref_max_consecutive_classes_cr') ? document.getElementById('pref_max_consecutive_classes_cr').value : "",
                compact_days: document.getElementById('pref_compact_days_cr') ? document.getElementById('pref_compact_days_cr').checked : false,
                friday_off: document.getElementById('pref_friday_off_cr') ? document.getElementById('pref_friday_off_cr').checked : false
            };

            const payload = {
                semester_id_to_load: semesterIdVal,
                selected_course_ids: selectedCourseIdsArray,
                preferences: preferencesPayload
            };

            fetch('process_student_schedule_request_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                     return response.text().then(text => {throw new Error(`Server error ${response.status} (initiating): ${text.substring(0,300)}`);});
                }
                return response.json();
            })
            .then(data => {
                if (data.status === 'background_initiated_student') {
                    displayRegMessageCR(data.message || 'Process started. Monitoring...', 'info', false);
                    currentStudentRunOutputFilename = data.output_file;
                    if (studentProgressInterval) clearInterval(studentProgressInterval);
                    studentProgressInterval = setInterval(pollStudentProgressCR, 2500);
                    updateStudentProgressDisplay(2, "Background process initiated. Waiting for updates...<br>");
                } else {
                    throw new Error(data.message || 'Could not start the schedule building process.');
                }
            })
            .catch(error => {
                console.error('Error initiating build:', error);
                displayRegMessageCR('Error: ' + error.message, 'danger');
                if(studentProgressSectionCR) studentProgressSectionCR.style.display = 'none';
                if(buildScheduleBtnCR) buildScheduleBtnCR.disabled = false;
                isStudentProcessRunning = false;
            });
        });
    }

    function pollStudentProgressCR() {
        if (!isStudentProcessRunning) {
            if(studentProgressInterval) clearInterval(studentProgressInterval);
            studentProgressInterval = null;
            return;
        }
        fetch('get_student_schedule_progress_ajax.php')
            .then(response => {
                if (!response.ok) throw new Error('Polling network error: ' + response.statusText);
                return response.json();
            })
            .then(data => {
                if (!isStudentProcessRunning) {
                    if(studentProgressInterval) clearInterval(studentProgressInterval);
                    studentProgressInterval = null;
                    return;
                }
                updateStudentProgressDisplay(data.progress_percent || 0, data.log_content || null);

                if (data.status === 'completed_student_schedule_success') {
                    stopStudentPollingAndResetUI(false);
                    if(studentProgressBarCR) {
                        studentProgressBarCR.classList.add('bg-success');
                        studentProgressBarCR.classList.remove('progress-bar-animated');
                    }
                    displayRegMessageCR(data.message || 'Schedule options generated successfully!', 'success', false);
                    if (data.output_file) {
                        currentStudentRunOutputFilename = data.output_file;
                        fetchAndRenderStudentScheduleOptionsCR(data.output_file);
                    } else {
                        displayRegMessageCR('Success, but output file reference missing!', 'warning', false);
                    }
                } else if (data.status === 'completed_student_schedule_error' || (data.status.startsWith('error_') && data.status !== 'error_initial_script_state' && data.status !== 'error_unknown_student_progress_state') ) {
                    stopStudentPollingAndResetUI(false);
                     if(studentProgressBarCR) {
                        studentProgressBarCR.classList.add('bg-danger');
                        studentProgressBarCR.classList.remove('progress-bar-animated');
                    }
                    displayRegMessageCR(data.message || 'An error occurred during schedule generation.', 'danger', false);
                    if (data.output_file) {
                         currentStudentRunOutputFilename = data.output_file;
                         fetchAndRenderStudentScheduleOptionsCR(data.output_file, true);
                    }
                } else if (!['running_background', 'initiating_background', 'python_running'].includes(data.status)) {
                    stopStudentPollingAndResetUI(true);
                    displayRegMessageCR('Polling stopped due to an unexpected server state: ' + data.status, 'warning', false);
                }
            })
            .catch(error => {
                console.error('Error polling student progress:', error);
                stopStudentPollingAndResetUI(true);
                displayRegMessageCR('Error checking progress: ' + error.message, 'danger', false);
                if(studentProgressBarCR) {
                    studentProgressBarCR.classList.add('bg-danger'); studentProgressBarCR.textContent = 'Error';
                }
            });
    }

    function stopStudentPollingAndResetUI(resetFull = true) {
        isStudentProcessRunning = false;
        if (studentProgressInterval) {
            clearInterval(studentProgressInterval);
            studentProgressInterval = null;
        }
        if (buildScheduleBtnCR) buildScheduleBtnCR.disabled = false;
        if (resetFull) {
            if(studentProgressSectionCR) studentProgressSectionCR.style.display = 'none';
            if(scheduleResultsContainerCR) scheduleResultsContainerCR.style.display = 'none';
            clearStudentLogContent();
            currentStudentRunOutputFilename = null;
        }
    }

    function fetchAndRenderStudentScheduleOptionsCR(outputFilename, isPythonReportedError = false) {
        if (!scheduleResultsContainerCR || !scheduleOptionsRenderAreaCR) return;
        scheduleResultsContainerCR.style.display = 'block';
        scheduleOptionsRenderAreaCR.innerHTML = '<p class="text-center p-3"><i class="fas fa-spinner fa-spin me-2"></i> Loading schedule options...</p>';

        fetch(`get_student_schedule_result_ajax.php?file=${encodeURIComponent(outputFilename)}`)
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {throw new Error('Fetching results network error: ' + response.status + ' ' + text.substring(0,200) ); });
            }
            return response.json();
        })
        .then(result => {
            if (result.status === 'success_student_result_retrieved' && result.data) {
                const pythonOutput = result.data;
                if (pythonOutput.final_schedule_options && Array.isArray(pythonOutput.final_schedule_options) && pythonOutput.final_schedule_options.length > 0) {
                    const renderPayload = {
                        schedule_options: pythonOutput.final_schedule_options,
                        view_type: 'weekly_visual_options',
                        option_metrics: pythonOutput.metrics_per_option || []
                    };
                    return fetch('render_student_schedule_options_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(renderPayload)
                    });
                } else if (isPythonReportedError || (pythonOutput.status && pythonOutput.status.toLowerCase().includes('error'))) {
                     scheduleOptionsRenderAreaCR.innerHTML = `<div class="alert alert-warning">Could not generate options. Python: ${escapeHtml(pythonOutput.status || 'Unknown')}. <br>${escapeHtml(pythonOutput.message || 'No details.')}</div>`;
                     return null;
                } else {
                    scheduleOptionsRenderAreaCR.innerHTML = '<p class="text-muted text-center p-3">No suitable schedule options found based on your selections and preferences.</p>';
                    return null;
                }
            } else {
                throw new Error(result.message || 'Failed to retrieve valid schedule data from server.');
            }
        })
        .then(renderResponse => {
            if (renderResponse === null) return null;
            if (renderResponse && renderResponse.ok) return renderResponse.text();
            if (renderResponse) throw new Error('Rendering options failed: ' + renderResponse.status);
            return null;
        })
        .then(html => {
            if (html) {
                scheduleOptionsRenderAreaCR.innerHTML = html;
                addSelectScheduleButtonListenersCR();
            } else if (scheduleOptionsRenderAreaCR.innerHTML.includes('Loading')) {
                scheduleOptionsRenderAreaCR.innerHTML = '<p class="text-warning text-center p-3">Could not display schedule options.</p>';
            }
        })
        .catch(error => {
            console.error('Error fetching/rendering student schedule options:', error);
            scheduleOptionsRenderAreaCR.innerHTML = `<div class="alert alert-danger">Error displaying options: ${error.message}</div>`;
        });
    }

    function addSelectScheduleButtonListenersCR() {
        if (!scheduleOptionsRenderAreaCR) return;
        const selectButtons = scheduleOptionsRenderAreaCR.querySelectorAll('.select-schedule-option-btn');
        selectButtons.forEach(button => {
            button.addEventListener('click', function() {
                const optionIndex = parseInt(this.dataset.optionIndex);
                const h4Element = this.closest('.schedule-option-wrapper')?.querySelector('h4');
                let defaultScheduleName = `My Schedule Option ${optionIndex + 1}`;
                if (h4Element && h4Element.firstChild && h4Element.firstChild.textContent) {
                     defaultScheduleName = h4Element.firstChild.textContent.trim().replace('Recommended','').trim() || defaultScheduleName;
                }
                const scheduleName = prompt("Enter a name for this schedule:", defaultScheduleName);

                if (scheduleName === null || scheduleName.trim() === "") {
                    displayRegMessageCR("Saving cancelled or name not provided.", "info", false);
                    return;
                }
                displayRegMessageCR(`Attempting to save '${escapeHtml(scheduleName.trim())}'...`, 'info', false);
                getScheduleOptionDataAndSaveCR(currentStudentRunOutputFilename, optionIndex, scheduleName.trim(), this);
            });
        });
    }

    function getScheduleOptionDataAndSaveCR(outputFilename, optionIndex, scheduleNameToSave, clickedButton) {
        if (!outputFilename) {
            displayRegMessageCR('Cannot save: Output filename reference is missing.', 'danger', false);
            return;
        }
        let originalButtonText = '';
        if (clickedButton) {
            originalButtonText = clickedButton.innerHTML;
            clickedButton.disabled = true;
            clickedButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
        }

        fetch(`get_student_schedule_result_ajax.php?file=${encodeURIComponent(outputFilename)}`)
        .then(response => {
            if (!response.ok) { return response.text().then(text => { throw new Error(`Error fetching data for save: ${response.status} ${text.substring(0,100)}`); });}
            return response.json();
        })
        .then(result => {
            if (result.status === 'success_student_result_retrieved' && result.data &&
                result.data.final_schedule_options && Array.isArray(result.data.final_schedule_options) &&
                result.data.final_schedule_options[optionIndex] && Array.isArray(result.data.final_schedule_options[optionIndex])) {

                const scheduleToSave = result.data.final_schedule_options[optionIndex];
                const semesterIdToSaveElement = document.getElementById('semester_id_cr_select');
                if (!semesterIdToSaveElement || !semesterIdToSaveElement.value) {
                    throw new Error('Semester ID for saving is not selected.');
                }
                const semesterIdToSave = semesterIdToSaveElement.value;

                const savePayload = {
                    selected_schedule_events: scheduleToSave,
                    semester_id: semesterIdToSave,
                    schedule_name: scheduleNameToSave
                };
                return fetch('save_student_schedule_ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(savePayload)
                });
            } else {
                let errorMsg = 'Could not retrieve specific schedule option data for saving.';
                if(result.data && result.data.message) errorMsg = `Data Error for Save: ${result.data.message}`;
                else if(result.message) errorMsg = `Server Error for Save: ${result.message}`;
                throw new Error(errorMsg);
            }
        })
        .then(saveResponse => {
            if (!saveResponse) return;
            if (!saveResponse.ok) {
                 return saveResponse.json().then(errData => { throw new Error(errData.message || `Saving failed: ${saveResponse.status}`); })
                                     .catch(() => { throw new Error(`Saving failed: ${saveResponse.status}`); });
            }
            return saveResponse.json();
        })
        .then(saveResult => {
            if (!saveResult) return;
            if (saveResult.status === 'success_schedule_saved') {
                displayRegMessageCR(saveResult.message || 'Schedule saved successfully!', 'success', false);
                if(clickedButton) {
                    clickedButton.classList.remove('btn-outline-primary', 'btn-success');
                    clickedButton.classList.add('btn-outline-success');
                    clickedButton.innerHTML = '<i class="fas fa-check me-2"></i> Saved!';
                    // Consider disabling it permanently or changing its role
                }
            } else {
                displayRegMessageCR(saveResult.message || 'Failed to save. Try a different name or check details.', 'danger', false);
            }
        })
        .catch(error => {
            console.error('Error in getScheduleOptionDataAndSaveCR:', error);
            displayRegMessageCR('Error saving schedule: ' + error.message, 'danger', false);
        })
        .finally(() => {
            if (clickedButton && clickedButton.innerHTML.includes('Saving...')) {
                clickedButton.disabled = false;
                clickedButton.innerHTML = originalButtonText || 'Select This Schedule';
            }
        });
    }

    updateSelectedCoursesDisplayCR();
});
</script>
</body>
</html>