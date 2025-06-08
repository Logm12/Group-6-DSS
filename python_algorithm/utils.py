import json
from datetime import datetime, time as dt_time, date, timedelta
import traceback
from typing import List, Dict, Tuple, Any, Set, Optional
from collections import defaultdict
from dataclasses import asdict, field, dataclass
import os
import sys

try:
    from models import ScheduledClass, Course, Instructor, Classroom, TimeSlot, Student
    MODELS_IMPORTED_SUCCESSFULLY = True
except ImportError as e_models:
    MODELS_IMPORTED_SUCCESSFULLY = False
    @dataclass(frozen=True)
    class TimeSlot: id: str; day_of_week: str; start_time: str; end_time: str
    @dataclass(frozen=True)
    class Classroom: id: int; room_code: str; capacity: int; type: str = 'Theory'
    @dataclass
    class Instructor:
        id: str; name: str; unavailable_slot_ids: Set[str] = field(default_factory=set)
        def __hash__(self): return hash(self.id)
        def __eq__(self, other): return isinstance(other, Instructor) and self.id == other.id
    @dataclass
    class Course:
        id: str; name: str; expected_students: int
        credits: Optional[int] = None; required_periods_per_session: int = 1
        def __hash__(self): return hash(self.id)
        def __eq__(self, other): return isinstance(other, Course) and self.id == other.id
    @dataclass(frozen=True)
    class Student: id: str; name: Optional[str] = None; enrolled_course_ids: Set[str] = field(default_factory=set)
    @dataclass
    class ScheduledClass:
        id: int
        course_id: str
        semester_id: int
        num_students: int
        instructor_id: Optional[str] = None
        classroom_id: Optional[int] = None
        timeslot_id: Optional[str] = None


def parse_time(t_str: Any) -> Optional[dt_time]:
    if isinstance(t_str, dt_time): return t_str
    if isinstance(t_str, timedelta): return (datetime.min + t_str).time()
    if not isinstance(t_str, str): return None
    try: return datetime.strptime(t_str, '%H:%M:%S').time()
    except ValueError:
        try: return datetime.strptime(t_str, '%H:%M').time()
        except ValueError: return None

DEFAULT_SETTINGS = {
    "penalty_student_clash_base": 1000.0, "penalty_lecturer_overload_base": 50.0,
    "penalty_lecturer_underload_base": 30.0, "penalty_lecturer_insufficient_break_base": 40.0,
    "penalty_classroom_capacity_violation_base": 10000.0,
    "penalty_classroom_underutilized_base": 10.0, "penalty_classroom_slightly_empty_base": 2.0,
    "lecturer_target_teaching_periods_per_week_min": 4,
    "lecturer_target_teaching_periods_per_week_max": 12,
    "penalty_lecturer_workload_deviation_base": 20.0,
    "classroom_slightly_empty_multiplier_base": 10.0,
    "target_classroom_fill_ratio_min": 0.5,
    "lecturer_min_break_minutes": 10, "student_max_consecutive_slots": 3,
    "target_classroom_fill_ratio_max": 1.0,
    "penalty_student_preference_violation_base": 100.0
}

def save_output_data_to_json(data: Any, filepath: str):
    try:
        def convert_special_types(obj):
            if hasattr(type(obj), '__dataclass_fields__'): return asdict(obj)
            if isinstance(obj, set): return sorted(list(obj))
            if isinstance(obj, (datetime, date, dt_time)): return obj.isoformat()
            if isinstance(obj, timedelta):
                 total_seconds = int(obj.total_seconds()); hours, rem = divmod(total_seconds, 3600); mins, secs = divmod(rem, 60)
                 return f"{hours:02d}:{mins:02d}:{secs:02d}"
            if hasattr(obj, '__dict__') and not callable(obj.__dict__): return obj.__dict__
            raise TypeError(f"Object of type {obj.__class__.__name__} is not JSON serializable.")

        os.makedirs(os.path.dirname(filepath), exist_ok=True)
        with open(filepath, 'w', encoding='utf-8') as f_json:
            json.dump(data, f_json, indent=4, ensure_ascii=False, default=convert_special_types)
    except Exception as e_json_save:
        print(f"UTILS ERROR: Error saving data to {filepath}: {e_json_save}", file=sys.stderr)

def preprocess_data_for_cp_and_ga(
    input_scheduled_classes: List[ScheduledClass],
    input_courses_catalog: Dict[str, Course],
    input_instructors: List[Instructor],
    input_classrooms: List[Classroom],
    input_timeslots: List[TimeSlot],
    input_students: List[Student],
    reference_scheduled_classes: List[ScheduledClass],
    semester_id_for_settings: Optional[int] = None,
    priority_settings: Optional[Dict[str, Any]] = None,
    run_type: str = "admin_optimize_semester"
) -> Dict[str, Any]:

    processed_data: Dict[str, Any] = {
        "settings": DEFAULT_SETTINGS.copy(),
        "scheduled_items": {},
        "lecturers": {},
        "classrooms": {},
        "timeslots": {},
        "courses_catalog_map": {},
        "mappings": {
            "lecturer_str_id_to_int_map": {}, "lecturer_int_map_to_str_id": {},
            "timeslot_str_id_to_int_map": {}, "timeslot_int_map_to_str_id": {},
            "classroom_pk_to_int_map": {}, "classroom_int_map_to_pk": {},
            "scheduled_item_original_id_to_idx_map": {}, "scheduled_item_idx_to_original_id_map": {},
        },
        "student_enrollments_by_course_id": defaultdict(set),
        "courses_enrolled_by_student_id": defaultdict(set),
        "course_potential_lecturers_map": defaultdict(set)
    }

    if semester_id_for_settings is not None:
        processed_data["settings"]["current_semester_id"] = semester_id_for_settings

    if priority_settings:
        if run_type == "student_schedule_request" and "student_id" in priority_settings:
            processed_data["settings"]["student_id"] = priority_settings["student_id"]

        priority_multipliers = {"low": 0.5, "medium": 1.0, "high": 2.0, "very_high": 5.0, "critical": 10.0}
        def get_effective_penalty(base_key: str, config_key_for_prio: str) -> float:
            base_val = processed_data["settings"].get(base_key, 0.0)
            prio_level = str(priority_settings.get(config_key_for_prio, "medium")).lower()
            multiplier = priority_multipliers.get(prio_level, 1.0)
            return float(base_val * multiplier)

        penalty_map_config = {
            "penalty_student_clash": ("penalty_student_clash_base", "student_clash"),
            "penalty_student_preference_violation": ("penalty_student_preference_violation_base", "student_preferences"),
            "penalty_lecturer_overload": ("penalty_lecturer_overload_base", "lecturer_load_break"),
            "penalty_lecturer_underload": ("penalty_lecturer_underload_base", "lecturer_load_break"),
            "penalty_lecturer_insufficient_break": ("penalty_lecturer_insufficient_break_base", "lecturer_load_break"),
            "penalty_lecturer_workload_deviation": ("penalty_lecturer_workload_deviation_base", "lecturer_load_break"),
            "penalty_classroom_capacity_violation": ("penalty_classroom_capacity_violation_base", "classroom_util"),
            "penalty_classroom_underutilized": ("penalty_classroom_underutilized_base", "classroom_util"),
            "penalty_classroom_slightly_empty": ("penalty_classroom_slightly_empty_base", "classroom_util"),
        }
        for setting_target, (base_key_name, config_key_name) in penalty_map_config.items():
            if base_key_name in processed_data["settings"]:
                processed_data["settings"][setting_target] = get_effective_penalty(base_key_name, config_key_name)

    id_counters = {"lecturer": 0, "timeslot": 0, "classroom": 0, "scheduled_item": 0}

    for instructor_obj in input_instructors:
        orig_id_str = str(instructor_obj.id)
        if orig_id_str not in processed_data["mappings"]["lecturer_str_id_to_int_map"]:
            mapped_id = id_counters["lecturer"]
            processed_data["mappings"]["lecturer_str_id_to_int_map"][orig_id_str] = mapped_id
            processed_data["mappings"]["lecturer_int_map_to_str_id"][mapped_id] = orig_id_str
            id_counters["lecturer"] += 1

    for timeslot_obj in input_timeslots:
        original_ts_id_str = str(timeslot_obj.id)
        if original_ts_id_str not in processed_data["mappings"]["timeslot_str_id_to_int_map"]:
            mapped_ts_id = id_counters["timeslot"]
            processed_data["mappings"]["timeslot_str_id_to_int_map"][original_ts_id_str] = mapped_ts_id
            processed_data["mappings"]["timeslot_int_map_to_str_id"][mapped_ts_id] = original_ts_id_str
            id_counters["timeslot"] += 1

    for classroom_obj in input_classrooms:
        orig_cr_pk = classroom_obj.id
        if orig_cr_pk not in processed_data["mappings"]["classroom_pk_to_int_map"]:
            mapped_cr_id = id_counters["classroom"]
            processed_data["mappings"]["classroom_pk_to_int_map"][orig_cr_pk] = mapped_cr_id
            processed_data["mappings"]["classroom_int_map_to_pk"][mapped_cr_id] = orig_cr_pk
            id_counters["classroom"] += 1

    for instructor_obj in input_instructors:
        orig_model_id_str = str(instructor_obj.id)
        mapped_lect_int_id = processed_data["mappings"]["lecturer_str_id_to_int_map"].get(orig_model_id_str)
        if mapped_lect_int_id is None: continue

        unavail_mapped_slot_ids = {
            processed_data["mappings"]["timeslot_str_id_to_int_map"].get(str(ts_busy_id))
            for ts_busy_id in instructor_obj.unavailable_slot_ids
            if str(ts_busy_id) in processed_data["mappings"]["timeslot_str_id_to_int_map"]
        }
        try: original_db_pk_lect = int(orig_model_id_str)
        except ValueError: original_db_pk_lect = -1

        processed_data["lecturers"][mapped_lect_int_id] = {
            "name": instructor_obj.name,
            "original_model_id_str": orig_model_id_str,
            "original_db_pk_int": original_db_pk_lect,
            "unavailable_slot_ids_mapped": sorted(list(unavail_mapped_slot_ids))
        }

    for timeslot_obj in input_timeslots:
        original_model_id_str = str(timeslot_obj.id)
        mapped_ts_int_id = processed_data["mappings"]["timeslot_str_id_to_int_map"].get(original_model_id_str)
        if mapped_ts_int_id is None: continue
        try: original_db_pk_ts = int(original_model_id_str)
        except ValueError: original_db_pk_ts = -1

        processed_data["timeslots"][mapped_ts_int_id] = {
            "day_of_week": timeslot_obj.day_of_week,
            "start_time": timeslot_obj.start_time,
            "end_time": timeslot_obj.end_time,
            "original_model_id_str": original_model_id_str,
            "original_db_pk_int": original_db_pk_ts
        }

    for classroom_obj in input_classrooms:
        original_model_pk_cr = classroom_obj.id
        mapped_cr_int_id = processed_data["mappings"]["classroom_pk_to_int_map"].get(original_model_pk_cr)
        if mapped_cr_int_id is None: continue
        processed_data["classrooms"][mapped_cr_int_id] = {
            "capacity": classroom_obj.capacity,
            "original_db_pk": original_model_pk_cr,
            "room_code": str(classroom_obj.room_code),
            "type": classroom_obj.type
        }

    active_course_ids_in_current_run = set()
    for sc_item_model in input_scheduled_classes:
        original_sc_item_id = sc_item_model.id
        course_id_str_item = str(sc_item_model.course_id)
        course_catalog_entry = input_courses_catalog.get(course_id_str_item)

        if not course_catalog_entry: continue

        if original_sc_item_id not in processed_data["mappings"]["scheduled_item_original_id_to_idx_map"]:
            mapped_sc_item_idx = id_counters["scheduled_item"]
            processed_data["mappings"]["scheduled_item_original_id_to_idx_map"][original_sc_item_id] = mapped_sc_item_idx
            processed_data["mappings"]["scheduled_item_idx_to_original_id_map"][mapped_sc_item_idx] = mapped_sc_item_idx
            id_counters["scheduled_item"] += 1
        current_mapped_sc_item_idx = processed_data["mappings"]["scheduled_item_original_id_to_idx_map"][original_sc_item_id]

        assigned_instr_mapped_id = processed_data["mappings"]["lecturer_str_id_to_int_map"].get(str(sc_item_model.instructor_id)) if sc_item_model.instructor_id else None
        assigned_room_mapped_id = processed_data["mappings"]["classroom_pk_to_int_map"].get(sc_item_model.classroom_id) if sc_item_model.classroom_id is not None else None
        assigned_ts_mapped_id = processed_data["mappings"]["timeslot_str_id_to_int_map"].get(str(sc_item_model.timeslot_id)) if sc_item_model.timeslot_id else None

        processed_data["scheduled_items"][current_mapped_sc_item_idx] = {
            "original_id": original_sc_item_id,
            "course_id_str": course_id_str_item,
            "course_name": course_catalog_entry.name,
            "num_students": sc_item_model.num_students,
            "required_periods_per_session": course_catalog_entry.required_periods_per_session,
            "assigned_instructor_mapped_int_id": assigned_instr_mapped_id,
            "assigned_classroom_mapped_int_id": assigned_room_mapped_id,
            "assigned_timeslot_mapped_int_id": assigned_ts_mapped_id,
            "semester_id": sc_item_model.semester_id
        }
        active_course_ids_in_current_run.add(course_id_str_item)

    if reference_scheduled_classes:
        for ref_sc_item in reference_scheduled_classes:
            course_id_ref = str(ref_sc_item.course_id)
            instructor_id_ref_str = str(ref_sc_item.instructor_id)

            if course_id_ref and instructor_id_ref_str:
                mapped_instructor_id_for_ref = processed_data["mappings"]["lecturer_str_id_to_int_map"].get(instructor_id_ref_str)
                if mapped_instructor_id_for_ref is not None:
                    processed_data["course_potential_lecturers_map"][course_id_ref].add(mapped_instructor_id_for_ref)

    for c_id_key in list(processed_data["course_potential_lecturers_map"].keys()):
        processed_data["course_potential_lecturers_map"][c_id_key] = sorted(
            list(processed_data["course_potential_lecturers_map"][c_id_key])
        )

    final_course_ids_for_catalog_build = set(active_course_ids_in_current_run)

    for student_item in input_students:
        student_id_str_enroll = str(student_item.id)
        if student_item.enrolled_course_ids:
            for enrolled_course_id in student_item.enrolled_course_ids:
                enrolled_c_id_str_val = str(enrolled_course_id)
                if enrolled_c_id_str_val in input_courses_catalog:
                    processed_data["student_enrollments_by_course_id"][enrolled_c_id_str_val].add(student_id_str_enroll)
                    processed_data["courses_enrolled_by_student_id"][student_id_str_enroll].add(enrolled_c_id_str_val)
                    final_course_ids_for_catalog_build.add(enrolled_c_id_str_val)

    for c_id_for_final_catalog in final_course_ids_for_catalog_build:
        if c_id_for_final_catalog in input_courses_catalog:
            course_obj_from_master = input_courses_catalog[c_id_for_final_catalog]
            processed_data["courses_catalog_map"][c_id_for_final_catalog] = {
                "id": course_obj_from_master.id, "name": course_obj_from_master.name,
                "expected_students": course_obj_from_master.expected_students,
                "credits": course_obj_from_master.credits,
                "required_periods_per_session": course_obj_from_master.required_periods_per_session
            }
    return processed_data

if __name__ == "__main__":
    script_dir_test_utils = os.path.dirname(os.path.abspath(__file__))

    mock_instructors_test = [Instructor(id="L1", name="L. One")]
    mock_timeslots_test = [TimeSlot(id="TS1", day_of_week="Mon", start_time="09:00", end_time="10:00")]
    mock_classrooms_test = [Classroom(id=1, room_code="R1", capacity=30)]
    mock_courses_catalog_test = {"C1": Course(id="C1", name="Course 1", expected_students=25)}
    mock_input_sc_test = [ScheduledClass(id=10, course_id="C1", num_students=25, semester_id=1, instructor_id="L1")]
    mock_reference_sc_test = [ScheduledClass(id=1, course_id="C1", instructor_id="L1", num_students=20, semester_id=0)]
    mock_students_test = []
    mock_prio_settings_test = {}

    processed_mock = preprocess_data_for_cp_and_ga(
        mock_input_sc_test, mock_courses_catalog_test, mock_instructors_test,
        mock_classrooms_test, mock_timeslots_test, mock_students_test,
        reference_scheduled_classes=mock_reference_sc_test,
        semester_id_for_settings=1, priority_settings=mock_prio_settings_test,
        run_type="admin_optimize_semester"
    )

    print("\n--- Test 2: Preprocessing with Real Data (from data_loader) ---")
    if MODELS_IMPORTED_SUCCESSFULLY:
        try:
            from data_loader import load_all_data

            target_semester_id_for_test = 1 

            loaded_sc_for_target_semester, \
            all_instructors_loaded, \
            all_classrooms_loaded, \
            all_timeslots_loaded, \
            all_students_loaded, \
            master_courses_catalog_loaded = load_all_data(semester_id_to_load=target_semester_id_for_test)

            if not (all_instructors_loaded and all_classrooms_loaded and all_timeslots_loaded and master_courses_catalog_loaded):
                print(f"UTILS TEST WARNING: Essential catalog data missing for semester {target_semester_id_for_test}. Test results may be incomplete.")

            output_dir_real_data_test = os.path.join(script_dir_test_utils, "output_data_utils_real_data_test")
            os.makedirs(output_dir_real_data_test, exist_ok=True)

            current_run_type_test = "admin_optimize_semester"
            print(f"\nPreprocessing for: {current_run_type_test}, Semester: {target_semester_id_for_test}")
            processed_admin_output_test = preprocess_data_for_cp_and_ga(
                input_scheduled_classes=loaded_sc_for_target_semester,
                input_courses_catalog=master_courses_catalog_loaded,
                input_instructors=all_instructors_loaded,
                input_classrooms=all_classrooms_loaded,
                input_timeslots=all_timeslots_loaded,
                input_students=all_students_loaded,
                reference_scheduled_classes=loaded_sc_for_target_semester,
                semester_id_for_settings=target_semester_id_for_test,
                priority_settings=None,
                run_type=current_run_type_test
            )
            if processed_admin_output_test:
                admin_output_filepath_test = os.path.join(output_dir_real_data_test, f"utils_preprocessed_admin_sem{target_semester_id_for_test}.json")
                save_output_data_to_json(processed_admin_output_test, admin_output_filepath_test)
            else:
                print(f"ERROR: Admin run preprocessing returned no output for Semester {target_semester_id_for_test}.")

            current_run_type_test = "student_schedule_request"
            student_requested_course_ids_example = ["BSA105501", "INE105001", "INS308001"]
            virtual_items_for_student_test = []
            temp_virtual_id_counter = -1

            for course_id_req_test in student_requested_course_ids_example:
                course_detail_test = master_courses_catalog_loaded.get(course_id_req_test)
                if course_detail_test:
                    virtual_items_for_student_test.append(
                        ScheduledClass(
                            id=temp_virtual_id_counter,
                            course_id=course_id_req_test,
                            semester_id=target_semester_id_for_test,
                            num_students=course_detail_test.expected_students or 25,
                            instructor_id=None, classroom_id=None, timeslot_id=None
                        )
                    )
                    temp_virtual_id_counter -= 1
                else:
                    print(f"UTILS TEST INFO: Student requested course '{course_id_req_test}' not in catalog. Skipping for virtual item creation.")
            
            if virtual_items_for_student_test:
                print(f"\nPreprocessing for: {current_run_type_test}, Semester: {target_semester_id_for_test}, Courses: {student_requested_course_ids_example}")
                student_priority_settings_example = {
                    "student_id": "S_TEST_UTILS_007",
                    "student_preferences": "high"
                }
                processed_student_output_test = preprocess_data_for_cp_and_ga(
                    input_scheduled_classes=virtual_items_for_student_test,
                    input_courses_catalog=master_courses_catalog_loaded,
                    input_instructors=all_instructors_loaded,
                    input_classrooms=all_classrooms_loaded,
                    input_timeslots=all_timeslots_loaded,
                    input_students=all_students_loaded,
                    reference_scheduled_classes=loaded_sc_for_target_semester,
                    semester_id_for_settings=target_semester_id_for_test,
                    priority_settings=student_priority_settings_example,
                    run_type=current_run_type_test
                )
                if processed_student_output_test:
                    student_output_filepath_test = os.path.join(output_dir_real_data_test, f"utils_preprocessed_student_sem{target_semester_id_for_test}.json")
                    save_output_data_to_json(processed_student_output_test, student_output_filepath_test)
                else:
                    print(f"ERROR: Student run preprocessing returned no output for Semester {target_semester_id_for_test}.")
            else:
                print("UTILS TEST INFO: No virtual items created for student run test (requested courses might not be in catalog).")

        except ImportError:
            print(f"UTILS TEST ERROR: Could not import 'data_loader'. Real data test skipped.", file=sys.stderr)
        except Exception as e_real_data_test_main:
            print(f"UTILS TEST ERROR: An unexpected error occurred during real data test setup or preprocessing: {e_real_data_test_main}", file=sys.stderr)
            traceback.print_exc(file=sys.stderr)
    else:
        print("UTILS TEST INFO: Skipping real data test due to models.py import failure.")

    print("\n--- UTILS.PY: Standalone Test Suite Finished ---")