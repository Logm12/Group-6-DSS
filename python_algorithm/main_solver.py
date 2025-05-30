import json
import os
import sys
import time as pytime
import traceback
import copy 
from datetime import datetime 
from typing import List, Dict, Tuple, Any, Optional, Set

# --- Đảm bảo thư mục hiện tại nằm trong sys.path ---
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

# --- Biến global để lưu trữ cấu hình ---
scheduler_config: Dict[str, Any] = {}

# --- Module Imports with Error Handling ---
try:
    from data_loader import load_all_data
    # Không print ở đây nữa, sẽ dùng write_progress
except ImportError as e_imp_dl:
    # Ghi lỗi nghiêm trọng ra stderr và cố gắng ghi vào log nếu có thể
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    error_msg_critical = f"[{timestamp}] MAIN_SOLVER_CRITICAL: Error importing data_loader: {e_imp_dl}\n{traceback.format_exc()}"
    print(error_msg_critical, file=sys.stderr, flush=True)
    # Cố gắng ghi vào log nếu _PROGRESS_LOG_FILE_PATH đã được set sớm (khó khả thi ở đây)
    sys.exit(1)

try:
    from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json, DEFAULT_SETTINGS
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


# --- Constants and Global Variables ---
DEFAULT_CP_TIME_LIMIT_SECONDS = 30.0 
DEFAULT_GA_POPULATION_SIZE = 30
DEFAULT_GA_GENERATIONS = 50 
DEFAULT_GA_CROSSOVER_RATE = 0.8
DEFAULT_GA_MUTATION_RATE = 0.2 
DEFAULT_GA_TOURNAMENT_SIZE = 3 

# Tên file input config mặc định, nhưng sẽ được ghi đè bởi PHP với run_id
# PHP sẽ truyền tên file input cụ thể qua command hoặc một cách khác,
# hoặc Python sẽ đọc tên file input từ một file cố định do PHP tạo.
# Hiện tại, PHP ghi nội dung config vào một file có tên cố định (hoặc có run_id).
# PHP_INPUT_CONFIG_FILENAME được dùng để Python biết file nào cần đọc trong input_data.
# PHP sẽ chịu trách nhiệm tạo file này với tên chính xác.
# Ví dụ: input_data/scheduler_input_config_Ymd_His_uniqid.json
# Python sẽ nhận tên file này từ PHP, hoặc PHP sẽ luôn ghi vào "scheduler_input_config.json"
# và Python đọc file đó. Để đơn giản, PHP sẽ tạo file có tên cố định theo run_id.

# PHP_INPUT_CONFIG_FILENAME = "scheduler_input_config.json" # Sẽ được thay bằng tên file động từ PHP
FINAL_OUTPUT_FILENAME = "final_schedule_output.json" # Tên mặc định, sẽ được override
CP_DEBUG_FILENAME = "cp_intermediate_solution.json" # Tên mặc định, có thể thêm run_id nếu cần

_PROGRESS_LOG_FILE_PATH: Optional[str] = None 

# --- Utility Functions ---
def write_progress(message: str):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]
    log_line = f"[{timestamp}] PYTHON_PROGRESS: {message}"
    print(log_line, flush=True) 

    global _PROGRESS_LOG_FILE_PATH
    if _PROGRESS_LOG_FILE_PATH:
        try:
            # Đường dẫn _PROGRESS_LOG_FILE_PATH đã là đường dẫn tuyệt đối hoặc tương đối từ script_dir
            log_dir = os.path.dirname(_PROGRESS_LOG_FILE_PATH)
            if log_dir and not os.path.exists(log_dir):
                os.makedirs(log_dir, exist_ok=True)
            with open(_PROGRESS_LOG_FILE_PATH, "a", encoding="utf-8") as f_log:
                f_log.write(log_line + "\n")
        except Exception as e_log_write:
            print(f"[{timestamp}] PYTHON_WARNING: Could not write to progress file '{_PROGRESS_LOG_FILE_PATH}': {e_log_write}", file=sys.stderr, flush=True)

def print_stage_header(title: str):
    separator = "=" * 80
    write_progress(separator)
    write_progress(f"===== {title.upper():^70} =====")
    write_progress(separator)

def load_scheduler_config_from_php(base_script_dir: str, config_filename: str) -> dict:
    # config_filename là tên file (ví dụ: scheduler_input_config_XYZ.json) nằm trong input_data/
    config_file_path = os.path.join(base_script_dir, "input_data", config_filename)
    
    default_config = {
        "semester_id_to_load": None,
        "cp_time_limit_seconds": DEFAULT_CP_TIME_LIMIT_SECONDS,
        "ga_population_size": DEFAULT_GA_POPULATION_SIZE,
        "ga_generations": DEFAULT_GA_GENERATIONS,
        "ga_crossover_rate": DEFAULT_GA_CROSSOVER_RATE,
        "ga_mutation_rate": DEFAULT_GA_MUTATION_RATE,
        "ga_tournament_size": DEFAULT_GA_TOURNAMENT_SIZE,
        "ga_allow_hard_constraint_violations": False,
        "priority_student_clash": "medium",
        "priority_lecturer_load_break": "medium",
        "priority_classroom_util": "medium",
        "progress_log_file_path_from_php": None, # Đường dẫn tương đối từ thư mục python_algorithm
        "output_filename_override": None # Đường dẫn tương đối từ thư mục python_algorithm/output_data/
    }
    
    loaded_config = default_config.copy()

    if os.path.exists(config_file_path):
        try:
            with open(config_file_path, 'r', encoding="utf-8") as f:
                php_config = json.load(f)
            loaded_config.update(php_config)
        except json.JSONDecodeError as e_json:
            # Ghi lỗi này vào progress log nếu có thể, hoặc stderr
            error_msg = f"Error decoding JSON from {config_file_path}: {e_json}. Using defaults where possible."
            write_progress(f"CRITICAL_CONFIG_ERROR: {error_msg}")
            print(f"PYTHON_CRITICAL: {error_msg}", file=sys.stderr, flush=True)

        except Exception as e_conf_read:
            error_msg = f"Error reading config file {config_file_path}: {e_conf_read}. Using defaults."
            write_progress(f"CRITICAL_CONFIG_ERROR: {error_msg}")
            print(f"PYTHON_CRITICAL: {error_msg}", file=sys.stderr, flush=True)
    else:
        error_msg = f"Config file {config_file_path} not found. Using default solver parameters."
        write_progress(f"CONFIG_WARNING: {error_msg}")
        # Không print ra stderr trừ khi đây là lỗi nghiêm trọng không thể chạy tiếp
    
    return loaded_config

def save_final_output_and_log(data: dict, base_script_dir: str, config: Dict[str, Any]):
    # Sử dụng tên file output từ config nếu có, nếu không dùng tên mặc định
    output_filename = config.get("output_filename_override", FINAL_OUTPUT_FILENAME)
    output_file_path = os.path.join(base_script_dir, output_filename) # output_filename từ config đã bao gồm output_data/
    
    try:
        # Đảm bảo thư mục output tồn tại
        output_dir = os.path.dirname(output_file_path)
        if output_dir and not os.path.exists(output_dir):
            os.makedirs(output_dir, exist_ok=True)
            write_progress(f"Created output directory: {output_dir}")

        save_output_data_to_json(data, output_file_path) # Hàm từ utils.py
        write_progress(f"Output data saved to: {output_file_path}") # Log đường dẫn đầy đủ
    except Exception as e_save_final:
        write_progress(f"MAIN_SOLVER_ERROR: Failed to save final output to {output_file_path}: {e_save_final}")
        print(f"PYTHON_ERROR: Failed to save final output to {output_file_path}: {e_save_final}", file=sys.stderr, flush=True)


def save_error_output(message: str, status_code: str, semester_id: Optional[int], base_script_dir: str, config: Dict[str, Any]):
    error_output = {
        "status": status_code, 
        "message": str(message), 
        "semester_id": semester_id, 
        "final_schedule": [],
        "metrics": {}
    }
    save_final_output_and_log(error_output, base_script_dir, config)
    write_progress(f"ERROR_OUTPUT_SAVED: {status_code} - {message}.")


# --- Main Scheduler Execution ---
def run_scheduler(config_filename_from_php: str): # Nhận tên file config từ argument
    global _PROGRESS_LOG_FILE_PATH 
    global scheduler_config # Để các hàm khác có thể truy cập

    script_dir = os.path.dirname(os.path.abspath(__file__)) # Thư mục python_algorithm
    overall_start_time = pytime.time()
    
    semester_id: Optional[int] = None
    num_input_scheduled_classes = 0
    num_items_after_preprocessing = 0
    cp_attempted_items = 0
    cp_solution_metrics: Dict[str, Any] = {}
    ga_best_penalty_score = float('inf')
    ga_final_detailed_metrics: Dict[str, Any] = {}
    final_schedule_result: Optional[List[Dict[str, Any]]] = None

    # 1. Load Configuration
    # Sử dụng config_filename_from_php thay vì PHP_INPUT_CONFIG_FILENAME cố định
    scheduler_config = load_scheduler_config_from_php(script_dir, config_filename_from_php)
    write_progress(f"PYTHON DEBUG: Effective config being used: {json.dumps(scheduler_config, indent=2)}")
    # Set up progress log file path (đường dẫn này là tương đối từ script_dir)
    relative_log_path = scheduler_config.get("progress_log_file_path_from_php")
    if relative_log_path and isinstance(relative_log_path, str):
        # Đường dẫn này đã bao gồm output_data/logs/ từ PHP, nên chỉ cần join với script_dir
        _PROGRESS_LOG_FILE_PATH = os.path.join(script_dir, relative_log_path)
        try:
            log_dir_to_create = os.path.dirname(_PROGRESS_LOG_FILE_PATH)
            if log_dir_to_create and not os.path.exists(log_dir_to_create):
                os.makedirs(log_dir_to_create, exist_ok=True)
            # File log sẽ được mở ở chế độ 'a' (append) bởi write_progress,
            # nhưng nếu PHP muốn mỗi lần chạy là file mới, PHP cần đảm bảo tên file log là duy nhất.
            # Hoặc Python có thể xóa file cũ nếu có lệnh từ config.
            # Hiện tại, PHP tạo tên file log duy nhất, nên ta chỉ cần append.
            # Ghi dòng khởi tạo log đầu tiên (để file được tạo ngay)
            with open(_PROGRESS_LOG_FILE_PATH, "w", encoding="utf-8") as f_log_init: # "w" để ghi đè nếu file đã tồn tại từ lần chạy trước (ít khả năng với tên unique)
                 f_log_init.write(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]}] PYTHON_PROGRESS: Progress log initialized by Python.\n")
        except Exception as e_log_setup:
            _PROGRESS_LOG_FILE_PATH = None
            print(f"PYTHON_WARNING: Failed to set up Python progress log file at '{_PROGRESS_LOG_FILE_PATH}': {e_log_setup}", file=sys.stderr, flush=True)
    
    write_progress("Progress: 0% complete - Python Scheduler Process Started.")
    print_stage_header("0. PYTHON: LOADING SCHEDULER CONFIGURATION")
    write_progress(f"Python: Effective Configuration (excluding log path): { {k:v for k,v in scheduler_config.items() if k not in ['progress_log_file_path_from_php', 'output_filename_override']} }")
    if scheduler_config.get("output_filename_override"):
        write_progress(f"Python: Output will be saved to: {scheduler_config['output_filename_override']}")
    if _PROGRESS_LOG_FILE_PATH:
        write_progress(f"Python: Progress log will be written to: {_PROGRESS_LOG_FILE_PATH}")


    try:
        semester_id_str = scheduler_config.get("semester_id_to_load")
        if semester_id_str is None:
            raise ValueError("'semester_id_to_load' is missing in configuration.")
        semester_id = int(semester_id_str)

        cp_time_limit = float(scheduler_config.get("cp_time_limit_seconds", DEFAULT_CP_TIME_LIMIT_SECONDS))
        ga_pop_size = int(scheduler_config.get("ga_population_size", DEFAULT_GA_POPULATION_SIZE))
        ga_gens = int(scheduler_config.get("ga_generations", DEFAULT_GA_GENERATIONS))
        ga_crossover_r = float(scheduler_config.get("ga_crossover_rate", DEFAULT_GA_CROSSOVER_RATE))
        ga_mutation_r = float(scheduler_config.get("ga_mutation_rate", DEFAULT_GA_MUTATION_RATE))
        ga_tournament_s = int(scheduler_config.get("ga_tournament_size", DEFAULT_GA_TOURNAMENT_SIZE))
        ga_allow_hc_violations_flag = str(scheduler_config.get("ga_allow_hard_constraint_violations", "false")).lower() == 'true'
        
        priority_settings_from_config = {
            "student_clash": scheduler_config.get("priority_student_clash", "medium"),
            "lecturer_load_break": scheduler_config.get("priority_lecturer_load_break", "medium"),
            "classroom_util": scheduler_config.get("priority_classroom_util", "medium")
        }
        write_progress(f"Python: Parameters Parsed. SemesterID={semester_id}")
        write_progress("Progress: 5% complete - Configuration loaded.")

        # 2. Load and Preprocess Data
        print_stage_header(f"1. PYTHON: LOADING & PREPROCESSING DATA - SEMESTER: {semester_id}")
        loaded_data = load_all_data(semester_id_to_load=semester_id) # data_loader.py có thể gọi write_progress
        if not any(loaded_data):
            raise RuntimeError(f"Data loading failed for semester {semester_id}.")
        
        input_sc_list, input_instr_list, input_room_list, input_tslot_list, input_stud_list, input_course_cat = loaded_data
        num_input_scheduled_classes = len(input_sc_list)

        if not (input_instr_list and input_room_list and input_tslot_list and input_course_cat):
            raise RuntimeError(f"Essential catalog data empty for semester {semester_id} after loading.")
        write_progress(f"Python: Raw data loaded: {num_input_scheduled_classes} SchedClasses.")
        write_progress("Progress: 15% complete - Data loaded. Starting preprocessing.")

        processed_data_dict = preprocess_data_for_cp_and_ga(
            input_scheduled_classes=input_sc_list, input_courses_catalog=input_course_cat,
            input_instructors=input_instr_list, input_classrooms=input_room_list,
            input_timeslots=input_tslot_list, input_students=input_stud_list, 
            semester_id_for_settings=semester_id, priority_settings=priority_settings_from_config
        ) # utils.py có thể gọi write_progress
        if not processed_data_dict: 
            raise RuntimeError(f"Preprocessing returned no data for semester {semester_id}.")
        
        num_items_after_preprocessing = len(processed_data_dict.get("scheduled_items", {}))
        # ... (log warning nếu cần) ...
        write_progress(f"Python: Data preprocessed. {num_items_after_preprocessing} items for scheduling.")
        write_progress("Progress: 25% complete - Preprocessing finished.")

        # 3. CP-SAT Stage
        if num_items_after_preprocessing > 0:
            print_stage_header("2. PYTHON: SOLVING HARD CONSTRAINTS WITH CP-SAT")
            write_progress(f"Python: Starting CP-SAT. Time limit: {cp_time_limit}s.")
            cp_solver = CourseSchedulingCPSAT(processed_data_dict, progress_logger=write_progress)
            # cp_module.py nên gọi write_progress("Progress: X%") ở các điểm khác nhau
            cp_schedule_output_list, cp_solution_metrics = cp_solver.solve(time_limit_seconds=cp_time_limit)
            cp_attempted_items = cp_solution_metrics.get("num_items_targeted_for_cp_solver", 0)
            # ... (log kết quả CP) ...
            if cp_schedule_output_list:
                write_progress(f"CP-SAT found solution with {len(cp_schedule_output_list)} events. Status: {cp_solution_metrics.get('solver_status', 'N/A')}.")
                # Lưu CP debug output với tên file có run_id (nếu cần)
                cp_debug_filename_final = scheduler_config.get("output_filename_override", FINAL_OUTPUT_FILENAME).replace("final_schedule_output", "cp_debug_solution")
                save_final_output_and_log({"cp_metrics": cp_solution_metrics, "cp_schedule": cp_schedule_output_list}, script_dir, {"output_filename_override": cp_debug_filename_final}) # Truyền config vào save_final
            else:
                write_progress(f"CP-SAT found NO solutions. Status: {cp_solution_metrics.get('solver_status', 'N/A')}.")
            write_progress("Progress: 50% complete - CP-SAT Stage Finished.")
        else:
            write_progress("Python: Skipping CP-SAT stage: No schedulable items after preprocessing.")
            cp_solution_metrics = {"solver_status": "NOT_RUN_NO_ITEMS"}
            write_progress("Progress: 50% complete - CP-SAT Stage Skipped.")


        # 4. GA Stage
        initial_schedules_for_ga_input = []
        if cp_schedule_output_list:
            initial_schedules_for_ga_input.append(copy.deepcopy(cp_schedule_output_list)) 
        
        if num_items_after_preprocessing > 0 and initial_schedules_for_ga_input:
            print_stage_header("3. PYTHON: OPTIMIZING SOFT CONSTRAINTS WITH GENETIC ALGORITHM")
            write_progress(f"Python: Starting GA. Pop: {ga_pop_size}, Gens: {ga_gens}.")
            ga_solver = GeneticAlgorithmScheduler(
                processed_data=processed_data_dict, 
                initial_population_from_cp=initial_schedules_for_ga_input,
                population_size=ga_pop_size, generations=ga_gens,
                crossover_rate=ga_crossover_r, mutation_rate=ga_mutation_r, tournament_size=ga_tournament_s,
                allow_hard_constraint_violations_in_ga=ga_allow_hc_violations_flag,
                progress_logger=write_progress # ga_module.py sẽ gọi write_progress với %
            )
            final_schedule_result, ga_best_penalty_score, ga_final_detailed_metrics = ga_solver.run()
            # ... (log kết quả GA) ...
            write_progress("Progress: 95% complete - GA Stage Finished.")
        elif cp_schedule_output_list:
            write_progress("Python: Using CP-SAT solution as GA stage was skipped or had no valid input.")
            final_schedule_result = cp_schedule_output_list
            ga_best_penalty_score = float('inf') 
            ga_final_detailed_metrics = {}
            write_progress("Progress: 95% complete - GA Stage Skipped.")
        else:
            write_progress("Python: No solution from CP-SAT, and GA stage cannot run. No schedule generated.")
            final_schedule_result = None
            ga_best_penalty_score = float('inf')
            ga_final_detailed_metrics = {}
            write_progress("Progress: 95% complete - GA Stage Skipped (No Input).")

        # 5. Finalizing and Saving Output
        print_stage_header("4. PYTHON: FINALIZING AND SAVING RESULTS")
        # ... (logic xác định final_status_msg, final_status_code giữ nguyên) ...
        final_status_msg = "Scheduler run completed by Python."
        final_status_code = "unknown_final_status_py"
        num_events_in_final_sched = len(final_schedule_result) if final_schedule_result else 0

        if num_items_after_preprocessing == 0:
            final_status_code = "success_no_items_to_schedule_py"
            final_status_msg = "Python: No items were available/valid for scheduling after preprocessing."
        elif final_schedule_result:
            cp_target = cp_solution_metrics.get("num_items_targeted_for_cp_solver", 0) if cp_solution_metrics else num_items_after_preprocessing
            if num_events_in_final_sched == cp_target and cp_target > 0 :
                final_status_code = "success_full_schedule_generated_py"
            elif num_events_in_final_sched > 0:
                final_status_code = "success_partial_schedule_generated_py"
            else: 
                final_status_code = "failure_no_solution_found_by_pipeline_py"
            final_status_msg = f"Python: Schedule generation status: {final_status_code}. Events: {num_events_in_final_sched}."
            if ga_best_penalty_score != float('inf'): final_status_msg += f" GA penalty: {ga_best_penalty_score:.2f}."

        else: 
            final_status_code = "failure_pipeline_did_not_produce_schedule_py"
            final_status_msg = f"Python: The scheduling pipeline did not produce any schedule. CP status: {cp_solution_metrics.get('solver_status','N/A')}."
        
        write_progress(f"Python: Final Outcome: {final_status_code} - {final_status_msg}")

        output_data_final = {
            "status": final_status_code, "message": final_status_msg, "semester_id": semester_id,
            "metrics": {
                "input_data_summary": {
                    "num_raw_scheduled_classes_from_db": num_input_scheduled_classes,
                    "num_items_after_preprocessing": num_items_after_preprocessing
                },
                "cp_solver_summary": cp_solution_metrics,
                "ga_solver_summary": {
                    "final_penalty_score": ga_best_penalty_score if ga_best_penalty_score != float('inf') else None,
                    "detailed_soft_constraint_metrics": ga_final_detailed_metrics.get("soft_constraints_details", {}),
                    "ga_hard_constraints_violated_in_final": ga_final_detailed_metrics.get("hard_constraints_violated_in_final_schedule", None)
                },
                "overall_performance": {
                    "total_execution_time_seconds": None, 
                    "num_events_in_final_schedule": num_events_in_final_sched
                }
            },
            "final_schedule": final_schedule_result if final_schedule_result else []
        }
        save_final_output_and_log(output_data_final, script_dir, scheduler_config) # Truyền config vào
        write_progress("Progress: 100% complete - Python: Results finalized and saved.")

    except ValueError as e_val: 
        error_message = f"Python Configuration Error: {e_val}"
        write_progress(f"PYTHON_CRITICAL_ERROR: {error_message}")
        save_error_output(error_message, "error_invalid_config_type_py", scheduler_config.get("semester_id_to_load"), script_dir, scheduler_config)
    except RuntimeError as e_rt: 
        error_message = f"Python Runtime Error: {e_rt}"
        write_progress(f"PYTHON_CRITICAL_ERROR: {error_message}")
        save_error_output(error_message, "error_runtime_critical_py", semester_id, script_dir, scheduler_config)
    except Exception as e_unhandled:
        error_message = f"Python Unhandled Exception: {e_unhandled}"
        tb_str = traceback.format_exc()
        write_progress(f"PYTHON_CRITICAL_ERROR: {error_message}\n{tb_str}")
        save_error_output(f"{error_message}\nFull Traceback:\n{tb_str}", "error_unhandled_exception_main_py", semester_id, script_dir, scheduler_config)
    finally:
        overall_end_time = pytime.time()
        total_time = overall_end_time - overall_start_time
        write_progress(f"Python: Total script execution time: {total_time:.3f} seconds.")
        
        output_filename_final = scheduler_config.get("output_filename_override", FINAL_OUTPUT_FILENAME)
        output_file_path_final_check = os.path.join(script_dir, output_filename_final) # Đã bao gồm output_data/

        if os.path.exists(output_file_path_final_check):
            try:
                with open(output_file_path_final_check, 'r+', encoding='utf-8') as f_update:
                    data_to_update = json.load(f_update)
                    if "metrics" in data_to_update and "overall_performance" in data_to_update["metrics"]:
                        data_to_update["metrics"]["overall_performance"]["total_execution_time_seconds"] = round(total_time, 3)
                        f_update.seek(0)
                        json.dump(data_to_update, f_update, indent=4, ensure_ascii=False)
                        f_update.truncate()
                    # else: write_progress("Python: Could not update total execution time in final output: metrics structure unexpected.")
            except Exception as e_update_time:
                write_progress(f"Python WARNING: Could not update total execution time in {output_filename_final}: {e_update_time}")
        write_progress("--- PYTHON: END OF SCHEDULER SCRIPT EXECUTION ---")

if __name__ == "__main__":
    # Khi chạy trực tiếp, cần có file config mẫu hoặc lấy từ argument
    # sys.argv[1] sẽ là tên file config được truyền từ PHP qua call_python_scheduler
    # (nếu PHP được cấu hình để truyền nó như một argument)
    # Hoặc, PHP sẽ tạo một file config với tên cố định có run_id, và Python đọc file đó.
    
    # Dòng này để đảm bảo các thư mục tồn tại khi chạy test Python trực tiếp
    base_dir_main = os.path.dirname(os.path.abspath(__file__))
    for subdir_name_main in ["input_data", "output_data", os.path.join("output_data", "logs")]: 
        path_to_create_main = os.path.join(base_dir_main, subdir_name_main)
        if not os.path.exists(path_to_create_main):
            try: os.makedirs(path_to_create_main, exist_ok=True)
            except Exception: pass # Bỏ qua lỗi nếu không tạo được khi test

    # Đây là phần quan trọng: Python script sẽ nhận tên file config làm argument
    # khi được gọi bởi PHP qua call_python_scheduler (nếu PHP được sửa để truyền)
    # Hoặc, PHP sẽ ghi vào một file cố định có run_id, và Python đọc nó.
    # Hàm `call_python_scheduler` trong `functions.php` đã ghi nội dung JSON
    # vào một file có tên là `$python_input_filename` (ví dụ: scheduler_input_config_XYZ.json)
    # trong thư mục `python_algorithm/input_data/`.
    # Vậy `main_solver.py` cần biết tên file đó.

    # Giả sử PHP đã tạo file scheduler_input_config_XYZ.json
    # và tên file đó được truyền vào như một argument dòng lệnh cho main_solver.py
    if len(sys.argv) > 1:
        config_file_name_arg = sys.argv[1] 
        # Cần validate config_file_name_arg ở đây để tránh path traversal nếu nó từ nguồn không tin cậy
        # Ví dụ: chỉ cho phép tên file, không cho phép ../
        safe_config_file_name = os.path.basename(config_file_name_arg)
        if safe_config_file_name != config_file_name_arg:
            print("PYTHON_FATAL: Invalid config filename argument (potential path traversal).", file=sys.stderr)
            sys.exit(1)
        
        print(f"PYTHON_INFO: Received config filename argument: {safe_config_file_name}", flush=True)
    else:
        # Fallback nếu không có argument (ví dụ khi chạy test Python trực tiếp)
        # Tạo một file config mẫu để test nếu nó không tồn tại
        safe_config_file_name = "scheduler_input_config_default_test.json" # File config mặc định để test
        default_test_config_path = os.path.join(base_dir_main, "input_data", safe_config_file_name)
        if not os.path.exists(default_test_config_path):
            print(f"PYTHON_INFO: No config filename argument. Creating a default test config: {default_test_config_path}", flush=True)
            try:
                with open(default_test_config_path, 'w') as f_test_conf:
                    # Cấu hình tối thiểu để chạy test
                    json.dump({
                        "semester_id_to_load": 1, # ID học kỳ tồn tại trong DB test
                        "cp_time_limit_seconds": 10.0,
                        "ga_generations": 10, # Giảm để chạy test nhanh
                        "ga_population_size": 10,
                        "progress_log_file_path_from_php": "output_data/logs/default_test_progress.log",
                        "output_filename_override": "output_data/default_test_output.json"
                    }, f_test_conf, indent=4)
            except Exception as e_create_test_conf:
                 print(f"PYTHON_WARNING: Could not create default test config: {e_create_test_conf}", file=sys.stderr, flush=True)
        else:
             print(f"PYTHON_INFO: No config filename argument. Using existing default test config: {default_test_config_path}", flush=True)


    try:
        run_scheduler(config_filename_from_php=safe_config_file_name) # Chạy với tên file config
    except Exception as e_main_script_level: 
        # ... (xử lý lỗi fatal giữ nguyên) ...
        fatal_error_msg = f"MAIN_SOLVER_FATAL_OUTSIDE_RUN_SCHEDULER: {e_main_script_level}"
        print(fatal_error_msg, file=sys.stderr, flush=True)
        traceback.print_exc(file=sys.stderr)
        # Cố gắng lưu lỗi này vào file output (nếu có thể)
        try:
            script_dir_for_fatal = os.path.dirname(os.path.abspath(__file__))
            # Cố gắng đọc output_filename_override từ config nếu có thể, nếu không dùng default
            output_fn_fatal = FINAL_OUTPUT_FILENAME
            if 'scheduler_config' in globals() and scheduler_config.get('output_filename_override'):
                output_fn_fatal = scheduler_config['output_filename_override']
            
            output_path_fatal = os.path.join(script_dir_for_fatal, output_fn_fatal)
            output_dir_for_fatal_create = os.path.dirname(output_path_fatal)

            if not os.path.exists(output_dir_for_fatal_create): os.makedirs(output_dir_for_fatal_create, exist_ok=True)
            
            with open(output_path_fatal, 'w', encoding='utf-8') as f_fatal:
                json.dump({ 
                    "status": "error_fatal_unhandled_in_script_main_block_py",
                    "message": fatal_error_msg + "\nTraceback:\n" + traceback.format_exc(),
                    "semester_id": None, 
                    "final_schedule": [],
                    "metrics": {}
                }, f_fatal, indent=4, ensure_ascii=False)
        except Exception: pass # Bỏ qua nếu không lưu được