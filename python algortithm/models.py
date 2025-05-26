# models.py
from dataclasses import dataclass, field
from typing import List, Optional, Set # Sử dụng Set cho các ID để tránh trùng lặp và tìm kiếm nhanh

# Sử dụng Set cho các ID để đảm bảo tính duy nhất và truy cập nhanh
# Các ID này sẽ tương ứng với các khóa chính trong CSDL của bạn

@dataclass(frozen=True)
class TimeSlot:
    id: str
    day_of_week: str
    session_date: str # YYYY-MM-DD
    start_time: str  # HH:MM:SS
    end_time: str    # HH:MM:SS
    num_periods: int # <<<< THÊM DÒNG NÀY: Số tiết học trong timeslot này

    def __repr__(self):
        return (f"TimeSlot(id='{self.id}', day='{self.day_of_week}', date='{self.session_date}', "
                f"time='{self.start_time}-{self.end_time}', periods={self.num_periods})")

@dataclass(frozen=True)
class Classroom:
    id: str  # Tương ứng với RoomCode từ CSDL hoặc ClassroomID nếu bạn tạo sequence
    capacity: int  # Từ cột Capacity
    # type: Optional[str] = None # Ví dụ: "Theory", "Lab" (Từ cột Type)

    def __repr__(self):
        return f"Classroom(id='{self.id}', capacity={self.capacity})"

@dataclass
class Instructor:
    id: str  # Tương ứng với LecturerID (nếu là số) hoặc một mã GV duy nhất
    name: str  # Từ cột LecturerName
    # Danh sách các TimeSlot.id mà giảng viên này bận (từ bảng InstructorUnavailableSlots)
    unavailable_slot_ids: Set[str] = field(default_factory=set)
    # Ràng buộc mềm: (có thể thêm sau nếu cần cho GA)
    # max_teaching_hours: Optional[float] = None
    # preferred_timeslot_ids: Set[str] = field(default_factory=set)

    def __hash__(self): # Cần thiết nếu Instructor được dùng làm key hoặc trong set
        return hash(self.id)

    def __eq__(self, other):
        if not isinstance(other, Instructor):
            return NotImplemented
        return self.id == other.id

    def __repr__(self):
        return f"Instructor(id='{self.id}', name='{self.name}')"


@dataclass
class Course:
    id: str  # Tương ứng với CourseID từ CSDL (mã môn học)
    name: str  # Từ cột CourseName
    num_students: int  # Số sinh viên dự kiến (Từ ExpectedStudents hoặc tính từ StudentEnrollments)
    
    # Số tiết học cần cho một buổi của môn này.
    # Sẽ được so khớp với số tiết của TimeSlot.
    # Ví dụ: nếu môn cần 3 tiết liền, required_periods_per_session = 3.
    # Điều này cần được xác định rõ từ dữ liệu đầu vào của bạn (ví dụ: từ cột SessionDurationSlots).
    required_periods_per_session: int

    # Danh sách ID của các giảng viên có thể dạy môn này (có thể lấy từ một bảng liên kết Course_Instructors)
    # Nếu để trống, nghĩa là bất kỳ giảng viên nào (có thể không thực tế)
    eligible_instructor_ids: Set[str] = field(default_factory=set)

    # Yêu cầu đặc thù của phòng học (nếu có, ví dụ: "Lab", "Projector")
    # classroom_requirements: Optional[str] = None # Ví dụ: 'Lab'

    def __hash__(self):
        return hash(self.id)

    def __eq__(self, other):
        if not isinstance(other, Course):
            return NotImplemented
        return self.id == other.id

    def __repr__(self):
        return f"Course(id='{self.id}', name='{self.name}', students={self.num_students}, periods={self.required_periods_per_session})"

@dataclass(frozen=True)
class Student:
    id: str # Tương ứng với StudentID từ CSDL
    # name: str # Từ cột StudentName (có thể không cần trực tiếp cho thuật toán nếu chỉ quan tâm đến ID)
    # Danh sách các Course.id mà sinh viên này đã đăng ký (từ bảng StudentEnrollments)
    enrolled_course_ids: Set[str] = field(default_factory=set)

    def __repr__(self):
        return f"Student(id='{self.id}', enrolled_courses={len(self.enrolled_course_ids)})"


# --- Cấu trúc biểu diễn một lớp học đã được xếp lịch ---
# Đây sẽ là "gen" trong cá thể của thuật toán GA, hoặc là kết quả của CP.
@dataclass(frozen=True) # Bất biến, tốt cho việc so sánh và hashing
class ScheduledClass:
    course_id: str
    instructor_id: str
    classroom_id: str
    timeslot_id: str # ID của TimeSlot đã được gán

    # Bạn có thể thêm các thuộc tính được suy ra để dễ tính toán fitness sau này,
    # ví dụ: day, start_time, end_time lấy từ TimeSlot object tương ứng.
    # Hoặc để hàm fitness tự tra cứu khi cần.

    def __repr__(self):
        return (f"ScheduledClass(Course: {self.course_id}, Instr: {self.instructor_id}, "
                f"Room: {self.classroom_id}, Slot: {self.timeslot_id})")

# --- Cấu trúc biểu diễn một giải pháp hoàn chỉnh (một lịch trình) ---
# Một cá thể trong GA sẽ là một list các ScheduledClass.
# Type alias for a schedule
Schedule = List[ScheduledClass]