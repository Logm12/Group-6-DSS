import json
import os
import sys
import time as pytime
import traceback
import copy 
from datetime import datetime 
from typing import List, Dict, Tuple, Any, Optional, Set # <<<< THÊM DÒNG NÀY

# --- Đảm bảo thư mục hiện tại nằm trong sys.path ---
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

# --- Module Imports with Error Handling ---
# (Giữ nguyên phần import các module khác)
try:
    from data_loader import load_all_data
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_INFO: data_loader imported.")
except ImportError as e_imp_dl:
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_CRITICAL: Error importing data_loader: {e_imp_dl}\n{traceback.format_exc()}", file=sys.stderr)
    sys.exit(1)

try:
    from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json, DEFAULT_SETTINGS
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_INFO: utils imported.")
except ImportError as e_imp_ut:
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_CRITICAL: Error importing utils: {e_imp_ut}\n{traceback.format_exc()}", file=sys.stderr)
    sys.exit(1)

try:
    from cp_module import CourseSchedulingCPSAT
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_INFO: cp_module imported.")
except ImportError as e_imp_cp:
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_CRITICAL: Error importing cp_module: {e_imp_cp}\n{traceback.format_exc()}", file=sys.stderr)
    sys.exit(1)

try:
    from ga_module import GeneticAlgorithmScheduler
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_INFO: ga_module imported.")
except ImportError as e_imp_ga:
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_CRITICAL: Error importing ga_module: {e_imp_ga}\n{traceback.format_exc()}", file=sys.stderr)
    sys.exit(1)


# --- Constants and Global Variables ---
DEFAULT_CP_TIME_LIMIT_SECONDS = 30.0 
DEFAULT_GA_POPULATION_SIZE = 30
DEFAULT_GA_GENERATIONS = 50 
DEFAULT_GA_CROSSOVER_RATE = 0.8
DEFAULT_GA_MUTATION_RATE = 0.2 
DEFAULT_GA_TOURNAMENT_SIZE = 3 

PHP_INPUT_CONFIG_FILENAME = "scheduler_input_config.json" 
FINAL_OUTPUT_FILENAME = "final_schedule_output.json"    
CP_DEBUG_FILENAME = "cp_intermediate_solution.json" 
_PROGRESS_LOG_FILE_PATH: Optional[str] = None # Sẽ được set từ config của PHP # <<<< LỖI Ở ĐÂY ĐÃ ĐƯỢC 

# --- Utility Functions ---
def write_progress(message: str):
    """Ghi thông điệp ra stdout và file log (nếu được cấu hình)."""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3] # Thêm milliseconds
    log_line = f"[{timestamp}] PYTHON_PROGRESS: {message}" # Thêm prefix để phân biệt với log của solver
    print(log_line, flush=True) # flush=True để PHP thấy ngay

    global _PROGRESS_LOG_FILE_PATH
    if _PROGRESS_LOG_FILE_PATH:
        try:
            log_dir = os.path.dirname(_PROGRESS_LOG_FILE_PATH)
            if log_dir and not os.path.exists(log_dir):
                os.makedirs(log_dir, exist_ok=True)
            with open(_PROGRESS_LOG_FILE_PATH, "a", encoding="utf-8") as f_log:
                f_log.write(log_line + "\n")
        except Exception as e_log_write:
            # In lỗi này ra stderr để không lẫn vào output JSON chuẩn cho PHP
            print(f"[{timestamp}] PYTHON_WARNING: Could not write to progress file '{_PROGRESS_LOG_FILE_PATH}': {e_log_write}", file=sys.stderr, flush=True)

def print_stage_header(title: str):
    """In tiêu đề cho một giai đoạn xử lý."""
    separator = "=" * 80
    write_progress(separator)
    write_progress(f"===== {title.upper():^70} =====") # Căn giữa tiêu đề
    write_progress(separator)

def load_scheduler_config_from_php(base_script_dir: str) -> dict:
    """Đọc cấu hình do PHP truyền qua file JSON."""
    config_file_path = os.path.join(base_script_dir, "input_data", PHP_INPUT_CONFIG_FILENAME)
    
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
        "priority_lecturer_load_break": "medium", # Mặc định là medium
        "priority_classroom_util": "medium",
        "progress_log_file_path_from_php": None 
    }
    
    loaded_config = default_config.copy() # Bắt đầu với default

    if os.path.exists(config_file_path):
        try:
            with open(config_file_path, 'r', encoding="utf-8") as f:
                php_config = json.load(f)
            loaded_config.update(php_config) # Ghi đè default bằng giá trị từ PHP nếu có
        except json.JSONDecodeError as e_json:
            # Log lỗi này sau khi _PROGRESS_LOG_FILE_PATH được thiết lập từ loaded_config
            # (nếu progress_log_file_path_from_php vẫn có thể đọc được từ default hoặc file lỗi)
            # Tạm thời chỉ in ra stderr nếu _PROGRESS_LOG_FILE_PATH chưa có
            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] PYTHON_WARNING: Error decoding JSON from {config_file_path}: {e_json}. Using defaults where possible.", file=sys.stderr, flush=True)
        except Exception as e_conf_read:
            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] PYTHON_WARNING: Error reading config file {config_file_path}: {e_conf_read}. Using defaults.", file=sys.stderr, flush=True)
    # else: Sẽ log sau khi _PROGRESS_LOG_FILE_PATH được thiết lập
    
    return loaded_config

def save_final_output_and_log(data: dict, base_script_dir: str, filename: str = FINAL_OUTPUT_FILENAME):
    """Lưu output cuối cùng ra file JSON và ghi log."""
    output_file_path = os.path.join(base_script_dir, "output_data", filename)
    try:
        save_output_data_to_json(data, output_file_path) # Hàm từ utils.py
        write_progress(f"Output data saved to: {filename} in output_data directory.")
    except Exception as e_save_final:
        write_progress(f"MAIN_SOLVER_ERROR: Failed to save final output to {output_file_path}: {e_save_final}")

def save_error_output(message: str, status_code: str, semester_id: Optional[int], base_script_dir: str):
    """Lưu thông điệp lỗi ra file JSON output chuẩn."""
    error_output = {
        "status": status_code, 
        "message": str(message), 
        "semester_id": semester_id, 
        "final_schedule": [],
        "metrics": {} # Thêm metrics rỗng
    }
    save_final_output_and_log(error_output, base_script_dir) # Sử dụng hàm chung để lưu
    write_progress(f"ERROR_OUTPUT_SAVED: {status_code} - {message}.")


# --- Main Scheduler Execution ---
def run_scheduler():
    global _PROGRESS_LOG_FILE_PATH 
    script_dir = os.path.dirname(os.path.abspath(__file__))
    overall_start_time = pytime.time()
    # Khởi tạo các biến sẽ dùng trong output cuối cùng hoặc khối finally
    semester_id: Optional[int] = None
    num_input_scheduled_classes = 0
    num_items_after_preprocessing = 0
    cp_attempted_items = 0
    cp_solution_metrics: Dict[str, Any] = {}
    ga_best_penalty_score = float('inf')
    ga_final_detailed_metrics: Dict[str, Any] = {}
    final_schedule_result: Optional[List[Dict[str, Any]]] = None

    # 1. Load Configuration from PHP (được gọi trước để set _PROGRESS_LOG_FILE_PATH)
    scheduler_config = load_scheduler_config_from_php(script_dir)
    
    # Set up progress log file path
    relative_log_path_from_php = scheduler_config.get("progress_log_file_path_from_php")
    if relative_log_path_from_php and isinstance(relative_log_path_from_php, str):
        # Đảm bảo đường dẫn là an toàn, chuẩn hóa và nằm trong thư mục con của script_dir
        # Ví dụ: "output_data/logs/progress.log"
        normalized_path = os.path.normpath(os.path.join(script_dir, relative_log_path_from_php))
        if normalized_path.startswith(script_dir): # Chỉ cho phép ghi trong thư mục con
            _PROGRESS_LOG_FILE_PATH = normalized_path
            try:
                log_dir_to_create = os.path.dirname(_PROGRESS_LOG_FILE_PATH)
                if log_dir_to_create and not os.path.exists(log_dir_to_create):
                    os.makedirs(log_dir_to_create, exist_ok=True)
                # Ghi đè file log mỗi lần chạy mới
                with open(_PROGRESS_LOG_FILE_PATH, "w", encoding="utf-8") as f_log_init:
                    f_log_init.write(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]}] PYTHON_PROGRESS: Progress log initialized.\n")
            except Exception as e_log_setup:
                _PROGRESS_LOG_FILE_PATH = None # Fallback nếu không tạo/ghi được
                print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] PYTHON_WARNING: Failed to set up progress log file at '{normalized_path}': {e_log_setup}", file=sys.stderr, flush=True)
        else:
            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] PYTHON_WARNING: Invalid log path '{relative_log_path_from_php}' (resolved to '{normalized_path}'). Must be within script directory. Logging to stdout only.", file=sys.stderr, flush=True)
            _PROGRESS_LOG_FILE_PATH = None
    
    write_progress("Python Scheduler Process Started.") # Log đầu tiên sau khi có thể đã set _PROGRESS_LOG_FILE_PATH
    print_stage_header("0. LOADING SCHEDULER CONFIGURATION")
    
    config_file_path_log_check = os.path.join(script_dir, "input_data", PHP_INPUT_CONFIG_FILENAME)
    if not os.path.exists(config_file_path_log_check):
        write_progress(f"WARNING: Config file '{PHP_INPUT_CONFIG_FILENAME}' not found at '{config_file_path_log_check}'. Using default solver parameters.")
    # Log config sau khi đã có write_progress
    write_progress(f"Effective Configuration (excluding log path): { {k:v for k,v in scheduler_config.items() if k != 'progress_log_file_path_from_php'} }")

    try:
        # Validate and get parameters from config
        semester_id_str = scheduler_config.get("semester_id_to_load")
        if semester_id_str is None:
            raise ValueError("'semester_id_to_load' is missing in configuration.")
        semester_id = int(semester_id_str) # Chuyển sang int

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
        write_progress(f"Parameters Parsed: SemesterID={semester_id}, CP_Time={cp_time_limit}s, GA_Pop={ga_pop_size}, GA_Gens={ga_gens}, Priorities={priority_settings_from_config}, GA_Allow_HC={ga_allow_hc_violations_flag}")

        # 2. Load and Preprocess Data
        print_stage_header(f"1. LOADING & PREPROCESSING DATA - SEMESTER: {semester_id}")
        loaded_data = load_all_data(semester_id_to_load=semester_id)
        if not any(loaded_data): # data_loader trả về tuple các list/dict rỗng nếu lỗi nghiêm trọng
            raise RuntimeError(f"Data loading failed for semester {semester_id}. load_all_data returned empty collections.")
        
        # Đảm bảo tên biến unpack khớp với tên tham số của preprocess_data_for_cp_and_ga
        input_sc_list, input_instr_list, input_room_list, input_tslot_list, input_stud_list, input_course_cat = loaded_data
        num_input_scheduled_classes = len(input_sc_list)

        if not (input_instr_list and input_room_list and input_tslot_list and input_course_cat):
            raise RuntimeError(f"Essential catalog data (Instructors, Classrooms, TimeSlots, CourseCatalog) empty for semester {semester_id} after loading.")
        write_progress(f"Raw data loaded: {num_input_scheduled_classes} SchedClasses, {len(input_instr_list)} Instrs, {len(input_room_list)} Rooms, {len(input_tslot_list)} TSlots, {len(input_stud_list)} Studs, {len(input_course_cat)} CourseCatalog entries.")

        processed_data_dict = preprocess_data_for_cp_and_ga(
            input_scheduled_classes=input_sc_list, 
            input_courses_catalog=input_course_cat,
            input_instructors=input_instr_list,
            input_classrooms=input_room_list,
            input_timeslots=input_tslot_list,
            input_students=input_stud_list, 
            semester_id_for_settings=semester_id,
            priority_settings=priority_settings_from_config
        )
        if not processed_data_dict: 
            raise RuntimeError(f"Preprocessing returned no data for semester {semester_id}.")
        
        num_items_after_preprocessing = len(processed_data_dict.get("scheduled_items", {}))
        if num_items_after_preprocessing == 0 and num_input_scheduled_classes > 0:
            # Có input SCs nhưng không có gì còn lại sau preproc -> vấn đề
            write_progress(f"WARNING: {num_input_scheduled_classes} SchedClasses provided, but 0 items remain after preprocessing for semester {semester_id}.")
        elif num_items_after_preprocessing == 0:
            # Không có input SCs, hoặc tất cả input SCs đều không hợp lệ (đã được log bởi utils)
             write_progress(f"INFO: No items are schedulable after preprocessing for semester {semester_id}. This might be expected if input was empty or all items were invalid.")
             # Vẫn tiếp tục để lưu output "thành công rỗng"
        
        write_progress(f"Data preprocessed. {num_items_after_preprocessing} items identified for potential scheduling.")

        # 3. CP-SAT Stage
        cp_schedule_output_list: Optional[List[Dict[str,Any]]] = None
        if num_items_after_preprocessing > 0: # Chỉ chạy CP nếu có gì đó để xếp
            print_stage_header("2. SOLVING HARD CONSTRAINTS WITH CP-SAT")
            cp_solver = CourseSchedulingCPSAT(processed_data_dict, progress_logger=write_progress)
            cp_schedule_output_list, cp_solution_metrics = cp_solver.solve(time_limit_seconds=cp_time_limit)
            cp_attempted_items = cp_solution_metrics.get("num_items_targeted_for_cp_solver", 0)

            if cp_schedule_output_list:
                write_progress(f"CP-SAT found a solution with {len(cp_schedule_output_list)} events. CP targeted {cp_attempted_items} events. CP Status: {cp_solution_metrics.get('solver_status', 'N/A')}.")
                cp_debug_path = os.path.join(script_dir, "output_data", CP_DEBUG_FILENAME)
                save_final_output_and_log({"cp_metrics": cp_solution_metrics, "cp_schedule": cp_schedule_output_list}, script_dir, CP_DEBUG_FILENAME)
            else:
                write_progress(f"CP-SAT found NO solutions. CP targeted {cp_attempted_items} events. CP Status: {cp_solution_metrics.get('solver_status', 'N/A')}.")
        else:
            write_progress("Skipping CP-SAT stage: No schedulable items after preprocessing.")
            cp_solution_metrics = {"solver_status": "NOT_RUN_NO_ITEMS"} # Metric mặc định nếu CP không chạy


        # 4. GA Stage
        initial_schedules_for_ga_input: List[List[Dict[str, Any]]] = []
        if cp_schedule_output_list: # Nếu CP có kết quả
            initial_schedules_for_ga_input.append(copy.deepcopy(cp_schedule_output_list)) 
        
        # Chỉ chạy GA nếu có scheduled_items và (CP thành công hoặc GA được phép chạy với quần thể ngẫu nhiên/yếu)
        # Hiện tại, chỉ chạy GA nếu CP có kết quả
        if num_items_after_preprocessing > 0 and initial_schedules_for_ga_input:
            print_stage_header("3. OPTIMIZING SOFT CONSTRAINTS WITH GENETIC ALGORITHM")
            ga_solver = GeneticAlgorithmScheduler(
                processed_data=processed_data_dict, 
                initial_population_from_cp=initial_schedules_for_ga_input,
                population_size=ga_pop_size, generations=ga_gens,
                crossover_rate=ga_crossover_r, mutation_rate=ga_mutation_r, tournament_size=ga_tournament_s,
                allow_hard_constraint_violations_in_ga=ga_allow_hc_violations_flag,
                progress_logger=write_progress
            )
            # run() trả về: best_schedule, best_penalty, detailed_metrics
            final_schedule_result, ga_best_penalty_score, ga_final_detailed_metrics = ga_solver.run() 
            
            if final_schedule_result:
                write_progress(f"GA finished. Best penalty: {ga_best_penalty_score:.2f}. Events in GA schedule: {len(final_schedule_result)}")
            else:
                write_progress("GA finished but did not produce a best schedule. Using CP result if available.")
                # Nếu GA thất bại, và CP có kết quả, chúng ta sẽ dùng kết quả CP
                if cp_schedule_output_list:
                    final_schedule_result = cp_schedule_output_list
                    # Cần tính lại penalty cho giải pháp CP nếu GA không cải thiện (để so sánh)
                    # Hoặc dựa vào cp_metrics nếu nó có đánh giá soft constraints (hiện tại không)
                    write_progress("Falling back to CP-SAT solution.")
                else: # Cả CP và GA đều không có giải pháp
                     write_progress("Neither CP nor GA produced a schedule.")

        elif cp_schedule_output_list: # CP có kết quả, nhưng GA không chạy (ví dụ num_items=0 nhưng CP vẫn có gì đó, hoặc logic khác)
            write_progress("Using CP-SAT solution as GA stage was skipped or had no valid input.")
            final_schedule_result = cp_schedule_output_list
            ga_best_penalty_score = float('inf') # GA không chạy
            ga_final_detailed_metrics = {} # GA không chạy
        else: # Không có item, hoặc CP không có kết quả
            write_progress("No solution from CP-SAT, and GA stage cannot run without initial population. No schedule generated.")
            final_schedule_result = None # Đảm bảo là None
            ga_best_penalty_score = float('inf')
            ga_final_detailed_metrics = {}

        # 5. Finalizing and Saving Output
        print_stage_header("4. FINALIZING AND SAVING RESULTS")
        final_status_msg = "Scheduler run completed."
        final_status_code = "unknown_final_status"
        num_events_in_final_sched = len(final_schedule_result) if final_schedule_result else 0

        if num_items_after_preprocessing == 0:
            final_status_code = "success_no_items_to_schedule"
            final_status_msg = "No items were available/valid for scheduling after preprocessing."
        elif final_schedule_result:
            if num_events_in_final_sched == cp_attempted_items and cp_attempted_items > 0 : # cp_attempted_items là số CP thực sự cố gắng xếp
                final_status_code = "success_full_schedule_generated"
                final_status_msg = f"Successfully scheduled all {num_events_in_final_sched} targeted items."
            elif num_events_in_final_sched > 0:
                final_status_code = "success_partial_schedule_generated"
                final_status_msg = f"Partial schedule generated: {num_events_in_final_sched} out of {cp_attempted_items if cp_attempted_items > 0 else num_items_after_preprocessing} items scheduled."
            else: # final_schedule_result is None or empty list, but num_items_after_preprocessing > 0
                final_status_code = "failure_no_solution_found_by_pipeline"
                final_status_msg = f"No valid schedule found by the CP-GA pipeline for {num_items_after_preprocessing} processed items. CP status: {cp_solution_metrics.get('solver_status','N/A')}."
            
            if ga_best_penalty_score != float('inf'):
                final_status_msg += f" GA final penalty: {ga_best_penalty_score:.2f}."
            elif cp_schedule_output_list: # CP có kết quả, GA không chạy/không cải thiện
                 final_status_msg += " CP solution was used."

        else: # No final_schedule_result and num_items_after_preprocessing > 0
            final_status_code = "failure_pipeline_did_not_produce_schedule"
            final_status_msg = f"The scheduling pipeline did not produce any schedule for {num_items_after_preprocessing} processed items. CP status: {cp_solution_metrics.get('solver_status','N/A')}."
        
        write_progress(f"Final Outcome: {final_status_code} - {final_status_msg}")

        # Tạo output JSON cuối cùng
        output_data_final = {
            "status": final_status_code,
            "message": final_status_msg,
            "semester_id": semester_id,
            "metrics": {
                "input_data_summary": {
                    "num_raw_scheduled_classes_from_db": num_input_scheduled_classes,
                    "num_items_after_preprocessing": num_items_after_preprocessing
                },
                "cp_solver_summary": cp_solution_metrics, # Metrics từ CP_module
                "ga_solver_summary": {
                    "final_penalty_score": ga_best_penalty_score if ga_best_penalty_score != float('inf') else None,
                    "detailed_soft_constraint_metrics": ga_final_detailed_metrics.get("soft_constraints_details", {}),
                    "ga_hard_constraints_violated_in_final": ga_final_detailed_metrics.get("hard_constraints_violated_in_final_schedule", None)
                },
                "overall_performance": {
                    "total_execution_time_seconds": None, # Sẽ được cập nhật ở finally
                    "num_events_in_final_schedule": num_events_in_final_sched
                }
            },
            "final_schedule": final_schedule_result if final_schedule_result else []
        }
        save_final_output_and_log(output_data_final, script_dir)

    except ValueError as e_val: # Bắt lỗi chuyển đổi kiểu dữ liệu từ config
        error_message = f"Configuration Error: {e_val}"
        write_progress(f"CRITICAL_ERROR: {error_message}")
        save_error_output(error_message, "error_invalid_config_type", scheduler_config.get("semester_id_to_load"), script_dir) # Dùng scheduler_config trực tiếp
    except RuntimeError as e_rt: # Bắt lỗi runtime tùy chỉnh (ví dụ: không load được data)
        error_message = f"Runtime Error: {e_rt}"
        write_progress(f"CRITICAL_ERROR: {error_message}")
        save_error_output(error_message, "error_runtime_critical", semester_id, script_dir) # semester_id có thể đã được set
    except Exception as e_unhandled:
        error_message = f"Unhandled Exception in Main Scheduler: {e_unhandled}"
        write_progress(f"CRITICAL_ERROR: {error_message}\n{traceback.format_exc()}")
        save_error_output(f"{error_message}\nFull Traceback:\n{traceback.format_exc()}", 
                          "error_unhandled_exception_main", 
                          semester_id, # semester_id có thể đã được set
                          script_dir)
    finally:
        overall_end_time = pytime.time()
        total_time = overall_end_time - overall_start_time
        write_progress(f"Total Python script execution time: {total_time:.3f} seconds.")
        
        # Cố gắng cập nhật thời gian chạy vào file output nếu nó đã được tạo
        output_file_path_final_check = os.path.join(script_dir, "output_data", FINAL_OUTPUT_FILENAME)
        if os.path.exists(output_file_path_final_check):
            try:
                with open(output_file_path_final_check, 'r+', encoding='utf-8') as f_update:
                    data_to_update = json.load(f_update)
                    if "metrics" in data_to_update and "overall_performance" in data_to_update["metrics"]:
                        data_to_update["metrics"]["overall_performance"]["total_execution_time_seconds"] = round(total_time, 3)
                        f_update.seek(0)
                        json.dump(data_to_update, f_update, indent=4, ensure_ascii=False)
                        f_update.truncate()
                        write_progress("Updated total execution time in final output file.")
                    else: # Nếu cấu trúc không như mong đợi, không làm gì cả
                        write_progress("Could not update total execution time in final output: metrics structure unexpected.")

            except Exception as e_update_time:
                write_progress(f"WARNING: Could not update total execution time in {FINAL_OUTPUT_FILENAME}: {e_update_time}")
        write_progress("--- END OF PYTHON SCHEDULER SCRIPT EXECUTION ---")

if __name__ == "__main__":
    base_dir = os.path.dirname(os.path.abspath(__file__))
    for subdir_name in ["input_data", "output_data"]: 
        path_to_create = os.path.join(base_dir, subdir_name)
        if not os.path.exists(path_to_create):
            try:
                os.makedirs(path_to_create, exist_ok=True)
                print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_SETUP: Created directory {path_to_create}", flush=True)
            except Exception as e_dir_create:
                 print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_SETUP_WARNING: Could not create directory {path_to_create}: {e_dir_create}", file=sys.stderr, flush=True)
    try:
        run_scheduler()
    except Exception as e_main_script_level: 
        fatal_error_msg = f"MAIN_SOLVER_FATAL_OUTSIDE_RUN_SCHEDULER: {e_main_script_level}"
        print(fatal_error_msg, file=sys.stderr, flush=True)
        traceback.print_exc(file=sys.stderr)
        # Cố gắng lưu lỗi này vào file output (nếu có thể)
        try:
            script_dir_for_fatal = os.path.dirname(os.path.abspath(__file__))
            output_dir_for_fatal = os.path.join(script_dir_for_fatal, "output_data")
            if not os.path.exists(output_dir_for_fatal): os.makedirs(output_dir_for_fatal, exist_ok=True)
            
            with open(os.path.join(output_dir_for_fatal, FINAL_OUTPUT_FILENAME), 'w', encoding='utf-8') as f_fatal:
                json.dump({ 
                    "status": "error_fatal_unhandled_in_script_main_block",
                    "message": fatal_error_msg + "\nTraceback:\n" + traceback.format_exc(),
                    "semester_id": None, 
                    "final_schedule": [],
                    "metrics": {}
                }, f_fatal, indent=4, ensure_ascii=False)
            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_FATAL: Fatal error details attempted to be saved to {FINAL_OUTPUT_FILENAME}.", file=sys.stderr, flush=True)
        except Exception as e_save_fatal_json:
            print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] MAIN_SOLVER_FATAL: Also failed to save fatal error output to JSON: {e_save_fatal_json}", file=sys.stderr, flush=True)