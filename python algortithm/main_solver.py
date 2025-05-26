import json
import os
import sys
import time as pytime

# Đảm bảo rằng các module trong cùng thư mục có thể được import
# (Quan trọng nếu bạn chạy main_solver.py từ một thư mục khác)
current_dir = os.path.dirname(os.path.abspath(__file__))
sys.path.append(current_dir)

from utils import load_data_from_db, preprocess_data, save_output_data
from cp_module import CourseSchedulingCPSAT # Sử dụng phiên bản OR-Tools
from ga_module import GeneticAlgorithmScheduler

# --- CẤU HÌNH ---
# Đường dẫn file input (nếu dùng file JSON thay vì DB, hiện không dùng)
# INPUT_JSON_FILE = os.path.join(current_dir, "input_data", "input.json")

# Đường dẫn file output
OUTPUT_JSON_FILE = os.path.join(current_dir, "output_data", "final_schedule_output.json")
CP_SOLUTION_DEBUG_FILE = os.path.join(current_dir, "output_data", "cp_intermediate_solution.json") # Để debug CP

# Tham số cho CP-SAT Solver
CP_TIME_LIMIT_SECONDS = 60.0 # Giới hạn thời gian cho CP-SAT tìm giải pháp (ví dụ: 60 giây)

# Tham số cho Genetic Algorithm
GA_POPULATION_SIZE = 50
GA_GENERATIONS = 100 # Số thế hệ GA, có thể cần tăng nếu bài toán phức tạp
GA_CROSSOVER_RATE = 0.85
GA_MUTATION_RATE = 0.15 # Tăng một chút để khám phá
GA_TOURNAMENT_SIZE = 5

def run_scheduler():
    """
    Hàm chính để chạy toàn bộ quy trình xếp lịch.
    """
    overall_start_time = pytime.time()

    # 1. Load và xử lý dữ liệu đầu vào
    print_stage_header("1. LOADING AND PREPROCESSING DATA")
    raw_data = load_data_from_db()
    if not raw_data:
        print("Failed to load data from database. Exiting.")
        save_output_data_on_error("Failed to load data from database.")
        return

    processed_data = preprocess_data(raw_data)
    if not processed_data or not processed_data.get("courses"):
        print("Failed to preprocess data or no courses found. Exiting.")
        save_output_data_on_error("Failed to preprocess data or no courses found.")
        return
    
    num_initial_courses = len(processed_data["courses"])
    print(f"Data loaded and preprocessed for {num_initial_courses} initial courses.")
    if num_initial_courses == 0:
        print("No courses to schedule. Exiting.")
        save_output_data({"status": "success_no_courses", "message": "No courses to schedule", "schedule": []}, OUTPUT_JSON_FILE)
        return

    # 2. Giải quyết bằng Constraint Programming (CP-SAT)
    print_stage_header("2. SOLVING HARD CONSTRAINTS WITH CP-SAT (OR-Tools)")
    initial_cp_solutions = [] # Sẽ là list của các schedule, mỗi schedule là list các event
    
    try:
        # Chọn tất cả các course đã qua preprocess để đưa vào CP
        # CP solver sẽ tự pre-filter bên trong nó
        cp_input_data = processed_data 

        cp_solver_instance = CourseSchedulingCPSAT(cp_input_data)
        # CP-SAT hiện tại trong cp_module_ortools.py được thiết kế để trả về 1 giải pháp tốt nhất
        # nó tìm được (hoặc giải pháp đầu tiên nếu có nhiều).
        # Hàm solve trả về list chứa 1 schedule (nếu thành công).
        cp_solutions_list = cp_solver_instance.solve(time_limit_seconds=CP_TIME_LIMIT_SECONDS)
        
        if cp_solutions_list: # cp_solutions_list là list chứa các schedule
            initial_cp_solutions = cp_solutions_list # Lấy tất cả các giải pháp CP trả về
            print(f"CP-SAT found {len(initial_cp_solutions)} solution(s) satisfying hard constraints.")
            # Lưu giải pháp CP đầu tiên để debug (nếu có)
            if initial_cp_solutions[0]:
                 save_output_data({
                    "status": "cp_success",
                    "num_courses_to_cp": len(cp_solver_instance.courses_to_schedule_ids),
                    "num_events_in_cp_schedule": len(initial_cp_solutions[0]),
                    "schedule": initial_cp_solutions[0]
                }, CP_SOLUTION_DEBUG_FILE)
        else:
            print("CP-SAT did not find any solution satisfying all hard constraints within the time limit.")

    except Exception as e:
        print(f"Error during CP-SAT solving: {e}")
        import traceback
        traceback.print_exc()
        save_output_data_on_error(f"Error during CP-SAT solving: {str(e)}")
        # Quyết định có dừng hẳn hay không. Nếu không có giải pháp CP, GA sẽ rất khó.
        # return # Nên dừng nếu CP thất bại nặng nề

    final_optimized_schedule = None
    final_status_message = ""

    # 3. Tối ưu bằng Genetic Algorithm (GA)
    if initial_cp_solutions: # Chỉ chạy GA nếu CP có giải pháp
        print_stage_header("3. OPTIMIZING SOFT CONSTRAINTS WITH GENETIC ALGORITHM")
        try:
            # initial_cp_solutions là list các schedule, mỗi schedule là list các event
            # GA sẽ dùng các schedule này làm quần thể ban đầu
            ga_optimizer = GeneticAlgorithmScheduler(
                processed_data=processed_data, 
                initial_population=initial_cp_solutions, # Truyền list các schedule từ CP
                population_size=GA_POPULATION_SIZE,
                generations=GA_GENERATIONS,
                crossover_rate=GA_CROSSOVER_RATE,
                mutation_rate=GA_MUTATION_RATE,
                tournament_size=GA_TOURNAMENT_SIZE
            )
            
            best_schedule_from_ga, final_penalty = ga_optimizer.run()

            if best_schedule_from_ga:
                final_optimized_schedule = best_schedule_from_ga
                final_status_message = f"Successfully optimized by GA. Final penalty: {final_penalty:.2f}"
                print(f"\nGA optimization successful. Best schedule penalty: {final_penalty:.2f}")
            else:
                final_status_message = "GA did not improve or find a valid schedule. Using best CP solution if available."
                print("\nGA did not produce a better schedule or failed. Using a CP solution.")
                # Lấy giải pháp đầu tiên (hoặc tốt nhất nếu CP trả về nhiều và đã được sắp xếp) từ CP
                final_optimized_schedule = initial_cp_solutions[0] if initial_cp_solutions else None
        
        except Exception as e:
            print(f"Error during GA optimization: {e}")
            import traceback
            traceback.print_exc()
            final_status_message = f"Error during GA optimization: {str(e)}. Using best CP solution if available."
            # Lấy giải pháp CP nếu GA lỗi
            final_optimized_schedule = initial_cp_solutions[0] if initial_cp_solutions else None
            
    elif num_initial_courses > 0 : # Có course đầu vào nhưng CP không tìm được giải pháp
        final_status_message = "CP-SAT found no initial solution. No GA optimization performed."
        print(final_status_message)
        final_optimized_schedule = None # Không có giải pháp nào
    else: # Không có course đầu vào
        final_status_message = "No courses provided to schedule."
        final_optimized_schedule = []


    # 4. Lưu kết quả cuối cùng
    print_stage_header("4. FINALIZING AND SAVING RESULTS")
    output_content = {
        "status": "",
        "message": final_status_message,
        "num_initial_courses_for_run": num_initial_courses,
        "num_courses_to_cp": len(cp_solver_instance.courses_to_schedule_ids) if 'cp_solver_instance' in locals() and hasattr(cp_solver_instance, 'courses_to_schedule_ids') else 0,
        "num_events_in_final_schedule": len(final_optimized_schedule) if final_optimized_schedule else 0,
        "schedule": final_optimized_schedule if final_optimized_schedule else []
    }

    if final_optimized_schedule and len(final_optimized_schedule) > 0:
        # Kiểm tra xem số lượng event trong schedule cuối có bằng số lượng course CP xử lý không
        num_courses_cp_processed = len(cp_solver_instance.courses_to_schedule_ids) if 'cp_solver_instance' in locals() and hasattr(cp_solver_instance, 'courses_to_schedule_ids') else 0
        if num_courses_cp_processed > 0 and len(final_optimized_schedule) == num_courses_cp_processed:
             output_content["status"] = "success_full_schedule"
        elif num_courses_cp_processed > 0 and len(final_optimized_schedule) < num_courses_cp_processed:
            output_content["status"] = "success_partial_schedule"
            output_content["message"] += f". Note: Only {len(final_optimized_schedule)} of {num_courses_cp_processed} target courses were scheduled."
        else: # Trường hợp schedule có event nhưng không có thông tin CP (ít khả năng)
            output_content["status"] = "success_schedule_found"

    elif final_optimized_schedule is None and num_initial_courses > 0: # CP không có giải pháp
        output_content["status"] = "failure_no_solution_found"
    elif not final_optimized_schedule and num_initial_courses > 0: # final_optimized_schedule là list rỗng
        output_content["status"] = "failure_empty_schedule_produced"
    else: # Không có course đầu vào
        output_content["status"] = "no_courses_to_schedule"


    save_output_data(output_content, OUTPUT_JSON_FILE)
    print(f"\nFinal schedule saved to: {OUTPUT_JSON_FILE}")
    if final_optimized_schedule:
        print(f"Summary: Scheduled {len(final_optimized_schedule)} events.")
    else:
        print("Summary: No schedule was generated.")

    overall_end_time = pytime.time()
    print(f"\nTotal execution time: {overall_end_time - overall_start_time:.2f} seconds.")

def print_stage_header(title):
    """Helper function to print stage headers."""
    print("\n" + "="*60)
    print(f"===== {title.upper()} =====")
    print("="*60)

def save_output_data_on_error(error_message):
    """Saves a generic error message to the output file."""
    error_output = {
        "status": "error_critical",
        "message": error_message,
        "schedule": []
    }
    save_output_data(error_output, OUTPUT_JSON_FILE)
    print(f"Critical error occurred. Error details saved to {OUTPUT_JSON_FILE}")


if __name__ == "__main__":
    # Tạo các thư mục input_data, output_data nếu chưa có
    # (utils.py cũng có thể làm điều này khi test, nhưng để ở đây cho chắc)
    if not os.path.exists(os.path.join(current_dir, "input_data")):
        os.makedirs(os.path.join(current_dir, "input_data"))
    if not os.path.exists(os.path.join(current_dir, "output_data")):
        os.makedirs(os.path.join(current_dir, "output_data"))
        
    run_scheduler()