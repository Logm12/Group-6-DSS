import json
from datetime import datetime, time, timedelta
import mysql.connector # Thư viện kết nối MySQL

# --- CẤU HÌNH KẾT NỐI CSDL ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',       # THAY ĐỔI NẾU CẦN
    'password': '',   # THAY ĐỔI NẾU CẦN
    'database': 'dss'
}
# -----------------------------

# --- CẤU HÌNH TIẾT HỌC MẶC ĐỊNH (Sử dụng nếu không có trong CSDL hoặc để tính toán) ---
DEFAULT_SETTINGS = {
    "basic_slot_duration_minutes": 50,
    "break_duration_minutes": 5
}
# ---------------------------------------------------------------------------------

def get_db_connection():
    """Establishes a database connection."""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except mysql.connector.Error as err:
        print(f"Error connecting to MySQL: {err}")
        return None

def calculate_effective_basic_slots(start_time_obj, end_time_obj, slot_duration_min, break_duration_min):
    """
    Calculates the number of effective basic teaching slots within a given time interval.
    """
    if not isinstance(start_time_obj, time) or not isinstance(end_time_obj, time):
        return 0

    # Convert time objects to datetime objects for duration calculation (on an arbitrary date)
    today = datetime.today().date()
    start_dt = datetime.combine(today, start_time_obj)
    end_dt = datetime.combine(today, end_time_obj)

    if end_dt <= start_dt:
        return 0

    total_interval_minutes = (end_dt - start_dt).total_seconds() / 60
    
    # If only one slot can fit, there's no break
    if total_interval_minutes < slot_duration_min:
        return 0
    if total_interval_minutes <= slot_duration_min + break_duration_min : # Handles edge case for single slot
        if total_interval_minutes >= slot_duration_min:
            return 1
        else: 
            return 0

    # For multiple slots, each "unit" is (slot_duration + break_duration)
    # The last slot doesn't have a break *after* it within this interval.
    # So, total_duration = n * slot_duration + (n-1) * break_duration
    # n * (slot_duration + break_duration) - break_duration = total_interval_minutes
    # n = (total_interval_minutes + break_duration) / (slot_duration + break_duration)
    
    if slot_duration_min + break_duration_min == 0: # Avoid division by zero
        return 0
        
    effective_slots = int(
        (total_interval_minutes + break_duration_min) / (slot_duration_min + break_duration_min)
    )
    return effective_slots


def load_data_from_db():
    """
    Loads all necessary data from the MySQL database.
    """
    conn = get_db_connection()
    if not conn:
        return None
    
    cursor = conn.cursor(dictionary=True) # Fetch as dictionaries
    raw_data = {}

    try:
        # 1. Settings (Lấy từ Semesters hoặc dùng default)
        cursor.execute("SELECT SemesterID as semester_id FROM Semesters WHERE SemesterName = 'Học kỳ Import từ Excel' LIMIT 1") # Giả sử bạn muốn học kỳ này
        semester_setting = cursor.fetchone()
        raw_data["settings"] = {**DEFAULT_SETTINGS, **(semester_setting if semester_setting else {"semester_id": 1})} # Default semester_id if not found

        # 2. Courses
        # Lưu ý: Cột ExpectedStudents không có trong schema SQL gốc. Thêm mặc định nếu cần.
        # Tạm thời thêm ExpectedStudents là 40 nếu không có trong bảng
        cursor.execute("""
            SELECT 
                CourseID as course_id, 
                CourseName as name, 
                SessionDurationSlots as session_duration_slots,
                COALESCE(ExpectedStudents, 40) as expected_students
                -- Add preferred_lecturers, needs_special_classroom_type if these columns exist
            FROM Courses
        """)
        raw_data["courses"] = cursor.fetchall()
        for course in raw_data["courses"]: # Ensure default empty lists for optional fields
            course.setdefault("preferred_lecturers", [])
            course.setdefault("needs_special_classroom_type", None)


        # 3. Lecturers
        cursor.execute("""
            SELECT 
                LecturerID as lecturer_id, 
                LecturerName as name
                -- Add max_teaching_slots_per_week, min_teaching_slots_per_week if these columns exist
            FROM Lecturers
        """)
        raw_data["lecturers"] = cursor.fetchall()

        # 4. Classrooms
        cursor.execute("""
            SELECT 
                ClassroomID as classroom_id, 
                RoomCode as room_code, 
                Capacity as capacity, 
                Type as type 
            FROM Classrooms
        """)
        raw_data["classrooms"] = cursor.fetchall()

        # 5. TimeSlots
        cursor.execute("""
            SELECT 
                TimeSlotID as timeslot_id, 
                DayOfWeek as day_of_week, 
                SessionDate as session_date, 
                StartTime as start_time, 
                EndTime as end_time 
            FROM TimeSlots
        """)
        timeslots_from_db = cursor.fetchall()
        raw_data["timeslots"] = []
        slot_duration = raw_data["settings"]["basic_slot_duration_minutes"]
        break_duration = raw_data["settings"]["break_duration_minutes"]
        for ts in timeslots_from_db:
            # Convert timedelta from DB to time string for consistency before parsing
            if isinstance(ts['start_time'], timedelta):
                ts['start_time'] = (datetime.min + ts['start_time']).time().strftime('%H:%M:%S')
            if isinstance(ts['end_time'], timedelta):
                ts['end_time'] = (datetime.min + ts['end_time']).time().strftime('%H:%M:%S')
            
            start_time_obj = parse_time(ts['start_time'])
            end_time_obj = parse_time(ts['end_time'])
            
            ts_copy = ts.copy()
            ts_copy["effective_basic_slots"] = calculate_effective_basic_slots(
                start_time_obj, end_time_obj, slot_duration, break_duration
            )
            raw_data["timeslots"].append(ts_copy)


        # 6. Instructor Unavailable Slots
        cursor.execute("""
            SELECT 
                LecturerID as lecturer_id, 
                BusyDayOfWeek as day_of_week, 
                BusyStartTime as start_time, 
                BusyEndTime as end_time,
                Reason as reason 
            FROM InstructorUnavailableSlots
            WHERE SemesterID = %s OR SemesterID IS NULL -- Lấy cho học kỳ hiện tại hoặc chung chung
        """, (raw_data["settings"]["semester_id"],))
        unavailable_slots = cursor.fetchall()
        raw_data["instructor_unavailable_slots"] = []
        for us in unavailable_slots:
            if isinstance(us['start_time'], timedelta):
                us['start_time'] = (datetime.min + us['start_time']).time().strftime('%H:%M:%S')
            if isinstance(us['end_time'], timedelta):
                us['end_time'] = (datetime.min + us['end_time']).time().strftime('%H:%M:%S')
            raw_data["instructor_unavailable_slots"].append(us)


        # 7. Student Enrollments
        cursor.execute("""
            SELECT 
                StudentID as student_id, 
                CourseID as course_id 
            FROM StudentEnrollments
            WHERE SemesterID = %s OR SemesterID IS NULL
        """, (raw_data["settings"]["semester_id"],))
        raw_data["student_enrollments"] = cursor.fetchall()

        print(f"Successfully loaded data from database '{DB_CONFIG['database']}'")
        return raw_data

    except mysql.connector.Error as err:
        print(f"Error reading from database: {err}")
        return None
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
            # print("MySQL connection is closed")

def save_output_data(data, file_path):
    """
    Saves output data (schedule) to a JSON file.
    (This function can be modified later to save directly to DB if needed)
    """
    try:
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=4, ensure_ascii=False)
        print(f"Successfully saved output data to {file_path}")
    except Exception as e:
        print(f"An unexpected error occurred while saving output data: {e}")

def parse_time(time_input):
    """
    Parses a time string (HH:MM:SS) or timedelta into a datetime.time object.
    """
    if isinstance(time_input, time):
        return time_input
    if isinstance(time_input, timedelta): # MySQL TIME columns can be returned as timedelta
        return (datetime.min + time_input).time()
    if isinstance(time_input, str):
        try:
            return datetime.strptime(time_input, '%H:%M:%S').time()
        except ValueError:
            print(f"Warning: Could not parse time string '{time_input}'. Using midnight as default.")
            return time(0, 0, 0)
    print(f"Warning: Unparseable time input '{time_input}' of type {type(time_input)}. Using midnight.")
    return time(0,0,0)


def check_time_overlap(start1_input, end1_input, start2_input, end2_input):
    """
    Checks if two time intervals [start1, end1) and [start2, end2) overlap.
    Assumes times are on the same day.
    Inputs can be time strings, timedelta, or time objects.
    """
    start1, end1 = parse_time(start1_input), parse_time(end1_input)
    start2, end2 = parse_time(start2_input), parse_time(end2_input)

    # An interval [s, e) overlaps if s1 < e2 and s2 < e1
    return start1 < end2 and start2 < end1

def preprocess_data(raw_db_data):
    """
    Preprocesses the raw data loaded from DB for easier use by the algorithms.
    - Converts time strings/timedeltas to time objects where necessary.
    - Creates mappings for quick lookups.
    - Validates essential fields.
    """
    if not raw_db_data:
        return None

    processed_data = {
        "settings": raw_db_data.get("settings", DEFAULT_SETTINGS),
        "courses": {},
        "lecturers": {},
        "classrooms": {},
        "timeslots": {},
        "instructor_unavailable_map": {}, # lecturer_id -> list of unavailable (day, start, end)
        "student_enrollments_map": {}, # course_id -> list of student_ids
        "students_courses_map": {} # student_id -> list of course_ids
    }

    # Process courses
    for course_data in raw_db_data.get("courses", []):
        course_id = course_data.get("course_id")
        if not course_id or "session_duration_slots" not in course_data:
            print(f"Warning: Skipping course due to missing 'course_id' or 'session_duration_slots': {course_data}")
            continue
        processed_data["courses"][course_id] = {
            "id": course_id,
            "name": course_data.get("name", "Unknown Course"),
            "expected_students": course_data.get("expected_students", 40), # Default if not present
            "duration_slots": course_data["session_duration_slots"],
            "preferred_lecturers": course_data.get("preferred_lecturers", []),
            "needs_special_classroom_type": course_data.get("needs_special_classroom_type")
        }

    # Process lecturers
    for lecturer_data in raw_db_data.get("lecturers", []):
        lecturer_id = lecturer_data.get("lecturer_id")
        if lecturer_id is None: # LecturerID is INT AUTO_INCREMENT, so it should exist
            print(f"Warning: Skipping lecturer due to missing 'lecturer_id': {lecturer_data}")
            continue
        processed_data["lecturers"][lecturer_id] = {
            "id": lecturer_id,
            "name": lecturer_data.get("name", "Unknown Lecturer"),
            "max_slots": lecturer_data.get("max_teaching_slots_per_week"),
            "min_slots": lecturer_data.get("min_teaching_slots_per_week")
        }

    # Process classrooms
    for classroom_data in raw_db_data.get("classrooms", []):
        classroom_id = classroom_data.get("classroom_id")
        if classroom_id is None or "capacity" not in classroom_data :
            print(f"Warning: Skipping classroom due to missing 'classroom_id' or 'capacity': {classroom_data}")
            continue
        processed_data["classrooms"][classroom_id] = {
            "id": classroom_id,
            "room_code": classroom_data.get("room_code", "Unknown Room"),
            "capacity": classroom_data["capacity"],
            "type": classroom_data.get("type", "Theory")
        }

    # Process timeslots (already have effective_basic_slots from load_data_from_db)
    for ts_data in raw_db_data.get("timeslots", []):
        ts_id = ts_data.get("timeslot_id")
        if ts_id is None or not all(k in ts_data for k in ["day_of_week", "start_time", "end_time", "effective_basic_slots"]):
            print(f"Warning: Skipping timeslot due to missing essential fields: {ts_data}")
            continue
        processed_data["timeslots"][ts_id] = {
            "id": ts_id,
            "day_of_week": ts_data["day_of_week"],
            "session_date": ts_data.get("session_date").strftime('%Y-%m-%d') if ts_data.get("session_date") else None,
            "start_time": parse_time(ts_data["start_time"]),
            "end_time": parse_time(ts_data["end_time"]),
            "effective_basic_slots": ts_data["effective_basic_slots"]
        }

    # Process instructor unavailable slots
    for un_slot in raw_db_data.get("instructor_unavailable_slots", []):
        lecturer_id = un_slot.get("lecturer_id")
        if lecturer_id is None or not all(k in un_slot for k in ["day_of_week", "start_time", "end_time"]):
            print(f"Warning: Skipping unavailable slot due to missing fields: {un_slot}")
            continue
        
        if lecturer_id not in processed_data["instructor_unavailable_map"]:
            processed_data["instructor_unavailable_map"][lecturer_id] = []
        processed_data["instructor_unavailable_map"][lecturer_id].append({
            "day_of_week": un_slot["day_of_week"],
            "start_time": parse_time(un_slot["start_time"]),
            "end_time": parse_time(un_slot["end_time"]),
            "reason": un_slot.get("reason")
        })

    # Process student enrollments
    for enroll_data in raw_db_data.get("student_enrollments", []):
        student_id = enroll_data.get("student_id")
        course_id = enroll_data.get("course_id")
        if not student_id or not course_id:
            print(f"Warning: Skipping enrollment due to missing fields: {enroll_data}")
            continue
        
        if course_id not in processed_data["student_enrollments_map"]:
            processed_data["student_enrollments_map"][course_id] = []
        if student_id not in processed_data["student_enrollments_map"][course_id]:
             processed_data["student_enrollments_map"][course_id].append(student_id)

        if student_id not in processed_data["students_courses_map"]:
            processed_data["students_courses_map"][student_id] = []
        if course_id not in processed_data["students_courses_map"][student_id]:
            processed_data["students_courses_map"][student_id].append(course_id)
            
    print("Data preprocessing completed.")
    return processed_data


# Example Usage (for testing utils.py directly)
if __name__ == "__main__":
    # Create output_data directory if it doesn't exist
    import os
    if not os.path.exists("output_data"):
        os.makedirs("output_data")

    print("Attempting to load data directly from database...")
    raw_data_from_db = load_data_from_db()

    if raw_data_from_db:
        print("\n--- Raw Data Snippet from DB (first few items) ---")
        print("Settings:", raw_data_from_db.get("settings"))
        print("First 2 Courses:", raw_data_from_db.get("courses", [])[:2])
        print("First 2 Lecturers:", raw_data_from_db.get("lecturers", [])[:2])
        print("First 2 Classrooms:", raw_data_from_db.get("classrooms", [])[:2])
        print("First 2 Timeslots (with calculated effective_basic_slots):", raw_data_from_db.get("timeslots", [])[:2])
        print("First 2 Unavailable:", raw_data_from_db.get("instructor_unavailable_slots", [])[:2])
        print("First 5 Enrollments:", raw_data_from_db.get("student_enrollments", [])[:5])
        
        processed_data = preprocess_data(raw_data_from_db)
        if processed_data:
            print("\n--- Processed Data Sample ---")
            # Print a sample from each processed data structure
            if processed_data["courses"]:
                first_course_id = list(processed_data["courses"].keys())[0]
                print(f"Course {first_course_id}:", processed_data["courses"][first_course_id])
            
            if processed_data["timeslots"]:
                first_timeslot_id = list(processed_data["timeslots"].keys())[0]
                print(f"Timeslot {first_timeslot_id}:", processed_data["timeslots"][first_timeslot_id])

            if processed_data["instructor_unavailable_map"]:
                first_lecturer_with_unavailability = list(processed_data["instructor_unavailable_map"].keys())[0]
                print(f"Lecturer {first_lecturer_with_unavailability} Unavailable:", processed_data["instructor_unavailable_map"][first_lecturer_with_unavailability])
            else:
                print("No instructor unavailability data found/processed.")

            if processed_data["students_courses_map"]:
                first_student_with_enrollments = list(processed_data["students_courses_map"].keys())[0]
                print(f"Student {first_student_with_enrollments} Courses:", processed_data["students_courses_map"][first_student_with_enrollments])
            else:
                print("No student enrollment data found/processed.")

            # Test saving dummy output
            dummy_output = [{"course_id": "ANY_COURSE_ID_FROM_DB", "lecturer_id": 1, "classroom_id": 1, "timeslot_id": 1}]
            if processed_data["courses"]: # Use an actual course ID if available
                dummy_output[0]["course_id"] = list(processed_data["courses"].keys())[0]
            
            save_output_data(dummy_output, "output_data/db_derived_output.json")
    else:
        print("Failed to load data from database.")