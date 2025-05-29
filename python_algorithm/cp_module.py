# File: htdocs/DSS/python_algorithm/cp_module.py
from ortools.sat.python import cp_model
import time as pytime
from collections import defaultdict
import traceback
from typing import Optional, Dict, Any, List, Tuple
import os # Được thêm vào từ code của bạn

class CourseSchedulingCPSAT:
    def __init__(self, processed_data: dict, progress_logger: Optional[callable] = None):
        self.data = processed_data
        self.model = cp_model.CpModel()
        self.progress_logger = progress_logger

        self.initial_scheduled_item_keys_int: List[int] = list(self.data.get("scheduled_items", {}).keys())
        
        self.all_lecturer_ids_int_mapped = list(self.data.get("lecturers", {}).keys())
        self.all_classroom_ids_int_mapped = list(self.data.get("classrooms", {}).keys())
        self.all_timeslot_ids_int_mapped = list(self.data.get("timeslots", {}).keys())

        self.mappings = self.data.get("mappings", {
            "lecturer_str_id_to_int_map": {}, "lecturer_int_map_to_str_id": {},
            "timeslot_str_id_to_int_map": {}, "timeslot_int_map_to_str_id": {},
            "classroom_pk_to_int_map": {}, "classroom_int_map_to_pk": {},
            "scheduled_item_db_id_to_idx_map": {}, "scheduled_item_idx_to_db_id_map": {},
        })

        self.item_vars: Dict[int, Dict[str, Any]] = {}
        self.items_to_schedule_keys_int: List[int] = []

        self.solver_status: Optional[str] = None
        self.solve_time_seconds: float = 0.0
        self.model_build_time_seconds: float = 0.0
        self.num_items_targeted: int = 0
        self.num_items_successfully_scheduled: int = 0
        self.scheduling_success_rate: float = 0.0
        self.unscheduled_item_original_db_ids: List[int] = []

        self._log_cp("CP-SAT Module Initialized.")
        if not self.initial_scheduled_item_keys_int:
            self._log_cp("WARNING: No initial scheduled_items found in processed_data during CP-SAT init.")

    def _log_cp(self, message: str):
        prefix = "CP_SAT"
        if self.progress_logger:
            self.progress_logger(f"{prefix}: {message}")
        else:
            print(f"{prefix}_PRINT: {message}")

    def _pre_filter_and_create_variables(self):
        self._log_cp("Starting pre-filtering and variable creation...")
        if not self.initial_scheduled_item_keys_int:
            self._log_cp("Pre-filter: No initial_scheduled_item_keys_int to process. Aborting variable creation.")
            return

        temp_items_to_schedule_keys = []
        skipped_count = 0
        for item_key_int in self.initial_scheduled_item_keys_int:
            item_detail = self.data["scheduled_items"].get(item_key_int)
            if not item_detail:
                self._log_cp(f"WARNING: Pre-filter - Scheduled Item Key {item_key_int} not found in processed_data['scheduled_items']. Skipping.")
                skipped_count += 1
                continue
            
            original_db_id = item_detail.get("original_db_id") 
            course_id_str = item_detail.get("course_id_str")
            # course_name_for_log = item_detail.get("course_name", "N/A") # Không dùng trực tiếp

            assigned_lecturer_mapped_int_id = item_detail.get("assigned_instructor_mapped_int_id")
            
            if assigned_lecturer_mapped_int_id is None:
                 self._log_cp(f"CRITICAL: Pre-filter - Item Key {item_key_int} (Course: {course_id_str}, DB_ID: {original_db_id}) "
                             f"has NO pre-assigned instructor. CP-SAT (as currently written) requires pre-assigned instructor. Skipping item.")
                 skipped_count += 1
                 continue
            if assigned_lecturer_mapped_int_id not in self.all_lecturer_ids_int_mapped:
                self._log_cp(f"CRITICAL: Pre-filter - Item Key {item_key_int} (Course: {course_id_str}, DB_ID: {original_db_id}) "
                             f"has invalid assigned_instructor_mapped_int_id ({assigned_lecturer_mapped_int_id}). Skipping.")
                skipped_count += 1
                continue
            
            lecturer_var = self.model.NewIntVar(assigned_lecturer_mapped_int_id, assigned_lecturer_mapped_int_id, name=f"item_{item_key_int}_lect_var")

            valid_timeslots_for_item_domain = self.all_timeslot_ids_int_mapped

            item_num_students = item_detail.get("num_students", 0)
            if item_num_students == 0:
                 self._log_cp(f"WARNING: Pre-filter - Item Key {item_key_int} (Course: {course_id_str}, DB_ID: {original_db_id}) has 0 num_students.")

            valid_classrooms_for_item_domain = [
                cr_mapped_int_id for cr_mapped_int_id in self.all_classroom_ids_int_mapped
                if self.data["classrooms"].get(cr_mapped_int_id, {}).get("capacity", 0) >= item_num_students
            ]
            
            if not valid_classrooms_for_item_domain:
                self._log_cp(f"INFO: Pre-filter - Item Key {item_key_int} (Course: {course_id_str}, DB_ID: {original_db_id}, Students: {item_num_students}) "
                             f"unschedulable due to: no suitable classrooms (capacity). Skipping.")
                skipped_count += 1
                continue
            
            if not valid_timeslots_for_item_domain: 
                self._log_cp(f"INFO: Pre-filter - Item Key {item_key_int} (Course: {course_id_str}, DB_ID: {original_db_id}) "
                             f"unschedulable due to: no available timeslots. Skipping.")
                skipped_count += 1
                continue

            temp_items_to_schedule_keys.append(item_key_int)
            
            classroom_var = self.model.NewIntVarFromDomain(cp_model.Domain.FromValues(valid_classrooms_for_item_domain), name=f"item_{item_key_int}_room_var")
            timeslot_var = self.model.NewIntVarFromDomain(cp_model.Domain.FromValues(valid_timeslots_for_item_domain), name=f"item_{item_key_int}_ts_var")
            
            self.item_vars[item_key_int] = {
                "lecturer_var": lecturer_var, 
                "classroom_var": classroom_var,
                "timeslot_var": timeslot_var,
                "fixed_assigned_lecturer_mapped_int_id": assigned_lecturer_mapped_int_id, 
                "classroom_domain_values_int": valid_classrooms_for_item_domain,
                "timeslot_domain_values_int": valid_timeslots_for_item_domain,
                "original_db_id": original_db_id, 
                "course_id_str": course_id_str    
            }
        
        self.items_to_schedule_keys_int = temp_items_to_schedule_keys
        self.num_items_targeted = len(self.items_to_schedule_keys_int)

        if skipped_count > 0:
             self._log_cp(f"Pre-filter: Skipped {skipped_count} items due to missing data, invalid assignments, or no suitable resources pre-solver.")
        if not self.items_to_schedule_keys_int:
             self._log_cp("WARNING: Pre-filter - No items are schedulable after pre-filtering.")
        else:
            self._log_cp(f"Pre-filter: {self.num_items_targeted} items are potentially schedulable and variables created.")
        self._log_cp("Finished pre-filtering and variable creation.")

    def _add_constraints(self):
        self._log_cp("Adding hard constraints to the model...")
        if not self.items_to_schedule_keys_int or not self.item_vars:
            self._log_cp("No items or variables to add constraints for. Skipping constraint addition.")
            return

        num_schedulable_items = self.num_items_targeted
        
        if num_schedulable_items > 1:
            constraints_added_hc1 = 0
            constraints_added_hc2 = 0
            for i in range(num_schedulable_items):
                for j in range(i + 1, num_schedulable_items):
                    item1_key_int = self.items_to_schedule_keys_int[i]
                    item2_key_int = self.items_to_schedule_keys_int[j]

                    if item1_key_int not in self.item_vars or item2_key_int not in self.item_vars:
                        continue 

                    vars1 = self.item_vars[item1_key_int]
                    vars2 = self.item_vars[item2_key_int]

                    if vars1["fixed_assigned_lecturer_mapped_int_id"] == vars2["fixed_assigned_lecturer_mapped_int_id"]:
                        self.model.Add(vars1["timeslot_var"] != vars2["timeslot_var"])
                        constraints_added_hc1 +=1

                    b_room_equal = self.model.NewBoolVar(f'b_room_eq_item{item1_key_int}_item{item2_key_int}')
                    self.model.Add(vars1["classroom_var"] == vars2["classroom_var"]).OnlyEnforceIf(b_room_equal)
                    self.model.Add(vars1["classroom_var"] != vars2["classroom_var"]).OnlyEnforceIf(b_room_equal.Not())
                    self.model.Add(vars1["timeslot_var"] != vars2["timeslot_var"]).OnlyEnforceIf(b_room_equal)
                    constraints_added_hc2 +=1
            self._log_cp(f"Added {constraints_added_hc1} HC1 (Lecturer-Timeslot) and {constraints_added_hc2} HC2 (Classroom-Timeslot) constraints.")
        else:
            self._log_cp("Skipped HC1 & HC2: Less than 2 schedulable items.")

        constraints_added_hc3 = 0
        for item_key_int in self.items_to_schedule_keys_int:
            if item_key_int not in self.item_vars: continue

            item_vars_entry = self.item_vars[item_key_int]
            assigned_lecturer_mapped_int_id = item_vars_entry["fixed_assigned_lecturer_mapped_int_id"]
            item_timeslot_var = item_vars_entry["timeslot_var"]
            
            lecturer_details = self.data["lecturers"].get(assigned_lecturer_mapped_int_id)
            if not lecturer_details:
                self._log_cp(f"WARNING: HC3 - Lecturer MAPPED ID {assigned_lecturer_mapped_int_id} for item key {item_key_int} not in self.data['lecturers'].")
                continue
            
            unavailable_mapped_ts_int_ids = lecturer_details.get("unavailable_slot_ids_mapped", []) 
            if not unavailable_mapped_ts_int_ids: continue

            for busy_mapped_ts_int_id in unavailable_mapped_ts_int_ids:
                self.model.Add(item_timeslot_var != busy_mapped_ts_int_id)
                constraints_added_hc3 +=1
        self._log_cp(f"Added {constraints_added_hc3} HC3 (Instructor Unavailable Slots) constraints.")
        self._log_cp("Finished adding hard constraints.")

    def solve(self, time_limit_seconds: float = 30.0) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
        self._log_cp("Starting CP-SAT model solving process...")
        self.solver_status = None; self.solve_time_seconds = 0.0; self.model_build_time_seconds = 0.0
        self.num_items_successfully_scheduled = 0; self.scheduling_success_rate = 0.0
        self.unscheduled_item_original_db_ids = []

        start_build_time = pytime.time()
        try:
            self._pre_filter_and_create_variables()
            if not self.items_to_schedule_keys_int or not self.item_vars:
                self._log_cp("Solver: No schedulable items after pre-filtering. Cannot solve.")
                self.solver_status = "NO_ITEMS_TO_SCHEDULE_POST_PREFILTER"
                self.model_build_time_seconds = pytime.time() - start_build_time
                return [], self.get_solution_metrics()
            self._add_constraints()
        except Exception as e_build:
            self._log_cp(f"CRITICAL ERROR during model building: {e_build}\n{traceback.format_exc()}")
            self.model_build_time_seconds = pytime.time() - start_build_time
            self.solver_status = "MODEL_BUILD_ERROR"
            return [], self.get_solution_metrics()
        
        self.model_build_time_seconds = pytime.time() - start_build_time
        self._log_cp(f"Time to build CP-SAT model for {self.num_items_targeted} items: {self.model_build_time_seconds:.2f}s.")

        if self.num_items_targeted == 0:
            self._log_cp("Solver: No items to solve after model build. Returning empty schedule.")
            self.solver_status = "NO_ITEMS_TO_SCHEDULE_POST_BUILD"
            return [], self.get_solution_metrics()

        solver = cp_model.CpSolver()
        solver.parameters.log_search_progress = True 
        if time_limit_seconds is not None: solver.parameters.max_time_in_seconds = float(time_limit_seconds)
        
        self._log_cp(f"Solver starting... Time limit: {solver.parameters.max_time_in_seconds}s. Solving for {self.num_items_targeted} items.")
        start_solve_time = pytime.time()
        status_code = solver.Solve(self.model)
        self.solve_time_seconds = pytime.time() - start_solve_time
        self.solver_status = solver.StatusName(status_code)
        self._log_cp(f"Solver finished in {self.solve_time_seconds:.2f}s. Status: {self.solver_status}")

        solutions_list_of_dicts = []
        if status_code == cp_model.OPTIMAL or status_code == cp_model.FEASIBLE:
            self._log_cp("Solver found a FEASIBLE or OPTIMAL solution. Extracting schedule...")
            for item_key_int in self.items_to_schedule_keys_int:
                item_vars_entry = self.item_vars.get(item_key_int)
                if not item_vars_entry: self._log_cp(f"WARNING: Item key {item_key_int} not in item_vars post-solve. Skipping."); continue

                original_item_db_id = item_vars_entry["original_db_id"]
                item_course_id_str = item_vars_entry["course_id_str"]
                try:
                    lect_mapped_int_id_val = item_vars_entry["fixed_assigned_lecturer_mapped_int_id"]
                    room_mapped_int_id_val = solver.Value(item_vars_entry["classroom_var"])
                    ts_mapped_int_id_val = solver.Value(item_vars_entry["timeslot_var"])

                    if not (isinstance(lect_mapped_int_id_val, int) and isinstance(room_mapped_int_id_val, int) and isinstance(ts_mapped_int_id_val, int)):
                        self._log_cp(f"INFO: Item Key {item_key_int} (DB_ID: {original_item_db_id}, Course: {item_course_id_str}) not fully assigned. L:{lect_mapped_int_id_val}, R:{room_mapped_int_id_val}, T:{ts_mapped_int_id_val}. Unscheduled.")
                        self.unscheduled_item_original_db_ids.append(original_item_db_id); continue
                    
                    lecturer_data = self.data["lecturers"].get(lect_mapped_int_id_val)
                    classroom_data = self.data["classrooms"].get(room_mapped_int_id_val)
                    timeslot_data = self.data["timeslots"].get(ts_mapped_int_id_val)

                    if not lecturer_data or not classroom_data or not timeslot_data:
                        self._log_cp(f"ERROR: No processed data for mapped IDs for item key {item_key_int} (DB_ID: {original_item_db_id}). L_m:{lect_mapped_int_id_val}, R_m:{room_mapped_int_id_val}, T_m:{ts_mapped_int_id_val}. Unscheduled.")
                        self.unscheduled_item_original_db_ids.append(original_item_db_id); continue

                    lect_original_db_pk = lecturer_data.get("original_db_pk_int")
                    room_original_db_pk = classroom_data.get("original_db_pk")   
                    ts_original_db_pk = timeslot_data.get("original_db_pk_int")  

                    if lect_original_db_pk is None or room_original_db_pk is None or ts_original_db_pk is None:
                        self._log_cp(f"ERROR: Missing original_db_pk for item key {item_key_int} (DB_ID: {original_item_db_id}). L_PK:{lect_original_db_pk}, R_PK:{room_original_db_pk}, T_PK:{ts_original_db_pk}. Unscheduled.")
                        self.unscheduled_item_original_db_ids.append(original_item_db_id); continue
                    
                    item_detail_from_data = self.data["scheduled_items"].get(item_key_int, {})
                    solutions_list_of_dicts.append({
                        "schedule_db_id": original_item_db_id, "course_id_str": item_course_id_str, 
                        "lecturer_id_db": lect_original_db_pk, "classroom_id_db": room_original_db_pk, 
                        "timeslot_id_db": ts_original_db_pk, 
                        "course_name": item_detail_from_data.get("course_name", "N/A"),
                        "num_students": item_detail_from_data.get("num_students", 0),
                        "lecturer_name": lecturer_data.get("name", "N/A"),
                        "room_code": classroom_data.get("room_code", "N/A"),
                        "timeslot_info_str": (f"{timeslot_data.get('day_of_week','N/A')} ({timeslot_data.get('start_time','N/A')}-{timeslot_data.get('end_time','N/A')})")
                    })
                    self.num_items_successfully_scheduled += 1
                except cp_model. ennenum.INT_MAX_SENTINEL_TYPE_DO_NOT_USE_DIRECTLY as e_sentinel: 
                    self._log_cp(f"INFO: Var not fixed for item key {item_key_int} (DB_ID: {original_item_db_id}). Err: {e_sentinel}. Unscheduled.")
                    self.unscheduled_item_original_db_ids.append(original_item_db_id)
                except Exception as e_extract_sol:
                    self._log_cp(f"ERROR retrieving solution for item key {item_key_int} (DB_ID: {original_item_db_id}): {e_extract_sol}\n{traceback.format_exc()}")
                    self.unscheduled_item_original_db_ids.append(original_item_db_id)
            
            self._log_cp(f"Finished processing solver results. Extracted {self.num_items_successfully_scheduled} fully scheduled items.")
            if self.num_items_successfully_scheduled == self.num_items_targeted and self.num_items_targeted > 0:
                self._log_cp(f"Successfully scheduled all {self.num_items_targeted} targeted items.")
            elif self.num_items_successfully_scheduled > 0:
                 self._log_cp(f"PARTIAL SCHEDULE: Scheduled {self.num_items_successfully_scheduled}/{self.num_items_targeted} items. "
                              f"{len(self.unscheduled_item_original_db_ids)} items unplaced by CP (DB IDs: {self.unscheduled_item_original_db_ids[:5]}...).")
            else: self._log_cp("WARNING: Solver FEASIBLE/OPTIMAL, but NO valid schedule items extracted.")
        elif status_code == cp_model.INFEASIBLE: self._log_cp("Solver: Model is INFEASIBLE.")
        else: self._log_cp(f"Solver: Stopped with status {self.solver_status}. No solution generated.")
        
        if self.num_items_targeted > 0: self.scheduling_success_rate = (self.num_items_successfully_scheduled / self.num_items_targeted) * 100
        else: self.scheduling_success_rate = 0.0 if self.initial_scheduled_item_keys_int else 100.0 
        
        self._log_cp(f"CP-SAT model solving process ended. Returning {len(solutions_list_of_dicts)} actual scheduled items.")
        return solutions_list_of_dicts, self.get_solution_metrics()

    def get_solution_metrics(self) -> Dict[str, Any]:
        return {
            "solver_status": self.solver_status,
            "solve_time_seconds": round(self.solve_time_seconds, 3),
            "model_build_time_seconds": round(self.model_build_time_seconds, 3),
            "num_items_initial_from_data": len(self.initial_scheduled_item_keys_int),
            "num_items_targeted_for_cp_solver": self.num_items_targeted,
            "num_items_successfully_scheduled_by_cp": self.num_items_successfully_scheduled,
            "cp_scheduling_success_rate_percent": round(self.scheduling_success_rate, 2),
            "num_items_unscheduled_by_cp_solver": len(self.unscheduled_item_original_db_ids),
            "list_of_cp_unscheduled_item_original_db_ids": self.unscheduled_item_original_db_ids[:20]
        }

if __name__ == "__main__":
    current_script_dir = os.path.dirname(os.path.abspath(__file__))
    try:
        from data_loader import load_all_data
        from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json 
    except ImportError as e:
        print(f"CP_MODULE MAIN ERROR: Error importing modules: {e}\n{traceback.format_exc()}")
        exit(1)

    output_dir_cp_real_test = os.path.join(current_script_dir, "output_data_cp_real_test")
    if not os.path.exists(output_dir_cp_real_test): os.makedirs(output_dir_cp_real_test)

    test_semester_id_cp = 1 
    print(f"CP_MODULE MAIN: Test with REAL DATA: Loading for semester_id = {test_semester_id_cp}...")
    
    try:
        db_loaded_data = load_all_data(semester_id_to_load=test_semester_id_cp)
        if not any(db_loaded_data): 
             print(f"CP_MODULE MAIN ERROR: load_all_data for semester {test_semester_id_cp} returned no data. Exiting."); exit(1)
        real_input_scheduled_classes, real_input_instructors, real_input_classrooms, \
        real_input_timeslots, real_input_students, real_input_courses_catalog = db_loaded_data
    except Exception as e_load:
        print(f"CP_MODULE MAIN ERROR: During load_all_data: {e_load}\n{traceback.format_exc()}"); exit(1)

    if not (real_input_instructors and real_input_classrooms and real_input_timeslots and real_input_courses_catalog):
        print(f"CP_MODULE MAIN WARNING: Not all essential catalog data loaded for semester {test_semester_id_cp}.")

    print("CP_MODULE MAIN: Preprocessing loaded data...")
    processed_input_data_for_cp = None
    try:
        processed_input_data_for_cp = preprocess_data_for_cp_and_ga(
            input_scheduled_classes=real_input_scheduled_classes, 
            input_courses_catalog=real_input_courses_catalog,
            input_instructors=real_input_instructors,
            input_classrooms=real_input_classrooms,
            input_timeslots=real_input_timeslots,
            input_students=real_input_students, 
            semester_id_for_settings=test_semester_id_cp,
            priority_settings=None 
        )
    except Exception as e_preprocess:
        print(f"CP_MODULE MAIN ERROR: During utils.preprocess_data_for_cp_and_ga: {e_preprocess}\n{traceback.format_exc()}")
        processed_input_data_for_cp = None 

    # Check if scheduled_items exists and is populated OR if input_scheduled_classes was empty (initial run)
    proceed_to_solve = False
    if processed_input_data_for_cp:
        if processed_input_data_for_cp.get("scheduled_items"):
            proceed_to_solve = True
        elif not real_input_scheduled_classes: # Input was empty, so scheduled_items will be empty
            print("CP_MODULE MAIN INFO: No input scheduled classes, so 'scheduled_items' is empty. This is okay for an initial run from scratch (though CP might not do much).")
            # Depending on design, CP might still run if it's supposed to generate items from a catalog,
            # but current CP design processes existing scheduled_items.
            # For this test, we'll let it proceed if other data is present.
            if processed_input_data_for_cp.get("lecturers") and processed_input_data_for_cp.get("classrooms") and processed_input_data_for_cp.get("timeslots"):
                 # proceed_to_solve = True # Or False, if CP needs scheduled_items
                 print("CP_MODULE MAIN INFO: No items to schedule, CP solver will likely not run or do anything meaningful.")
            else:
                print("CP_MODULE MAIN ERROR: No scheduled_items and other essential processed data missing.")
        else: # Input SCs existed, but scheduled_items is empty -> error in preprocessing or filtering
             print("CP_MODULE MAIN WARNING: Preprocessing successful, but no 'scheduled_items' found while input SCs existed. Check input/preprocessing logs.")
    else:
        print("CP_MODULE MAIN ERROR: Failed to preprocess real data. Cannot proceed with CP-SAT solver test.")


    if proceed_to_solve:
        try:
            time_limit_for_cp_test = 60.0 
            num_potential_items = len(processed_input_data_for_cp.get("scheduled_items", {})) # type: ignore
            print(f"CP_MODULE MAIN: Initializing CP-SAT solver. Processed data has {num_potential_items} potential items.")
            
            def simple_test_logger_cp(msg): print(f"LOGGER_CP_CALLBACK: {msg}")
            cp_solver_instance = CourseSchedulingCPSAT(processed_input_data_for_cp, progress_logger=simple_test_logger_cp) # type: ignore
            
            print(f"CP_MODULE MAIN: Solving with CP-SAT. Time limit: {time_limit_for_cp_test}s")
            cp_schedule_result_list, cp_metrics_result = cp_solver_instance.solve(time_limit_seconds=time_limit_for_cp_test)
            
            print("\n--- CP-SAT Solution Metrics (from cp_module test) ---")
            for key, value in cp_metrics_result.items():
                if key == "list_of_cp_unscheduled_item_original_db_ids" and isinstance(value, list) and len(value) > 5:
                     print(f"  {key}: {value[:5]}... (and {len(value)-5} more)")
                else: print(f"  {key}: {value}")

            output_file_path_cp_test = os.path.join(output_dir_cp_real_test, f"cp_module_real_sem_{test_semester_id_cp}_solution.json")
            final_output_for_json = {
                "test_run_info": {"module": "cp_module.py", "semester_id_tested": test_semester_id_cp, "status_message": "Test completed"},
                "cp_solver_metrics": cp_metrics_result,
                "cp_generated_schedule": cp_schedule_result_list if cp_schedule_result_list else []
            }
            if cp_schedule_result_list:
                print(f"\n--- CP-SAT Feasible Schedule Found ({len(cp_schedule_result_list)} items) ---")
                for item_idx, scheduled_item_dict in enumerate(cp_schedule_result_list[:min(3, len(cp_schedule_result_list))]):
                    print(f"  Item {item_idx + 1}: {scheduled_item_dict}")
                if len(cp_schedule_result_list) > 3: print("  ...")
            else: print(f"\nCP_MODULE MAIN: No complete/feasible schedule found by CP-SAT for semester {test_semester_id_cp}.")
            
            save_output_data_to_json(final_output_for_json, output_file_path_cp_test)
            print(f"CP_MODULE MAIN: Solution and metrics saved to: {output_file_path_cp_test}")
        except Exception as e_solve:
            print(f"CP_MODULE MAIN ERROR: During CP-SAT solving or output saving: {e_solve}\n{traceback.format_exc()}")
    
    print("\nCP_MODULE MAIN: Test finished.")