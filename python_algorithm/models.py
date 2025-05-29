# models.py
from dataclasses import dataclass, field
from typing import List, Optional, Set, Tuple

# Type alias for a schedule, which is a list of ScheduledClass objects
# Using forward reference as ScheduledClass is defined later.
Schedule = List['ScheduledClass']

@dataclass(frozen=True)
class TimeSlot:
    id: str # Original TimeSlotID from DB, converted to string (e.g., "1", "2", ..., "24")
    day_of_week: str # e.g., 'Monday', 'Tuesday'
    start_time: str  # HH:MM:SS
    end_time: str    # HH:MM:SS

    def __repr__(self):
        return (f"TimeSlot(id='{self.id}', day='{self.day_of_week}', "
                f"time='{self.start_time}-{self.end_time}')")

@dataclass(frozen=True)
class Classroom:
    id: int # Original ClassroomID from DB
    room_code: str
    capacity: int
    type: str = 'Theory' # Default type as per DB description (VARCHAR(50), Default: 'Theory')

    def __repr__(self):
        return (f"Classroom(id={self.id}, room_code='{self.room_code}', "
                f"capacity={self.capacity}, type='{self.type}')")

@dataclass
class Instructor:
    id: str # Original LecturerID from DB (INT), converted to string by data_loader for consistency
    name: str
    # Set of TimeSlot.id where instructor is unavailable.
    # data_loader.py maps (BusyDayOfWeek, BusyStartTime, BusyEndTime) from instructorunavailableslots
    # to these TimeSlot.id(s)
    unavailable_slot_ids: Set[str] = field(default_factory=set)

    def __hash__(self):
        return hash(self.id)

    def __eq__(self, other):
        if not isinstance(other, Instructor):
            return NotImplemented
        return self.id == other.id

    def __repr__(self):
        return f"Instructor(id='{self.id}', name='{self.name}')"

@dataclass
class Course:
    id: str # Original CourseID from DB (VARCHAR(20), e.g., 'BSA105501')
    name: str
    # ExpectedStudents from DB (courses.ExpectedStudents).
    # Crucial for capacity constraints. data_loader.py must populate this.
    # Assumes this value is available and > 0 for courses that need scheduling.
    expected_students: int
    credits: Optional[int] = None # From courses.Credits (currently NULL in sample)

    # Assumes SessionDurationSlots from DB is always 1, as per "đã chuẩn hóa timeslots".
    # This means one course session fits exactly one TimeSlot.
    # If a course requires multiple *contiguous* timeslots, this model and associated logic
    # (especially in CP/GA and constraints) will need significant changes.
    required_periods_per_session: int = 1 # Based on courses.SessionDurationSlots (currently 1)

    def __hash__(self):
        return hash(self.id)

    def __eq__(self, other):
        if not isinstance(other, Course):
            return NotImplemented
        return self.id == other.id

    def __repr__(self):
        return (f"Course(id='{self.id}', name='{self.name}', "
                f"expected_students={self.expected_students}, "
                f"credits={self.credits if self.credits is not None else 'N/A'}, "
                f"periods_per_session={self.required_periods_per_session})")

@dataclass(frozen=True)
class Student:
    id: str # Original StudentID from DB (VARCHAR(20))
    name: Optional[str] = None # From students.StudentName, for potential future use (e.g. reports)
    # Set of Course.id, populated from studentenrollments table
    enrolled_course_ids: Set[str] = field(default_factory=set)

    def __repr__(self):
        student_name_str = f", name='{self.name}'" if self.name else ""
        return (f"Student(id='{self.id}{student_name_str}', "
                f"enrolled_courses_count={len(self.enrolled_course_ids)})")

@dataclass
class ScheduledClass:
    """
    Represents a specific class session to be scheduled or already existing in input.
    If this is from input `scheduledclasses` table, `id` is its PK.
    The scheduler assigns instructor_id, classroom_id, timeslot_id.
    """
    # Fields that must be provided or are known from input
    id: int                 # Original ScheduleID from DB (PK of scheduledclasses table if input).
                            # For newly generated classes by the algorithm, this might be a temporary ID
                            # or assigned when saving to DB (e.g., negative or unique placeholder).
    course_id: str          # Course.id (FK to courses.CourseID)
    semester_id: int        # Semester.id (FK to semesters.SemesterID)

    # num_students for this specific class instance/section.
    # This value is crucial for the classroom capacity constraint.
    # For initial scheduling, this is typically derived from Course.expected_students.
    # If a course is split into multiple sections with different student counts,
    # this would reflect the count for *this specific section*.
    # data_loader.py is responsible for populating this correctly.
    num_students: int

    # These are the decision variables the scheduler will determine and assign
    instructor_id: Optional[str] = None  # Instructor.id (FK to lecturers.LecturerID)
    classroom_id: Optional[int] = None   # Classroom.id (FK to classrooms.ClassroomID)
    timeslot_id: Optional[str] = None    # TimeSlot.id (FK to timeslots.TimeSlotID)

    def __repr__(self):
        return (f"ScheduledClass(id={self.id}, Course='{self.course_id}', NumStud={self.num_students}, "
                f"Sem={self.semester_id}, Instr='{self.instructor_id or 'Unassigned'}', "
                f"Room='{self.classroom_id or 'Unassigned'}', Slot='{self.timeslot_id or 'Unassigned'}')")

@dataclass
class SchedulingMetrics:
    """
    Stores metrics evaluating the quality of a generated schedule.
    """
    # Overall quality/fitness
    fitness_score: Optional[float] = None          # e.g., from GA, lower is better if it's a cost
    
    # Constraint violations
    hard_constraints_violated: Optional[int] = 0  # Number of hard constraint violations (should be 0 for a feasible schedule)
    soft_constraints_penalty: Optional[float] = 0.0 # Total weighted penalty from soft constraint violations

    # Specific soft constraint metrics (examples, counts or specific penalty contributions)
    student_clashes: Optional[int] = None          # Number of instances where a student has overlapping classes
    instructor_overloads_count: Optional[int] = None # Number of instructors exceeding max teaching load
    instructor_underloads_count: Optional[int] = None# Number of instructors below min teaching load
    instructor_teaching_hours_variance: Optional[float] = None # Statistic for fairness in teaching load
    instructor_short_breaks_violations: Optional[int] = None # Instances of insufficient breaks between classes for instructors
    
    # Resource utilization metrics
    room_utilization_percentage: Optional[float] = None # (Total student hours / Total available seat hours in used slots)
    total_seated_students: Optional[int] = None      # Sum of num_students for all scheduled classes
    total_classroom_capacity_in_used_slots: Optional[int] = None # Sum of capacity for (classroom, timeslot) pairs that are used
    sum_wasted_room_capacity: Optional[int] = None   # Sum of (classroom.capacity - num_students) for each scheduled class
    number_of_rooms_used: Optional[int] = None
    
    # Custom accuracy/precision metric (definition to be finalized based on specific project goals)
    # e.g., percentage of 'preferred' slots used, a composite score of soft constraint satisfaction, etc.
    custom_defined_accuracy: Optional[float] = None

    computation_time_seconds: Optional[float] = None

    def __repr__(self):
        metrics_list = [f"{key}={value}" for key, value in self.__dict__.items() if value is not None]
        if not metrics_list:
            return "SchedulingMetrics(No metrics calculated)"
        return f"SchedulingMetrics({', '.join(metrics_list)})"

@dataclass
class SchedulingResult:
    """
    Encapsulates the output of a scheduling run, including the main schedule,
    its metrics, and potentially alternative solutions for "What-If" scenarios.
    """
    run_id: str # A unique identifier for this scheduling run (e.g., UUID, timestamp-based)
    
    # The primary/best schedule found by the algorithm
    main_schedule: Schedule # Type alias: List[ScheduledClass]
    main_metrics: SchedulingMetrics

    # For "What-If" scenarios or if the algorithm is configured to return multiple good solutions.
    # Each tuple contains an alternative schedule (List[ScheduledClass]) and its corresponding metrics.
    alternative_schedules: List[Tuple[Schedule, SchedulingMetrics]] = field(default_factory=list)

    # Metadata about the scheduling run
    scenario_name: Optional[str] = None # e.g., "Default Run", "WhatIf: Reduced Room Capacity by 10%"
    status_message: str = "Completed" # e.g., "Optimal solution found", "Feasible solution found", "Terminated due to timeout", "Error during execution"
    input_parameters: Optional[dict] = None # Dictionary of key parameters used for this run

    def __repr__(self):
        num_alternatives = len(self.alternative_schedules)
        main_schedule_info = f"Size: {len(self.main_schedule)} classes" if self.main_schedule else "None"
        return (f"SchedulingResult(RunID: {self.run_id}, Scenario: '{self.scenario_name or 'Default'}', "
                f"Status: '{self.status_message}', "
                f"MainSchedule: ({main_schedule_info}), MainMetrics: {self.main_metrics}, "
                f"NumAlternativeSolutions: {num_alternatives})")