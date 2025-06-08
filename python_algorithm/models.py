# models.py
from dataclasses import dataclass, field
from typing import List, Optional, Set, Tuple

Schedule = List['ScheduledClass']

@dataclass(frozen=True)
class TimeSlot:
    id: str
    day_of_week: str
    start_time: str
    end_time: str

    def __repr__(self):
        return (f"TimeSlot(id='{self.id}', day='{self.day_of_week}', "
                f"time='{self.start_time}-{self.end_time}')")

@dataclass(frozen=True)
class Classroom:
    id: int
    room_code: str
    capacity: int
    type: str = 'Theory'

    def __repr__(self):
        return (f"Classroom(id={self.id}, room_code='{self.room_code}', "
                f"capacity={self.capacity}, type='{self.type}')")

@dataclass
class Instructor:
    id: str
    name: str
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
    id: str
    name: str
    expected_students: int
    credits: Optional[int] = None
    required_periods_per_session: int = 1

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
    id: str
    name: Optional[str] = None
    enrolled_course_ids: Set[str] = field(default_factory=set)

    def __repr__(self):
        student_name_str = f", name='{self.name}'" if self.name else ""
        return (f"Student(id='{self.id}{student_name_str}', "
                f"enrolled_courses_count={len(self.enrolled_course_ids)})")

@dataclass
class ScheduledClass:
    id: int
    course_id: str
    semester_id: int
    num_students: int
    instructor_id: Optional[str] = None
    classroom_id: Optional[int] = None
    timeslot_id: Optional[str] = None

    def __repr__(self):
        return (f"ScheduledClass(id={self.id}, Course='{self.course_id}', NumStud={self.num_students}, "
                f"Sem={self.semester_id}, Instr='{self.instructor_id or 'Unassigned'}', "
                f"Room='{self.classroom_id or 'Unassigned'}', Slot='{self.timeslot_id or 'Unassigned'}')")

@dataclass
class SchedulingMetrics:
    fitness_score: Optional[float] = None
    hard_constraints_violated: Optional[int] = 0
    soft_constraints_penalty: Optional[float] = 0.0
    student_clashes: Optional[int] = None
    instructor_overloads_count: Optional[int] = None
    instructor_underloads_count: Optional[int] = None
    instructor_teaching_hours_variance: Optional[float] = None
    instructor_short_breaks_violations: Optional[int] = None
    room_utilization_percentage: Optional[float] = None
    total_seated_students: Optional[int] = None
    total_classroom_capacity_in_used_slots: Optional[int] = None
    sum_wasted_room_capacity: Optional[int] = None
    number_of_rooms_used: Optional[int] = None
    custom_defined_accuracy: Optional[float] = None
    computation_time_seconds: Optional[float] = None

    def __repr__(self):
        metrics_list = [f"{key}={value}" for key, value in self.__dict__.items() if value is not None]
        if not metrics_list:
            return "SchedulingMetrics(No metrics calculated)"
        return f"SchedulingMetrics({', '.join(metrics_list)})"

@dataclass
class SchedulingResult:
    run_id: str
    main_schedule: Schedule
    main_metrics: SchedulingMetrics
    alternative_schedules: List[Tuple[Schedule, SchedulingMetrics]] = field(default_factory=list)
    scenario_name: Optional[str] = None
    status_message: str = "Completed"
    input_parameters: Optional[dict] = None

    def __repr__(self):
        num_alternatives = len(self.alternative_schedules)
        main_schedule_info = f"Size: {len(self.main_schedule)} classes" if self.main_schedule else "None"
        return (f"SchedulingResult(RunID: {self.run_id}, Scenario: '{self.scenario_name or 'Default'}', "
                f"Status: '{self.status_message}', "
                f"MainSchedule: ({main_schedule_info}), MainMetrics: {self.main_metrics}, "
                f"NumAlternativeSolutions: {num_alternatives})")