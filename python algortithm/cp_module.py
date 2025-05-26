from ortools.sat.python import cp_model
from utils import parse_time, check_time_overlap # Sử dụng lại các hàm tiện ích
import time as pytime # Để đo thời gian
from collections import defaultdict

# Hàm tiện ích để làm phẳng domain từ OR-Tools
def flatten_domain(domain_proto):
    """
    Converts a domain protocol buffer into a list of integer values.
    A domain is a union of disjoint intervals [min_i, max_i].
    """
    values = []
    for i in range(0, len(domain_proto), 2):
        min_val = domain_proto[i]
        max_val = domain_proto[i+1]
        for val in range(min_val, max_val + 1):
            values.append(val)
    return values

class CourseSchedulingCPSAT:
    def __init__(self, processed_data):
        self.data = processed_data
        self.model = cp_model.CpModel()
        
        self.initial_course_ids = list(self.data["courses"].keys())
        self.all_lecturer_ids = list(self.data["lecturers"].keys())
        self.all_classroom_ids = list(self.data["classrooms"].keys())
        self.all_timeslot_ids = list(self.data["timeslots"].keys())

        self.course_vars = {} 
        self.courses_to_schedule_ids = [] 

        if not self.initial_course_ids:
            print("Warning: No initial courses to schedule.")
        # Các kiểm tra khác sẽ thực hiện sau pre-filtering

    def _pre_filter_and_create_variables(self):
        if not self.initial_course_ids:
            return

        temp_courses_to_schedule = []

        for course_id_str in self.initial_course_ids:
            course_detail = self.data["courses"].get(course_id_str)
            if not course_detail:
                print(f"Warning: Course ID {course_id_str} not found in processed_data['courses']. Skipping.")
                continue

            valid_timeslots_for_course = [
                ts_id for ts_id, ts_details in self.data["timeslots"].items()
                if ts_details["effective_basic_slots"] == course_detail["duration_slots"]
            ]
            valid_classrooms_for_course = [
                cr_id for cr_id, cr_details in self.data["classrooms"].items()
                if cr_details["capacity"] >= course_detail["expected_students"]
            ]
            valid_lecturers_for_course = self.all_lecturer_ids 
            if course_detail.get("preferred_lecturers"):
                preferred = [l_id for l_id in course_detail["preferred_lecturers"] if l_id in self.all_lecturer_ids]
                if preferred:
                    valid_lecturers_for_course = preferred
            
            if not valid_lecturers_for_course or not valid_classrooms_for_course or not valid_timeslots_for_course:
                print(f"Info: Course {course_id_str} is unschedulable due to empty pre-filtered domains and will be skipped.")
                continue 
            
            temp_courses_to_schedule.append(course_id_str)
            
            lecturer_var = self.model.NewIntVarFromDomain(
                cp_model.Domain.FromValues(valid_lecturers_for_course), 
                name=f"{course_id_str}_lect"
            )
            classroom_var = self.model.NewIntVarFromDomain(
                cp_model.Domain.FromValues(valid_classrooms_for_course),
                name=f"{course_id_str}_room"
            )
            timeslot_var = self.model.NewIntVarFromDomain(
                cp_model.Domain.FromValues(valid_timeslots_for_course),
                name=f"{course_id_str}_ts"
            )
            
            self.course_vars[course_id_str] = {
                "lecturer": lecturer_var,
                "classroom": classroom_var,
                "timeslot": timeslot_var
            }
        
        self.courses_to_schedule_ids = temp_courses_to_schedule
        
        if not self.courses_to_schedule_ids:
             print("Warning: No courses are schedulable after pre-filtering.")
        elif (self.courses_to_schedule_ids and # Chỉ kiểm tra nếu có course thực sự cần xếp
              (not self.all_lecturer_ids or not self.all_classroom_ids or not self.all_timeslot_ids)):
             raise ValueError("Missing essential data (lecturers, classrooms, or timeslots) for schedulable courses.")

    def _add_constraints(self):
        if not self.courses_to_schedule_ids or not self.course_vars:
            print("No courses or variables to add constraints for.")
            return

        # HC3: Giảng viên không được xếp vào giờ bận
        for course_id_str in self.courses_to_schedule_ids:
            if course_id_str not in self.course_vars: continue

            lecturer_var = self.course_vars[course_id_str]["lecturer"]
            timeslot_var = self.course_vars[course_id_str]["timeslot"]
            
            # SỬA Ở ĐÂY: Sử dụng flatten_domain
            possible_lecturers_values = flatten_domain(lecturer_var.Proto().domain)
            possible_timeslots_values = flatten_domain(timeslot_var.Proto().domain)

            for l_id in possible_lecturers_values:
                lecturer_busy_slots = self.data["instructor_unavailable_map"].get(l_id, [])
                if not lecturer_busy_slots:
                    continue

                for ts_id in possible_timeslots_values:
                    assigned_timeslot_details = self.data["timeslots"].get(ts_id)
                    if not assigned_timeslot_details: continue

                    for busy_slot in lecturer_busy_slots:
                        if busy_slot["day_of_week"] == assigned_timeslot_details["day_of_week"]:
                            if check_time_overlap(assigned_timeslot_details["start_time"], 
                                                  assigned_timeslot_details["end_time"],
                                                  busy_slot["start_time"], 
                                                  busy_slot["end_time"]):
                                self.model.AddForbiddenAssignments(
                                    [lecturer_var, timeslot_var],
                                    [(l_id, ts_id)] 
                                )
                                break 
        
        # HC1 & HC2 (Giữ nguyên như trước)
        if len(self.courses_to_schedule_ids) > 1:
            for i in range(len(self.courses_to_schedule_ids)):
                for j in range(i + 1, len(self.courses_to_schedule_ids)):
                    c1_id = self.courses_to_schedule_ids[i]
                    c2_id = self.courses_to_schedule_ids[j]

                    if c1_id not in self.course_vars or c2_id not in self.course_vars:
                        continue

                    l1 = self.course_vars[c1_id]["lecturer"]
                    t1 = self.course_vars[c1_id]["timeslot"]
                    r1 = self.course_vars[c1_id]["classroom"]

                    l2 = self.course_vars[c2_id]["lecturer"]
                    t2 = self.course_vars[c2_id]["timeslot"]
                    r2 = self.course_vars[c2_id]["classroom"]

                    b_lect_equal = self.model.NewBoolVar(f'b_lect_equal_{c1_id}_{c2_id}')
                    self.model.Add(l1 == l2).OnlyEnforceIf(b_lect_equal)
                    self.model.Add(l1 != l2).OnlyEnforceIf(b_lect_equal.Not())
                    self.model.Add(t1 != t2).OnlyEnforceIf(b_lect_equal)
                    
                    b_room_equal = self.model.NewBoolVar(f'b_room_equal_{c1_id}_{c2_id}')
                    self.model.Add(r1 == r2).OnlyEnforceIf(b_room_equal)
                    self.model.Add(r1 != r2).OnlyEnforceIf(b_room_equal.Not())
                    self.model.Add(t1 != t2).OnlyEnforceIf(b_room_equal)

    def solve(self, time_limit_seconds=30.0):
        start_build_time = pytime.time()
        self._pre_filter_and_create_variables()
        
        if not self.courses_to_schedule_ids or not self.course_vars:
            print("CP-SAT Solver: No schedulable courses after pre-filtering or variable creation. Cannot solve.")
            return []
            
        self._add_constraints()
        end_build_time = pytime.time()
        print(f"Time to build CP-SAT model for {len(self.courses_to_schedule_ids)} courses: {end_build_time - start_build_time:.2f} seconds.")

        solver = cp_model.CpSolver()
        if time_limit_seconds is not None:
            solver.parameters.max_time_in_seconds = float(time_limit_seconds)
        
        print(f"CP-SAT Solver starting... Time limit: {solver.parameters.max_time_in_seconds}s")
        start_solve_time = pytime.time()
        status = solver.Solve(self.model)
        end_solve_time = pytime.time()
        
        print(f"CP-SAT Solver finished in {end_solve_time - start_solve_time:.2f} seconds. Status: {solver.StatusName(status)}")
        print(f"Solver statistics: {solver.ResponseStats()}")

        solutions = []
        if status == cp_model.OPTIMAL or status == cp_model.FEASIBLE:
            print(f"Solution found.")
            
            current_schedule = []
            all_courses_scheduled_this_solution = True
            num_actually_scheduled_events = 0

            for course_id_str in self.courses_to_schedule_ids: 
                if course_id_str not in self.course_vars: continue

                variables = self.course_vars[course_id_str]
                try:
                    lect_id = solver.Value(variables["lecturer"])
                    room_id = solver.Value(variables["classroom"])
                    ts_id = solver.Value(variables["timeslot"])
                    
                    # Nếu solver không gán giá trị (có thể xảy ra nếu model phức tạp và dừng sớm)
                    # Hoặc nếu giá trị không nằm trong domain mong đợi (ít khả năng với CP-SAT)
                    if lect_id is None or room_id is None or ts_id is None:
                        print(f"Info: Course {course_id_str} was not assigned a value by the solver (or assigned None).")
                        all_courses_scheduled_this_solution = False
                        continue

                    if course_id_str not in self.data["courses"] or \
                       lect_id not in self.data["lecturers"] or \
                       room_id not in self.data["classrooms"] or \
                       ts_id not in self.data["timeslots"]:
                        print(f"Warning: Solver assigned an ID not in original data for course {course_id_str}. L={lect_id}, R={room_id}, T={ts_id}. Skipping event.")
                        all_courses_scheduled_this_solution = False
                        continue
                        
                    current_schedule.append({
                        "course_id": course_id_str,
                        "lecturer_id": lect_id,
                        "classroom_id": room_id,
                        "timeslot_id": ts_id,
                        "course_name": self.data["courses"][course_id_str]["name"],
                        "lecturer_name": self.data["lecturers"][lect_id]["name"],
                        "room_code": self.data["classrooms"][room_id]["room_code"],
                        "timeslot_info": (
                            f"{self.data['timeslots'][ts_id]['day_of_week']} "
                            f"{self.data['timeslots'][ts_id]['start_time'].strftime('%H:%M')}-"
                            f"{self.data['timeslots'][ts_id]['end_time'].strftime('%H:%M')}"
                        )
                    })
                    num_actually_scheduled_events += 1
                except Exception as e: # Bắt lỗi cụ thể hơn nếu có thể
                    print(f"Error retrieving solution value for course {course_id_str}: {e}")
                    all_courses_scheduled_this_solution = False
            
            # Chỉ chấp nhận giải pháp nếu tất cả các course mục tiêu (đã qua pre-filter) đều được xếp
            if num_actually_scheduled_events == len(self.courses_to_schedule_ids):
                solutions.append(current_schedule)
                print(f"Successfully scheduled all {len(self.courses_to_schedule_ids)} target courses.")
            elif current_schedule: # Có một số môn được xếp nhưng không phải tất cả
                 print(f"Warning: Partial schedule. Scheduled {num_actually_scheduled_events}/{len(self.courses_to_schedule_ids)} target courses. This solution will be discarded by current logic.")
            else: # Không môn nào được xếp dù status là FEASIBLE/OPTIMAL
                print("Warning: Solver reported FEASIBLE/OPTIMAL but no valid schedule events could be extracted.")

        elif status == cp_model.INFEASIBLE:
            print("CP-SAT Solver: No solution found. The model is INFEASIBLE.")
        else:
            print(f"CP-SAT Solver: Solver stopped with status {solver.StatusName(status)} (e.g., ABORTED due to time limit without a feasible solution covering all target courses).")
            
        return solutions

# --- Example Usage ---
if __name__ == "__main__":
    from utils import load_data_from_db, preprocess_data, save_output_data
    import os

    output_dir = "output_data"
    if not os.path.exists(output_dir):
        os.makedirs(output_dir)

    print("Loading data from DB for CP-SAT module testing...")
    raw_data_from_db = load_data_from_db()

    if raw_data_from_db:
        processed_data = preprocess_data(raw_data_from_db)
        if processed_data and processed_data.get("courses"): # Kiểm tra có course không
            all_course_ids_from_data = list(processed_data['courses'].keys())
            
            #courses_to_test_ids = all_course_ids_from_data[:10] # Test 10 môn đầu
            courses_to_test_ids = all_course_ids_from_data # Test tất cả

            if not courses_to_test_ids:
                 print("No courses selected for testing from the available data.")
            else:
                print(f"Selected {len(courses_to_test_ids)} courses for CP-SAT testing: {courses_to_test_ids}")
                
                test_specific_processed_data = {
                    k: v for k, v in processed_data.items()
                }
                test_specific_processed_data["courses"] = {
                    cid: processed_data["courses"][cid] for cid in courses_to_test_ids if cid in processed_data["courses"]
                }
                
                if not test_specific_processed_data["courses"]:
                    print("No courses to schedule after filtering for testing (selected set was empty or invalid).")
                elif not test_specific_processed_data.get("lecturers") or \
                     not test_specific_processed_data.get("classrooms") or \
                     not test_specific_processed_data.get("timeslots"):
                    print("CP-SAT Solver: Missing essential data for the test set.")
                else:
                    try:
                        time_limit_for_test = 60.0  
                        print(f"Attempting to solve with CP-SAT. Time limit: {time_limit_for_test}s")
                        
                        cp_sat_solver_instance = CourseSchedulingCPSAT(test_specific_processed_data)
                        list_of_schedules = cp_sat_solver_instance.solve(time_limit_seconds=time_limit_for_test) 

                        output_file_path = os.path.join(output_dir, "cp_sat_solution_output.json")
                        if list_of_schedules:
                            print(f"\n--- CP-SAT Solution(s) (Found {len(list_of_schedules)}) ---")
                            # Lấy giải pháp đầu tiên để lưu và in
                            first_schedule_events = list_of_schedules[0]
                            print(f"\nSolution 1 (contains {len(first_schedule_events)} scheduled events):")
                            if not first_schedule_events:
                                print("  (Empty schedule in this solution object)")
                            for event in first_schedule_events:
                                print(
                                    f"  Course: {event['course_id']} ({event.get('course_name', 'N/A')}) -> "
                                    f"Lecturer: {event['lecturer_id']} ({event.get('lecturer_name', 'N/A')}), "
                                    f"Room: {event['classroom_id']} ({event.get('room_code', 'N/A')}), "
                                    f"Timeslot: {event['timeslot_id']} ({event.get('timeslot_info', 'N/A')})"
                                )
                            save_output_data({
                                "status": "success_cp_sat", 
                                "num_courses_input_to_solver": len(cp_sat_solver_instance.courses_to_schedule_ids),
                                "num_events_scheduled": len(first_schedule_events) if first_schedule_events else 0,
                                "schedule": first_schedule_events if first_schedule_events else [], 
                                "source": "cp_module_ortools_test_full_fixed_domainvalues"
                            }, output_file_path)
                        else:
                            print("No complete solution found by CP-SAT solver.")
                            save_output_data({
                                "status": "failure_cp_sat_no_complete_solution", 
                                "num_courses_input_to_solver": len(cp_sat_solver_instance.courses_to_schedule_ids) if hasattr(cp_sat_solver_instance, 'courses_to_schedule_ids') else len(courses_to_test_ids),
                                "schedule": [], 
                                "source": "cp_module_ortools_test_full_fixed_domainvalues"
                            }, output_file_path)
                    
                    except ValueError as e:
                        print(f"ValueError during CP-SAT setup or solving: {e}")
                    except Exception as e:
                        print(f"An unexpected error occurred during CP-SAT processing: {e}")
                        import traceback
                        traceback.print_exc()
        elif not processed_data: # Nếu preprocess_data trả về None
             print("Failed to preprocess data (preprocess_data returned None).")
        else: # Nếu processed_data không None nhưng không có 'courses'
            print("Processed data does not contain 'courses' key or it's empty.")
            
    else: # Nếu load_data_from_db trả về None
        print("Failed to load data from DB (load_data_from_db returned None).")