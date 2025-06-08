from ortools.sat.python import cp_model
import time as pytime
from collections import defaultdict 
import traceback
from typing import Optional, Dict, Any, List, Tuple
import os 
import sys 

class CourseSchedulingCPSAT:
    def __init__(self,
                 processed_data: dict,
                 progress_logger: Optional[callable] = None,
                 run_type: str = "admin_optimize_semester"):

        self.data = processed_data
        self.model = cp_model.CpModel()
        self.progress_logger = progress_logger
        self.run_type = run_type

        self.initial_scheduled_item_keys_int: List[int] = list(self.data.get("scheduled_items", {}).keys())

        self.all_lecturer_ids_int_mapped: List[int] = list(self.data.get("lecturers", {}).keys())
        self.all_classroom_ids_int_mapped: List[int] = list(self.data.get("classrooms", {}).keys())
        self.all_timeslot_ids_int_mapped: List[int] = list(self.data.get("timeslots", {}).keys())

        self.mappings = self.data.get("mappings", {})
        self.item_vars: Dict[int, Dict[str, Any]] = {}
        self.items_to_schedule_keys_int: List[int] = []

        self.solver_status: Optional[str] = None
        self.solve_time_seconds: float = 0.0
        self.model_build_time_seconds: float = 0.0
        self.num_items_targeted: int = 0
        self.num_items_successfully_scheduled: int = 0
        self.scheduling_success_rate: float = 0.0
        self.unscheduled_item_original_ids: List[Any] = []

        self._log_cp(f"CP-SAT Module Initialized. Run Type: '{self.run_type}'.")
        if not self.initial_scheduled_item_keys_int:
            self._log_cp("WARNING: No initial 'scheduled_items' found in processed_data. Solver might have no tasks.")

    def _log_cp(self, message: str):
        prefix = "CP_SAT"
        if self.progress_logger:
            self.progress_logger(f"{prefix}: {message}")
        else:
            print(f"{prefix}_STDOUT: {message}")

    def _pre_filter_and_create_variables(self):
        self._log_cp("Starting pre-filtering and decision variable creation...")
        if not self.initial_scheduled_item_keys_int:
            self._log_cp("Pre-filter SKIPPED: No initial items to process.")
            return

        temp_items_to_schedule_keys_after_filter: List[int] = []
        items_skipped_count = 0
        
        course_lecturer_map = self.data.get("course_potential_lecturers_map", {})

        for item_key_int_mapped_idx in self.initial_scheduled_item_keys_int:
            item_details_from_processed_data = self.data["scheduled_items"].get(item_key_int_mapped_idx)
            if not item_details_from_processed_data:
                self._log_cp(f"WARNING: Pre-filter - Mapped Item Key {item_key_int_mapped_idx} not found. Skipping.")
                items_skipped_count += 1
                continue

            original_item_id = item_details_from_processed_data.get("original_id")
            course_id_str_for_item = item_details_from_processed_data.get("course_id_str")
            item_num_students = item_details_from_processed_data.get("num_students", 0)

            if item_num_students <= 0 and self.run_type != "student_schedule_request":
                 self._log_cp(f"WARNING: Pre-filter - Item {item_key_int_mapped_idx} (Course: {course_id_str_for_item}) has {item_num_students} students.")
            
            lecturer_cp_var = None
            fixed_lecturer_id_for_item: Optional[int] = None
            potential_lecturers_for_this_item_mapped_ids: List[int] = []


            if self.run_type == "admin_optimize_semester":
                pre_assigned_lecturer_mapped_int_id = item_details_from_processed_data.get("assigned_instructor_mapped_int_id")
                if pre_assigned_lecturer_mapped_int_id is None:
                    self._log_cp(f"INFO: Admin Run - Item {item_key_int_mapped_idx} (Course: {course_id_str_for_item}) has NO pre-assigned instructor. Skipping this item as current admin logic requires it.")
                    items_skipped_count += 1
                    continue
                if pre_assigned_lecturer_mapped_int_id not in self.all_lecturer_ids_int_mapped:
                    self._log_cp(f"ERROR: Admin Run - Item {item_key_int_mapped_idx} (Course: {course_id_str_for_item}) has invalid pre-assigned instructor ID ({pre_assigned_lecturer_mapped_int_id}). Skipping.")
                    items_skipped_count += 1
                    continue
                lecturer_cp_var = self.model.NewIntVar(pre_assigned_lecturer_mapped_int_id, pre_assigned_lecturer_mapped_int_id, name=f"item_{item_key_int_mapped_idx}_lect_fixed")
                fixed_lecturer_id_for_item = pre_assigned_lecturer_mapped_int_id
                potential_lecturers_for_this_item_mapped_ids = [pre_assigned_lecturer_mapped_int_id]

            elif self.run_type == "student_schedule_request":
                if not self.all_lecturer_ids_int_mapped:
                    self._log_cp(f"ERROR: Student Run - No lecturers available system-wide. Cannot assign lecturer for item {item_key_int_mapped_idx}. Skipping.")
                    items_skipped_count += 1
                    continue
                
                potential_lecturers_for_this_item_mapped_ids = course_lecturer_map.get(course_id_str_for_item, [])
                
                if not potential_lecturers_for_this_item_mapped_ids:
                    self._log_cp(f"WARNING: Student Run - Item {item_key_int_mapped_idx} (Course: {course_id_str_for_item}): No specific lecturers found in course_potential_lecturers_map. Falling back to all available lecturers.")
                    potential_lecturers_for_this_item_mapped_ids = self.all_lecturer_ids_int_mapped
                    if not potential_lecturers_for_this_item_mapped_ids:
                         self._log_cp(f"ERROR: Student Run - Fallback failed, no lecturers available at all for item {item_key_int_mapped_idx}. Skipping.")
                         items_skipped_count += 1
                         continue

                lecturer_cp_var = self.model.NewIntVarFromDomain(
                    cp_model.Domain.FromValues(potential_lecturers_for_this_item_mapped_ids),
                    name=f"item_{item_key_int_mapped_idx}_lect_choice"
                )
            else:
                self._log_cp(f"ERROR: Pre-filter - Unknown run_type '{self.run_type}'. Skipping item {item_key_int_mapped_idx}.")
                items_skipped_count += 1
                continue

            suitable_classrooms_for_item_mapped_ids = [
                cr_mapped_int_id for cr_mapped_int_id in self.all_classroom_ids_int_mapped
                if self.data["classrooms"].get(cr_mapped_int_id, {}).get("capacity", 0) >= item_num_students
            ]
            if not suitable_classrooms_for_item_mapped_ids:
                self._log_cp(f"INFO: Pre-filter - Item {item_key_int_mapped_idx} (Course: {course_id_str_for_item}, Students: {item_num_students}) unschedulable: no suitable classrooms. Skipping.")
                items_skipped_count += 1
                continue
            classroom_cp_var = self.model.NewIntVarFromDomain(
                cp_model.Domain.FromValues(suitable_classrooms_for_item_mapped_ids),
                name=f"item_{item_key_int_mapped_idx}_room"
            )

            if not self.all_timeslot_ids_int_mapped:
                self._log_cp(f"ERROR: Pre-filter - No timeslots available. Cannot assign timeslot for item {item_key_int_mapped_idx}. Skipping.")
                items_skipped_count += 1
                continue
            timeslot_cp_var = self.model.NewIntVarFromDomain(
                cp_model.Domain.FromValues(self.all_timeslot_ids_int_mapped),
                name=f"item_{item_key_int_mapped_idx}_ts"
            )

            temp_items_to_schedule_keys_after_filter.append(item_key_int_mapped_idx)
            self.item_vars[item_key_int_mapped_idx] = {
                "lecturer_var": lecturer_cp_var,
                "classroom_var": classroom_cp_var,
                "timeslot_var": timeslot_cp_var,
                "fixed_assigned_lecturer_mapped_int_id": fixed_lecturer_id_for_item,
                "lecturer_domain_values_int": potential_lecturers_for_this_item_mapped_ids,
                "classroom_domain_values_int": suitable_classrooms_for_item_mapped_ids,
                "timeslot_domain_values_int": self.all_timeslot_ids_int_mapped,
                "original_id": original_item_id,
                "course_id_str": course_id_str_for_item,
                "num_students": item_num_students
            }

        self.items_to_schedule_keys_int = temp_items_to_schedule_keys_after_filter
        self.num_items_targeted = len(self.items_to_schedule_keys_int)

        if items_skipped_count > 0:
             self._log_cp(f"Pre-filter: Skipped {items_skipped_count} item(s).")
        if not self.items_to_schedule_keys_int:
             self._log_cp("WARNING: Pre-filter - No items schedulable after pre-filtering.")
        else:
            self._log_cp(f"Pre-filter: {self.num_items_targeted} item(s) potentially schedulable; variables created.")
        self._log_cp("Finished pre-filtering and variable creation phase.")

    def _add_constraints(self):
        self._log_cp("Adding hard constraints to the CP-SAT model...")
        if not self.items_to_schedule_keys_int or not self.item_vars:
            self._log_cp("Constraint Addition SKIPPED: No items or variables defined.")
            return

        num_items = len(self.items_to_schedule_keys_int)

        # HC: Classroom-Timeslot exclusivity (a classroom cannot be used by two different items at the same timeslot)
        if num_items > 1:
            for i in range(num_items):
                for j in range(i + 1, num_items):
                    item1_k = self.items_to_schedule_keys_int[i]
                    item2_k = self.items_to_schedule_keys_int[j]
                    if item1_k not in self.item_vars or item2_k not in self.item_vars: continue

                    vars1 = self.item_vars[item1_k]
                    vars2 = self.item_vars[item2_k]

                    b_room_eq = self.model.NewBoolVar(f'b_room_eq_{item1_k}_{item2_k}')
                    self.model.Add(vars1["classroom_var"] == vars2["classroom_var"]).OnlyEnforceIf(b_room_eq)
                    self.model.Add(vars1["classroom_var"] != vars2["classroom_var"]).OnlyEnforceIf(b_room_eq.Not())
                    self.model.Add(vars1["timeslot_var"] != vars2["timeslot_var"]).OnlyEnforceIf(b_room_eq)
        
        if self.run_type == "admin_optimize_semester":
            # HC: Lecturer-Timeslot exclusivity (fixed lecturer can't teach two classes at same time)
            if num_items > 1:
                for i in range(num_items):
                    for j in range(i + 1, num_items):
                        item1_k_adm = self.items_to_schedule_keys_int[i]
                        item2_k_adm = self.items_to_schedule_keys_int[j]
                        if item1_k_adm not in self.item_vars or item2_k_adm not in self.item_vars: continue
                        
                        vars1_adm = self.item_vars[item1_k_adm]
                        vars2_adm = self.item_vars[item2_k_adm]
                        
                        if vars1_adm["fixed_assigned_lecturer_mapped_int_id"] is not None and \
                           vars1_adm["fixed_assigned_lecturer_mapped_int_id"] == vars2_adm.get("fixed_assigned_lecturer_mapped_int_id"):
                            self.model.Add(vars1_adm["timeslot_var"] != vars2_adm["timeslot_var"])
            
            # HC: Fixed lecturer cannot be scheduled in their unavailable slots.
            for item_k_adm_hc3 in self.items_to_schedule_keys_int:
                if item_k_adm_hc3 not in self.item_vars: continue
                item_vars_adm = self.item_vars[item_k_adm_hc3]
                fixed_lect_id = item_vars_adm.get("fixed_assigned_lecturer_mapped_int_id")
                item_ts_var_adm = item_vars_adm["timeslot_var"]
                
                if fixed_lect_id is not None:
                    lect_details = self.data["lecturers"].get(fixed_lect_id)
                    if lect_details:
                        for busy_ts_id in lect_details.get("unavailable_slot_ids_mapped", []):
                            self.model.Add(item_ts_var_adm != busy_ts_id)

        elif self.run_type == "student_schedule_request":
            # HC: Lecturer-Timeslot exclusivity (variable lecturer can't teach two classes at same time)
            if num_items > 1:
                for i in range(num_items):
                    for j in range(i + 1, num_items):
                        item1_k_std = self.items_to_schedule_keys_int[i]
                        item2_k_std = self.items_to_schedule_keys_int[j]
                        if item1_k_std not in self.item_vars or item2_k_std not in self.item_vars: continue

                        vars1_std = self.item_vars[item1_k_std]
                        vars2_std = self.item_vars[item2_k_std]

                        b_lect_eq_std = self.model.NewBoolVar(f'b_lect_eq_std_{item1_k_std}_{item2_k_std}')
                        self.model.Add(vars1_std["lecturer_var"] == vars2_std["lecturer_var"]).OnlyEnforceIf(b_lect_eq_std)
                        self.model.Add(vars1_std["lecturer_var"] != vars2_std["lecturer_var"]).OnlyEnforceIf(b_lect_eq_std.Not())
                        self.model.Add(vars1_std["timeslot_var"] != vars2_std["timeslot_var"]).OnlyEnforceIf(b_lect_eq_std)

            # HC: An item's assigned lecturer cannot be unavailable in the item's assigned timeslot.
            for item_k_std_hc3 in self.items_to_schedule_keys_int:
                if item_k_std_hc3 not in self.item_vars: continue
                item_lect_var = self.item_vars[item_k_std_hc3]["lecturer_var"]
                item_ts_var = self.item_vars[item_k_std_hc3]["timeslot_var"]

                for lecturer_mapped_id, lect_detail in self.data.get("lecturers", {}).items():
                    unavailable_slots = lect_detail.get("unavailable_slot_ids_mapped", [])
                    if unavailable_slots:
                        for busy_ts_id in unavailable_slots:
                            b_is_this_lect = self.model.NewBoolVar(f'b_item{item_k_std_hc3}_is_lect{lecturer_mapped_id}_busy_slot{busy_ts_id}')
                            self.model.Add(item_lect_var == lecturer_mapped_id).OnlyEnforceIf(b_is_this_lect)
                            self.model.Add(item_lect_var != lecturer_mapped_id).OnlyEnforceIf(b_is_this_lect.Not())
                            self.model.Add(item_ts_var != busy_ts_id).OnlyEnforceIf(b_is_this_lect)
            
            # HC: Student Self-Clash (all items for this student must be in different timeslots).
            if num_items > 1:
                student_item_timeslot_vars = [self.item_vars[key]["timeslot_var"] for key in self.items_to_schedule_keys_int if key in self.item_vars]
                if len(student_item_timeslot_vars) > 1 :
                    self.model.AddAllDifferent(student_item_timeslot_vars)
        
        self._log_cp("Finished adding hard constraints.")


    def solve(self, time_limit_seconds: float = 30.0) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
        self._log_cp(f"CP-SAT Solver Process Starting for run_type: '{self.run_type}'.")
        self.solver_status = None; self.solve_time_seconds = 0.0; self.model_build_time_seconds = 0.0
        self.num_items_successfully_scheduled = 0; self.scheduling_success_rate = 0.0
        self.unscheduled_item_original_ids = []

        model_build_start_time = pytime.time()
        try:
            self._pre_filter_and_create_variables()
            if not self.items_to_schedule_keys_int or not self.item_vars:
                self._log_cp("Solver ABORTED: No schedulable items after pre-filtering.")
                self.solver_status = "NO_ITEMS_POST_PREFILTER"
                self.model_build_time_seconds = pytime.time() - model_build_start_time
                return [], self.get_solution_metrics()
            
            self._add_constraints()
        except Exception as e_model_build:
            self._log_cp(f"CRITICAL ERROR during CP-SAT model building: {e_model_build}\n{traceback.format_exc()}")
            self.model_build_time_seconds = pytime.time() - model_build_start_time
            self.solver_status = "MODEL_BUILD_ERROR"
            return [], self.get_solution_metrics()
        
        self.model_build_time_seconds = pytime.time() - model_build_start_time

        if self.num_items_targeted == 0:
            self._log_cp("Solver SKIPPED: No items targeted for solving. Returning empty.")
            self.solver_status = "NO_ITEMS_TARGETED"
            return [], self.get_solution_metrics()

        cp_solver_instance = cp_model.CpSolver()
        cp_solver_instance.parameters.log_search_progress = False
        if time_limit_seconds is not None and time_limit_seconds > 0:
            cp_solver_instance.parameters.max_time_in_seconds = float(time_limit_seconds)
        
        solver_start_time = pytime.time()
        solution_status_code = cp_solver_instance.Solve(self.model)
        self.solve_time_seconds = pytime.time() - solver_start_time
        self.solver_status = cp_solver_instance.StatusName(solution_status_code)
        self._log_cp(f"CP-SAT solver finished. Time: {self.solve_time_seconds:.3f}s. Status: {self.solver_status}")

        extracted_solutions_list: List[Dict[str, Any]] = []
        if solution_status_code == cp_model.OPTIMAL or solution_status_code == cp_model.FEASIBLE:
            for item_key_sol in self.items_to_schedule_keys_int:
                item_vars_extract = self.item_vars.get(item_key_sol)
                if not item_vars_extract: continue

                original_item_id_sol = item_vars_extract["original_id"]
                try:
                    assigned_lect_mapped_id = cp_solver_instance.Value(item_vars_extract["lecturer_var"])
                    assigned_room_mapped_id = cp_solver_instance.Value(item_vars_extract["classroom_var"])
                    assigned_ts_mapped_id = cp_solver_instance.Value(item_vars_extract["timeslot_var"])

                    lect_details_sol = self.data["lecturers"].get(assigned_lect_mapped_id)
                    room_details_sol = self.data["classrooms"].get(assigned_room_mapped_id)
                    ts_details_sol = self.data["timeslots"].get(assigned_ts_mapped_id)

                    if not (lect_details_sol and room_details_sol and ts_details_sol):
                        self._log_cp(f"WARN: Missing details for mapped IDs for item {original_item_id_sol}. Marking unscheduled.")
                        self.unscheduled_item_original_ids.append(original_item_id_sol); continue
                    
                    orig_lect_pk = lect_details_sol.get("original_db_pk_int")
                    orig_room_pk = room_details_sol.get("original_db_pk")
                    orig_ts_pk   = ts_details_sol.get("original_db_pk_int")

                    if orig_lect_pk is None or orig_room_pk is None or orig_ts_pk is None:
                         self._log_cp(f"WARN: Missing original DB PK for assigned entities for item {original_item_id_sol}. Marking unscheduled.")
                         self.unscheduled_item_original_ids.append(original_item_id_sol); continue

                    item_details_proc_data = self.data["scheduled_items"].get(item_key_sol, {})

                    extracted_solutions_list.append({
                        "schedule_db_id": original_item_id_sol,
                        "course_id_str": item_vars_extract["course_id_str"],
                        "lecturer_id_db": orig_lect_pk,
                        "classroom_id_db": orig_room_pk,
                        "timeslot_id_db": orig_ts_pk,
                        "num_students": item_vars_extract.get("num_students", 0),
                        "course_name": item_details_proc_data.get("course_name", "N/A"),
                        "lecturer_name": lect_details_sol.get("name", "N/A"),
                        "room_code": room_details_sol.get("room_code", "N/A"),
                        "timeslot_info_str": (f"{ts_details_sol.get('day_of_week','')} "
                                              f"({ts_details_sol.get('start_time','')}-"
                                              f"{ts_details_sol.get('end_time','')})")
                    })
                    self.num_items_successfully_scheduled += 1
                except Exception as e_extract:
                    self._log_cp(f"ERROR: Solution extraction failed for item {original_item_id_sol}: {e_extract}")
                    self.unscheduled_item_original_ids.append(original_item_id_sol)
            
            if self.num_items_successfully_scheduled < self.num_items_targeted and self.num_items_targeted > 0:
                 self._log_cp(f"WARNING: PARTIAL SCHEDULE. Scheduled {self.num_items_successfully_scheduled}/{self.num_items_targeted} items by CP-SAT.")

        elif solution_status_code == cp_model.INFEASIBLE:
            self._log_cp("Solver Result: Model is INFEASIBLE. No solution satisfying all hard constraints exists.")
            self.unscheduled_item_original_ids = [self.item_vars[key]["original_id"] for key in self.items_to_schedule_keys_int if key in self.item_vars]
        else:
            self._log_cp(f"Solver Result: Stopped with status '{self.solver_status}'. No solution generated or not guaranteed feasible/optimal.")
            self.unscheduled_item_original_ids = [self.item_vars[key]["original_id"] for key in self.items_to_schedule_keys_int if key in self.item_vars]

        if self.num_items_targeted > 0:
            self.scheduling_success_rate = (self.num_items_successfully_scheduled / self.num_items_targeted) * 100
        elif not self.initial_scheduled_item_keys_int: self.scheduling_success_rate = 100.0
        else: self.scheduling_success_rate = 0.0

        return extracted_solutions_list, self.get_solution_metrics()

    def get_solution_metrics(self) -> Dict[str, Any]:
        return {
            "solver_status": self.solver_status,
            "solve_time_seconds": round(self.solve_time_seconds, 3),
            "model_build_time_seconds": round(self.model_build_time_seconds, 3),
            "num_items_initial_from_data": len(self.initial_scheduled_item_keys_int),
            "num_items_targeted_for_cp_solver": self.num_items_targeted,
            "num_items_successfully_scheduled_by_cp": self.num_items_successfully_scheduled,
            "cp_scheduling_success_rate_percent": round(self.scheduling_success_rate, 2),
            "num_items_unscheduled_by_cp_solver": len(self.unscheduled_item_original_ids),
            "list_of_cp_unscheduled_item_original_ids": self.unscheduled_item_original_ids[:10]
        }

if __name__ == "__main__":
    current_script_dir_cp_test = os.path.dirname(os.path.abspath(__file__))

    # Note: These imports are required for the standalone test to run.
    # In a full project, these would likely be handled by a higher-level script or module.
    try:
        from data_loader import load_all_data
        from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json
        from models import ScheduledClass, Course
    except ImportError as e_import_test_cp:
        print(f"CP_MODULE_TEST ERROR: Could not import dependencies: {e_import_test_cp}", file=sys.stderr)
        exit(1)

    output_dir_for_cp_module_test = os.path.join(current_script_dir_cp_test, "output_data_cp_module_test")
    os.makedirs(output_dir_for_cp_module_test, exist_ok=True)

    test_semester_id_for_cp = 1
    test_run_type_for_cp = "student_schedule_request"

    try:
        _input_scheduled_classes_from_db, \
        _input_instructors, \
        _input_classrooms, \
        _input_timeslots, \
        _input_students, \
        _input_courses_catalog_master = load_all_data(semester_id_to_load=test_semester_id_for_cp)

        if not (_input_instructors and _input_classrooms and _input_timeslots and _input_courses_catalog_master):
            print(f"CP_MODULE_TEST WARNING: Not all essential catalog data loaded for semester {test_semester_id_for_cp}. Test might be limited.", file=sys.stderr)
    except Exception as e_load_test_cp:
        print(f"CP_MODULE_TEST ERROR: During data_loader.load_all_data: {e_load_test_cp}", file=sys.stderr)
        traceback.print_exc(file=sys.stderr)
        exit(1)

    reference_data_for_lecturer_map = _input_scheduled_classes_from_db

    items_to_preprocess_for_test = []
    student_priority_settings_for_test: Optional[Dict[str, Any]] = None

    if test_run_type_for_cp == "admin_optimize_semester":
        items_to_preprocess_for_test = _input_scheduled_classes_from_db
        if not items_to_preprocess_for_test:
            print(f"CP_MODULE_TEST INFO: Admin run, but no pre-existing scheduled classes in DB for semester {test_semester_id_for_cp}.")
    elif test_run_type_for_cp == "student_schedule_request":
        student_requested_course_ids_test = ["BSA105501", "INE105001"]
        temp_id_test = -1
        for course_id_test_req in student_requested_course_ids_test:
            course_details_test = _input_courses_catalog_master.get(course_id_test_req)
            if course_details_test:
                items_to_preprocess_for_test.append(
                    ScheduledClass(id=temp_id_test, course_id=course_id_test_req, semester_id=test_semester_id_for_cp,
                                   num_students=course_details_test.expected_students or 25)
                )
                temp_id_test -=1
            else:
                print(f"CP_MODULE_TEST WARNING: Mock student request: CourseID '{course_id_test_req}' not found in catalog.")
        if not items_to_preprocess_for_test:
             print(f"CP_MODULE_TEST ERROR: Student run, failed to create any virtual items from requested courses.", file=sys.stderr); exit(1)
        student_priority_settings_for_test = {"student_id": "S_TEST_CP_001"}

    processed_input_for_cp_test = None
    try:
        processed_input_for_cp_test = preprocess_data_for_cp_and_ga(
            input_scheduled_classes=items_to_preprocess_for_test,
            input_courses_catalog=_input_courses_catalog_master,
            input_instructors=_input_instructors,
            input_classrooms=_input_classrooms,
            input_timeslots=_input_timeslots,
            input_students=_input_students,
            reference_scheduled_classes=reference_data_for_lecturer_map,
            semester_id_for_settings=test_semester_id_for_cp,
            priority_settings=student_priority_settings_for_test,
            run_type=test_run_type_for_cp
        )
    except Exception as e_preprocess_test_cp:
        print(f"CP_MODULE_TEST ERROR: During utils.preprocess_data_for_cp_and_ga: {e_preprocess_test_cp}", file=sys.stderr)
        traceback.print_exc(file=sys.stderr)
        exit(1)

    if not processed_input_for_cp_test:
        print("CP_MODULE_TEST ERROR: Preprocessing returned no data.", file=sys.stderr)
        exit(1)
    if not processed_input_for_cp_test.get("scheduled_items") and items_to_preprocess_for_test :
        print("CP_MODULE_TEST ERROR: Preprocessing successful, but no 'scheduled_items' found in processed data, while input items existed.", file=sys.stderr)

    if processed_input_for_cp_test.get("scheduled_items"):
        try:
            time_limit_for_cp_module_test = 15.0

            def cp_module_test_logger(msg_test_cp): pass

            cp_solver_test_instance = CourseSchedulingCPSAT(
                processed_input_for_cp_test,
                progress_logger=cp_module_test_logger,
                run_type=test_run_type_for_cp
            )

            cp_schedule_result_list_test, cp_metrics_result_test = cp_solver_test_instance.solve(time_limit_seconds=time_limit_for_cp_module_test)

            output_file_path_for_cp_test_run = os.path.join(output_dir_for_cp_module_test, f"cp_module_test_sem{test_semester_id_for_cp}_runtype_{test_run_type_for_cp}.json")
            final_output_for_json_test = {
                "test_run_info": {"module": "cp_module.py_standalone", "semester_id": test_semester_id_for_cp, "run_type": test_run_type_for_cp},
                "cp_solver_metrics": cp_metrics_result_test,
                "cp_generated_schedule": cp_schedule_result_list_test if cp_schedule_result_list_test else []
            }

            save_output_data_to_json(final_output_for_json_test, output_file_path_for_cp_test_run)

        except Exception as e_solve_test_cp:
            print(f"CP_MODULE_TEST ERROR: During CP-SAT solving or output saving: {e_solve_test_cp}", file=sys.stderr)
            traceback.print_exc(file=sys.stderr)
    else:
        print("CP_MODULE_TEST INFO: Skipping CP-SAT solving as no schedulable items were prepared by utils.py.")

    print("\n--- CP_MODULE.PY: Standalone Test Finished ---")