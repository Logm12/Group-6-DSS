# File: htdocs/DSS/python_algorithm/ga_module.py
import random
import copy
from collections import defaultdict
from datetime import datetime, date, time as dt_time, timedelta 
import traceback
from typing import List, Dict, Tuple, Any, Optional, Set
import os # Thêm os để dùng trong if __name__

# --- Import Utils ---
try:
    from utils import parse_time, check_time_overlap, DEFAULT_SETTINGS
    UTILS_IMPORTED = True
except ImportError:
    UTILS_IMPORTED = False
    # Placeholder functions and settings if utils.py is not found
    def parse_time(t_str: Any) -> Optional[dt_time]:
        if isinstance(t_str, dt_time): return t_str
        if isinstance(t_str, timedelta): return (datetime.min + t_str).time()
        if not isinstance(t_str, str): return None
        try: return datetime.strptime(t_str, '%H:%M:%S').time()
        except ValueError:
            try: return datetime.strptime(t_str, '%H:%M').time()
            except ValueError: return None

    def check_time_overlap(s1_str: Optional[str], e1_str: Optional[str], 
                           s2_str: Optional[str], e2_str: Optional[str]) -> bool:
        s1, e1, s2, e2 = parse_time(s1_str), parse_time(e1_str), parse_time(s2_str), parse_time(e2_str)
        if not all(isinstance(t, dt_time) for t in [s1, e1, s2, e2]): return False
        if s1 is None or e1 is None or s2 is None or e2 is None: return False
        if s1 >= e1 or s2 >= e2: return False
        return max(s1, s2) < min(e1, e2)

    DEFAULT_SETTINGS = {
        "penalty_student_clash_base": 1000, "penalty_lecturer_overload_base": 50,
        "penalty_lecturer_underload_base": 30, "penalty_lecturer_insufficient_break_base": 40,
        "penalty_classroom_underutilized_base": 10, "penalty_classroom_slightly_empty_base": 2,
        "lecturer_min_teaching_periods_per_semester": 4, 
        "lecturer_max_teaching_periods_per_semester": 12, 
        "lecturer_min_break_minutes": 10, "target_classroom_fill_ratio_min": 0.5,
        "classroom_slightly_empty_multiplier_base": 10.0, 
    }


class GeneticAlgorithmScheduler:
    def __init__(self,
                 processed_data: Dict[str, Any],
                 initial_population_from_cp: List[List[Dict[str, Any]]], 
                 population_size: int = 50,
                 generations: int = 100,
                 crossover_rate: float = 0.8,
                 mutation_rate: float = 0.2, # Tăng mutation rate một chút
                 tournament_size: int = 5,
                 allow_hard_constraint_violations_in_ga: bool = False,
                 progress_logger: Optional[callable] = None):

        self.data = processed_data
        self.progress_logger = progress_logger
        self._log_ga("Initializing GeneticAlgorithmScheduler...")

        if initial_population_from_cp and isinstance(initial_population_from_cp, list):
            # Kiểm tra xem initial_population_from_cp có phải là một list các event (một lịch trình)
            # hay là một list của các list các event (list các lịch trình)
            if len(initial_population_from_cp) > 0 and isinstance(initial_population_from_cp[0], dict) and \
               all(k in initial_population_from_cp[0] for k in ["course_id_str", "lecturer_id_db"]): 
                self.initial_population_from_cp = [initial_population_from_cp] 
                self._log_ga("GA_INFO: initial_population_from_cp seemed like a single schedule; wrapped it in a list.")
            else: 
                self.initial_population_from_cp = initial_population_from_cp
        else:
            self.initial_population_from_cp = []
            self._log_ga("GA_WARN: No initial population provided from CP or format was unexpected. GA might struggle.")

        self.population_size = population_size
        self.generations = generations
        self.crossover_rate = crossover_rate
        self.mutation_rate = mutation_rate
        self.tournament_size = tournament_size
        self.population: List[List[Dict[str, Any]]] = []
        
        self.allow_hard_constraint_violations_in_ga = allow_hard_constraint_violations_in_ga
        self.penalty_hard_constraint_violation = 100000.0 

        effective_settings = self.data.get("settings", DEFAULT_SETTINGS)
        self.penalty_student_clash = float(effective_settings.get("penalty_student_clash", DEFAULT_SETTINGS.get("penalty_student_clash_base", 1000)))
        self.penalty_lecturer_overload = float(effective_settings.get("penalty_lecturer_overload", DEFAULT_SETTINGS.get("penalty_lecturer_overload_base", 50)))
        self.penalty_lecturer_underload = float(effective_settings.get("penalty_lecturer_underload", DEFAULT_SETTINGS.get("penalty_lecturer_underload_base", 30)))
        self.penalty_lecturer_insufficient_break = float(effective_settings.get("penalty_lecturer_insufficient_break", DEFAULT_SETTINGS.get("penalty_lecturer_insufficient_break_base", 40)))
        self.penalty_classroom_underutilized = float(effective_settings.get("penalty_classroom_underutilized", DEFAULT_SETTINGS.get("penalty_classroom_underutilized_base", 10)))
        self.penalty_classroom_slightly_empty = float(effective_settings.get("penalty_classroom_slightly_empty", DEFAULT_SETTINGS.get("penalty_classroom_slightly_empty_base", 2)))
        self.classroom_slightly_empty_multiplier = float(effective_settings.get("classroom_slightly_empty_multiplier", DEFAULT_SETTINGS.get("classroom_slightly_empty_multiplier_base", 10.0)))
        self.lecturer_min_periods = int(effective_settings.get("lecturer_target_teaching_periods_per_week_min", DEFAULT_SETTINGS.get("lecturer_min_teaching_periods_per_semester", 4)))
        self.lecturer_max_periods = int(effective_settings.get("lecturer_target_teaching_periods_per_week_max", DEFAULT_SETTINGS.get("lecturer_max_teaching_periods_per_semester", 12)))
        self.lecturer_min_break_minutes = int(effective_settings.get("lecturer_min_break_minutes", DEFAULT_SETTINGS.get("lecturer_min_break_minutes", 10)))
        self.target_classroom_fill_ratio_min = float(effective_settings.get("target_classroom_fill_ratio_min", DEFAULT_SETTINGS.get("target_classroom_fill_ratio_min", 0.5)))
        
        self.mappings = self.data.get("mappings", {})
        
        self.lecturer_db_pk_to_mapped_int: Dict[int, int] = {}
        for mapped_int_id_str, lect_data_item in self.data.get("lecturers", {}).items():
            db_pk = lect_data_item.get("original_db_pk_int") 
            if db_pk is not None: self.lecturer_db_pk_to_mapped_int[db_pk] = int(mapped_int_id_str)
        
        self.timeslot_db_pk_to_mapped_int: Dict[int, int] = {}
        for mapped_int_id_str, ts_data_item in self.data.get("timeslots", {}).items():
            db_pk = ts_data_item.get("original_db_pk_int") 
            if db_pk is not None: self.timeslot_db_pk_to_mapped_int[db_pk] = int(mapped_int_id_str)
        
        self.classroom_db_pk_to_mapped_int: Dict[int, int] = {}
        for db_pk, mapped_int_id in self.mappings.get("classroom_pk_to_int_map", {}).items():
            self.classroom_db_pk_to_mapped_int[db_pk] = int(mapped_int_id)

        self.student_enrollments_by_course_id = self.data.get("student_enrollments_by_course_id", defaultdict(set))
        self.courses_enrolled_by_student_id = self.data.get("courses_enrolled_by_student_id", defaultdict(set))
        
        # QUAN TRỌNG: self.courses_catalog là nguồn thông tin chính về các môn học cho GA
        self.courses_catalog: Dict[str, Dict[str, Any]] = self.data.get("courses_catalog_map", {})
        self._log_ga(f"GA courses_catalog_map initialized. Number of entries: {len(self.courses_catalog)}")
        if self.courses_catalog and len(self.courses_catalog) < 10: 
            for cid_debug, cdata_debug in list(self.courses_catalog.items())[:3]:
                self._log_ga(f"  Sample catalog entry: {cid_debug} -> Name: {cdata_debug.get('name')}, ExpectedStud: {cdata_debug.get('expected_students')}")
        
        # Kiểm tra các CourseID gây lỗi từ log trước đó
        problematic_ids_from_log = ['INS328003', 'INE105102', 'INS327102', 'INS325101', 'PEC100802', 'INS303002'] 
        found_count = 0
        for pid in problematic_ids_from_log:
            if pid in self.courses_catalog:
                self._log_ga(f"  DEBUG CHECK: Problematic ID '{pid}' IS PRESENT in self.courses_catalog (Name: {self.courses_catalog[pid].get('name')}).")
                found_count +=1
            else:
                self._log_ga(f"  DEBUG CHECK: Problematic ID '{pid}' IS MISSING from self.courses_catalog.")
        if found_count == len(problematic_ids_from_log) and problematic_ids_from_log:
             self._log_ga(f"  DEBUG CHECK: All previously problematic IDs checked ARE NOW PRESENT in self.courses_catalog.")
        elif problematic_ids_from_log:
             self._log_ga(f"  DEBUG CHECK: SOME previously problematic IDs ARE STILL MISSING from self.courses_catalog.")


        self._log_ga(f"GA Initialized. PopSize={self.population_size}, Gens={self.generations}, AllowHCViolations={self.allow_hard_constraint_violations_in_ga}")
        self._log_ga(f"Settings: StudentClashPenalty={self.penalty_student_clash}, LecturerOverloadPenalty={self.penalty_lecturer_overload}, LecturerMinPeriods={self.lecturer_min_periods}")

    def _log_ga(self, message: str):
        prefix = "GA"
        if self.progress_logger: self.progress_logger(f"{prefix}: {message}")
        else: print(f"{prefix}_PRINT: {message}")

    def _get_mapped_lecturer_id_from_db_pk(self, lecturer_db_pk: Optional[int]) -> Optional[int]:
        if lecturer_db_pk is None: return None
        return self.lecturer_db_pk_to_mapped_int.get(lecturer_db_pk)

    def _get_mapped_timeslot_id_from_db_pk(self, timeslot_db_pk: Optional[int]) -> Optional[int]:
        if timeslot_db_pk is None: return None
        return self.timeslot_db_pk_to_mapped_int.get(timeslot_db_pk)

    def _get_mapped_classroom_id_from_db_pk(self, classroom_db_pk: Optional[int]) -> Optional[int]:
        if classroom_db_pk is None: return None
        return self.classroom_db_pk_to_mapped_int.get(classroom_db_pk)

    def _initialize_population(self) -> bool:
        self._log_ga("Initializing population...")
        self.population = [] 
        valid_initial_schedules: List[List[Dict[str, Any]]] = []

        if self.initial_population_from_cp:
            self._log_ga(f"Processing {len(self.initial_population_from_cp)} schedule(s) from CP input.")
            for i, cp_schedule_candidate in enumerate(self.initial_population_from_cp):
                if cp_schedule_candidate and isinstance(cp_schedule_candidate, list) and all(isinstance(e, dict) for e in cp_schedule_candidate):
                    is_valid_for_ga = self.allow_hard_constraint_violations_in_ga or self._is_schedule_hard_valid(cp_schedule_candidate)
                    if is_valid_for_ga:
                        valid_initial_schedules.append(copy.deepcopy(cp_schedule_candidate))
                    else: self._log_ga(f"CP schedule candidate #{i+1} FAILED hard validation for GA and was DISCARDED.")
                else: self._log_ga(f"CP schedule candidate #{i+1} is not in expected format. Discarded.")
        
        if not valid_initial_schedules:
            self._log_ga("CRITICAL: No valid initial schedules from CP to form GA population. GA cannot proceed effectively.")
            return False

        self.population.extend(valid_initial_schedules)
        if 0 < len(self.population) < self.population_size:
            self._log_ga(f"Cloning {len(self.population)} valid schedule(s) to reach population size {self.population_size}.")
            num_to_clone = self.population_size - len(self.population)
            for i in range(num_to_clone): self.population.append(copy.deepcopy(self.population[i % len(valid_initial_schedules)]))
        elif len(self.population) > self.population_size:
            self._log_ga(f"Input provided {len(self.population)} valid schedules, > pop_size ({self.population_size}). Taking first {self.population_size}.")
            self.population = self.population[:self.population_size]
        
        if not self.population and self.population_size > 0:
             self._log_ga("CRITICAL ERROR: Population is empty after initialization. GA cannot proceed."); return False
        self._log_ga(f"Successfully initialized population with {len(self.population)} individuals."); return True

    def _is_schedule_hard_valid(self, schedule: List[Dict[str, Any]]) -> bool:
        if not schedule: return True
        lecturer_slots = defaultdict(set); classroom_slots = defaultdict(set)
        for event_idx, event in enumerate(schedule):
            l_db_pk, r_db_pk, t_db_pk = event.get("lecturer_id_db"), event.get("classroom_id_db"), event.get("timeslot_id_db")
            course_id = str(event.get("course_id_str"))
            num_students = event.get("num_students", 0)

            if not all(val is not None for val in [l_db_pk, r_db_pk, t_db_pk, course_id]):
                self._log_ga(f"ValidationFail (Event {event_idx}): Missing core IDs. Data: {str(event)[:100]}"); return False
            if t_db_pk in lecturer_slots[l_db_pk]:
                self._log_ga(f"ValidationFail HC1 (Event {event_idx}): Lect {l_db_pk} conflict at TS {t_db_pk} for Course {course_id}"); return False 
            lecturer_slots[l_db_pk].add(t_db_pk)
            if t_db_pk in classroom_slots[r_db_pk]:
                self._log_ga(f"ValidationFail HC2 (Event {event_idx}): Room {r_db_pk} conflict at TS {t_db_pk} for Course {course_id}"); return False
            classroom_slots[r_db_pk].add(t_db_pk)
            
            map_l_id = self._get_mapped_lecturer_id_from_db_pk(l_db_pk)
            map_t_id = self._get_mapped_timeslot_id_from_db_pk(t_db_pk)
            if map_l_id is None or map_t_id is None:
                self._log_ga(f"ValidationFail (Event {event_idx}): Cannot map L_DB_ID {l_db_pk} or T_DB_ID {t_db_pk}"); return False 
            lect_proc = self.data.get("lecturers", {}).get(map_l_id)
            if not lect_proc: self._log_ga(f"ValidationFail (Event {event_idx}): No processed data for MappedLectID {map_l_id}"); return False
            if map_t_id in lect_proc.get("unavailable_slot_ids_mapped", []):
                self._log_ga(f"ValidationFail HC3 (Event {event_idx}): Lect {l_db_pk} unavailable at MappedTS {map_t_id}"); return False
            
            map_r_id = self._get_mapped_classroom_id_from_db_pk(r_db_pk)
            if map_r_id is None: self._log_ga(f"ValidationFail (Event {event_idx}): Cannot map R_DB_ID {r_db_pk}"); return False
            room_proc = self.data.get("classrooms", {}).get(map_r_id)
            if not room_proc: self._log_ga(f"ValidationFail (Event {event_idx}): No processed data for MappedRoomID {map_r_id}"); return False
            if num_students > room_proc.get("capacity", 0):
                self._log_ga(f"ValidationFail HC4 (Event {event_idx}): Course {course_id} ({num_students} studs) > Room {r_db_pk} cap ({room_proc.get('capacity',0)})"); return False
        return True

    def _calculate_fitness(self, schedule: List[Dict[str, Any]]) -> float:
        total_penalty = 0.0; _debug_penalties = defaultdict(float)
        if self.allow_hard_constraint_violations_in_ga and not self._is_schedule_hard_valid(schedule):
            total_penalty += self.penalty_hard_constraint_violation; _debug_penalties["HCV_Overall"] += self.penalty_hard_constraint_violation
            return total_penalty 

        student_slots = defaultdict(list)
        for event in schedule:
            course_id, t_db_pk = str(event.get("course_id_str")), event.get("timeslot_id_db")
            if not course_id or t_db_pk is None: continue
            map_ts_id = self._get_mapped_timeslot_id_from_db_pk(t_db_pk)
            if map_ts_id is None: continue
            ts_proc = self.data.get("timeslots", {}).get(map_ts_id)
            if not ts_proc: continue
            start_obj, end_obj, day_str = parse_time(ts_proc.get("start_time")), parse_time(ts_proc.get("end_time")), ts_proc.get("day_of_week")
            if not all([start_obj, end_obj, day_str]): continue
            
            students_in_course = self.student_enrollments_by_course_id.get(course_id, set())
            for std_id in students_in_course:
                for ex_day, ex_start, ex_end in student_slots[std_id]:
                    if ex_day == day_str and max(ex_start, start_obj) < min(ex_end, end_obj):
                        total_penalty += self.penalty_student_clash; _debug_penalties["SC1_StudentClash"] += self.penalty_student_clash
                student_slots[std_id].append((day_str, start_obj, end_obj))
        
        lect_periods, lect_schedules_breaks = defaultdict(int), defaultdict(list)
        for event in schedule:
            l_db_pk, c_id, t_db_pk = event.get("lecturer_id_db"), str(event.get("course_id_str")), event.get("timeslot_id_db")
            if not all(v is not None for v in [l_db_pk, c_id, t_db_pk]): continue
            
            # Lấy thông tin course từ self.courses_catalog (đã được chuẩn bị trong __init__)
            course_cat_info = self.courses_catalog.get(c_id) # c_id là course_id_str
            map_ts_id = self._get_mapped_timeslot_id_from_db_pk(t_db_pk)
            if not course_cat_info or map_ts_id is None: 
                # self._log_ga(f"FITNESS WARN: Course {c_id} not in catalog or TS {t_db_pk} not mapped. Event: {event.get('schedule_db_id')}")
                continue 
            
            ts_proc = self.data.get("timeslots", {}).get(map_ts_id)
            if not ts_proc: continue
            
            # required_periods_per_session đã có trong course_cat_info từ utils.py
            lect_periods[l_db_pk] += course_cat_info.get("required_periods_per_session", 1)
            start_obj, end_obj, day_str = parse_time(ts_proc.get("start_time")), parse_time(ts_proc.get("end_time")), ts_proc.get("day_of_week")
            if all([start_obj, end_obj, day_str]): lect_schedules_breaks[l_db_pk].append({"day":day_str, "start":start_obj, "end":end_obj})

        for l_db_pk, periods in lect_periods.items():
            if periods > self.lecturer_max_periods:
                pen = self.penalty_lecturer_overload * (periods - self.lecturer_max_periods)
                total_penalty += pen; _debug_penalties["SC2_LecturerOverload"] += pen
            if periods < self.lecturer_min_periods:
                pen = self.penalty_lecturer_underload * (self.lecturer_min_periods - periods)
                total_penalty += pen; _debug_penalties["SC2_LecturerUnderload"] += pen
        
        for l_db_pk, items in lect_schedules_breaks.items():
            items.sort(key=lambda x: (x["day"], x["start"]))
            for i in range(len(items) - 1):
                ev1, ev2 = items[i], items[i+1]
                if ev1["day"] == ev2["day"] and ev2["start"] >= ev1["end"]:
                    dt_e1_end, dt_e2_start = datetime.combine(date.min, ev1["end"]), datetime.combine(date.min, ev2["start"])
                    diff_min = (dt_e2_start - dt_e1_end).total_seconds() / 60.0
                    if 0 <= diff_min < self.lecturer_min_break_minutes:
                        total_penalty += self.penalty_lecturer_insufficient_break
                        _debug_penalties["SC3_LecturerInsufficientBreak"] += self.penalty_lecturer_insufficient_break
        
        for event in schedule:
            r_db_pk, students = event.get("classroom_id_db"), event.get("num_students", 0)
            if r_db_pk is None or students == 0: continue
            map_r_id = self._get_mapped_classroom_id_from_db_pk(r_db_pk)
            if map_r_id is None: continue
            room_proc = self.data.get("classrooms",{}).get(map_r_id)
            if not room_proc: continue
            capacity = room_proc.get("capacity",0)
            if capacity > 0:
                fill = students / capacity
                if fill < (self.target_classroom_fill_ratio_min * 0.5):
                    total_penalty += self.penalty_classroom_underutilized
                    _debug_penalties["SC4_RoomSevereUnder"] += self.penalty_classroom_underutilized
                elif fill < self.target_classroom_fill_ratio_min:
                    pen = self.penalty_classroom_slightly_empty * (self.target_classroom_fill_ratio_min - fill) * self.classroom_slightly_empty_multiplier
                    total_penalty += pen; _debug_penalties["SC4_RoomSlightEmpty"] += pen
        
        if self.progress_logger and random.random() < 0.05: # Log sample
            log_msg = f"Fitness (Total={total_penalty:.2f}): "
            for n, v in _debug_penalties.items():
                if v > 0: log_msg += f"{n}={v:.0f}|"
            if len(log_msg) > 30: self._log_ga(log_msg)
        return total_penalty

    def _selection(self, evaluated_population: List[Tuple[float, List[Dict[str, Any]]]]) -> List[List[Dict[str, Any]]]:
        selected_individuals: List[List[Dict[str,Any]]] = []
        if not evaluated_population: self._log_ga("Selection WARN: Eval pop empty."); return []
        actual_tournament_size = min(self.tournament_size, len(evaluated_population))
        if actual_tournament_size <= 0: self._log_ga(f"Selection WARN: Tourn size invalid ({actual_tournament_size})."); return [s for _,s in evaluated_population]
        for _ in range(len(evaluated_population)):
            tournament_indices = random.sample(range(len(evaluated_population)), actual_tournament_size)
            tournament_contenders = [evaluated_population[i] for i in tournament_indices]
            tournament_contenders.sort(key=lambda x: x[0])
            selected_individuals.append(copy.deepcopy(tournament_contenders[0][1]))
        return selected_individuals

    def _crossover(self, parent1_schedule: List[Dict[str, Any]], parent2_schedule: List[Dict[str, Any]]) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
        child1, child2 = copy.deepcopy(parent1_schedule), copy.deepcopy(parent2_schedule)
        if not all([parent1_schedule, parent2_schedule, len(parent1_schedule) == len(parent2_schedule),
                    random.random() < self.crossover_rate, len(parent1_schedule) > 1]):
            return child1, child2

        p1_map = {event['schedule_db_id']: event for event in parent1_schedule if 'schedule_db_id' in event}
        p2_map = {event['schedule_db_id']: event for event in parent2_schedule if 'schedule_db_id' in event}
        common_ids = sorted(list(set(p1_map.keys()) & set(p2_map.keys())))
        if len(common_ids) <= 1: return child1, child2
        
        point = random.randint(1, len(common_ids) - 1)
        temp_c1_map, temp_c2_map = copy.deepcopy(p1_map), copy.deepcopy(p2_map)
        for i, s_db_id in enumerate(common_ids):
            ev_p1, ev_p2 = p1_map[s_db_id], p2_map[s_db_id]
            if i >= point: 
                for key_to_swap in ['timeslot_id_db', 'classroom_id_db', 'timeslot_info_str', 'room_code']:
                    temp_c1_map[s_db_id][key_to_swap] = ev_p2.get(key_to_swap)
                    temp_c2_map[s_db_id][key_to_swap] = ev_p1.get(key_to_swap)
        
        child1 = [temp_c1_map.get(event['schedule_db_id'], event) for event in parent1_schedule if 'schedule_db_id' in event]
        child2 = [temp_c2_map.get(event['schedule_db_id'], event) for event in parent2_schedule if 'schedule_db_id' in event]

        if not self.allow_hard_constraint_violations_in_ga:
            if not self._is_schedule_hard_valid(child1): child1 = copy.deepcopy(parent1_schedule)
            if not self._is_schedule_hard_valid(child2): child2 = copy.deepcopy(parent2_schedule)
        return child1, child2

    def _mutate(self, schedule: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        mutated_schedule = copy.deepcopy(schedule)
        if not schedule or random.random() >= self.mutation_rate: return mutated_schedule
        
        idx_to_mutate = random.randrange(len(mutated_schedule))
        event_to_change = mutated_schedule[idx_to_mutate]
        original_event_backup = copy.deepcopy(event_to_change)

        course_id_str = str(event_to_change.get("course_id_str"))
        num_students_in_event = event_to_change.get("num_students", 0)
        event_db_id_log = event_to_change.get('schedule_db_id', 'UnknownDB_ID_Mut')

        # SỬA Ở ĐÂY: Tra cứu trong self.courses_catalog
        course_info_catalog = self.courses_catalog.get(course_id_str) 
        if not course_info_catalog:
            self._log_ga(f"Mutation WARN: Course '{course_id_str}' (Event DB_ID: {event_db_id_log}) NOT FOUND in self.courses_catalog during mutation. Skipping mutation for this event.")
            return mutated_schedule 

        mutation_type = random.choice(["classroom", "timeslot"]) 
        if mutation_type == "classroom":
            possible_rooms = []
            for r_map_id, r_data_proc in self.data.get("classrooms", {}).items():
                r_db_pk = r_data_proc.get("original_db_pk")
                if r_db_pk is not None and r_data_proc.get("capacity",0) >= num_students_in_event and \
                   r_db_pk != original_event_backup.get("classroom_id_db"):
                    possible_rooms.append(r_db_pk)
            if possible_rooms:
                new_r_db_pk = random.choice(possible_rooms)
                event_to_change["classroom_id_db"] = new_r_db_pk
                new_map_r_id = self._get_mapped_classroom_id_from_db_pk(new_r_db_pk)
                if new_map_r_id is not None:
                    event_to_change["room_code"] = self.data.get("classrooms",{}).get(new_map_r_id,{}).get("room_code", "N/A_MutR")
            # else: self._log_ga(f"Mutation INFO: No alt rooms for Event {event_db_id_log}, Course {course_id_str}")
        elif mutation_type == "timeslot":
            possible_ts = []
            # required_periods = course_info_catalog.get("required_periods_per_session", 1) # Đã giả định là 1
            for ts_map_id, ts_data_proc in self.data.get("timeslots", {}).items():
                ts_db_pk = ts_data_proc.get("original_db_pk_int")
                if ts_db_pk is not None and ts_db_pk != original_event_backup.get("timeslot_id_db"):
                     # if ts_data_proc.get("assumed_num_periods", 1) == required_periods: # Bỏ nếu timeslot không có num_periods
                    possible_ts.append(ts_db_pk)
            if possible_ts:
                new_ts_db_pk = random.choice(possible_ts)
                event_to_change["timeslot_id_db"] = new_ts_db_pk
                new_map_ts_id = self._get_mapped_timeslot_id_from_db_pk(new_ts_db_pk)
                if new_map_ts_id is not None:
                    ts_proc_new = self.data.get("timeslots",{}).get(new_map_ts_id,{})
                    event_to_change["timeslot_info_str"] = (f"{ts_proc_new.get('day_of_week','N/A')} ({ts_proc_new.get('start_time','N/A')}-{ts_proc_new.get('end_time','N/A')})")
            # else: self._log_ga(f"Mutation INFO: No alt timeslots for Event {event_db_id_log}, Course {course_id_str}")
        
        if not self.allow_hard_constraint_violations_in_ga and not self._is_schedule_hard_valid(mutated_schedule):
            mutated_schedule[idx_to_mutate] = original_event_backup 
        return mutated_schedule

    def run(self, progress_logger_override: Optional[callable] = None) -> Tuple[Optional[List[Dict[str, Any]]], float, Dict[str, Any]]:
        if progress_logger_override: self.progress_logger = progress_logger_override
        self._log_ga("Attempting to initialize GA population...")
        if not self._initialize_population():
            self._log_ga("ERROR: GA Population initialization failed. Aborting GA run."); return None, float('inf'), {}
        
        best_schedule_overall: Optional[List[Dict[str, Any]]] = None
        lowest_penalty_overall = float('inf')
        
        self._log_ga("Evaluating initial population for GA...")
        evaluated_population: List[Tuple[float, List[Dict[str, Any]]]] = []
        for ind_sched in self.population:
            if ind_sched and isinstance(ind_sched, list):
                penalty = self._calculate_fitness(ind_sched)
                evaluated_population.append((penalty, ind_sched))
            else: self._log_ga("GA WARN: Invalid schedule in initial population during eval.")

        if not evaluated_population: self._log_ga("ERROR: GA Initial population effectively empty. Aborting GA."); return None, float('inf'), {}
        evaluated_population.sort(key=lambda x: x[0])
        if evaluated_population: lowest_penalty_overall, best_schedule_overall = evaluated_population[0][0], copy.deepcopy(evaluated_population[0][1])
        
        self._log_ga(f"GA evolution started. PopSize: {len(self.population)}. Gens: {self.generations}.")
        if best_schedule_overall: self._log_ga(f"Initial best penalty: {lowest_penalty_overall:.2f} for sched with {len(best_schedule_overall)} events.")
        else: self._log_ga("GA WARN: Initial pop did not yield a best schedule.")

        for generation in range(self.generations):
            selected_parents = self._selection(evaluated_population)
            if not selected_parents:
                self._log_ga(f"GA WARN Gen {generation+1}: Selection produced no parents. Using prev eval pop."); selected_parents = [s for _,s in evaluated_population]
                if not selected_parents : self._log_ga(f"GA CRITICAL Gen {generation+1}: No individuals for parents. Stopping."); break
            next_gen_cand: List[List[Dict[str, Any]]] = []
            if best_schedule_overall and len(best_schedule_overall) > 0 : next_gen_cand.append(copy.deepcopy(best_schedule_overall))
            
            num_parents_avail = len(selected_parents); parent_idx_pool = 0
            while len(next_gen_cand) < self.population_size:
                if num_parents_avail == 0: break
                if num_parents_avail < 2 or (self.population_size - len(next_gen_cand) == 1):
                    p_idx = parent_idx_pool % num_parents_avail; parent_idx_pool += 1
                    parent_mut = copy.deepcopy(selected_parents[p_idx])
                    if parent_mut: next_gen_cand.append(self._mutate(parent_mut))
                else: 
                    p1_idx, p2_idx = parent_idx_pool % num_parents_avail, (parent_idx_pool+1) % num_parents_avail
                    parent_idx_pool += 2 # Consume two parents
                    p1, p2 = selected_parents[p1_idx], selected_parents[p2_idx]
                    c1, c2 = self._crossover(p1, p2)
                    if c1: next_gen_cand.append(self._mutate(c1))
                    if len(next_gen_cand) < self.population_size and c2: next_gen_cand.append(self._mutate(c2))
            
            if not next_gen_cand: self._log_ga(f"GA WARN Gen {generation+1}: No new candidates. Stopping."); break
            self.population = next_gen_cand[:self.population_size] 
            if not self.population: self._log_ga(f"GA ERROR Gen {generation+1}: Population empty. Stopping."); break

            evaluated_population = []
            for ind_s in self.population:
                if ind_s and isinstance(ind_s, list): evaluated_population.append((self._calculate_fitness(ind_s), ind_s))
            if not evaluated_population: self._log_ga(f"GA ERROR Gen {generation+1}: New pop eval resulted in no valid individuals. Stopping."); break
            
            evaluated_population.sort(key=lambda x: x[0])
            curr_best_pen_gen, curr_best_sched_gen = evaluated_population[0][0], evaluated_population[0][1]
            if curr_best_pen_gen < lowest_penalty_overall:
                lowest_penalty_overall, best_schedule_overall = curr_best_pen_gen, copy.deepcopy(curr_best_sched_gen)
            if (generation + 1) % max(1, self.generations // 10) == 0 or generation == self.generations - 1 or generation == 0:
                 self._log_ga(f"End Gen {generation+1}/{self.generations}: BestInGenPen={curr_best_pen_gen:.2f}, OverallBestPen={lowest_penalty_overall:.2f}")
        
        self._log_ga(f"GA Evolution Finished. Final best penalty: {lowest_penalty_overall:.2f}")
        final_metrics = self._calculate_detailed_metrics(best_schedule_overall, lowest_penalty_overall)
        if best_schedule_overall:
            self._log_ga(f"Num events in best GA schedule: {len(best_schedule_overall)}")
            if not self.allow_hard_constraint_violations_in_ga and not self._is_schedule_hard_valid(best_schedule_overall):
                self._log_ga("GA CRITICAL WARNING: Final best schedule from GA VIOLATES HARD CONSTRAINTS (and allow_hard_constraint_violations_in_ga=False).")
                final_metrics["hard_constraints_violated_in_final_schedule"] = True
        else: self._log_ga("GA WARN: No best schedule found by GA."); final_metrics["num_scheduled_events"] = 0
        return best_schedule_overall, lowest_penalty_overall, final_metrics

    def _calculate_detailed_metrics(self, schedule: Optional[List[Dict[str, Any]]], final_penalty: float) -> Dict[str, Any]:
        metrics = {"final_penalty_score": round(final_penalty, 2), "num_scheduled_events": 0,
                   "hard_constraints_violated_in_final_schedule": False, 
                   "soft_constraints_details": defaultdict(lambda: {"count": 0, "penalty_contribution": 0.0})}
        if not schedule: self._log_ga("DetailedMetrics: Schedule is None."); return metrics
        metrics["num_scheduled_events"] = len(schedule)
        if not self._is_schedule_hard_valid(schedule): metrics["hard_constraints_violated_in_final_schedule"] = True
        
        # Recalculate soft penalties for breakdown
        student_slots = defaultdict(list)
        for event in schedule:
            course_id, t_db_pk = str(event.get("course_id_str")), event.get("timeslot_id_db")
            if not course_id or t_db_pk is None: continue
            map_ts_id = self._get_mapped_timeslot_id_from_db_pk(t_db_pk)
            if map_ts_id is None: continue
            ts_proc = self.data.get("timeslots", {}).get(map_ts_id)
            if not ts_proc: continue
            start_obj, end_obj, day_str = parse_time(ts_proc.get("start_time")), parse_time(ts_proc.get("end_time")), ts_proc.get("day_of_week")
            if not all([start_obj, end_obj, day_str]): continue
            students_in_course = self.student_enrollments_by_course_id.get(course_id, set())
            for std_id in students_in_course:
                for ex_day, ex_start, ex_end in student_slots[std_id]:
                    if ex_day == day_str and max(ex_start, start_obj) < min(ex_end, end_obj):
                        metrics["soft_constraints_details"]["student_clash"]["count"] += 1
                        metrics["soft_constraints_details"]["student_clash"]["penalty_contribution"] += self.penalty_student_clash
                student_slots[std_id].append((day_str, start_obj, end_obj))

        lect_periods, lect_schedules_breaks = defaultdict(int), defaultdict(list)
        for event in schedule:
            l_db_pk, c_id, t_db_pk = event.get("lecturer_id_db"), str(event.get("course_id_str")), event.get("timeslot_id_db")
            if not all(v is not None for v in [l_db_pk, c_id, t_db_pk]): continue
            course_cat_info = self.courses_catalog.get(c_id) # Tra cứu từ self.courses_catalog
            map_ts_id = self._get_mapped_timeslot_id_from_db_pk(t_db_pk)
            if not course_cat_info or map_ts_id is None: continue
            ts_proc = self.data.get("timeslots", {}).get(map_ts_id)
            if not ts_proc: continue
            lect_periods[l_db_pk] += course_cat_info.get("required_periods_per_session", 1)
            start_obj, end_obj, day_str = parse_time(ts_proc.get("start_time")), parse_time(ts_proc.get("end_time")), ts_proc.get("day_of_week")
            if all([start_obj, end_obj, day_str]): lect_schedules_breaks[l_db_pk].append({"day":day_str, "start":start_obj, "end":end_obj})

        for l_db_pk, periods in lect_periods.items():
            if periods > self.lecturer_max_periods:
                over = periods - self.lecturer_max_periods
                metrics["soft_constraints_details"]["lecturer_overload"]["count"] += over
                metrics["soft_constraints_details"]["lecturer_overload"]["penalty_contribution"] += self.penalty_lecturer_overload * over
            if periods < self.lecturer_min_periods:
                under = self.lecturer_min_periods - periods
                metrics["soft_constraints_details"]["lecturer_underload"]["count"] += under
                metrics["soft_constraints_details"]["lecturer_underload"]["penalty_contribution"] += self.penalty_lecturer_underload * under
        
        for l_db_pk, items in lect_schedules_breaks.items():
            items.sort(key=lambda x: (x["day"], x["start"]))
            for i in range(len(items) - 1):
                ev1, ev2 = items[i], items[i+1]
                if ev1["day"] == ev2["day"] and ev2["start"] >= ev1["end"]:
                    dt_e1_end, dt_e2_start = datetime.combine(date.min, ev1["end"]), datetime.combine(date.min, ev2["start"])
                    diff_min = (dt_e2_start - dt_e1_end).total_seconds() / 60.0
                    if 0 <= diff_min < self.lecturer_min_break_minutes:
                        metrics["soft_constraints_details"]["lecturer_insufficient_break"]["count"] += 1
                        metrics["soft_constraints_details"]["lecturer_insufficient_break"]["penalty_contribution"] += self.penalty_lecturer_insufficient_break
        
        for event in schedule:
            r_db_pk, students = event.get("classroom_id_db"), event.get("num_students", 0)
            if r_db_pk is None or students == 0: continue
            map_r_id = self._get_mapped_classroom_id_from_db_pk(r_db_pk)
            if map_r_id is None: continue
            room_proc = self.data.get("classrooms",{}).get(map_r_id)
            if not room_proc: continue
            capacity = room_proc.get("capacity",0)
            if capacity > 0:
                fill = students / capacity
                if fill < (self.target_classroom_fill_ratio_min * 0.5):
                    metrics["soft_constraints_details"]["classroom_severely_underutilized"]["count"] += 1
                    metrics["soft_constraints_details"]["classroom_severely_underutilized"]["penalty_contribution"] += self.penalty_classroom_underutilized
                elif fill < self.target_classroom_fill_ratio_min:
                    pen_val = self.penalty_classroom_slightly_empty * (self.target_classroom_fill_ratio_min - fill) * self.classroom_slightly_empty_multiplier
                    metrics["soft_constraints_details"]["classroom_slightly_empty"]["count"] += 1
                    metrics["soft_constraints_details"]["classroom_slightly_empty"]["penalty_contribution"] += pen_val
        
        metrics["soft_constraints_details"] = dict(metrics["soft_constraints_details"])
        self._log_ga(f"Detailed Metrics Calculated: {metrics}")
        return metrics


if __name__ == "__main__":
    current_script_dir = os.path.dirname(os.path.abspath(__file__))
    print("GA_MODULE TEST: Attempting to import necessary modules...")
    try:
        from data_loader import load_all_data
        from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json
        from cp_module import CourseSchedulingCPSAT
        print("GA_MODULE TEST: Successfully imported data_loader, utils, and cp_module.")
    except ImportError as e_import:
        print(f"GA_MODULE TEST ERROR: Failed to import required modules: {e_import}\n{traceback.format_exc()}"); exit(1)

    output_dir_ga_test_run = os.path.join(current_script_dir, "output_data_ga_test_run")
    if not os.path.exists(output_dir_ga_test_run): os.makedirs(output_dir_ga_test_run)

    test_semester_id, ga_pop_size, ga_num_generations, cp_solve_time_limit, ga_allow_hc_violations = 1, 30, 50, 15.0, False
    print(f"\n--- GA MODULE INTEGRATION TEST ---")
    print(f"Target SemesterID: {test_semester_id}, GA Pop: {ga_pop_size}, GA Gens: {ga_num_generations}, CP Time: {cp_solve_time_limit}s")

    print("\nStep 1: Loading data...")
    db_data = load_all_data(semester_id_to_load=test_semester_id)
    if not any(db_data): print("GA_MODULE TEST ERROR: load_all_data returned no data."); exit(1)
    # Sử dụng tên biến từ utils.py cho nhất quán khi gọi preprocess_data_for_cp_and_ga
    input_scheduled_classes_list, input_instructors_list, input_classrooms_list, \
    input_timeslots_list, input_students_list, input_courses_catalog_map = db_data
    if not (input_instructors_list and input_classrooms_list and input_timeslots_list and input_courses_catalog_map):
        print(f"GA_MODULE TEST ERROR: Essential catalog data missing after load."); exit(1)
    print(f"Data Loaded: {len(input_scheduled_classes_list)} SchedClassesIn, {len(input_instructors_list)} Instrs, ...")

    print("\nStep 2: Preprocessing data...")
    test_priority_settings = {"student_clash": "high", "lecturer_load_break": "medium", "classroom_util": "low"}
    processed_data = preprocess_data_for_cp_and_ga(
        input_scheduled_classes=input_scheduled_classes_list, # Đảm bảo tên khớp
        input_courses_catalog=input_courses_catalog_map,
        input_instructors=input_instructors_list,
        input_classrooms=input_classrooms_list,
        input_timeslots=input_timeslots_list,
        input_students=input_students_list,
        semester_id_for_settings=test_semester_id,
        priority_settings=test_priority_settings
    )
    if not processed_data or (not processed_data.get("scheduled_items") and input_scheduled_classes_list) : 
        print("GA_MODULE TEST ERROR: Preprocessed data invalid or 'scheduled_items' missing."); exit(1)
    num_items_for_solver = len(processed_data.get("scheduled_items", {}))
    print(f"Preprocessing Complete. Num items for solver: {num_items_for_solver}.")
    if num_items_for_solver == 0: print("GA_MODULE TEST INFO: 0 items to schedule.")

    print("\nStep 3: Running CP-SAT for initial GA population seed...")
    initial_schedules_for_ga_pop: List[List[Dict[str, Any]]] = []
    if num_items_for_solver > 0:
        try:
            def cp_ga_logger(msg): print(f"CP_FOR_GA_LOG: {msg}")
            cp_solver_for_ga = CourseSchedulingCPSAT(processed_data, progress_logger=cp_ga_logger)
            cp_schedule_output, cp_metrics_output = cp_solver_for_ga.solve(time_limit_seconds=cp_solve_time_limit)
            print("CP Solver Metrics (for GA init):")
            if cp_metrics_output:
                for k, v_met in cp_metrics_output.items(): print(f"  {k}: {v_met}")
            if cp_schedule_output and isinstance(cp_schedule_output, list) and len(cp_schedule_output) > 0:
                print(f"CP-SAT found initial solution with {len(cp_schedule_output)} events.")
                initial_schedules_for_ga_pop.append(cp_schedule_output)
                cp_out_path = os.path.join(output_dir_ga_test_run, f"ga_test_cp_seed_sem_{test_semester_id}.json")
                save_output_data_to_json({"status": "cp_seed_for_ga_test", "cp_schedule_seed": cp_schedule_output, "cp_metrics": cp_metrics_output or {}}, cp_out_path)
                print(f"  CP seed schedule saved to: {cp_out_path}")
            else: print("GA_MODULE TEST WARNING: CP-SAT did not find solution for GA seed.")
        except Exception as e_cp_run: print(f"GA_MODULE TEST ERROR during CP-SAT for GA seed: {e_cp_run}\n{traceback.format_exc()}")
    else: print("GA_MODULE TEST INFO: No items to schedule, skipping CP run for GA seed.")

    print("\nStep 4: Initializing and Running Genetic Algorithm...")
    if not initial_schedules_for_ga_pop and num_items_for_solver > 0 :
        print("GA_MODULE TEST WARNING: No initial schedules from CP. GA might be ineffective.")
    
    if num_items_for_solver == 0 and not initial_schedules_for_ga_pop:
        print("GA_MODULE TEST INFO: No items to schedule and no initial seed. GA will not run.")
    else:
        try:
            def ga_run_logger(msg): print(f"GA_RUN_LOG: {msg}")
            ga_instance = GeneticAlgorithmScheduler(
                processed_data=processed_data, initial_population_from_cp=initial_schedules_for_ga_pop,
                population_size=ga_pop_size, generations=ga_num_generations,
                allow_hard_constraint_violations_in_ga=ga_allow_hc_violations, progress_logger=ga_run_logger
            )
            final_best_ga_schedule, final_best_ga_penalty, final_ga_metrics = ga_instance.run()
            
            ga_final_output_path = os.path.join(output_dir_ga_test_run, f"ga_test_final_output_sem_{test_semester_id}.json")
            output_content_ga = {
                "test_run_info": {"module": "ga_module.py_integration_test", "semester_id_tested": test_semester_id},
                "ga_final_best_penalty": final_best_ga_penalty,
                "ga_final_detailed_metrics": final_ga_metrics,
                "ga_final_best_schedule": final_best_ga_schedule if final_best_ga_schedule else []
            }
            if final_best_ga_schedule:
                print(f"\n--- GA Run Completed ---\nFinal Best Penalty: {final_best_ga_penalty:.2f}\nEvents: {len(final_best_ga_schedule)}")
                print("Final Detailed Metrics:")
                for k_met, v_val in final_ga_metrics.items():
                    if k_met == "soft_constraints_details":
                        print(f"  {k_met}:"); [print(f"    {sc_n}: Count={sc_d['count']}, Pen={sc_d['penalty_contribution']:.2f}") for sc_n,sc_d in v_val.items()]
                    else: print(f"  {k_met}: {v_val}")
            else: print("GA_MODULE TEST: GA did not produce a final best schedule.")
            save_output_data_to_json(output_content_ga, ga_final_output_path)
            print(f"GA run final output saved to: {ga_final_output_path}")
        except Exception as e_ga_run: print(f"GA_MODULE TEST ERROR during GA execution: {e_ga_run}\n{traceback.format_exc()}")
    print("\n--- GA MODULE INTEGRATION TEST FINISHED ---")