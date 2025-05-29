# File: htdocs/DSS/python_algorithm/utils.py
import json
from datetime import datetime, time as dt_time, date, timedelta
import traceback
from typing import List, Dict, Tuple, Any, Set, Optional
from collections import defaultdict
from dataclasses import dataclass, field, asdict 

try:
    from models import ScheduledClass, Course, Instructor, Classroom, TimeSlot, Student
    MODELS_IMPORTED_SUCCESSFULLY = True
    print("UTILS.PY INFO: Successfully imported models from models.py")
except ImportError as e_models:
    print(f"UTILS.PY WARNING: Could not import from models.py (Error: {e_models}). Using placeholder classes for testing utils.py.")
    MODELS_IMPORTED_SUCCESSFULLY = False
    # ... (placeholders như cũ) ...
    @dataclass(frozen=True)
    class TimeSlot: 
        id: str 
        day_of_week: str 
        start_time: str
        end_time: str 

    @dataclass(frozen=True)
    class Classroom:
        id: int 
        room_code: str
        capacity: int
        type: str = 'Theory'

    @dataclass
    class Instructor:
        id: str 
        name: str
        unavailable_slot_ids: Set[str] = field(default_factory=set) 
        def __hash__(self): return hash(self.id)
        def __eq__(self, other): 
            if not isinstance(other, Instructor): return NotImplemented
            return self.id == other.id

    @dataclass
    class Course: 
        id: str 
        name: str
        expected_students: int
        credits: Optional[int] = None
        required_periods_per_session: int = 1 
        def __hash__(self): return hash(self.id)
        def __eq__(self, other):
            if not isinstance(other, Course): return NotImplemented
            return self.id == other.id

    @dataclass(frozen=True)
    class Student:
        id: str 
        name: Optional[str] = None
        enrolled_course_ids: Set[str] = field(default_factory=set) 

    @dataclass
    class ScheduledClass: 
        id: int 
        course_id: str 
        num_students: int 
        semester_id: int 
        instructor_id: Optional[str] = None  
        classroom_id: Optional[int] = None   
        timeslot_id: Optional[str] = None    

# --- Helper Functions (giữ nguyên parse_time, DEFAULT_SETTINGS, check_time_overlap, save_output_data_to_json) ---
def parse_time(t_str: Any) -> Optional[dt_time]:
    if isinstance(t_str, dt_time): return t_str
    if isinstance(t_str, timedelta): return (datetime.min + t_str).time()
    if not isinstance(t_str, str): return None
    try: return datetime.strptime(t_str, '%H:%M:%S').time()
    except ValueError:
        try: return datetime.strptime(t_str, '%H:%M').time()
        except ValueError: return None

DEFAULT_SETTINGS = {
    "basic_slot_duration_minutes": 50, "break_duration_minutes": 5, 
    "lecturer_min_break_minutes": 10, "student_max_consecutive_slots": 3, 
    "target_classroom_fill_ratio_min": 0.5, "target_classroom_fill_ratio_max": 1.0, 
    "penalty_student_clash_base": 1000, "penalty_lecturer_overload_base": 50,
    "penalty_lecturer_underload_base": 30, "penalty_lecturer_insufficient_break_base": 40,
    "penalty_classroom_capacity_violation_base": 10000, 
    "penalty_classroom_underutilized_base": 10, "penalty_classroom_slightly_empty_base": 2, 
    "lecturer_target_teaching_periods_per_week_min": 4, 
    "lecturer_target_teaching_periods_per_week_max": 12, 
    "penalty_lecturer_workload_deviation_base": 20,
    "classroom_slightly_empty_multiplier_base": 10.0 # Added from GA example
}

def check_time_overlap(slot_start_str: Optional[str], slot_end_str: Optional[str],
                       busy_start_str: Optional[str], busy_end_str: Optional[str]) -> bool:
    slot_start = parse_time(slot_start_str)
    slot_end = parse_time(slot_end_str)
    busy_start = parse_time(busy_start_str)
    busy_end = parse_time(busy_end_str)
    if not all(isinstance(t, dt_time) for t in [slot_start, slot_end, busy_start, busy_end]): return False
    if slot_start is None or slot_end is None or busy_start is None or busy_end is None: return False
    if slot_end <= slot_start or busy_end <= busy_start: return False
    return max(slot_start, busy_start) < min(slot_end, busy_end)

def save_output_data_to_json(data: Any, filepath: str):
    print(f"UTILS INFO: Attempting to save data to {filepath}")
    try:
        def convert_special_types(obj):
            if hasattr(type(obj), '__dataclass_fields__'): return asdict(obj) 
            if isinstance(obj, set): return sorted(list(obj)) 
            if isinstance(obj, (datetime, date, dt_time)): return obj.isoformat()
            if isinstance(obj, timedelta):
                 total_seconds = int(obj.total_seconds()); hours, rem = divmod(total_seconds, 3600); mins, secs = divmod(rem, 60)
                 return f"{hours:02}:{mins:02}:{secs:02}"
            if hasattr(obj, '__dict__') and not callable(obj.__dict__): return obj.__dict__
            raise TypeError(f"Object of type {obj.__class__.__name__} is not JSON serializable.")
        with open(filepath, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=4, ensure_ascii=False, default=convert_special_types)
        print(f"UTILS INFO: Successfully saved data to {filepath}")
    except TypeError as te:
        print(f"UTILS ERROR: TypeError saving to {filepath}. Error: {te}")
        if isinstance(data, (list, dict)):
            for i, item_or_key in enumerate(data if isinstance(data, list) else data.items()):
                try: json.dumps(item_or_key[1] if isinstance(data, dict) else item_or_key, default=convert_special_types)
                except TypeError: print(f"UTILS DEBUG: Problematic item/value approx {i}: {str(item_or_key)[:500]}"); break
        else: print(f"UTILS DEBUG: Problematic data: {str(data)[:500]}")
        traceback.print_exc()
    except Exception as e: print(f"UTILS ERROR: Error saving data to {filepath}: {e}"); traceback.print_exc()

# --- Main Preprocessing Function ---
def preprocess_data_for_cp_and_ga(
    # Nguồn dữ liệu đầu vào từ data_loader.py
    # scheduled_classes_model_list: Danh sách các đối tượng ScheduledClass (từ bảng ScheduledClasses)
    # courses_catalog_map: Dict[str, Course] (từ bảng Courses)
    # instructors_model_list: Danh sách các đối tượng Instructor (từ bảng Lecturers)
    # classrooms_model_list: Danh sách các đối tượng Classroom
    # timeslots_model_list: Danh sách các đối tượng TimeSlot
    # students_model_list: Danh sách các đối tượng Student
    
    input_scheduled_classes: List[ScheduledClass], 
    input_courses_catalog: Dict[str, Course], # Đây là catalog đầy đủ từ DB       
    input_instructors: List[Instructor],
    input_classrooms: List[Classroom],
    input_timeslots: List[TimeSlot],
    input_students: List[Student],
    semester_id_for_settings: Optional[int] = None,
    priority_settings: Optional[Dict[str, str]] = None 
) -> Dict[str, Any]:
    
    processed_data: Dict[str, Any] = {
        "settings": DEFAULT_SETTINGS.copy(), "scheduled_items": {}, 
        "lecturers": {}, "classrooms": {}, "timeslots": {},
        "courses_catalog_map": {}, # QUAN TRỌNG: Catalog này sẽ được xây dựng lại
        "mappings": {
            "lecturer_str_id_to_int_map": {}, "lecturer_int_map_to_str_id": {},
            "timeslot_str_id_to_int_map": {}, "timeslot_int_map_to_str_id": {},
            "classroom_pk_to_int_map": {}, "classroom_int_map_to_pk": {}, 
            "scheduled_item_db_id_to_idx_map": {}, "scheduled_item_idx_to_db_id_map": {},
        },
        "student_enrollments_by_course_id": defaultdict(set), 
        "courses_enrolled_by_student_id": defaultdict(set)    
    }

    if semester_id_for_settings is not None:
        processed_data["settings"]["current_semester_id"] = semester_id_for_settings
    
    print("UTILS INFO: Starting preprocessing for CP/GA modules...")
    print(f"UTILS INFO: Initial input_courses_catalog size: {len(input_courses_catalog)}")


    # Apply priority settings (giữ nguyên logic này)
    if priority_settings:
        priority_multipliers = {"low": 0.5, "medium": 1.0, "high": 2.0, "very_high": 5.0}
        def get_penalty(base_key: str, priority_key: str, default_priority: str = "medium") -> float:
            base_penalty = DEFAULT_SETTINGS.get(base_key, 0.0)
            priority_level = priority_settings.get(priority_key, default_priority) # type: ignore
            multiplier = priority_multipliers.get(priority_level, 1.0)
            return float(base_penalty * multiplier)
        # ... (gán các penalty như cũ) ...
        processed_data["settings"]["penalty_student_clash"] = get_penalty("penalty_student_clash_base", "student_clash")
        lecturer_prio_key = "lecturer_load_break"
        processed_data["settings"]["penalty_lecturer_overload"] = get_penalty("penalty_lecturer_overload_base", lecturer_prio_key)
        processed_data["settings"]["penalty_lecturer_underload"] = get_penalty("penalty_lecturer_underload_base", lecturer_prio_key)
        processed_data["settings"]["penalty_lecturer_insufficient_break"] = get_penalty("penalty_lecturer_insufficient_break_base", lecturer_prio_key)
        processed_data["settings"]["penalty_lecturer_workload_deviation"] = get_penalty("penalty_lecturer_workload_deviation_base", lecturer_prio_key)
        classroom_prio_key = "classroom_util"
        base_cap_penalty = DEFAULT_SETTINGS.get("penalty_classroom_capacity_violation_base", 10000.0)
        cap_priority_level = priority_settings.get(classroom_prio_key, "medium") # type: ignore
        cap_multiplier = priority_multipliers.get(cap_priority_level, 1.0)
        processed_data["settings"]["penalty_classroom_capacity_violation"] = float(base_cap_penalty * cap_multiplier)
        processed_data["settings"]["penalty_classroom_underutilized"] = get_penalty("penalty_classroom_underutilized_base", classroom_prio_key)
        processed_data["settings"]["penalty_classroom_slightly_empty"] = get_penalty("penalty_classroom_slightly_empty_base", classroom_prio_key)
        print(f"UTILS INFO: Effective penalties: student_clash={processed_data['settings']['penalty_student_clash']:.2f}, "
              f"classroom_capacity_violation={processed_data['settings']['penalty_classroom_capacity_violation']:.2f}")


    id_counters = {"lecturer": 0, "timeslot": 0, "classroom": 0, "scheduled_item": 0}
    
    # --- MAPPING PHASE (Giữ nguyên logic map ID) ---
    for item in input_instructors: # Sử dụng input_instructors
        model_str_id = str(item.id); 
        if model_str_id not in processed_data["mappings"]["lecturer_str_id_to_int_map"]:
            mapped_int_id = id_counters["lecturer"]
            processed_data["mappings"]["lecturer_str_id_to_int_map"][model_str_id] = mapped_int_id
            processed_data["mappings"]["lecturer_int_map_to_str_id"][mapped_int_id] = model_str_id
            id_counters["lecturer"] += 1
    print(f"UTILS INFO: Mapped {len(processed_data['mappings']['lecturer_str_id_to_int_map'])} unique lecturers.")

    for item in input_timeslots: # Sử dụng input_timeslots
        model_str_id = str(item.id)
        if model_str_id not in processed_data["mappings"]["timeslot_str_id_to_int_map"]:
            mapped_int_id = id_counters["timeslot"]
            processed_data["mappings"]["timeslot_str_id_to_int_map"][model_str_id] = mapped_int_id
            processed_data["mappings"]["timeslot_int_map_to_str_id"][mapped_int_id] = model_str_id
            id_counters["timeslot"] += 1
    print(f"UTILS INFO: Mapped {len(processed_data['mappings']['timeslot_str_id_to_int_map'])} unique timeslots.")

    for item in input_classrooms: # Sử dụng input_classrooms
        model_pk_id = item.id
        if model_pk_id not in processed_data["mappings"]["classroom_pk_to_int_map"]:
            mapped_int_id = id_counters["classroom"]
            processed_data["mappings"]["classroom_pk_to_int_map"][model_pk_id] = mapped_int_id
            processed_data["mappings"]["classroom_int_map_to_pk"][mapped_int_id] = model_pk_id
            id_counters["classroom"] += 1
    print(f"UTILS INFO: Mapped {len(processed_data['mappings']['classroom_pk_to_int_map'])} unique classrooms.")

    # --- PROCESSING PHASE ---
    # Process Instructors (Giữ nguyên logic, nhưng dùng input_instructors)
    for instructor_model in input_instructors:
        original_model_str_id = str(instructor_model.id) 
        mapped_lecturer_int_id = processed_data["mappings"]["lecturer_str_id_to_int_map"].get(original_model_str_id)
        if mapped_lecturer_int_id is None: continue
        unavailable_mapped_ts_int_ids = set()
        if instructor_model.unavailable_slot_ids:
            for ts_model_str_id in instructor_model.unavailable_slot_ids: 
                mapped_ts_id = processed_data["mappings"]["timeslot_str_id_to_int_map"].get(str(ts_model_str_id))
                if mapped_ts_id is not None: unavailable_mapped_ts_int_ids.add(mapped_ts_id)
        try: original_db_pk_as_int = int(original_model_str_id)
        except ValueError: print(f"UTILS CRITICAL: Instructor ID '{original_model_str_id}' not int. Skipping."); continue
        processed_data["lecturers"][mapped_lecturer_int_id] = {
            "name": instructor_model.name, "original_model_id_str": original_model_str_id, 
            "original_db_pk_int": original_db_pk_as_int, 
            "unavailable_slot_ids_mapped": sorted(list(unavailable_mapped_ts_int_ids)) 
        }
    print(f"UTILS INFO: Processed {len(processed_data['lecturers'])} lecturers.")

    # Process TimeSlots (Giữ nguyên, dùng input_timeslots)
    for timeslot_model in input_timeslots:
        original_model_str_id = str(timeslot_model.id) 
        mapped_timeslot_int_id = processed_data["mappings"]["timeslot_str_id_to_int_map"].get(original_model_str_id)
        if mapped_timeslot_int_id is None: continue
        try: original_db_pk_as_int = int(original_model_str_id)
        except ValueError: print(f"UTILS CRITICAL: TimeSlot ID '{original_model_str_id}' not int. Skipping."); continue
        processed_data["timeslots"][mapped_timeslot_int_id] = {
            "day_of_week": timeslot_model.day_of_week, "start_time": timeslot_model.start_time, 
            "end_time": timeslot_model.end_time, "original_model_id_str": original_model_str_id, 
            "original_db_pk_int": original_db_pk_as_int, 
        }
    print(f"UTILS INFO: Processed {len(processed_data['timeslots'])} timeslots.")

    # Process Classrooms (Giữ nguyên, dùng input_classrooms)
    for classroom_model in input_classrooms:
        original_model_pk_id = classroom_model.id 
        mapped_classroom_int_id = processed_data["mappings"]["classroom_pk_to_int_map"].get(original_model_pk_id)
        if mapped_classroom_int_id is None: continue
        processed_data["classrooms"][mapped_classroom_int_id] = {
            "capacity": classroom_model.capacity, "original_db_pk": original_model_pk_id, 
            "room_code": str(classroom_model.room_code), "type": classroom_model.type 
        }
    print(f"UTILS INFO: Processed {len(processed_data['classrooms'])} classrooms.")
    
    # QUAN TRỌNG: Xử lý ScheduledClass và xây dựng processed_data["courses_catalog_map"] CHỈ chứa các course thực sự được xem xét.
    print(f"UTILS INFO: Processing {len(input_scheduled_classes)} input scheduled class items...")
    valid_scheduled_items_count = 0
    referenced_course_ids_in_scheduled_items = set() # Theo dõi các CourseID thực sự được dùng

    for sc_model in input_scheduled_classes: 
        scheduled_item_db_id = sc_model.id
        course_id_str = str(sc_model.course_id)

        # KIỂM TRA QUAN TRỌNG: CourseID này phải có trong input_courses_catalog (từ DB)
        course_info_from_db_catalog = input_courses_catalog.get(course_id_str)
        if not course_info_from_db_catalog:
            print(f"UTILS WARNING: ScheduledClass (DB ID: {sc_model.id}) refs CourseID '{course_id_str}' which IS NOT in the input_courses_catalog (from DB). Skipping this SC item.")
            continue # Bỏ qua ScheduledClass này hoàn toàn

        # Nếu course hợp lệ, map ScheduleID
        if scheduled_item_db_id not in processed_data["mappings"]["scheduled_item_db_id_to_idx_map"]:
            mapped_idx = id_counters["scheduled_item"]
            processed_data["mappings"]["scheduled_item_db_id_to_idx_map"][scheduled_item_db_id] = mapped_idx
            processed_data["mappings"]["scheduled_item_idx_to_db_id_map"][mapped_idx] = scheduled_item_db_id
            id_counters["scheduled_item"] += 1
        mapped_scheduled_item_idx = processed_data["mappings"]["scheduled_item_db_id_to_idx_map"][scheduled_item_db_id]

        # Xử lý pre-assignments (logic giữ nguyên)
        mapped_assigned_lecturer_int_id = None
        if sc_model.instructor_id: 
            lecturer_model_str_id = str(sc_model.instructor_id)
            mapped_assigned_lecturer_int_id = processed_data["mappings"]["lecturer_str_id_to_int_map"].get(lecturer_model_str_id)
            if mapped_assigned_lecturer_int_id is None: print(f"UTILS WARNING: SC (DB ID: {sc_model.id}) pre-assigned instr_id '{lecturer_model_str_id}' not mapped.")
        
        mapped_assigned_classroom_int_id = None
        if sc_model.classroom_id is not None: 
            mapped_assigned_classroom_int_id = processed_data["mappings"]["classroom_pk_to_int_map"].get(sc_model.classroom_id)
            if mapped_assigned_classroom_int_id is None: print(f"UTILS WARNING: SC (DB ID: {sc_model.id}) pre-assigned classroom_id '{sc_model.classroom_id}' not mapped.")

        mapped_assigned_timeslot_int_id = None
        if sc_model.timeslot_id: 
            timeslot_model_str_id = str(sc_model.timeslot_id)
            mapped_assigned_timeslot_int_id = processed_data["mappings"]["timeslot_str_id_to_int_map"].get(timeslot_model_str_id)
            if mapped_assigned_timeslot_int_id is None: print(f"UTILS WARNING: SC (DB ID: {sc_model.id}) pre-assigned timeslot_id '{timeslot_model_str_id}' not mapped.")

        processed_data["scheduled_items"][mapped_scheduled_item_idx] = {
            "original_db_id": sc_model.id, "course_id_str": course_id_str, 
            "course_name": course_info_from_db_catalog.name, # Lấy từ catalog DB
            "num_students": sc_model.num_students, 
            "required_periods_per_session": course_info_from_db_catalog.required_periods_per_session, # Lấy từ catalog DB
            "assigned_instructor_mapped_int_id": mapped_assigned_lecturer_int_id, 
            "assigned_classroom_mapped_int_id": mapped_assigned_classroom_int_id, 
            "assigned_timeslot_mapped_int_id": mapped_assigned_timeslot_int_id, 
            "semester_id": sc_model.semester_id
        }
        valid_scheduled_items_count += 1
        referenced_course_ids_in_scheduled_items.add(course_id_str) # Thêm CourseID vào set
    print(f"UTILS INFO: Processed {valid_scheduled_items_count} valid scheduled items into final data structure.")

    # Xây dựng processed_data["courses_catalog_map"] CHỈ với các course thực sự được tham chiếu
    # bởi các scheduled_items hợp lệ HOẶC bởi student_enrollments.
    final_catalog_course_ids = set(referenced_course_ids_in_scheduled_items) # Bắt đầu với các course trong scheduled_items

    # Process Student Enrollments và thêm các course từ enrollment vào final_catalog_course_ids
    enrollments_added = 0
    for student_model in input_students: # Sử dụng input_students
        student_original_id_str = str(student_model.id)
        if student_model.enrolled_course_ids:
            for course_original_id_str_enrolled in student_model.enrolled_course_ids:
                course_key = str(course_original_id_str_enrolled)
                # Chỉ thêm enrollment nếu course đó có trong input_courses_catalog (từ DB)
                if course_key in input_courses_catalog: 
                    processed_data["student_enrollments_by_course_id"][course_key].add(student_original_id_str)
                    processed_data["courses_enrolled_by_student_id"][student_original_id_str].add(course_key)
                    enrollments_added +=1
                    final_catalog_course_ids.add(course_key) # Đảm bảo course này cũng có trong final catalog
                # else: print(f"UTILS INFO: Student '{student_original_id_str}' enrollment for CourseID '{course_key}' skipped as course not in DB catalog.")
    print(f"UTILS INFO: Processed student enrollments. Added {enrollments_added} links. "
          f"Unique courses w/ enroll: {len(processed_data['student_enrollments_by_course_id'])}. "
          f"Unique students w/ enroll: {len(processed_data['courses_enrolled_by_student_id'])}.")

    # Bây giờ, tạo processed_data["courses_catalog_map"] cuối cùng
    for course_id_to_keep in final_catalog_course_ids:
        if course_id_to_keep in input_courses_catalog:
            # Sao chép thông tin course từ input_courses_catalog (là object Course)
            # GA sẽ làm việc với dictionary, nên chuyển Course object thành dict nếu cần,
            # hoặc đảm bảo GA có thể truy cập thuộc tính của Course object.
            # Hiện tại, GA truy cập qua .get() nên dict là tốt.
            course_obj = input_courses_catalog[course_id_to_keep]
            processed_data["courses_catalog_map"][course_id_to_keep] = {
                "id": course_obj.id, # Mặc dù key đã là id
                "name": course_obj.name,
                "expected_students": course_obj.expected_students,
                "credits": course_obj.credits,
                "required_periods_per_session": course_obj.required_periods_per_session
            }
        else:
            # Điều này không nên xảy ra nếu logic đúng
            print(f"UTILS CRITICAL ERROR: CourseID '{course_id_to_keep}' was marked for keeping but not found in input_courses_catalog!")
            
    print(f"UTILS INFO: Final processed_data['courses_catalog_map'] contains {len(processed_data['courses_catalog_map'])} entries.")


    # Final checks (giữ nguyên)
    essential_data_keys = ["lecturers", "classrooms", "timeslots", "courses_catalog_map"] 
    for key in essential_data_keys:
        if not processed_data.get(key): 
            print(f"UTILS CRITICAL WARNING: Essential data category '{key}' is empty after processing.")
            
    if not processed_data["scheduled_items"] and input_scheduled_classes:
        print("UTILS CRITICAL WARNING: Input SC list not empty, but 'scheduled_items' is. All items filtered out.")
    elif not input_scheduled_classes:
         print("UTILS INFO: Input SC list was empty. Expected for initial scheduling.")

    print("UTILS INFO: Preprocessing finished.")
    return processed_data

# --- Main Test Block (giữ nguyên, chỉ cần đảm bảo tham số truyền vào preprocess_data_for_cp_and_ga đúng tên) ---
if __name__ == "__main__":
    import os
    current_script_dir = os.path.dirname(os.path.abspath(__file__))
    print("\nUTILS.PY TEST SUITE: MODELS_IMPORTED_SUCCESSFULLY =", MODELS_IMPORTED_SUCCESSFULLY)
    
    print("\nUTILS.PY TEST (MOCK DATA): Creating mock model objects...")
    mock_instructors_list = [ # Đổi tên để khớp tham số
        Instructor(id="1", name="Dr. Ada", unavailable_slot_ids={"3"}), 
        Instructor(id="2", name="Prof. Turing", unavailable_slot_ids=set())
    ]
    mock_timeslots_list = [ # Đổi tên
        TimeSlot(id="1", day_of_week="Monday", start_time="09:00:00", end_time="10:40:00"),
        TimeSlot(id="2", day_of_week="Monday", start_time="13:00:00", end_time="14:40:00"),
        TimeSlot(id="3", day_of_week="Tuesday", start_time="09:00:00", end_time="10:40:00")
    ]
    mock_classrooms_list = [ # Đổi tên
        Classroom(id=101, room_code="R1A", capacity=35, type="Lab"),
        Classroom(id=102, room_code="R1B", capacity=30, type="Theory")
    ]
    # Đây là input_courses_catalog
    mock_input_courses_catalog = {
        "CS101": Course(id="CS101", name="Intro to CS", expected_students=30, credits=3, required_periods_per_session=1),
        "MA201": Course(id="MA201", name="Calculus I", expected_students=25, credits=4, required_periods_per_session=1),
        "PH100": Course(id="PH100", name="Physics Basics", expected_students=20, credits=3, required_periods_per_session=1)
    }
    # Đây là input_scheduled_classes
    mock_input_scheduled_classes = [
        ScheduledClass(id=10, course_id="CS101", num_students=28, semester_id=1, instructor_id="1", classroom_id=101, timeslot_id="1"),
        ScheduledClass(id=11, course_id="MA201", num_students=25, semester_id=1, instructor_id="2", classroom_id=None, timeslot_id=None),
        ScheduledClass(id=12, course_id="CS101", num_students=30, semester_id=1, instructor_id=None, classroom_id=102, timeslot_id=None),
        ScheduledClass(id=13, course_id="PH100", num_students=18, semester_id=1, instructor_id="1", classroom_id=101, timeslot_id=None),
        ScheduledClass(id=14, course_id="XX999", num_students=10, semester_id=1, instructor_id="1", classroom_id=None, timeslot_id=None) 
    ]
    mock_students_list = [ # Đổi tên
        Student(id="S001", name="Alice", enrolled_course_ids={"CS101", "PH100"}),
        Student(id="S002", name="Bob", enrolled_course_ids={"MA201"}),
        Student(id="S003", name="Charlie", enrolled_course_ids={"CS101", "XX999"})
    ]

    output_dir_utils_mock = os.path.join(current_script_dir, "output_data_utils_mock_test")
    if not os.path.exists(output_dir_utils_mock): os.makedirs(output_dir_utils_mock)
    
    print("\nUTILS.PY TEST (MOCK DATA): Running preprocess_data_for_cp_and_ga...")
    mock_priority_settings = {"student_clash": "high", "lecturer_load_break": "low", "classroom_util": "medium"}
    
    processed_mock_data = preprocess_data_for_cp_and_ga(
        input_scheduled_classes=mock_input_scheduled_classes, 
        input_courses_catalog=mock_input_courses_catalog,
        input_instructors=mock_instructors_list, 
        input_classrooms=mock_classrooms_list,
        input_timeslots=mock_timeslots_list, 
        input_students=mock_students_list, 
        semester_id_for_settings=1,
        priority_settings=mock_priority_settings
    )

    if processed_mock_data:
        print("\nUTILS.PY TEST (MOCK DATA): Preprocessed data overview:")
        # ... (in kết quả như cũ) ...
        print(f"  Settings: {len(processed_mock_data.get('settings', {}))} keys, e.g., student_clash penalty = {processed_mock_data.get('settings', {}).get('penalty_student_clash')}")
        print("  Mappings:")
        for map_key, map_dict in processed_mock_data.get("mappings", {}).items():
            print(f"    {map_key}: {len(map_dict)} entries. Sample: {dict(list(map_dict.items())[:2]) if map_dict else '{}'}")
        for data_key in ["scheduled_items", "lecturers", "classrooms", "timeslots", 
                         "courses_catalog_map", # Kiểm tra catalog đã xử lý
                         "student_enrollments_by_course_id", "courses_enrolled_by_student_id"]:
            items_dict = processed_mock_data.get(data_key, {})
            print(f"  {data_key.capitalize()}: {len(items_dict)} entries.")
            if items_dict:
                first_key = next(iter(items_dict.keys()), None)
                if first_key is not None: print(f"    Sample for key '{first_key}': {str(items_dict[first_key])[:150]}...")
        output_file_mock = os.path.join(output_dir_utils_mock, "utils_preprocessed_mock_data.json")
        save_output_data_to_json(processed_mock_data, output_file_mock)

    # ... (phần test REAL DATA giữ nguyên, đảm bảo truyền đúng tên tham số) ...
    print("-" * 60)
    print("\nUTILS.PY TEST (REAL DATA): Attempting to load REAL DATA from database...")
    if MODELS_IMPORTED_SUCCESSFULLY:
        try:
            from data_loader import load_all_data 
            target_semester_id_for_real_test = 1 
            print(f"UTILS.PY TEST (REAL DATA): Loading data via data_loader for semester_id = {target_semester_id_for_real_test}")
            loaded_data_tuple_real = load_all_data(semester_id_to_load=target_semester_id_for_real_test)
            
            if not any(loaded_data_tuple_real):
                print(f"UTILS.PY TEST (REAL DATA) WARNING: data_loader for semester {target_semester_id_for_real_test} returned no data. Skipping real data preprocessing.")
            else:
                # Đổi tên biến unpack để khớp với tên tham số của preprocess_data_for_cp_and_ga
                real_input_scheduled_classes, real_input_instructors, real_input_classrooms, \
                real_input_timeslots, real_input_students, real_input_courses_catalog = loaded_data_tuple_real

                if not (real_input_instructors and real_input_classrooms and real_input_timeslots and real_input_courses_catalog): 
                    print(f"UTILS.PY TEST (REAL DATA) WARNING: Not all essential catalog data loaded for semester {target_semester_id_for_real_test}.")
                
                print(f"UTILS.PY TEST (REAL DATA): Loaded from DB: {len(real_input_scheduled_classes)} SC_input, "
                      f"{len(real_input_courses_catalog)} CoursesCat, {len(real_input_instructors)} Instructors, ...")

                output_dir_utils_real = os.path.join(current_script_dir, "output_data_utils_real_data_test")
                if not os.path.exists(output_dir_utils_real): os.makedirs(output_dir_utils_real)
                
                real_priority_settings = { "student_clash": "very_high", "lecturer_load_break": "medium", "classroom_util": "medium" }
                processed_real_data = preprocess_data_for_cp_and_ga(
                    input_scheduled_classes=real_input_scheduled_classes, 
                    input_courses_catalog=real_input_courses_catalog,
                    input_instructors=real_input_instructors, 
                    input_classrooms=real_input_classrooms,
                    input_timeslots=real_input_timeslots, 
                    input_students=real_input_students,
                    semester_id_for_settings=target_semester_id_for_real_test,
                    priority_settings=real_priority_settings
                )

                if processed_real_data and (processed_real_data.get("scheduled_items") or not real_input_scheduled_classes) : 
                    print("\nUTILS.PY TEST (REAL DATA): Preprocessed real data successfully.")
                    output_file_real = os.path.join(output_dir_utils_real, f"utils_preprocessed_real_data_sem_{target_semester_id_for_real_test}.json")
                    save_output_data_to_json(processed_real_data, output_file_real)
                else:
                    print("UTILS.PY TEST (REAL DATA) ERROR: Preprocessing real data failed or emptied essential categories. Check logs.")
        except ImportError:
            print(f"UTILS.PY TEST (REAL DATA) ERROR: Could not import 'data_loader'. Skipping real data test.")
        except Exception as e_main_real:
            print(f"UTILS.PY TEST (REAL DATA) ERROR: Unexpected error during real data test: {e_main_real}")
            traceback.print_exc()
    else:
        print("UTILS.PY TEST (REAL DATA): Skipped due to models.py import failure.")
    print("\nUTILS.PY All tests finished.")