import mysql.connector
from datetime import datetime, timedelta, time as dt_time
from typing import List, Dict, Tuple, Set, Optional
import uuid

from models import (
    TimeSlot, Classroom, Instructor, Course, Student, ScheduledClass,
    Schedule, SchedulingMetrics, SchedulingResult
)

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
        print(f"DATA_LOADER ERROR: Database connection failed: {err}")
        return None

def string_to_time(time_str: any) -> Optional[dt_time]:
    if isinstance(time_str, dt_time):
        return time_str
    if isinstance(time_str, timedelta):
        return (datetime.min + time_str).time()
    if not isinstance(time_str, str):
        return None
    try:
        return datetime.strptime(time_str, '%H:%M:%S').time()
    except ValueError:
        try:
            return datetime.strptime(time_str, '%H:%M').time()
        except ValueError:
            return None

def check_time_overlap(slot_start: Optional[dt_time], slot_end: Optional[dt_time],
                       busy_start: Optional[dt_time], busy_end: Optional[dt_time]) -> bool:
    if not all(isinstance(t, dt_time) for t in [slot_start, slot_end, busy_start, busy_end]):
        return False
    if slot_start >= slot_end or busy_start >= busy_end:
        return False
    return max(slot_start, busy_start) < min(slot_end, busy_end)


def load_all_data(semester_id_to_load: int) -> Tuple[
    List[ScheduledClass], List[Instructor], List[Classroom], List[TimeSlot], List[Student], Dict[str, Course]
]:
    conn = get_db_connection()
    empty_return = [], [], [], [], [], {}
    if not conn:
        return empty_return

    cursor = None
    print(f"DATA_LOADER INFO: Starting data load for SemesterID: {semester_id_to_load}.")
    try:
        cursor = conn.cursor(dictionary=True)

        query_semester = "SELECT SemesterName FROM Semesters WHERE SemesterID = %s"
        cursor.execute(query_semester, (semester_id_to_load,))
        semester_row = cursor.fetchone()
        if not semester_row:
            print(f"DATA_LOADER ERROR: SemesterID {semester_id_to_load} not found.")
            return empty_return
        print(f"DATA_LOADER INFO: Confirmed Semester: {semester_row['SemesterName']} (ID: {semester_id_to_load}).")

        print("DATA_LOADER INFO: Loading TimeSlots...")
        query_timeslots = "SELECT TimeSlotID, DayOfWeek, StartTime, EndTime FROM TimeSlots ORDER BY TimeSlotID"
        cursor.execute(query_timeslots)
        db_timeslots = cursor.fetchall()
        timeslots_list: List[TimeSlot] = []
        timeslot_map_by_db_id: Dict[int, TimeSlot] = {}
        timeslot_map_by_model_id: Dict[str, TimeSlot] = {}

        for row in db_timeslots:
            start_t = string_to_time(row['StartTime'])
            end_t = string_to_time(row['EndTime'])
            if not start_t or not end_t:
                print(f"DATA_LOADER WARNING: Skipping TimeSlotID {row['TimeSlotID']} due to invalid time format: Start='{row['StartTime']}', End='{row['EndTime']}'")
                continue
            
            ts_obj = TimeSlot(
                id=str(row['TimeSlotID']),
                day_of_week=str(row['DayOfWeek']),
                start_time=start_t.strftime('%H:%M:%S'),
                end_time=end_t.strftime('%H:%M:%S')
            )
            timeslots_list.append(ts_obj)
            timeslot_map_by_db_id[int(row['TimeSlotID'])] = ts_obj
            timeslot_map_by_model_id[ts_obj.id] = ts_obj
        if not timeslots_list:
            print(f"DATA_LOADER ERROR: No TimeSlots found in the database. Cannot proceed.")
            return empty_return
        print(f"DATA_LOADER INFO: Loaded {len(timeslots_list)} generic TimeSlots.")

        print("DATA_LOADER INFO: Loading Classrooms...")
        query_classrooms = "SELECT ClassroomID, RoomCode, Capacity, Type FROM Classrooms ORDER BY ClassroomID"
        cursor.execute(query_classrooms)
        classrooms_list: List[Classroom] = []
        classroom_map_by_db_id: Dict[int, Classroom] = {}
        for row in cursor.fetchall():
            cr_obj = Classroom(
                id=int(row['ClassroomID']),
                room_code=str(row['RoomCode']),
                capacity=int(row['Capacity']),
                type=str(row['Type']) if row['Type'] is not None else 'Theory'
            )
            classrooms_list.append(cr_obj)
            classroom_map_by_db_id[cr_obj.id] = cr_obj
        if not classrooms_list:
            print(f"DATA_LOADER WARNING: No Classrooms found. Scheduling might be impossible if rooms are required.")
        print(f"DATA_LOADER INFO: Loaded {len(classrooms_list)} Classrooms.")

        print("DATA_LOADER INFO: Loading Instructors...")
        query_instructors = "SELECT LecturerID, LecturerName FROM Lecturers ORDER BY LecturerID"
        cursor.execute(query_instructors)
        instructors_list: List[Instructor] = []
        instructor_map_by_db_id: Dict[int, Instructor] = {}
        
        for row in cursor.fetchall():
            instr_obj = Instructor(
                id=str(row['LecturerID']),
                name=str(row['LecturerName']),
                unavailable_slot_ids=set()
            )
            instructors_list.append(instr_obj)
            instructor_map_by_db_id[int(row['LecturerID'])] = instr_obj
        if not instructors_list:
            print(f"DATA_LOADER WARNING: No Instructors found. Scheduling might be impossible if instructors are required.")
        print(f"DATA_LOADER INFO: Loaded {len(instructors_list)} Instructors.")

        print("DATA_LOADER INFO: Loading Courses Catalog...")
        query_courses_table = """
            SELECT CourseID, CourseName, ExpectedStudents, Credits, SessionDurationSlots 
            FROM Courses 
            ORDER BY CourseID
        """
        cursor.execute(query_courses_table)
        db_courses_info = cursor.fetchall()
        courses_catalog_map: Dict[str, Course] = {}

        for row_course_info in db_courses_info:
            course_id_str = str(row_course_info['CourseID'])
            expected_students = int(row_course_info['ExpectedStudents'] or 0)
            if expected_students == 0:
                print(f"DATA_LOADER WARNING: Course {course_id_str} ('{row_course_info['CourseName']}') has 0 ExpectedStudents. This might affect scheduling constraints.")

            credits_val = row_course_info['Credits']
            
            courses_catalog_map[course_id_str] = Course(
                id=course_id_str,
                name=str(row_course_info['CourseName']),
                expected_students=expected_students,
                credits=int(credits_val) if credits_val is not None else None,
                required_periods_per_session=int(row_course_info['SessionDurationSlots'] or 1)
            )

        if not courses_catalog_map:
            print(f"DATA_LOADER ERROR: No Courses found in the Courses table. Cannot effectively schedule.")
            return empty_return
        print(f"DATA_LOADER INFO: Loaded {len(courses_catalog_map)} Courses into catalog.")

        print(f"DATA_LOADER INFO: Loading ScheduledClasses for SemesterID {semester_id_to_load}...")
        query_scheduled_classes = """
            SELECT ScheduleID, CourseID, LecturerID, ClassroomID, TimeSlotID, SemesterID
            FROM ScheduledClasses
            WHERE SemesterID = %s
            ORDER BY ScheduleID
        """
        cursor.execute(query_scheduled_classes, (semester_id_to_load,))
        db_scheduled_classes = cursor.fetchall()
        scheduled_classes_list: List[ScheduledClass] = []

        for sc_row in db_scheduled_classes:
            course_id_str = str(sc_row['CourseID'])
            course_obj = courses_catalog_map.get(course_id_str)

            if not course_obj:
                print(f"DATA_LOADER WARNING: ScheduledClass ID {sc_row['ScheduleID']} refers to CourseID {course_id_str} which is not in Courses catalog. Skipping.")
                continue

            num_students_for_class = course_obj.expected_students

            lecturer_db_id = sc_row.get('LecturerID')
            instructor_model_id: Optional[str] = None
            if lecturer_db_id is not None:
                lecturer_db_id_int = int(lecturer_db_id)
                if lecturer_db_id_int in instructor_map_by_db_id:
                    instructor_model_id = instructor_map_by_db_id[lecturer_db_id_int].id
                else:
                    print(f"DATA_LOADER WARNING: ScheduledClass ID {sc_row['ScheduleID']} has invalid LecturerID {lecturer_db_id_int}. Setting assignment to None.")
            
            classroom_db_id = sc_row.get('ClassroomID')
            classroom_model_id: Optional[int] = None
            if classroom_db_id is not None:
                classroom_db_id_int = int(classroom_db_id)
                if classroom_db_id_int in classroom_map_by_db_id:
                    classroom_model_id = classroom_map_by_db_id[classroom_db_id_int].id
                else:
                    print(f"DATA_LOADER WARNING: ScheduledClass ID {sc_row['ScheduleID']} has invalid ClassroomID {classroom_db_id_int}. Setting assignment to None.")

            timeslot_db_id = sc_row.get('TimeSlotID')
            timeslot_model_id: Optional[str] = None
            if timeslot_db_id is not None:
                timeslot_db_id_int = int(timeslot_db_id)
                if timeslot_db_id_int in timeslot_map_by_db_id:
                    timeslot_model_id = timeslot_map_by_db_id[timeslot_db_id_int].id
                else:
                    print(f"DATA_LOADER WARNING: ScheduledClass ID {sc_row['ScheduleID']} has invalid TimeSlotID {timeslot_db_id_int}. Setting assignment to None.")

            scheduled_classes_list.append(ScheduledClass(
                id=int(sc_row['ScheduleID']),
                course_id=course_id_str,
                num_students=num_students_for_class,
                semester_id=int(sc_row['SemesterID']),
                instructor_id=instructor_model_id,
                classroom_id=classroom_model_id,
                timeslot_id=timeslot_model_id
            ))
        
        if not scheduled_classes_list:
            print(f"DATA_LOADER INFO: No pre-existing ScheduledClass entries found for SemesterID {semester_id_to_load}. "
                  "The scheduling algorithm will generate a new schedule if courses are defined and need scheduling.")
        else:
            print(f"DATA_LOADER INFO: Loaded {len(scheduled_classes_list)} existing ScheduledClass entries for the semester.")
        
        print("DATA_LOADER INFO: Loading Students and their enrollments...")
        query_students = "SELECT StudentID, StudentName FROM Students ORDER BY StudentID"
        cursor.execute(query_students)
        students_list: List[Student] = []
        student_map_by_original_id: Dict[str, Student] = {}

        for row in cursor.fetchall():
            student_id_str = str(row['StudentID'])
            student_name = str(row['StudentName']) if row['StudentName'] else None
            student_obj = Student(id=student_id_str, name=student_name, enrolled_course_ids=set())
            students_list.append(student_obj)
            student_map_by_original_id[student_id_str] = student_obj

        query_enrollments = """
            SELECT StudentID, CourseID 
            FROM StudentEnrollments 
            WHERE SemesterID = %s
        """
        cursor.execute(query_enrollments, (semester_id_to_load,))
        enrollments_count = 0
        enrollments_processed_for_known_students_courses = 0
        for row in cursor.fetchall():
            enrollments_count +=1
            student_original_id_str = str(row['StudentID'])
            course_original_id_str = str(row['CourseID'])
            
            student_obj = student_map_by_original_id.get(student_original_id_str)
            if student_obj:
                if course_original_id_str in courses_catalog_map:
                     student_obj.enrolled_course_ids.add(course_original_id_str)
                     enrollments_processed_for_known_students_courses +=1
                else:
                    print(f"DATA_LOADER INFO: Student '{student_original_id_str}' enrollment for CourseID '{course_original_id_str}' skipped: Course not in catalog for this semester load.")
            else:
                 print(f"DATA_LOADER INFO: Enrollment for StudentID '{student_original_id_str}' (Course '{course_original_id_str}') skipped: Student not found in loaded students.")
        print(f"DATA_LOADER INFO: Loaded {len(students_list)} Students. Processed {enrollments_processed_for_known_students_courses}/{enrollments_count} enrollments for semester {semester_id_to_load}.")

        print("DATA_LOADER INFO: Updating Instructor unavailable slots...")
        query_unavailable_defs = """
            SELECT iu.LecturerID, iu.BusyDayOfWeek, iu.BusyStartTime, iu.BusyEndTime
            FROM InstructorUnavailableSlots iu
            WHERE iu.SemesterID = %s OR iu.SemesterID IS NULL 
        """
        cursor.execute(query_unavailable_defs, (semester_id_to_load,))
        
        unavailable_slots_processed = 0
        for unavailable_def in cursor.fetchall():
            lecturer_db_id_int = int(unavailable_def['LecturerID'])
            instructor_obj = instructor_map_by_db_id.get(lecturer_db_id_int)

            if not instructor_obj:
                print(f"DATA_LOADER INFO: Unavailable slot definition for unknown LecturerDBID {lecturer_db_id_int}. Skipping.")
                continue

            busy_day_str = str(unavailable_def['BusyDayOfWeek'])
            busy_start_time_obj = string_to_time(unavailable_def['BusyStartTime'])
            busy_end_time_obj = string_to_time(unavailable_def['BusyEndTime'])

            if not busy_start_time_obj or not busy_end_time_obj:
                print(f"DATA_LOADER WARNING: Invalid time for unavailability for LecturerID {lecturer_db_id_int} (Name: {instructor_obj.name}). BusyDay: {busy_day_str}, Start: {unavailable_def['BusyStartTime']}, End: {unavailable_def['BusyEndTime']}. Skipping this entry.")
                continue
            
            if busy_start_time_obj >= busy_end_time_obj:
                print(f"DATA_LOADER WARNING: Invalid busy period (start >= end) for LecturerID {lecturer_db_id_int}. Start: {busy_start_time_obj}, End: {busy_end_time_obj}. Skipping.")
                continue

            for ts_model_id, ts_obj in timeslot_map_by_model_id.items():
                if ts_obj.day_of_week.lower() == busy_day_str.lower():
                    ts_start_obj = string_to_time(ts_obj.start_time)
                    ts_end_obj = string_to_time(ts_obj.end_time)

                    if not ts_start_obj or not ts_end_obj:
                        print(f"DATA_LOADER CRITICAL WARNING: TimeSlot object {ts_obj.id} has invalid times. Skipping overlap check.")
                        continue
                        
                    if check_time_overlap(ts_start_obj, ts_end_obj, busy_start_time_obj, busy_end_time_obj):
                        instructor_obj.unavailable_slot_ids.add(ts_model_id)
                        unavailable_slots_processed += 1

        print(f"DATA_LOADER INFO: Updated unavailable slots for instructors. Applied {unavailable_slots_processed} specific slot blockages based on definitions.")

    except mysql.connector.Error as err:
        print(f"DATA_LOADER ERROR: Database error during data loading: {err}")
        return empty_return
    except Exception as e:
        print(f"DATA_LOADER ERROR: An unexpected error occurred during data loading: {e}")
        import traceback
        traceback.print_exc()
        return empty_return
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

    print(f"DATA_LOADER INFO: Data loading process completed for SemesterID: {semester_id_to_load}.")
    return scheduled_classes_list, instructors_list, classrooms_list, timeslots_list, students_list, courses_catalog_map

if __name__ == '__main__':
    print("DATA_LOADER: Running standalone test...")
    target_semester_id = 1 

    conn_test = get_db_connection()
    sem_exists = False
    if conn_test:
        try:
            cursor_test = conn_test.cursor(dictionary=True)
            cursor_test.execute("SELECT SemesterName FROM Semesters WHERE SemesterID = %s", (target_semester_id,))
            sem_row_test = cursor_test.fetchone()
            if sem_row_test:
                sem_exists = True
                print(f"DATA_LOADER Test: SemesterID {target_semester_id} ('{sem_row_test['SemesterName']}') exists. Proceeding with load.")
            cursor_test.close()
        except Exception as e_test_sem:
            print(f"DATA_LOADER Test ERROR: Error checking semester existence: {e_test_sem}")
        finally:
            if conn_test.is_connected():
                conn_test.close()
    
    if not sem_exists:
        print(f"DATA_LOADER Test ERROR: SemesterID {target_semester_id} does not exist in 'Semesters' table. Please check DB or change target_semester_id.")
    else:
        print(f"DATA_LOADER Test: Attempting to load all data for SemesterID {target_semester_id}...")
        
        loaded_scheduled_classes, loaded_instructors, loaded_classrooms, \
        loaded_timeslots, loaded_students, loaded_courses_catalog = load_all_data(target_semester_id)

        print(f"\n--- Data Loading Summary for Semester {target_semester_id} ---")
        
        print(f"Total TimeSlots loaded: {len(loaded_timeslots)}")
        if loaded_timeslots:
            for i in range(min(3, len(loaded_timeslots))):
                 print(f"  Sample TimeSlot {i+1}: {loaded_timeslots[i]}")

        print(f"Total Classrooms loaded: {len(loaded_classrooms)}")
        if loaded_classrooms:
            for i in range(min(3, len(loaded_classrooms))):
                 print(f"  Sample Classroom {i+1}: {loaded_classrooms[i]}")

        print(f"Total Instructors loaded: {len(loaded_instructors)}")
        if loaded_instructors:
            for i in range(min(3, len(loaded_instructors))):
                instr = loaded_instructors[i]
                print(f"  Sample Instructor {i+1}: ID={instr.id}, Name='{instr.name}', Unavailable Slot IDs: {instr.unavailable_slot_ids if instr.unavailable_slot_ids else 'None'}")

        print(f"Total Courses in Catalog: {len(loaded_courses_catalog)}")
        if loaded_courses_catalog:
            count = 0
            for course_id_sample, course_sample in loaded_courses_catalog.items():
                print(f"  Sample Course {count+1}: {course_sample}")
                count +=1
                if count >=3: break
        
        print(f"Total Scheduled Classes loaded (input/existing): {len(loaded_scheduled_classes)}")
        if loaded_scheduled_classes:
            for i in range(min(3, len(loaded_scheduled_classes))):
                 print(f"  Sample ScheduledClass {i+1}: {loaded_scheduled_classes[i]}")
        
        print(f"Total Students loaded: {len(loaded_students)}")
        if loaded_students:
            for i in range(min(3, len(loaded_students))):
                stud = loaded_students[i]
                enrolled_ids = list(stud.enrolled_course_ids)[:2]
                print(f"  Sample Student {i+1}: ID={stud.id}, Name='{stud.name}', Enrolled Courses (sample): {enrolled_ids if enrolled_ids else 'None'}")
        
        print("\nDATA_LOADER Test: Load process finished.")