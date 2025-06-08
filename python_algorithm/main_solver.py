import json
import os
import sys
import time as pytime
import traceback
import copy
from datetime import datetime
from typing import List, Dict, Tuple, Any, Optional

current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

scheduler_config: Dict[str, Any] = {}

try:
    from data_loader import load_all_data
    from models import Course, ScheduledClass
except ImportError as e_imp_dl_models:
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    error_msg_critical = f"[{timestamp}] MAIN_SOLVER_CRITICAL: Error importing data_loader or models: {e_imp_dl_models}\n{traceback.format_exc()}"
    print(error_msg_critical, file=sys.stderr, flush=True)
    sys.exit(1)

try:
    from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json
except ImportError as e_imp_ut:
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    error_msg_critical = f"[{timestamp}] MAIN_SOLVER_CRITICAL: Error importing utils: {e_imp_ut}\n{traceback.format_exc()}"
    print(error_msg_critical, file=sys.stderr, flush=True)
    sys.exit(1)

try:
    from cp_module import CourseSchedulingCPSAT
except ImportError as e_imp_cp:
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    error_msg_critical = f"[{timestamp}] MAIN_SOLVER_CRITICAL: Error importing cp_module: {e_imp_cp}\n{traceback.format_exc()}"
    print(error_msg_critical, file=sys.stderr, flush=True)
    sys.exit(1)

try:
    from ga_module import GeneticAlgorithmScheduler
except ImportError as e_imp_ga:
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    error_msg_critical = f"[{timestamp}] MAIN_SOLVER_CRITICAL: Error importing ga_module: {e_imp_ga}\n{traceback.format_exc()}"
    print(error_msg_critical, file=sys.stderr, flush=True)
    sys.exit(1)

DEFAULT_CP_TIME_LIMIT_SECONDS = 30.0
DEFAULT_GA_POPULATION_SIZE = 30
DEFAULT_GA_GENERATIONS = 50

DEFAULT_FINAL_OUTPUT_FILENAME = "final_schedule_output.json"

_PROGRESS_LOG_FILE_PATH: Optional[str] = None

def write_progress(message: str):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]
    log_line = f"[{timestamp}] PYTHON_PROGRESS: {message}"
    print(log_line, flush=True)

    global _PROGRESS_LOG_FILE_PATH
    if _PROGRESS_LOG_FILE_PATH:
        try:
            log_dir = os.path.dirname(_PROGRESS_LOG_FILE_PATH)
            if log_dir and not os.path.exists(log_dir):
                os.makedirs(log_dir, exist_ok=True)
            with open(_PROGRESS_LOG_FILE_PATH, "a", encoding="utf-8") as f_log:
                f_log.write(log_line + "\n")
        except Exception as e_log_write:
            print(f"[{timestamp}] PYTHON_WARNING: Could not write to progress file '{_PROGRESS_LOG_FILE_PATH}': {e_log_write}", file=sys.stderr, flush=True)

def print_stage_header(title: str):
    separator = "=" * 60
    write_progress(separator)
    write_progress(f"{title.upper():^60}")
    write_progress(separator)

def load_scheduler_config_from_php(base_script_dir: str, config_filename: str) -> dict:
    config_file_path = os.path.join(base_script_dir, "input_data", config_filename)
    
    default_config = {
        "run_type": "admin_optimize_semester",
        "student_id": None,
        "requested_course_ids": [],
        "student_preferences": {},
        "semester_id_to_load": None,
        "python_executable_path": "python",
        "cp_time_limit_seconds": DEFAULT_CP_TIME_LIMIT_SECONDS,
        "ga_population_size": DEFAULT_GA_POPULATION_SIZE,
        "ga_generations": DEFAULT_GA_GENERATIONS,
        "ga_crossover_rate": 0.8,
        "ga_mutation_rate": 0.2,
        "ga_tournament_size": 3,
        "ga_allow_hard_constraint_violations": False,
        "priority_student_clash": "medium",
        "priority_lecturer_load_break": "medium",
        "priority_classroom_util": "medium",
        "priority_student_preferences": "medium",
        "progress_log_file_path_from_php": None,
        "output_filename_override": None
    }
    loaded_config = default_config.copy()

    if os.path.exists(config_file_path):
        try:
            with open(config_file_path, 'r', encoding="utf-8") as f:
                php_config_content = json.load(f)
            loaded_config.update(php_config_content)
        except Exception as e_conf_read:
            error_msg = f"Error reading/parsing config {config_file_path}: {e_conf_read}. Using defaults."
            write_progress(f"CONFIG_ERROR: {error_msg}")
            print(f"PYTHON_ERROR: {error_msg}", file=sys.stderr, flush=True)
    else:
        write_progress(f"CONFIG_WARNING: Config file {config_file_path} not found. Using default parameters.")
    return loaded_config

def save_final_output_and_log(data: dict, base_script_dir: str, config: Dict[str, Any], default_filename: str):
    output_filename = config.get("output_filename_override", default_filename)
    output_file_name_only = os.path.basename(output_filename)
    output_file_full_path = os.path.join(base_script_dir, "output_data", output_file_name_only)

    try:
        save_output_data_to_json(data, output_file_full_path)
    except Exception as e_save_final:
        write_progress(f"MAIN_SOLVER_ERROR: Failed to save final output to {output_file_full_path}: {e_save_final}")
        print(f"PYTHON_ERROR: Saving final output failed: {e_save_final}", file=sys.stderr, flush=True)

def save_error_output(message: str, status_code: str, semester_id: Optional[int], base_script_dir: str, config: Dict[str, Any]):
    error_output = {
        "status": status_code, "message": str(message), "semester_id": semester_id,
        "final_schedule": [], "final_schedule_options": [], "metrics": {"error_occurred": True}
    }
    save_final_output_and_log(error_output, base_script_dir, config, DEFAULT_FINAL_OUTPUT_FILENAME)
    write_progress(f"ERROR_OUTPUT_SAVED: Status='{status_code}', Msg='{message}'.")

def run_scheduler(config_filename_from_php: str):
    global _PROGRESS_LOG_FILE_PATH
    global scheduler_config

    script_dir = os.path.dirname(os.path.abspath(__file__))
    overall_start_time = pytime.time()
    
    semester_id: Optional[int] = None
    run_type_from_config: str = "unknown_run_type"
    cp_solution_metrics: Dict[str, Any] = {}
    ga_best_penalty_score = float('inf')
    ga_final_detailed_metrics: Dict[str, Any] = {}
    final_schedule_output_list: Optional[List[Dict[str, Any]]] = None
    final_schedule_options_list_student: Optional[List[List[Dict[str, Any]]]] = None


    scheduler_config = load_scheduler_config_from_php(script_dir, config_filename_from_php)
    
    relative_log_path = scheduler_config.get("progress_log_file_path_from_php")
    if relative_log_path and isinstance(relative_log_path, str):
        _PROGRESS_LOG_FILE_PATH = os.path.join(script_dir, relative_log_path)
        try:
            os.makedirs(os.path.dirname(_PROGRESS_LOG_FILE_PATH), exist_ok=True)
            with open(_PROGRESS_LOG_FILE_PATH, "w", encoding="utf-8") as f_log_init:
                 f_log_init.write(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]}] PYTHON_PROGRESS: Python log init.\n")
        except Exception as e_log_setup:
            _PROGRESS_LOG_FILE_PATH = None
            print(f"PYTHON_WARNING: Failed to setup progress log at '{_PROGRESS_LOG_FILE_PATH}': {e_log_setup}", file=sys.stderr, flush=True)

    write_progress(f"Progress: 0% - Python Scheduler Started (Config: {config_filename_from_php})")

    try:
        semester_id_str = scheduler_config.get("semester_id_to_load")
        if semester_id_str is None: raise ValueError("'semester_id_to_load' missing in config.")
        semester_id = int(semester_id_str)
        if semester_id <= 0: raise ValueError(f"Invalid 'semester_id_to_load': {semester_id_str}.")

        run_type_from_config = scheduler_config.get("run_type", "admin_optimize_semester")
        write_progress(f"Run Type: {run_type_from_config}, Semester ID: {semester_id}")

        cp_time_limit = float(scheduler_config.get("cp_time_limit_seconds", DEFAULT_CP_TIME_LIMIT_SECONDS))
        ga_pop_size = int(scheduler_config.get("ga_population_size", DEFAULT_GA_POPULATION_SIZE))
        ga_gens = int(scheduler_config.get("ga_generations", DEFAULT_GA_GENERATIONS))
        ga_crossover_r = float(scheduler_config.get("ga_crossover_rate", 0.8))
        ga_mutation_r = float(scheduler_config.get("ga_mutation_rate", 0.2))
        ga_tournament_s = int(scheduler_config.get("ga_tournament_size", 3))
        ga_allow_hc_violations_flag = str(scheduler_config.get("ga_allow_hard_constraint_violations", "false")).lower() == 'true'

        priority_settings_for_utils = {
            key: scheduler_config.get(key, "medium")
            for key in ["priority_student_clash", "priority_lecturer_load_break",
                        "priority_classroom_util", "priority_student_preferences"]
        }
        if run_type_from_config == "student_schedule_request" and scheduler_config.get("student_id"):
            priority_settings_for_utils["student_id"] = scheduler_config.get("student_id")


        print_stage_header(f"1. DATA LOADING - SEMESTER: {semester_id}")
        db_scheduled_classes_for_semester, \
        db_instructors, \
        db_classrooms, \
        db_timeslots, \
        db_students, \
        db_courses_catalog = load_all_data(semester_id_to_load=semester_id)

        if not (db_instructors and db_classrooms and db_timeslots and db_courses_catalog):
            raise RuntimeError(f"Essential base data missing for semester {semester_id} from DB.")
        write_progress(f"Base data loaded: {len(db_courses_catalog)} courses, {len(db_instructors)} instructors.")

        reference_schedule_data_for_utils = db_scheduled_classes_for_semester


        current_run_input_scheduled_classes: List[ScheduledClass] = []
        if run_type_from_config == 'admin_optimize_semester':
            current_run_input_scheduled_classes = db_scheduled_classes_for_semester
            if not current_run_input_scheduled_classes:
                write_progress("Admin Run INFO: No pre-existing scheduled classes in DB for this semester. Attempting to schedule from scratch if possible.")
        elif run_type_from_config == 'student_schedule_request':
            requested_course_ids = scheduler_config.get("requested_course_ids", [])
            if not requested_course_ids: raise ValueError("Student request: 'requested_course_ids' missing.")
            
            temp_item_id_counter = -1
            for course_id_req in requested_course_ids:
                course_detail = db_courses_catalog.get(str(course_id_req))
                if not course_detail:
                    write_progress(f"Student Run WARNING: Requested CourseID '{course_id_req}' not in catalog. Skipping."); continue
                
                num_students_virtual = course_detail.expected_students
                if num_students_virtual is None or num_students_virtual <= 0:
                    num_students_virtual = 1 
                
                current_run_input_scheduled_classes.append(ScheduledClass(
                    id=temp_item_id_counter, course_id=str(course_id_req), semester_id=semester_id,
                    num_students=num_students_virtual, instructor_id=None, classroom_id=None, timeslot_id=None
                ))
                temp_item_id_counter -= 1
            if not current_run_input_scheduled_classes:
                 raise RuntimeError("Student Run: No valid schedulable items created from requested courses.")
        else:
            raise ValueError(f"Unknown 'run_type': {run_type_from_config}")

        write_progress(f"Progress: 15% - Data Loaded. Preprocessing {len(current_run_input_scheduled_classes)} items for current run.")

        processed_data_dict = preprocess_data_for_cp_and_ga(
            input_scheduled_classes=current_run_input_scheduled_classes,
            input_courses_catalog=db_courses_catalog,
            input_instructors=db_instructors,
            input_classrooms=db_classrooms,
            input_timeslots=db_timeslots,
            input_students=db_students,
            reference_scheduled_classes=reference_schedule_data_for_utils,
            semester_id_for_settings=semester_id,
            priority_settings=priority_settings_for_utils,
            run_type=run_type_from_config
        )
        if not processed_data_dict:
            raise RuntimeError(f"Data preprocessing by utils.py returned no data for semester {semester_id}.")
        
        num_items_after_preprocessing = len(processed_data_dict.get("scheduled_items", {}))
        if len(current_run_input_scheduled_classes) > 0 and num_items_after_preprocessing == 0 :
            write_progress("MAIN_SOLVER WARNING: All input items were filtered out during preprocessing.")
        elif num_items_after_preprocessing == 0:
             write_progress("MAIN_SOLVER INFO: No items available for scheduling after preprocessing.")
        write_progress(f"Progress: 25% - Preprocessing Finished. {num_items_after_preprocessing} items for solvers.")


        cp_schedule_output_list: Optional[List[Dict[str, Any]]] = None
        run_cp_sat_stage = num_items_after_preprocessing > 0
        
        if run_cp_sat_stage:
            print_stage_header("2. CP-SAT SOLVER - INITIAL SCHEDULE")
            cp_solver = CourseSchedulingCPSAT(
                processed_data=processed_data_dict,
                progress_logger=write_progress,
                run_type=run_type_from_config
            )
            cp_schedule_output_list, cp_solution_metrics = cp_solver.solve(time_limit_seconds=cp_time_limit)
            
            if cp_schedule_output_list:
                write_progress(f"CP-SAT found solution with {len(cp_schedule_output_list)} events. Status: {cp_solution_metrics.get('solver_status', 'N/A')}.")
            else:
                write_progress(f"CP-SAT found NO feasible solutions. Status: {cp_solution_metrics.get('solver_status', 'N/A')}.")
        else:
            write_progress("Skipping CP-SAT stage: No items after preprocessing.")
            cp_solution_metrics = {"solver_status": "SKIPPED_NO_ITEMS"}
        write_progress("Progress: 50% - CP-SAT Stage Finished.")

        initial_schedules_for_ga: List[List[Dict[str, Any]]] = []
        if cp_schedule_output_list and len(cp_schedule_output_list) > 0:
            initial_schedules_for_ga.append(copy.deepcopy(cp_schedule_output_list))
        
        run_ga_stage = num_items_after_preprocessing > 0
        
        if run_ga_stage:
            print_stage_header("3. GENETIC ALGORITHM - OPTIMIZATION")
            student_prefs_for_ga = scheduler_config.get("student_preferences", {}) if run_type_from_config == 'student_schedule_request' else {}

            ga_solver = GeneticAlgorithmScheduler(
                processed_data=processed_data_dict,
                initial_population_from_cp=initial_schedules_for_ga,
                population_size=ga_pop_size, generations=ga_gens,
                crossover_rate=ga_crossover_r, mutation_rate=ga_mutation_r, tournament_size=ga_tournament_s,
                allow_hard_constraint_violations_in_ga=ga_allow_hc_violations_flag,
                progress_logger=write_progress,
                run_type=run_type_from_config,
                student_specific_preferences=student_prefs_for_ga
            )
            ga_best_schedule_result, ga_best_penalty_score, ga_final_detailed_metrics = ga_solver.run()

            if ga_best_schedule_result:
                final_schedule_output_list = ga_best_schedule_result
                if run_type_from_config == "student_schedule_request":
                    final_schedule_options_list_student = [ga_best_schedule_result] 
                    if "metrics_per_option" not in ga_final_detailed_metrics and "final_penalty_score" in ga_final_detailed_metrics:
                         ga_final_detailed_metrics["metrics_per_option"] = [{
                            "final_penalty_score": ga_final_detailed_metrics["final_penalty_score"],
                            "is_recommended": True
                         }]


                write_progress(f"GA finished. Best penalty: {ga_best_penalty_score:.2f}. Schedule has {len(final_schedule_output_list)} events.")
            elif cp_schedule_output_list:
                final_schedule_output_list = cp_schedule_output_list
                if run_type_from_config == "student_schedule_request":
                    final_schedule_options_list_student = [cp_schedule_output_list]
                write_progress("GA did not produce a better schedule; using CP-SAT solution if available.")
            else:
                final_schedule_output_list = []
                if run_type_from_config == "student_schedule_request": final_schedule_options_list_student = []
                write_progress("Neither CP-SAT nor GA produced a schedule.")
        elif cp_schedule_output_list:
             final_schedule_output_list = cp_schedule_output_list
             if run_type_from_config == "student_schedule_request": final_schedule_options_list_student = [cp_schedule_output_list]
             write_progress("GA Skipped. Using CP-SAT solution as final.")
        else:
            final_schedule_output_list = []
            if run_type_from_config == "student_schedule_request": final_schedule_options_list_student = []
            write_progress("No solution from CP-SAT, and GA was skipped. No schedule generated.")
        write_progress("Progress: 95% - GA Stage Finished / Skipped.")


        print_stage_header("4. FINALIZING RESULTS")
        final_status_code_py = "unknown_py_status"
        num_events_final = len(final_schedule_output_list) if final_schedule_output_list is not None else 0
        
        if num_items_after_preprocessing == 0 and len(current_run_input_scheduled_classes) == 0 :
            final_status_code_py = "success_no_input_items_for_py"
            final_status_message_py = "Python: No input items were provided for scheduling."
        elif num_items_after_preprocessing == 0 and len(current_run_input_scheduled_classes) > 0:
             final_status_code_py = "failure_all_items_filtered_by_py_preprocess"
             final_status_message_py = "Python: All input items were filtered out during preprocessing."
        elif final_schedule_output_list is not None and num_events_final > 0:
            target_items = num_items_after_preprocessing
            if num_events_final == target_items:
                final_status_code_py = "success_full_schedule_generated_py"
                final_status_message_py = f"Python: Successfully generated a full schedule with {num_events_final} events."
            else:
                final_status_code_py = "success_partial_schedule_generated_py"
                final_status_message_py = f"Python: Generated partial schedule: {num_events_final}/{target_items} events."
            if ga_best_penalty_score != float('inf'): final_status_message_py += f" GA penalty: {ga_best_penalty_score:.2f}."
        else:
            final_status_code_py = "failure_no_schedule_produced_py"
            final_status_message_py = f"Python: No schedule produced. CP: {cp_solution_metrics.get('solver_status','N/A')}."

        output_data_to_save = {
            "status": final_status_code_py,
            "message": final_status_message_py,
            "semester_id": semester_id,
            "run_type_processed": run_type_from_config,
            "metrics": {
                "input_data_summary": {
                    "num_input_items_for_run": len(current_run_input_scheduled_classes),
                    "num_items_after_preprocessing": num_items_after_preprocessing
                },
                "cp_solver_summary": cp_solution_metrics,
                "ga_solver_summary": ga_final_detailed_metrics,
                "overall_performance": {
                    "total_execution_time_seconds": None,
                    "num_events_in_final_schedule": num_events_final
                }
            },
            "final_schedule": final_schedule_output_list if final_schedule_output_list is not None else []
        }
        if run_type_from_config == "student_schedule_request":
            output_data_to_save["final_schedule_options"] = final_schedule_options_list_student if final_schedule_options_list_student is not None else []
            output_data_to_save["metrics_per_option"] = ga_final_detailed_metrics.get("metrics_per_option", []) if final_schedule_options_list_student else []


        save_final_output_and_log(output_data_to_save, script_dir, scheduler_config, DEFAULT_FINAL_OUTPUT_FILENAME)
        write_progress("Progress: 100% - Python: Results Finalized and Saved.")

    except ValueError as e_val:
        error_msg = f"Python Config/Value Error: {e_val}"
        write_progress(f"PYTHON_CRITICAL_ERROR: {error_msg}\n{traceback.format_exc()}")
        save_error_output(error_msg, "error_python_value_config", semester_id, script_dir, scheduler_config)
    except RuntimeError as e_rt:
        error_msg = f"Python Runtime Error: {e_rt}"
        write_progress(f"PYTHON_CRITICAL_ERROR: {error_msg}\n{traceback.format_exc()}")
        save_error_output(error_msg, "error_python_runtime_critical", semester_id, script_dir, scheduler_config)
    except Exception as e_unhandled:
        error_msg = f"Python Unhandled Exception: {e_unhandled}"
        tb_str = traceback.format_exc()
        write_progress(f"PYTHON_CRITICAL_ERROR: {error_msg}\n{tb_str}")
        save_error_output(f"{error_msg}\nTraceback:\n{tb_str}",
                          "error_python_unhandled_exception", semester_id, script_dir, scheduler_config)
    finally:
        overall_end_time = pytime.time()
        total_exec_time = overall_end_time - overall_start_time
        write_progress(f"Python: Total script execution time: {total_exec_time:.3f} seconds.")
        
        final_output_filename_update = scheduler_config.get("output_filename_override", DEFAULT_FINAL_OUTPUT_FILENAME)
        final_output_file_path_update = os.path.join(script_dir, "output_data", os.path.basename(final_output_filename_update))

        if os.path.exists(final_output_file_path_update):
            try:
                with open(final_output_file_path_update, 'r+', encoding='utf-8') as f_update:
                    data_to_update = json.load(f_update)
                    if "metrics" in data_to_update and "overall_performance" in data_to_update["metrics"]:
                        data_to_update["metrics"]["overall_performance"]["total_execution_time_seconds"] = round(total_exec_time, 3)
                        f_update.seek(0)
                        json.dump(data_to_update, f_update, indent=4, ensure_ascii=False)
                        f_update.truncate()
            except Exception as e_update_time:
                write_progress(f"Python WARNING: Failed to update total exec time in output: {e_update_time}")
        
        write_progress("--- PYTHON SCHEDULER SCRIPT EXECUTION FINISHED ---")

if __name__ == "__main__":
    base_dir_main = os.path.dirname(os.path.abspath(__file__))
    for subdir_check in ["input_data", "output_data", os.path.join("output_data", "logs")]:
        os.makedirs(os.path.join(base_dir_main, subdir_check), exist_ok=True)

    config_file_arg = "scheduler_input_config_default_test.json"
    if len(sys.argv) > 1:
        safe_config_filename = os.path.basename(sys.argv[1])
        if safe_config_filename != sys.argv[1]:
            print(f"PYTHON_FATAL_ERROR: Invalid config filename '{sys.argv[1]}'. Exiting.", file=sys.stderr, flush=True)
            sys.exit(1)
        config_file_arg = safe_config_filename
    else:
        default_test_config_path = os.path.join(base_dir_main, "input_data", config_file_arg)
        if not os.path.exists(default_test_config_path):
            try:
                with open(default_test_config_path, 'w', encoding='utf-8') as f_default:
                    json.dump({
                        "run_type": "student_schedule_request",
                        "student_id": "S_TEST_MAIN_001",
                        "requested_course_ids": ["BSA105501", "INE105001"],
                        "student_preferences": {"time_of_day": "morning", "max_consecutive_classes": "3"},
                        "semester_id_to_load": 1,
                        "cp_time_limit_seconds": 10.0, "ga_generations": 15, "ga_population_size": 15,
                        "progress_log_file_path_from_php": f"output_data/logs/py_direct_test_{datetime.now().strftime('%Y%m%d%H%M%S')}.log",
                        "output_filename_override": f"output_data/py_direct_test_output_{datetime.now().strftime('%Y%m%d%H%M%S')}.json"
                    }, f_default, indent=4)
                print(f"PYTHON_INFO: Created default test config: {default_test_config_path}", flush=True)
            except Exception as e_create_conf:
                 print(f"PYTHON_WARNING: Could not create default test config: {e_create_conf}", file=sys.stderr, flush=True)

    try:
        run_scheduler(config_filename_from_php=config_file_arg)
    except SystemExit: raise
    except Exception as e_main_run:
        fatal_msg = f"MAIN_SOLVER_FATAL_ERROR (top level): {e_main_run}"
        print(fatal_msg, file=sys.stderr, flush=True)
        traceback.print_exc(file=sys.stderr)
        script_dir_fatal = os.path.dirname(os.path.abspath(__file__))
        cfg_fatal = scheduler_config if 'scheduler_config' in globals() and scheduler_config else {}
        save_error_output(f"{fatal_msg}\n{traceback.format_exc()}",
                          "error_python_fatal_script_level",
                          cfg_fatal.get("semester_id_to_load"),
                          script_dir_fatal, cfg_fatal)
        sys.exit(1)