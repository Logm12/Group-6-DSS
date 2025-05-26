# data_loader.py
import mysql.connector
from datetime import datetime, timedelta, time # THÊM datetime và time ở đây
from typing import List, Dict, Tuple, Set

# Import các model đã định nghĩa
from models import TimeSlot, Classroom, Instructor, Course, Student

# --- Thông tin kết nối CSDL ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',
    'database': 'dss'
}

def get_db_connection():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except mysql.connector.Error as err:
        print(f"Lỗi kết nối CSDL: {err}")
        return None

def calculate_num_periods(start_time_obj, end_time_obj, period_duration_minutes=50, break_minutes=0):
    if not start_time_obj or not end_time_obj:
        return 0

    start_dt = None
    end_dt = None

    # Chuyển đổi start_time_obj sang datetime.datetime để tính toán
    if isinstance(start_time_obj, timedelta):
        start_dt = datetime.min + start_time_obj # Sử dụng datetime.min
    elif isinstance(start_time_obj, time): # Nếu là datetime.time
        start_dt = datetime.combine(datetime.min.date(), start_time_obj) # Kết hợp với một ngày giả định
    else: # Giả sử là chuỗi HH:MM:SS
        try:
            start_dt = datetime.strptime(str(start_time_obj), '%H:%M:%S')
        except ValueError:
            return 1 # Hoặc xử lý lỗi khác


    # Chuyển đổi end_time_obj sang datetime.datetime để tính toán
    if isinstance(end_time_obj, timedelta):
        end_dt = datetime.min + end_time_obj # Sử dụng datetime.min
    elif isinstance(end_time_obj, time):
        end_dt = datetime.combine(datetime.min.date(), end_time_obj)
    else: # Giả sử là chuỗi HH:MM:SS
        try:
            end_dt = datetime.strptime(str(end_time_obj), '%H:%M:%S')
        except ValueError:
            return 1


    if end_dt <= start_dt:
        end_dt += timedelta(days=1) # Xử lý qua ngày

    total_duration_minutes = (end_dt - start_dt).total_seconds() / 60

    if total_duration_minutes <= 0:
        return 0

    if period_duration_minutes == 0: return 1

    if 140 <= total_duration_minutes <= 170: return 3
    elif 220 <= total_duration_minutes <= 250: return 4
    elif 90 <= total_duration_minutes <= 125: return 2
    elif 190 <= total_duration_minutes <= 245: return 4
    elif total_duration_minutes > 245: return 5
    elif 40 <= total_duration_minutes <= 70: return 1
    else:
        # print(f"Warning: Cannot accurately determine num_periods for duration {total_duration_minutes} mins. Defaulting to 1.")
        return 1
    # return int(num_p) if num_p > 0 else 1 # Dòng này không cần thiết nữa


def load_all_data(semester_id_to_load: int) -> Tuple[List[Course], List[Instructor], List[Classroom], List[TimeSlot], List[Student]]:
    conn = get_db_connection()
    if not conn: return [], [], [], [], []
    cursor = conn.cursor(dictionary=True)

    print("Loading TimeSlots...")
    query_timeslots = "SELECT TimeSlotID, DayOfWeek, SessionDate, StartTime, EndTime FROM TimeSlots"
    cursor.execute(query_timeslots)
    db_timeslots = cursor.fetchall()
    timeslots_list: List[TimeSlot] = [] # Đổi tên biến
    timeslot_map_by_db_id: Dict[int, TimeSlot] = {}

    for row in db_timeslots:
        session_date_str = row['SessionDate'].strftime('%Y-%m-%d')
        _start_time_val = row['StartTime'] # Đây là timedelta hoặc time
        _end_time_val = row['EndTime']   # Đây là timedelta hoặc time

        start_time_str: str
        end_time_str: str

        if isinstance(_start_time_val, timedelta): start_time_str = (datetime.min + _start_time_val).strftime('%H:%M:%S')
        elif isinstance(_start_time_val, time): start_time_str = _start_time_val.strftime('%H:%M:%S')
        else: start_time_str = str(_start_time_val) # Để hàm calculate xử lý

        if isinstance(_end_time_val, timedelta): end_time_str = (datetime.min + _end_time_val).strftime('%H:%M:%S')
        elif isinstance(_end_time_val, time): end_time_str = _end_time_val.strftime('%H:%M:%S')
        else: end_time_str = str(_end_time_val)

        num_p = calculate_num_periods_from_times(_start_time_val, _end_time_val) # Sử dụng giá trị gốc

        ts_obj = TimeSlot(
            id=str(row['TimeSlotID']),
            day_of_week=row['DayOfWeek'],
            session_date=session_date_str,
            start_time=start_time_str,
            end_time=end_time_str,
            num_periods=num_p # GÁN SỐ TIẾT
        )
        timeslots_list.append(ts_obj)
        timeslot_map_by_db_id[row['TimeSlotID']] = ts_obj
    print(f"Loaded {len(timeslots_list)} TimeSlots.")
    # 2. Load Classrooms
    print("Loading Classrooms...")
    query_classrooms = "SELECT ClassroomID, RoomCode, Capacity, Type FROM Classrooms"
    cursor.execute(query_classrooms)
    db_classrooms = cursor.fetchall()
    classrooms_list: List[Classroom] = [] # Đổi tên để tránh nhầm lẫn với module
    for row in db_classrooms:
        classrooms_list.append(Classroom(
            id=row['RoomCode'],
            capacity=row['Capacity']
        ))
    print(f"Loaded {len(classrooms_list)} Classrooms.")

    # 3. Load Instructors và InstructorUnavailableSlots
    print("Loading Instructors...")
    query_instructors = "SELECT LecturerID, LecturerName FROM Lecturers"
    cursor.execute(query_instructors)
    db_instructors = cursor.fetchall()
    instructors_list: List[Instructor] = [] # Đổi tên
    instructor_map_by_db_id: Dict[int, Instructor] = {}

    for row in db_instructors:
        instr_obj = Instructor(
            id=str(row['LecturerID']),
            name=row['LecturerName'],
            unavailable_slot_ids=set()
        )
        instructors_list.append(instr_obj)
        instructor_map_by_db_id[row['LecturerID']] = instr_obj

    query_unavailable = """
        SELECT ius.LecturerID, ts.TimeSlotID
        FROM InstructorUnavailableSlots ius
        JOIN TimeSlots ts ON (ius.BusyDayOfWeek = ts.DayOfWeek AND ius.BusyStartTime = ts.StartTime AND ius.BusyEndTime = ts.EndTime)
        WHERE ius.SemesterID = %s OR ius.SemesterID IS NULL
    """
    cursor.execute(query_unavailable, (semester_id_to_load,))
    db_unavailable_slots = cursor.fetchall()
    for row in db_unavailable_slots:
        lecturer_db_id = row['LecturerID']
        timeslot_db_id = row['TimeSlotID']
        if lecturer_db_id in instructor_map_by_db_id and timeslot_db_id in timeslot_map_by_db_id:
            instructor_obj = instructor_map_by_db_id[lecturer_db_id]
            timeslot_obj_id_str = timeslot_map_by_db_id[timeslot_db_id].id
            instructor_obj.unavailable_slot_ids.add(timeslot_obj_id_str)
    print(f"Loaded {len(instructors_list)} Instructors and updated their unavailable slots.")

    # 4. Load Courses
    print("Loading Courses...")
    query_courses = "SELECT CourseID, CourseName, ExpectedStudents, SessionDurationSlots FROM Courses"
    cursor.execute(query_courses)
    db_courses = cursor.fetchall()
    courses_list: List[Course] = [] # Đổi tên
    for row in db_courses:
        courses_list.append(Course(
            id=row['CourseID'],
            name=row['CourseName'],
            num_students=row['ExpectedStudents'] if row['ExpectedStudents'] is not None else 0,
            required_periods_per_session=row['SessionDurationSlots'] if row['SessionDurationSlots'] is not None else 1,
            eligible_instructor_ids=set()
        ))
    print(f"Loaded {len(courses_list)} Courses.")

    # 5. Load Students và StudentEnrollments
    print("Loading Students...")
    query_students = "SELECT StudentID, StudentName FROM Students"
    cursor.execute(query_students)
    db_students_info = cursor.fetchall()
    students_list: List[Student] = [] # Đổi tên
    student_map: Dict[str, Student] = {}

    for row in db_students_info:
        student_obj = Student(
            id=row['StudentID'],
            enrolled_course_ids=set()
        )
        students_list.append(student_obj)
        student_map[row['StudentID']] = student_obj

    query_enrollments = "SELECT StudentID, CourseID FROM StudentEnrollments WHERE SemesterID = %s"
    cursor.execute(query_enrollments, (semester_id_to_load,))
    db_enrollments = cursor.fetchall()

    for row in db_enrollments:
        student_id_str = row['StudentID']
        course_id_str = row['CourseID']
        if student_id_str in student_map:
            student_map[student_id_str].enrolled_course_ids.add(course_id_str)
    print(f"Loaded {len(students_list)} Students and their enrollments for semester {semester_id_to_load}.")

    cursor.close()
    conn.close()

    return courses_list, instructors_list, classrooms_list, timeslots, students_list


if __name__ == '__main__':
    print("Testing data loader...")
    target_semester_id = 1
    
    # Đổi tên biến để không trùng với tên module (nếu có)
    loaded_courses, loaded_instructors, loaded_classrooms, loaded_timeslots, loaded_students = load_all_data(target_semester_id)

    print(f"\n--- Summary ---")
    print(f"Total Courses: {len(loaded_courses)}")
    if loaded_courses:
        print(f"Sample Course: {loaded_courses[0]}")
        if loaded_students and loaded_students[0].enrolled_course_ids:
            print(f"  Enrollments for first student ({loaded_students[0].id}):")
            for cid in list(loaded_students[0].enrolled_course_ids)[:3]: print(f"    - {cid}")

    print(f"Total Instructors: {len(loaded_instructors)}")
    if loaded_instructors:
        print(f"Sample Instructor: {loaded_instructors[0]}")
        if loaded_instructors[0].unavailable_slot_ids:
            print(f"  Unavailable slots: {list(loaded_instructors[0].unavailable_slot_ids)[:3]}")

    print(f"Total Classrooms: {len(loaded_classrooms)}")
    if loaded_classrooms:
        print(f"Sample Classroom: {loaded_classrooms[0]}")

    print(f"Total TimeSlots: {len(loaded_timeslots)}")
    if loaded_timeslots:
        print(f"Sample TimeSlot: {loaded_timeslots[0]}")

    print(f"Total Students loaded (with enrollments for semester {target_semester_id}): {len(loaded_students)}")
    if loaded_students:
         print(f"Sample Student: {loaded_students[0]}")