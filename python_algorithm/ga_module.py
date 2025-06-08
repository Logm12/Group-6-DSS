import random
import copy
from collections import defaultdict
from datetime import datetime, date, time as dt_time, timedelta
import traceback
from typing import List, Dict, Tuple, Any, Optional, Set
import os
import sys

try:
    from utils import parse_time, DEFAULT_SETTINGS
except ImportError:
    def parse_time(t_str: Any) -> Optional[dt_time]:
        if isinstance(t_str, dt_time): return t_str
        if isinstance(t_str, str):
            try: return datetime.strptime(t_str, '%H:%M:%S').time()
            except ValueError:
                try: return datetime.strptime(t_str, '%H:%M').time()
                except ValueError: return None
        return None
    DEFAULT_SETTINGS = {}

class GeneticAlgorithmScheduler:
    def _log_ga(self, message: str):
        prefix = "GA_SOLVER"
        if self.progress_logger:
            self.progress_logger(f"{prefix}: {message}")
        else:
            print(f"{prefix}_STDOUT: {message}")

    def __init__(self,
                 processed_data: Dict[str, Any],
                 initial_population_from_cp: List[List[Dict[str, Any]]],
                 population_size: int = 50,
                 generations: int = 100,
                 crossover_rate: float = 0.8,
                 mutation_rate: float = 0.2,
                 tournament_size: int = 5,
                 allow_hard_constraint_violations_in_ga: bool = False,
                 progress_logger: Optional[callable] = None,
                 run_type: str = "admin_optimize_semester",
                 student_specific_preferences: Optional[Dict[str, Any]] = None
                ):

        self.data = processed_data
        self.progress_logger = progress_logger
        self.run_type = run_type
        self.student_preferences = student_specific_preferences if student_specific_preferences is not None else {}

        self.initial_population_from_cp: List[List[Dict[str, Any]]] = []
        if initial_population_from_cp and isinstance(initial_population_from_cp, list):
            if initial_population_from_cp and isinstance(initial_population_from_cp[0], dict):
                self.initial_population_from_cp.append(copy.deepcopy(initial_population_from_cp))
            elif all(isinstance(sched, list) for sched in initial_population_from_cp):
                self.initial_population_from_cp = [copy.deepcopy(sched) for sched in initial_population_from_cp if sched]

        self.population_size = max(10, population_size)
        self.generations = generations
        self.crossover_rate = crossover_rate
        self.mutation_rate = mutation_rate
        self.tournament_size = max(2, tournament_size)
        self.population: List[List[Dict[str, Any]]] = []
        self.allow_hard_constraint_violations_in_ga = allow_hard_constraint_violations_in_ga
        
        effective_settings = self.data.get("settings", DEFAULT_SETTINGS)
        self.penalty_hard_constraint_violation = float(effective_settings.get("penalty_hard_constraint_base", 100000.0))
        self.penalty_student_clash = float(effective_settings.get("penalty_student_clash", 1000.0))
        self.penalty_student_preference_violation = float(effective_settings.get("penalty_student_preference_violation", 200.0))
        self.penalty_lecturer_overload = float(effective_settings.get("penalty_lecturer_overload", 50.0))
        self.penalty_lecturer_underload = float(effective_settings.get("penalty_lecturer_underload", 30.0))
        self.penalty_lecturer_insufficient_break = float(effective_settings.get("penalty_lecturer_insufficient_break", 40.0))
        self.lecturer_min_periods = int(effective_settings.get("lecturer_target_teaching_periods_per_week_min", 4))
        self.lecturer_max_periods = int(effective_settings.get("lecturer_target_teaching_periods_per_week_max", 12))
        self.lecturer_min_break_minutes = int(effective_settings.get("lecturer_min_break_minutes", 10))
        self.penalty_classroom_capacity_violation = float(effective_settings.get("penalty_classroom_capacity_violation", 10000.0))
        self.penalty_classroom_underutilized = float(effective_settings.get("penalty_classroom_underutilized", 10.0))
        self.penalty_classroom_slightly_empty = float(effective_settings.get("penalty_classroom_slightly_empty", 2.0))
        self.classroom_slightly_empty_multiplier = float(effective_settings.get("classroom_slightly_empty_multiplier_base", 10.0))
        self.target_classroom_fill_ratio_min = float(effective_settings.get("target_classroom_fill_ratio_min", 0.5))
        
        self.mappings = self.data.get("mappings", {})
        self.lecturers_data_mapped = self.data.get("lecturers", {})
        self.classrooms_data_mapped = self.data.get("classrooms", {})
        self.timeslots_data_mapped = self.data.get("timeslots", {})
        self.courses_catalog = self.data.get("courses_catalog_map", {})
        self.student_enrollments_by_course_id: Dict[str, Set[str]] = self.data.get("student_enrollments_by_course_id", defaultdict(set))
        self.course_potential_lecturers_map_mapped = self.data.get("course_potential_lecturers_map", {})

        self.target_student_id_for_run: Optional[str] = None
        if self.run_type == "student_schedule_request":
            self.target_student_id_for_run = self.data.get("settings", {}).get("student_id")

    def _get_lecturer_db_pk_from_mapped_id(self, mapped_lect_id: Optional[int]) -> Optional[int]:
        if mapped_lect_id is None or mapped_lect_id not in self.lecturers_data_mapped: return None
        return self.lecturers_data_mapped[mapped_lect_id].get("original_db_pk_int")

    def _get_classroom_db_pk_from_mapped_id(self, mapped_room_id: Optional[int]) -> Optional[int]:
        if mapped_room_id is None or mapped_room_id not in self.classrooms_data_mapped: return None
        return self.classrooms_data_mapped[mapped_room_id].get("original_db_pk")

    def _get_timeslot_db_pk_from_mapped_id(self, mapped_ts_id: Optional[int]) -> Optional[int]:
        if mapped_ts_id is None or mapped_ts_id not in self.timeslots_data_mapped: return None
        return self.timeslots_data_mapped[mapped_ts_id].get("original_db_pk_int")

    def _get_lecturer_name_from_mapped_id(self, mapped_lect_id: Optional[int]) -> Optional[str]:
        if mapped_lect_id is None or mapped_lect_id not in self.lecturers_data_mapped: return None
        return self.lecturers_data_mapped[mapped_lect_id].get("name")

    def _get_room_code_from_mapped_id(self, mapped_room_id: Optional[int]) -> Optional[str]:
        if mapped_room_id is None or mapped_room_id not in self.classrooms_data_mapped: return None
        return self.classrooms_data_mapped[mapped_room_id].get("room_code")

    def _get_timeslot_info_str_from_mapped_id(self, mapped_ts_id: Optional[int]) -> str:
        if mapped_ts_id is None or mapped_ts_id not in self.timeslots_data_mapped: return "N/A_Time"
        ts_details = self.timeslots_data_mapped[mapped_ts_id]
        return f"{ts_details.get('day_of_week','')} ({ts_details.get('start_time','')}-{ts_details.get('end_time','')})"

    def _get_mapped_id_from_db_pk(self, db_pk: Optional[int], data_map: Dict[int, Dict[str, Any]], pk_field_name_in_data_map: str) -> Optional[int]:
        if db_pk is None: return None
        for mapped_id, details in data_map.items():
            if details.get(pk_field_name_in_data_map) == db_pk:
                return mapped_id
        return None

    def _get_mapped_lecturer_id_for_fitness(self, lecturer_db_pk: Optional[int]) -> Optional[int]:
        return self._get_mapped_id_from_db_pk(lecturer_db_pk, self.lecturers_data_mapped, "original_db_pk_int")

    def _get_mapped_classroom_id_for_fitness(self, classroom_db_pk: Optional[int]) -> Optional[int]:
        return self._get_mapped_id_from_db_pk(classroom_db_pk, self.classrooms_data_mapped, "original_db_pk")

    def _get_mapped_timeslot_id_for_fitness(self, timeslot_db_pk: Optional[int]) -> Optional[int]:
        return self._get_mapped_id_from_db_pk(timeslot_db_pk, self.timeslots_data_mapped, "original_db_pk_int")

    def _create_random_individual(self) -> Optional[List[Dict[str, Any]]]:
        if not self.data.get("scheduled_items"): return None
        
        random_schedule: List[Dict[str, Any]] = []
        items_for_this_individual = self.data.get("scheduled_items", {})

        all_mapped_lect_ids = list(self.lecturers_data_mapped.keys())
        all_mapped_room_ids = list(self.classrooms_data_mapped.keys())
        all_mapped_ts_ids   = list(self.timeslots_data_mapped.keys())

        if not all_mapped_room_ids or not all_mapped_ts_ids:
            return None
        if self.run_type == "student_schedule_request" and not all_mapped_lect_ids:
             return None

        for item_mapped_idx, item_details in items_for_this_individual.items():
            course_id_str_current_item = item_details["course_id_str"]
            num_students_current_item = item_details["num_students"]
            chosen_lecturer_mapped_id: Optional[int] = None

            if self.run_type == "student_schedule_request":
                potential_lects_for_course_mapped = self.course_potential_lecturers_map_mapped.get(course_id_str_current_item, [])
                if potential_lects_for_course_mapped:
                    chosen_lecturer_mapped_id = random.choice(potential_lects_for_course_mapped)
                elif all_mapped_lect_ids:
                    chosen_lecturer_mapped_id = random.choice(all_mapped_lect_ids)
            else:
                assigned_lect_mapped_id_from_item = item_details.get("assigned_instructor_mapped_int_id")
                if assigned_lect_mapped_id_from_item is not None:
                    chosen_lecturer_mapped_id = assigned_lect_mapped_id_from_item
                elif all_mapped_lect_ids:
                    chosen_lecturer_mapped_id = random.choice(all_mapped_lect_ids)

            suitable_classrooms_mapped_ids = [
                r_map_id for r_map_id in all_mapped_room_ids
                if self.classrooms_data_mapped.get(r_map_id, {}).get("capacity", 0) >= num_students_current_item
            ]
            chosen_classroom_mapped_id: Optional[int] = None
            if suitable_classrooms_mapped_ids:
                chosen_classroom_mapped_id = random.choice(suitable_classrooms_mapped_ids)
            elif all_mapped_room_ids:
                chosen_classroom_mapped_id = random.choice(all_mapped_room_ids)

            chosen_timeslot_mapped_id: Optional[int] = random.choice(all_mapped_ts_ids) if all_mapped_ts_ids else None
            
            event = {
                "schedule_db_id": item_details["original_id"],
                "course_id_str": course_id_str_current_item,
                "num_students": num_students_current_item,
                "course_name": item_details.get("course_name", self.courses_catalog.get(course_id_str_current_item,{}).get("name","N/A")),
                "lecturer_id_db": self._get_lecturer_db_pk_from_mapped_id(chosen_lecturer_mapped_id),
                "classroom_id_db": self._get_classroom_db_pk_from_mapped_id(chosen_classroom_mapped_id),
                "timeslot_id_db": self._get_timeslot_db_pk_from_mapped_id(chosen_timeslot_mapped_id),
                "lecturer_name": self._get_lecturer_name_from_mapped_id(chosen_lecturer_mapped_id),
                "room_code": self._get_room_code_from_mapped_id(chosen_classroom_mapped_id),
                "timeslot_info_str": self._get_timeslot_info_str_from_mapped_id(chosen_timeslot_mapped_id)
            }
            random_schedule.append(event)
        return random_schedule

    def _mutate(self, schedule: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        mutated_schedule = copy.deepcopy(schedule)
        if not schedule or random.random() >= self.mutation_rate or not len(mutated_schedule) > 0:
            return mutated_schedule

        idx_to_mutate = random.randrange(len(mutated_schedule))
        event_to_change = mutated_schedule[idx_to_mutate]
        original_event_backup = copy.deepcopy(event_to_change)

        course_id_of_event_to_change = event_to_change["course_id_str"]
        num_students_of_event = event_to_change["num_students"]

        all_mapped_lect_ids = list(self.lecturers_data_mapped.keys())
        all_mapped_room_ids = list(self.classrooms_data_mapped.keys())
        all_mapped_ts_ids   = list(self.timeslots_data_mapped.keys())

        mutation_gene_type_choices = ["timeslot", "classroom"]
        if self.run_type == "student_schedule_request":
            mutation_gene_type_choices.append("lecturer")
        
        chosen_gene_to_mutate = random.choice(mutation_gene_type_choices)

        if chosen_gene_to_mutate == "lecturer" and self.run_type == "student_schedule_request":
            potential_lects_mapped = self.course_potential_lecturers_map_mapped.get(course_id_of_event_to_change, [])
            mutation_pool_mapped = potential_lects_mapped if potential_lects_mapped else all_mapped_lect_ids
            
            current_lect_db_pk_mutate = event_to_change.get("lecturer_id_db")
            current_lect_mapped_id_mutate = self._get_mapped_lecturer_id_for_fitness(current_lect_db_pk_mutate)
            
            eligible_lects_mutate = [l_id for l_id in mutation_pool_mapped if l_id != current_lect_mapped_id_mutate]
            if not eligible_lects_mutate and mutation_pool_mapped: eligible_lects_mutate = mutation_pool_mapped

            if eligible_lects_mutate:
                new_lect_mapped_id_mutate = random.choice(eligible_lects_mutate)
                event_to_change["lecturer_id_db"] = self._get_lecturer_db_pk_from_mapped_id(new_lect_mapped_id_mutate)
                event_to_change["lecturer_name"] = self._get_lecturer_name_from_mapped_id(new_lect_mapped_id_mutate)

        elif chosen_gene_to_mutate == "classroom":
            suitable_rooms_mapped_mutate = [
                r_id for r_id in all_mapped_room_ids
                if self.classrooms_data_mapped.get(r_id, {}).get("capacity", 0) >= num_students_of_event
            ]
            current_room_db_pk_mutate = event_to_change.get("classroom_id_db")
            current_room_mapped_id_mutate = self._get_mapped_classroom_id_for_fitness(current_room_db_pk_mutate)
            eligible_rooms_mutate = [r_id for r_id in suitable_rooms_mapped_mutate if r_id != current_room_mapped_id_mutate]
            if not eligible_rooms_mutate and suitable_rooms_mapped_mutate: eligible_rooms_mutate = suitable_rooms_mapped_mutate

            if eligible_rooms_mutate:
                new_room_mapped_id_mutate = random.choice(eligible_rooms_mutate)
                event_to_change["classroom_id_db"] = self._get_classroom_db_pk_from_mapped_id(new_room_mapped_id_mutate)
                event_to_change["room_code"] = self._get_room_code_from_mapped_id(new_room_mapped_id_mutate)
        
        elif chosen_gene_to_mutate == "timeslot":
            current_ts_db_pk_mutate = event_to_change.get("timeslot_id_db")
            current_ts_mapped_id_mutate = self._get_mapped_timeslot_id_for_fitness(current_ts_db_pk_mutate)
            eligible_ts_mutate = [ts_id for ts_id in all_mapped_ts_ids if ts_id != current_ts_mapped_id_mutate]
            if not eligible_ts_mutate and all_mapped_ts_ids: eligible_ts_mutate = all_mapped_ts_ids

            if eligible_ts_mutate:
                new_ts_mapped_id_mutate = random.choice(eligible_ts_mutate)
                event_to_change["timeslot_id_db"] = self._get_timeslot_db_pk_from_mapped_id(new_ts_mapped_id_mutate)
                event_to_change["timeslot_info_str"] = self._get_timeslot_info_str_from_mapped_id(new_ts_mapped_id_mutate)

        if not self.allow_hard_constraint_violations_in_ga and not self._is_schedule_hard_valid(mutated_schedule):
            mutated_schedule[idx_to_mutate] = original_event_backup
        return mutated_schedule

    def _initialize_population(self) -> bool:
        self.population = []

        if self.initial_population_from_cp:
            for cp_seed_schedule in self.initial_population_from_cp:
                if cp_seed_schedule and isinstance(cp_seed_schedule, list) and len(cp_seed_schedule) > 0:
                    if self.allow_hard_constraint_violations_in_ga or self._is_schedule_hard_valid(cp_seed_schedule):
                        self.population.append(copy.deepcopy(cp_seed_schedule))
        
        num_seeds = len(self.population)

        needed_random = self.population_size - num_seeds
        if needed_random > 0:
            created_count = 0
            for _ in range(needed_random):
                individual = self._create_random_individual()
                if individual:
                    if self.allow_hard_constraint_violations_in_ga or self._is_schedule_hard_valid(individual):
                        self.population.append(individual)
                        created_count +=1
        
        if not self.population and self.population_size > 0:
            self._log_ga("GA CRITICAL: Population empty after initialization."); return False
        
        self.population = self.population[:self.population_size]
        return True

    def _is_schedule_hard_valid(self, schedule: List[Dict[str, Any]]) -> bool:
        if not schedule: return True

        lecturer_occupied_slots_db_pks = defaultdict(set)
        classroom_occupied_slots_db_pks = defaultdict(set)
        student_self_clash_ts_db_pks = set()

        for event in schedule:
            lect_db_pk = event.get("lecturer_id_db")
            room_db_pk = event.get("classroom_id_db")
            ts_db_pk = event.get("timeslot_id_db")
            num_stud = event.get("num_students", 0)

            if lect_db_pk is None or room_db_pk is None or ts_db_pk is None: return False

            if ts_db_pk in lecturer_occupied_slots_db_pks[lect_db_pk]: return False
            lecturer_occupied_slots_db_pks[lect_db_pk].add(ts_db_pk)

            if ts_db_pk in classroom_occupied_slots_db_pks[room_db_pk]: return False
            classroom_occupied_slots_db_pks[room_db_pk].add(ts_db_pk)

            mapped_lect_id = self._get_mapped_lecturer_id_for_fitness(lect_db_pk)
            mapped_ts_id = self._get_mapped_timeslot_id_for_fitness(ts_db_pk)
            if mapped_lect_id is not None and mapped_ts_id is not None:
                lect_details = self.lecturers_data_mapped.get(mapped_lect_id)
                if lect_details and mapped_ts_id in lect_details.get("unavailable_slot_ids_mapped", []):
                    return False
            
            mapped_room_id = self._get_mapped_classroom_id_for_fitness(room_db_pk)
            if mapped_room_id is not None:
                room_details = self.classrooms_data_mapped.get(mapped_room_id)
                if room_details and num_stud > 0 and num_stud > room_details.get("capacity", 0):
                    return False
            
            if self.run_type == "student_schedule_request":
                if ts_db_pk in student_self_clash_ts_db_pks: return False
                student_self_clash_ts_db_pks.add(ts_db_pk)
        return True

    def _calculate_fitness(self, schedule: List[Dict[str, Any]]) -> float:
        total_penalty = 0.0

        is_currently_hard_valid = self._is_schedule_hard_valid(schedule)
        if not self.allow_hard_constraint_violations_in_ga and not is_currently_hard_valid:
            return float('inf')
        elif self.allow_hard_constraint_violations_in_ga and not is_currently_hard_valid:
            total_penalty += self.penalty_hard_constraint_violation

        if self.run_type == "admin_optimize_semester" and self.student_enrollments_by_course_id:
            student_occupied_slots_calc = defaultdict(list)
            for event in schedule:
                course_id_str_sc1 = str(event.get("course_id_str"))
                timeslot_db_pk_sc1 = event.get("timeslot_id_db")
                if not course_id_str_sc1 or timeslot_db_pk_sc1 is None: continue

                mapped_ts_id_sc1 = self._get_mapped_timeslot_id_for_fitness(timeslot_db_pk_sc1)
                if mapped_ts_id_sc1 is None: continue
                
                timeslot_proc_data_sc1 = self.timeslots_data_mapped.get(mapped_ts_id_sc1)
                if not timeslot_proc_data_sc1: continue

                start_obj_sc1 = parse_time(timeslot_proc_data_sc1.get("start_time"))
                end_obj_sc1 = parse_time(timeslot_proc_data_sc1.get("end_time"))
                day_str_sc1 = timeslot_proc_data_sc1.get("day_of_week")

                if not all([start_obj_sc1, end_obj_sc1, day_str_sc1]): continue
                
                for student_id_sc1 in self.student_enrollments_by_course_id.get(course_id_str_sc1, set()):
                    for existing_day_sc1, existing_start_sc1, existing_end_sc1 in student_occupied_slots_calc[student_id_sc1]:
                        if existing_day_sc1 == day_str_sc1 and max(existing_start_sc1, start_obj_sc1) < min(existing_end_sc1, end_obj_sc1):
                            total_penalty += self.penalty_student_clash
                    student_occupied_slots_calc[student_id_sc1].append((day_str_sc1, start_obj_sc1, end_obj_sc1))
        
        lecturer_periods_taught = defaultdict(int)
        lecturer_event_times_for_breaks = defaultdict(list)

        for event_lwb in schedule:
            lect_db_pk_lwb = event_lwb.get("lecturer_id_db")
            course_id_str_lwb = str(event_lwb.get("course_id_str"))
            timeslot_db_pk_lwb = event_lwb.get("timeslot_id_db")

            if not all(v is not None for v in [lect_db_pk_lwb, course_id_str_lwb, timeslot_db_pk_lwb]): continue
            
            course_catalog_info_lwb = self.courses_catalog.get(course_id_str_lwb)
            mapped_ts_id_lwb = self._get_mapped_timeslot_id_for_fitness(timeslot_db_pk_lwb)
            if not course_catalog_info_lwb or mapped_ts_id_lwb is None: continue
            
            timeslot_proc_data_lwb = self.timeslots_data_mapped.get(mapped_ts_id_lwb)
            if not timeslot_proc_data_lwb: continue

            lecturer_periods_taught[lect_db_pk_lwb] += course_catalog_info_lwb.get("required_periods_per_session", 1)
            
            start_obj_lwb = parse_time(timeslot_proc_data_lwb.get("start_time"))
            end_obj_lwb = parse_time(timeslot_proc_data_lwb.get("end_time"))
            day_str_lwb = timeslot_proc_data_lwb.get("day_of_week")
            if all([start_obj_lwb, end_obj_lwb, day_str_lwb]):
                lecturer_event_times_for_breaks[lect_db_pk_lwb].append({"day":day_str_lwb, "start":start_obj_lwb, "end":end_obj_lwb, "course_id": course_id_str_lwb})


        for lect_pk_load, periods_taught_load in lecturer_periods_taught.items():
            if periods_taught_load > self.lecturer_max_periods:
                penalty = self.penalty_lecturer_overload * (periods_taught_load - self.lecturer_max_periods)
                total_penalty += penalty
            if periods_taught_load < self.lecturer_min_periods:
                penalty = self.penalty_lecturer_underload * (self.lecturer_min_periods - periods_taught_load)
                total_penalty += penalty
        
        for lect_pk_break, events_list_break in lecturer_event_times_for_breaks.items():
            events_list_break.sort(key=lambda x_br: (x_br["day"], x_br["start"]))
            for i_br in range(len(events_list_break) - 1):
                event1_br = events_list_break[i_br]
                event2_br = events_list_break[i_br+1]
                if event1_br["day"] == event2_br["day"] and event2_br["start"] >= event1_br["end"]:
                    dt_event1_end = datetime.combine(date.min, event1_br["end"])
                    dt_event2_start = datetime.combine(date.min, event2_br["start"])
                    break_duration_minutes = (dt_event2_start - dt_event1_end).total_seconds() / 60.0
                    if 0 <= break_duration_minutes < self.lecturer_min_break_minutes:
                        total_penalty += self.penalty_lecturer_insufficient_break
        
        for event_util in schedule:
            room_db_pk_util = event_util.get("classroom_id_db")
            students_in_event_util = event_util.get("num_students", 0)
            if room_db_pk_util is None or students_in_event_util <= 0: continue

            mapped_room_id_util = self._get_mapped_classroom_id_for_fitness(room_db_pk_util)
            if mapped_room_id_util is None: continue
            
            room_proc_data_util = self.classrooms_data_mapped.get(mapped_room_id_util)
            if not room_proc_data_util: continue
            
            room_capacity_util = room_proc_data_util.get("capacity", 0)
            if room_capacity_util > 0:
                fill_ratio_util = students_in_event_util / room_capacity_util
                if fill_ratio_util < (self.target_classroom_fill_ratio_min * 0.5): 
                    total_penalty += self.penalty_classroom_underutilized
                elif fill_ratio_util < self.target_classroom_fill_ratio_min:
                    penalty = self.penalty_classroom_slightly_empty * \
                                     (self.target_classroom_fill_ratio_min - fill_ratio_util) * \
                                     self.classroom_slightly_empty_multiplier
                    total_penalty += penalty
        
        if self.run_type == "student_schedule_request" and self.student_preferences:
            pref_time_of_day_stud = self.student_preferences.get("time_of_day")
            if pref_time_of_day_stud and pref_time_of_day_stud != "":
                violations_time_of_day_count = 0
                for event_sp_item in schedule:
                    timeslot_db_pk_sp = event_sp_item.get("timeslot_id_db")
                    if timeslot_db_pk_sp is None: continue
                    mapped_ts_id_sp = self._get_mapped_timeslot_id_for_fitness(timeslot_db_pk_sp)
                    if mapped_ts_id_sp is None: continue
                    
                    timeslot_details_sp = self.timeslots_data_mapped.get(mapped_ts_id_sp)
                    if not timeslot_details_sp: continue
                    start_time_obj_sp = parse_time(timeslot_details_sp.get("start_time"))
                    if not start_time_obj_sp: continue

                    violated_this_event = False
                    if pref_time_of_day_stud == "morning" and start_time_obj_sp >= dt_time(12, 0, 0): violated_this_event = True
                    elif pref_time_of_day_stud == "afternoon" and (start_time_obj_sp < dt_time(12, 0, 0) or start_time_obj_sp >= dt_time(17, 30, 0)): violated_this_event = True
                    elif pref_time_of_day_stud == "no_early_morning" and start_time_obj_sp < dt_time(9, 0, 0): violated_this_event = True
                    elif pref_time_of_day_stud == "no_late_evening" and start_time_obj_sp >= dt_time(17, 0, 0): violated_this_event = True
                    
                    if violated_this_event: violations_time_of_day_count += 1
                
                if violations_time_of_day_count > 0:
                    penalty = violations_time_of_day_count * self.penalty_student_preference_violation
                    total_penalty += penalty

            max_consecutive_pref_str_stud = self.student_preferences.get("max_consecutive_classes")
            if max_consecutive_pref_str_stud and str(max_consecutive_pref_str_stud).isdigit():
                max_allowed_consecutive = int(max_consecutive_pref_str_stud)
                if max_allowed_consecutive > 0:
                    events_by_day_stud_cons: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
                    for event_cons in schedule:
                        ts_db_pk_cons = event_cons.get("timeslot_id_db")
                        if ts_db_pk_cons is None: continue
                        mapped_ts_id_cons = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_cons)
                        if mapped_ts_id_cons is None: continue
                        
                        ts_info_cons = self.timeslots_data_mapped.get(mapped_ts_id_cons)
                        if ts_info_cons and ts_info_cons.get("day_of_week"):
                            start_obj = parse_time(ts_info_cons.get("start_time"))
                            end_obj = parse_time(ts_info_cons.get("end_time"))
                            if start_obj and end_obj:
                                events_by_day_stud_cons[ts_info_cons['day_of_week']].append({
                                    "start_obj": start_obj, "end_obj": end_obj })
                    
                    total_consecutive_violations = 0
                    for day_val, day_event_times_list in events_by_day_stud_cons.items():
                        if len(day_event_times_list) <= max_allowed_consecutive: continue
                        valid_day_event_times = [et for et in day_event_times_list if et["start_obj"] and et["end_obj"]]
                        if len(valid_day_event_times) <= max_allowed_consecutive: continue
                        valid_day_event_times.sort(key=lambda x_sort_cons: x_sort_cons['start_obj'])

                        current_consecutive_count = 0
                        for i_cons_loop in range(len(valid_day_event_times)):
                            if current_consecutive_count == 0: current_consecutive_count = 1
                            else:
                                prev_event_end_dt = datetime.combine(date.min, valid_day_event_times[i_cons_loop-1]['end_obj'])
                                current_event_start_dt = datetime.combine(date.min, valid_day_event_times[i_cons_loop]['start_obj'])
                                break_duration_minutes = (current_event_start_dt - prev_event_end_dt).total_seconds() / 60.0
                                if break_duration_minutes <= (self.data.get("settings", {}).get("break_duration_minutes", 5) + 10):
                                    current_consecutive_count +=1
                                else:
                                    if current_consecutive_count > max_allowed_consecutive:
                                        total_consecutive_violations += (current_consecutive_count - max_allowed_consecutive)
                                    current_consecutive_count = 1 
                        if current_consecutive_count > max_allowed_consecutive:
                            total_consecutive_violations += (current_consecutive_count - max_allowed_consecutive)
                    if total_consecutive_violations > 0:
                        penalty = total_consecutive_violations * self.penalty_student_preference_violation
                        total_penalty += penalty
            
            if self.student_preferences.get("friday_off", False):
                friday_class_count = 0
                for event_fri_stud in schedule:
                    ts_db_pk_fri = event_fri_stud.get("timeslot_id_db")
                    if ts_db_pk_fri is None: continue
                    mapped_ts_id_fri = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_fri)
                    if mapped_ts_id_fri and self.timeslots_data_mapped.get(mapped_ts_id_fri,{}).get("day_of_week","").lower() == "friday":
                        friday_class_count +=1
                if friday_class_count > 0:
                    penalty = self.penalty_student_preference_violation
                    total_penalty += penalty

            pref_compact_days_stud = self.student_preferences.get("compact_days", False)
            default_target_max_days = self.data.get("settings", {}).get("student_max_study_days_per_week", 3)
            student_target_max_days = int(self.student_preferences.get("target_max_days", default_target_max_days))

            if pref_compact_days_stud and student_target_max_days > 0:
                unique_days_with_classes_stud = set()
                for event_cd_stud in schedule:
                    ts_db_pk_cd = event_cd_stud.get("timeslot_id_db")
                    if ts_db_pk_cd is None: continue
                    mapped_ts_id_cd = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_cd)
                    if mapped_ts_id_cd:
                        day_of_week_cd = self.timeslots_data_mapped.get(mapped_ts_id_cd,{}).get("day_of_week")
                        if day_of_week_cd: unique_days_with_classes_stud.add(day_of_week_cd)
                
                if len(unique_days_with_classes_stud) > student_target_max_days:
                    days_over_target = len(unique_days_with_classes_stud) - student_target_max_days
                    penalty = days_over_target * self.penalty_student_preference_violation
                    total_penalty += penalty
        return total_penalty

    def _selection(self, evaluated_population: List[Tuple[float, List[Dict[str, Any]]]]) -> List[List[Dict[str, Any]]]:
        selected_individuals: List[List[Dict[str,Any]]] = []
        if not evaluated_population: return []
        
        actual_tournament_size = min(self.tournament_size, len(evaluated_population))
        if actual_tournament_size <= 0:
            return [schedule_ind for _, schedule_ind in evaluated_population]

        for _ in range(len(evaluated_population)):
            tournament_participants_indices = random.sample(range(len(evaluated_population)), actual_tournament_size)
            tournament_contenders_with_fitness = [evaluated_population[i] for i in tournament_participants_indices]
            tournament_contenders_with_fitness.sort(key=lambda x_tourn: x_tourn[0])
            selected_individuals.append(copy.deepcopy(tournament_contenders_with_fitness[0][1]))
        return selected_individuals

    def _crossover(self, parent1: List[Dict[str, Any]], parent2: List[Dict[str, Any]]) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]]]:
        child1, child2 = copy.deepcopy(parent1), copy.deepcopy(parent2)
        
        if not all([parent1, parent2, len(parent1) == len(parent2), random.random() < self.crossover_rate, len(parent1) > 1]):
            return child1, child2

        num_genes = len(parent1)
        crossover_point = random.randint(1, num_genes - 1)

        keys_to_swap_in_event = ['timeslot_id_db', 'classroom_id_db', 'timeslot_info_str', 'room_code']
        if self.run_type == "student_schedule_request":
            keys_to_swap_in_event.extend(['lecturer_id_db', 'lecturer_name'])

        for i in range(crossover_point, num_genes):
            for key_to_swap in keys_to_swap_in_event:
                temp_val = child1[i].get(key_to_swap)
                child1[i][key_to_swap] = child2[i].get(key_to_swap)
                child2[i][key_to_swap] = temp_val
        
        if not self.allow_hard_constraint_violations_in_ga:
            if not self._is_schedule_hard_valid(child1): child1 = copy.deepcopy(parent1)
            if not self._is_schedule_hard_valid(child2): child2 = copy.deepcopy(parent2)
        return child1, child2

    def run(self, progress_logger_override: Optional[callable] = None) -> Tuple[Optional[List[Dict[str, Any]]], float, Dict[str, Any]]:
        if progress_logger_override: self.progress_logger = progress_logger_override

        if not self._initialize_population():
            self._log_ga("GA ERROR: Population initialization failed. Aborting GA run.");
            return None, float('inf'), self._calculate_detailed_metrics(None, float('inf'))

        best_schedule_overall: Optional[List[Dict[str, Any]]] = None
        lowest_penalty_overall = float('inf')
        evaluated_population: List[Tuple[float, List[Dict[str, Any]]]] = []

        for individual_schedule in self.population:
            if individual_schedule:
                fitness_score = self._calculate_fitness(individual_schedule)
                evaluated_population.append((fitness_score, individual_schedule))

        if not evaluated_population:
            self._log_ga("GA ERROR: Initial population evaluation yielded no valid individuals. Aborting.");
            return None, float('inf'), self._calculate_detailed_metrics(None, float('inf'))

        evaluated_population.sort(key=lambda x_sort_eval: x_sort_eval[0])
        if evaluated_population:
             lowest_penalty_overall, best_schedule_overall = evaluated_population[0]
        else:
            self._log_ga("GA ERROR: Evaluated population became empty unexpectedly after sort.");
            return None, float('inf'), self._calculate_detailed_metrics(None, float('inf'))


        for gen_num in range(self.generations):
            selected_parents_for_next_gen = self._selection(evaluated_population)
            if not selected_parents_for_next_gen:
                selected_parents_for_next_gen = [sched for _, sched in evaluated_population]
                if not selected_parents_for_next_gen: self._log_ga(f"GA CRITICAL Gen {gen_num+1}: No parents."); break

            next_generation_candidates: List[List[Dict[str, Any]]] = []
            if best_schedule_overall:
                next_generation_candidates.append(copy.deepcopy(best_schedule_overall))

            while len(next_generation_candidates) < self.population_size:
                if not selected_parents_for_next_gen: break
                
                p1_idx = random.randrange(len(selected_parents_for_next_gen))
                p2_idx = random.randrange(len(selected_parents_for_next_gen))
                parent1_co = selected_parents_for_next_gen[p1_idx]
                parent2_co = selected_parents_for_next_gen[p2_idx]

                child1_co, child2_co = self._crossover(parent1_co, parent2_co)
                
                mutated_child1 = self._mutate(child1_co)
                if mutated_child1: next_generation_candidates.append(mutated_child1)
                
                if len(next_generation_candidates) < self.population_size:
                    mutated_child2 = self._mutate(child2_co)
                    if mutated_child2: next_generation_candidates.append(mutated_child2)
            
            if not next_generation_candidates: self._log_ga(f"GA WARN Gen {gen_num+1}: No new candidates."); break
            self.population = next_generation_candidates[:self.population_size]
            if not self.population: self._log_ga(f"GA ERROR Gen {gen_num+1}: Population empty."); break

            evaluated_population = []
            for ind_new_gen in self.population:
                if ind_new_gen: evaluated_population.append((self._calculate_fitness(ind_new_gen), ind_new_gen))
            if not evaluated_population: self._log_ga(f"GA ERROR Gen {gen_num+1}: Evaluation failed."); break
            
            evaluated_population.sort(key=lambda x_eval_sort_new: x_eval_sort_new[0])
            current_gen_best_penalty, current_gen_best_schedule = evaluated_population[0]
            if current_gen_best_penalty < lowest_penalty_overall:
                lowest_penalty_overall = current_gen_best_penalty
                best_schedule_overall = copy.deepcopy(current_gen_best_schedule)
            
            if (gen_num + 1) % max(1, self.generations // 10) == 0 or gen_num == self.generations - 1:
                 self._log_ga(f"GA Gen {gen_num+1}/{self.generations}: BestInGen={current_gen_best_penalty:.2f}, OverallBest={lowest_penalty_overall:.2f}")
        
        final_detailed_metrics = self._calculate_detailed_metrics(best_schedule_overall, lowest_penalty_overall)
        return best_schedule_overall, lowest_penalty_overall, final_detailed_metrics

    def _calculate_detailed_metrics(self, schedule: Optional[List[Dict[str, Any]]], final_penalty_score: float) -> Dict[str, Any]:
        metrics = {
            "final_penalty_score": round(final_penalty_score, 2),
            "num_scheduled_events": len(schedule) if schedule else 0,
            "hard_constraints_violated_in_final_schedule": False,
            "soft_constraints_details": defaultdict(lambda: {"count": 0, "penalty_contribution": 0.0})
        }

        if not schedule:
            metrics["soft_constraints_details"] = dict(metrics["soft_constraints_details"])
            return metrics

        is_final_hard_valid = self._is_schedule_hard_valid(schedule)
        metrics["hard_constraints_violated_in_final_schedule"] = not is_final_hard_valid
        
        if not is_final_hard_valid and self.allow_hard_constraint_violations_in_ga:
            metrics["soft_constraints_details"]["HCV_Overall_Applied"]["count"] = 1
            metrics["soft_constraints_details"]["HCV_Overall_Applied"]["penalty_contribution"] = round(self.penalty_hard_constraint_violation, 2)

        if self.run_type == "admin_optimize_semester" and self.student_enrollments_by_course_id:
            student_occupied_slots_metrics = defaultdict(list)
            clashes_found_sc1_metric = 0
            for event_m_sc1 in schedule:
                course_id_str_m_sc1 = str(event_m_sc1.get("course_id_str"))
                timeslot_db_pk_m_sc1 = event_m_sc1.get("timeslot_id_db")
                if not course_id_str_m_sc1 or timeslot_db_pk_m_sc1 is None: continue

                mapped_ts_id_m_sc1 = self._get_mapped_timeslot_id_for_fitness(timeslot_db_pk_m_sc1)
                if mapped_ts_id_m_sc1 is None: continue
                
                timeslot_proc_data_m_sc1 = self.timeslots_data_mapped.get(mapped_ts_id_m_sc1)
                if not timeslot_proc_data_m_sc1: continue

                start_obj_m_sc1 = parse_time(timeslot_proc_data_m_sc1.get("start_time"))
                end_obj_m_sc1 = parse_time(timeslot_proc_data_m_sc1.get("end_time"))
                day_str_m_sc1 = timeslot_proc_data_m_sc1.get("day_of_week")

                if not all([start_obj_m_sc1, end_obj_m_sc1, day_str_m_sc1]): continue
                
                for student_id_m_sc1 in self.student_enrollments_by_course_id.get(course_id_str_m_sc1, set()):
                    for existing_day_m, existing_start_m, existing_end_m in student_occupied_slots_metrics[student_id_m_sc1]:
                        if existing_day_m == day_str_m_sc1 and max(existing_start_m, start_obj_m_sc1) < min(existing_end_m, end_obj_m_sc1):
                            clashes_found_sc1_metric += 1
                    student_occupied_slots_metrics[student_id_m_sc1].append((day_str_m_sc1, start_obj_m_sc1, end_obj_m_sc1))
            if clashes_found_sc1_metric > 0:
                metrics["soft_constraints_details"]["student_clash_admin"]["count"] = clashes_found_sc1_metric
                metrics["soft_constraints_details"]["student_clash_admin"]["penalty_contribution"] = round(clashes_found_sc1_metric * self.penalty_student_clash, 2)
        
        lect_periods_metrics = defaultdict(int)
        lect_event_times_metrics = defaultdict(list)

        for event_m_lwb in schedule:
            lect_db_pk_m_lwb = event_m_lwb.get("lecturer_id_db")
            course_id_str_m_lwb = str(event_m_lwb.get("course_id_str"))
            timeslot_db_pk_m_lwb = event_m_lwb.get("timeslot_id_db")

            if not all(v is not None for v in [lect_db_pk_m_lwb, course_id_str_m_lwb, timeslot_db_pk_m_lwb]): continue
            
            course_catalog_info_m_lwb = self.courses_catalog.get(course_id_str_m_lwb)
            mapped_ts_id_m_lwb = self._get_mapped_timeslot_id_for_fitness(timeslot_db_pk_m_lwb)
            if not course_catalog_info_m_lwb or mapped_ts_id_m_lwb is None: continue
            
            timeslot_proc_data_m_lwb = self.timeslots_data_mapped.get(mapped_ts_id_m_lwb)
            if not timeslot_proc_data_m_lwb: continue

            lect_periods_metrics[lect_db_pk_m_lwb] += course_catalog_info_m_lwb.get("required_periods_per_session", 1)
            
            start_obj_m_lwb = parse_time(timeslot_proc_data_m_lwb.get("start_time"))
            end_obj_m_lwb = parse_time(timeslot_proc_data_m_lwb.get("end_time"))
            day_str_m_lwb = timeslot_proc_data_m_lwb.get("day_of_week")
            if all([start_obj_m_lwb, end_obj_m_lwb, day_str_m_lwb]):
                lect_event_times_metrics[lect_db_pk_m_lwb].append({"day":day_str_m_lwb, "start":start_obj_m_lwb, "end":end_obj_m_lwb})

        total_overload_violations = 0; current_overload_penalty = 0.0
        total_underload_violations = 0; current_underload_penalty = 0.0
        for lect_pk_m_load, periods_m_load in lect_periods_metrics.items():
            if periods_m_load > self.lecturer_max_periods:
                violations = periods_m_load - self.lecturer_max_periods
                total_overload_violations += violations
                current_overload_penalty += violations * self.penalty_lecturer_overload
            if periods_m_load < self.lecturer_min_periods:
                violations = self.lecturer_min_periods - periods_m_load
                total_underload_violations += violations
                current_underload_penalty += violations * self.penalty_lecturer_underload
        
        if total_overload_violations > 0:
            metrics["soft_constraints_details"]["lecturer_overload"]["count"] = total_overload_violations
            metrics["soft_constraints_details"]["lecturer_overload"]["penalty_contribution"] = round(current_overload_penalty, 2)
        if total_underload_violations > 0:
            metrics["soft_constraints_details"]["lecturer_underload"]["count"] = total_underload_violations
            metrics["soft_constraints_details"]["lecturer_underload"]["penalty_contribution"] = round(current_underload_penalty, 2)

        insufficient_break_violations_metric = 0
        for lect_pk_m_break, events_m_break_list in lect_event_times_metrics.items():
            events_m_break_list.sort(key=lambda x_sort_br_met: (x_sort_br_met["day"], x_sort_br_met["start"]))
            for i_m_br in range(len(events_m_break_list) - 1):
                ev1_m_br, ev2_m_br = events_m_break_list[i_m_br], events_m_break_list[i_m_br+1]
                if ev1_m_br["day"] == ev2_m_br["day"] and ev2_m_br["start"] >= ev1_m_br["end"]:
                    dt_e1_end_met_br = datetime.combine(date.min, ev1_m_br["end"])
                    dt_e2_start_met_br = datetime.combine(date.min, ev2_m_br["start"])
                    diff_min_met_br = (dt_e2_start_met_br - dt_e1_end_met_br).total_seconds() / 60.0
                    if 0 <= diff_min_met_br < self.lecturer_min_break_minutes:
                        insufficient_break_violations_metric += 1
        if insufficient_break_violations_metric > 0:
            metrics["soft_constraints_details"]["lecturer_insufficient_break"]["count"] = insufficient_break_violations_metric
            metrics["soft_constraints_details"]["lecturer_insufficient_break"]["penalty_contribution"] = round(insufficient_break_violations_metric * self.penalty_lecturer_insufficient_break, 2)
        
        severe_underutil_count_metric = 0; current_severe_underutil_penalty = 0.0
        slight_empty_count_metric = 0; current_slight_empty_penalty = 0.0
        for event_m_util in schedule:
            room_db_pk_m_util = event_m_util.get("classroom_id_db")
            students_m_util = event_m_util.get("num_students", 0)
            if room_db_pk_m_util is None or students_m_util <= 0: continue
            
            map_r_id_m_util = self._get_mapped_classroom_id_for_fitness(room_db_pk_m_util)
            if map_r_id_m_util is None: continue
            room_proc_m_util = self.classrooms_data_mapped.get(map_r_id_m_util)
            if not room_proc_m_util: continue
            
            capacity_m_util = room_proc_m_util.get("capacity",0)
            if capacity_m_util > 0:
                fill_m_util = students_m_util / capacity_m_util
                if fill_m_util < (self.target_classroom_fill_ratio_min * 0.5):
                    severe_underutil_count_metric +=1
                    current_severe_underutil_penalty += self.penalty_classroom_underutilized
                elif fill_m_util < self.target_classroom_fill_ratio_min:
                    slight_empty_count_metric +=1
                    current_slight_empty_penalty += self.penalty_classroom_slightly_empty * \
                                                   (self.target_classroom_fill_ratio_min - fill_m_util) * \
                                                   self.classroom_slightly_empty_multiplier
        if severe_underutil_count_metric > 0:
            metrics["soft_constraints_details"]["classroom_severely_underutilized"]["count"] = severe_underutil_count_metric
            metrics["soft_constraints_details"]["classroom_severely_underutilized"]["penalty_contribution"] = round(current_severe_underutil_penalty, 2)
        if slight_empty_count_metric > 0:
            metrics["soft_constraints_details"]["classroom_slightly_empty"]["count"] = slight_empty_count_metric
            metrics["soft_constraints_details"]["classroom_slightly_empty"]["penalty_contribution"] = round(current_slight_empty_penalty, 2)

        if self.run_type == "student_schedule_request" and self.student_preferences:
            pref_time_of_day_metric = self.student_preferences.get("time_of_day")
            if pref_time_of_day_metric and pref_time_of_day_metric != "":
                violations_tod_metric_count = 0
                for event_sp_m in schedule:
                    ts_db_pk_sp_m = event_sp_m.get("timeslot_id_db")
                    if ts_db_pk_sp_m is None: continue
                    mapped_ts_id_sp_m = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_sp_m)
                    if mapped_ts_id_sp_m is None: continue
                    ts_details_sp_m = self.timeslots_data_mapped.get(mapped_ts_id_sp_m)
                    if not ts_details_sp_m: continue
                    start_time_obj_sp_m = parse_time(ts_details_sp_m.get("start_time"))
                    if not start_time_obj_sp_m: continue
                    violated_m = False
                    if pref_time_of_day_metric == "morning" and start_time_obj_sp_m >= dt_time(12,0): violated_m = True
                    elif pref_time_of_day_metric == "afternoon" and (start_time_obj_sp_m < dt_time(12,0) or start_time_obj_sp_m >= dt_time(17,30)): violated_m = True
                    elif pref_time_of_day_metric == "no_early_morning" and start_time_obj_sp_m < dt_time(9,0): violated_m = True
                    elif pref_time_of_day_metric == "no_late_evening" and start_time_obj_sp_m >= dt_time(17,0): violated_m = True
                    if violated_m: violations_tod_metric_count += 1
                if violations_tod_metric_count > 0:
                    metrics["soft_constraints_details"]["student_pref_time_of_day"]["count"] = violations_tod_metric_count
                    metrics["soft_constraints_details"]["student_pref_time_of_day"]["penalty_contribution"] = round(violations_tod_metric_count * self.penalty_student_preference_violation, 2)
            
            max_consecutive_pref_metric_str = self.student_preferences.get("max_consecutive_classes")
            if max_consecutive_pref_metric_str and str(max_consecutive_pref_metric_str).isdigit():
                max_allowed_consecutive_metric = int(max_consecutive_pref_metric_str)
                if max_allowed_consecutive_metric > 0:
                    events_by_day_metric_cons: Dict[str, List[Dict[str, Any]]] = defaultdict(list)
                    for event_m_cons in schedule:
                        ts_db_pk_m_cons = event_m_cons.get("timeslot_id_db")
                        if ts_db_pk_m_cons is None: continue
                        mapped_ts_id_m_cons = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_m_cons)
                        if mapped_ts_id_m_cons is None: continue
                        ts_info_m_cons = self.timeslots_data_mapped.get(mapped_ts_id_m_cons)
                        if ts_info_m_cons and ts_info_m_cons.get("day_of_week"):
                            start_obj_m = parse_time(ts_info_m_cons.get("start_time"))
                            end_obj_m = parse_time(ts_info_m_cons.get("end_time"))
                            if start_obj_m and end_obj_m:
                                events_by_day_metric_cons[ts_info_m_cons['day_of_week']].append({"start_obj": start_obj_m, "end_obj": end_obj_m})
                    
                    total_consecutive_violations_metric = 0
                    for day_val_m, day_event_times_m_list in events_by_day_metric_cons.items():
                        if len(day_event_times_m_list) <= max_allowed_consecutive_metric: continue
                        valid_day_event_times_m = [et for et in day_event_times_m_list if et["start_obj"] and et["end_obj"]]
                        if len(valid_day_event_times_m) <= max_allowed_consecutive_metric: continue
                        valid_day_event_times_m.sort(key=lambda x: x['start_obj'])
                        current_consecutive_m = 0
                        for i_cons_m in range(len(valid_day_event_times_m)):
                            if current_consecutive_m == 0: current_consecutive_m = 1
                            else:
                                prev_end_dt_m = datetime.combine(date.min, valid_day_event_times_m[i_cons_m-1]['end_obj'])
                                curr_start_dt_m = datetime.combine(date.min, valid_day_event_times_m[i_cons_m]['start_obj'])
                                break_mins_m = (curr_start_dt_m - prev_end_dt_m).total_seconds() / 60.0
                                if break_mins_m <= (self.data.get("settings", {}).get("break_duration_minutes", 5) + 10):
                                    current_consecutive_m +=1
                                else:
                                    if current_consecutive_m > max_allowed_consecutive_metric:
                                        total_consecutive_violations_metric += (current_consecutive_m - max_allowed_consecutive_metric)
                                    current_consecutive_m = 1
                        if current_consecutive_m > max_allowed_consecutive_metric:
                            total_consecutive_violations_metric += (current_consecutive_m - max_allowed_consecutive_metric)

                    if total_consecutive_violations_metric > 0:
                        metrics["soft_constraints_details"]["student_pref_max_consecutive"]["count"] = total_consecutive_violations_metric
                        metrics["soft_constraints_details"]["student_pref_max_consecutive"]["penalty_contribution"] = round(total_consecutive_violations_metric * self.penalty_student_preference_violation, 2)

            if self.student_preferences.get("friday_off", False):
                friday_class_count_metric = 0
                for event_fri_m in schedule:
                    ts_db_pk_fri_m = event_fri_m.get("timeslot_id_db")
                    if ts_db_pk_fri_m is None: continue
                    map_ts_fri_m = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_fri_m)
                    if map_ts_fri_m and self.timeslots_data_mapped.get(map_ts_fri_m,{}).get("day_of_week","").lower() == "friday":
                        friday_class_count_metric +=1
                if friday_class_count_metric > 0:
                    metrics["soft_constraints_details"]["student_pref_friday_off"]["count"] = friday_class_count_metric
                    metrics["soft_constraints_details"]["student_pref_friday_off"]["penalty_contribution"] = round(self.penalty_student_preference_violation, 2)

            pref_compact_days_metric = self.student_preferences.get("compact_days", False)
            default_target_max_days_metric = self.data.get("settings", {}).get("student_target_max_compact_days", 3)
            student_target_max_days_metric = int(self.student_preferences.get("target_max_days", default_target_max_days_metric))
            if pref_compact_days_metric and student_target_max_days_metric > 0 :
                unique_days_metric = set()
                for event_cd_m in schedule:
                    ts_db_pk_cd_m = event_cd_m.get("timeslot_id_db")
                    if ts_db_pk_cd_m is None: continue
                    map_ts_cd_m = self._get_mapped_timeslot_id_for_fitness(ts_db_pk_cd_m)
                    if map_ts_cd_m:
                        day_cd_m = self.timeslots_data_mapped.get(map_ts_cd_m,{}).get("day_of_week")
                        if day_cd_m: unique_days_metric.add(day_cd_m)
                days_over_target_metric = 0
                if len(unique_days_metric) > student_target_max_days_metric:
                    days_over_target_metric = len(unique_days_metric) - student_target_max_days_metric
                if days_over_target_metric > 0:
                    metrics["soft_constraints_details"]["student_pref_compact_days"]["count"] = days_over_target_metric
                    metrics["soft_constraints_details"]["student_pref_compact_days"]["penalty_contribution"] = round(days_over_target_metric * self.penalty_student_preference_violation, 2)
        
        metrics["soft_constraints_details"] = dict(metrics["soft_constraints_details"])
        return metrics

if __name__ == "__main__":
    current_script_dir_ga_test = os.path.dirname(os.path.abspath(__file__))

    try:
        from data_loader import load_all_data
        from utils import preprocess_data_for_cp_and_ga, save_output_data_to_json, DEFAULT_SETTINGS as UTILS_DEFAULT_SETTINGS
        from cp_module import CourseSchedulingCPSAT
        from models import ScheduledClass
    except ImportError as e_import_test_ga:
        print(f"GA_MODULE_TEST ERROR: Failed to import dependencies for test: {e_import_test_ga}", file=sys.stderr)
        exit(1)

    output_dir_ga_test = os.path.join(current_script_dir_ga_test, "output_data_ga_module_test")
    os.makedirs(output_dir_ga_test, exist_ok=True)

    test_semester_id = 1
    test_run_type_ga = "student_schedule_request"

    ga_params_for_test = {
        "population_size": 20, "generations": 10, "crossover_rate": 0.8,
        "mutation_rate": 0.2, "tournament_size": 3,
        "allow_hard_constraint_violations_in_ga": False
    }
    cp_time_limit_seed_test = 5.0

    try:
        db_data_tuple_test = load_all_data(semester_id_to_load=test_semester_id)
        sc_from_db_test, instr_list_test, room_list_test, ts_list_test, stud_list_test, courses_cat_test = db_data_tuple_test

        if not (instr_list_test and room_list_test and ts_list_test and courses_cat_test):
            print(f"GA_TEST ERROR: Essential catalog data missing for Semester {test_semester_id}. Cannot proceed."); exit(1)

        items_to_preprocess_for_ga = []
        student_preferences_for_ga_test = {}
        priority_settings_for_ga_test = {
            "student_clash": "critical", "lecturer_load_break": "medium",
            "classroom_util": "low", "student_preferences": "high"
        }

        if test_run_type_ga == "admin_optimize_semester":
            items_to_preprocess_for_ga = sc_from_db_test
            reference_classes_for_utils_test = sc_from_db_test
        elif test_run_type_ga == "student_schedule_request":
            student_requested_course_ids_test = ["BSA105501", "INE105001"]
            temp_id_virtual = -1
            for c_id_req_ga in student_requested_course_ids_test:
                course_detail_ga = courses_cat_test.get(c_id_req_ga)
                if course_detail_ga:
                    items_to_preprocess_for_ga.append(
                        ScheduledClass(id=temp_id_virtual, course_id=c_id_req_ga, semester_id=test_semester_id,
                                       num_students=course_detail_ga.expected_students or 20)
                    )
                    temp_id_virtual -=1
            student_preferences_for_ga_test = {"time_of_day": "morning", "max_consecutive_classes": "3"}
            priority_settings_for_ga_test["student_id"] = "S_TEST_GA_001"
            reference_classes_for_utils_test = sc_from_db_test
            if not reference_classes_for_utils_test:
                 print("GA_TEST WARNING: Student run, but reference_classes_for_utils_test (from sc_from_db_test) is empty. Lecturer map will be empty.")

        if not items_to_preprocess_for_ga and test_run_type_ga == "admin_optimize_semester":
            print(f"GA_TEST INFO: Admin run, but no pre-existing scheduled classes found for Semester {test_semester_id}.")

        processed_data_for_ga_test = preprocess_data_for_cp_and_ga(
            input_scheduled_classes=items_to_preprocess_for_ga,
            input_courses_catalog=courses_cat_test,
            input_instructors=instr_list_test,
            input_classrooms=room_list_test,
            input_timeslots=ts_list_test,
            input_students=stud_list_test,
            reference_scheduled_classes=reference_classes_for_utils_test,
            semester_id_for_settings=test_semester_id,
            priority_settings=priority_settings_for_ga_test,
            run_type=test_run_type_ga
        )

        if not processed_data_for_ga_test or \
           (not processed_data_for_ga_test.get("scheduled_items") and items_to_preprocess_for_ga):
            print("GA_TEST ERROR: Preprocessing failed or yielded no schedulable items when input existed."); exit(1)

        num_items_after_preprocess_ga = len(processed_data_for_ga_test.get("scheduled_items", {}))
        initial_schedules_from_cp_test: List[List[Dict[str, Any]]] = []

        if num_items_after_preprocess_ga > 0:
            cp_solver_for_ga_seed = CourseSchedulingCPSAT(
                processed_data_for_ga_test, run_type=test_run_type_ga
            )
            cp_output_seed, _ = cp_solver_for_ga_seed.solve(time_limit_seconds=cp_time_limit_seed_test)
            if cp_output_seed:
                initial_schedules_from_cp_test.append(cp_output_seed)

        if num_items_after_preprocess_ga > 0 or initial_schedules_from_cp_test:
            ga_scheduler_test = GeneticAlgorithmScheduler(
                processed_data=processed_data_for_ga_test,
                initial_population_from_cp=initial_schedules_from_cp_test,
                population_size=ga_params_for_test["population_size"],
                generations=ga_params_for_test["generations"],
                crossover_rate=ga_params_for_test["crossover_rate"],
                mutation_rate=ga_params_for_test["mutation_rate"],
                tournament_size=ga_params_for_test["tournament_size"],
                allow_hard_constraint_violations_in_ga=ga_params_for_test["allow_hard_constraint_violations_in_ga"],
                run_type=test_run_type_ga,
                student_specific_preferences=student_preferences_for_ga_test if test_run_type_ga == "student_schedule_request" else None
            )
            final_schedule_ga, final_penalty_ga, final_metrics_ga = ga_scheduler_test.run()

            print(f"\n--- GA Test Run Completed (Type: {test_run_type_ga}) ---")
            print(f"Final Best Penalty: {final_penalty_ga:.2f}")
            if final_schedule_ga: print(f"Events in Best Schedule: {len(final_schedule_ga)}")

            output_json_ga_test = {
                "test_info": {"module": "ga_module_test", "run_type": test_run_type_ga, "semester": test_semester_id},
                "params": ga_params_for_test,
                "penalty": final_penalty_ga,
                "metrics": final_metrics_ga,
                "schedule": final_schedule_ga if final_schedule_ga else []
            }
            ga_output_filepath_test = os.path.join(output_dir_ga_test, f"ga_test_output_{test_run_type_ga}_sem{test_semester_id}.json")
            save_output_data_to_json(output_json_ga_test, ga_output_filepath_test)
        else:
            print("GA_TEST INFO: No items to schedule and no CP seed. GA run skipped.")

    except Exception as e:
        print(f"GA_TEST ERROR: An unexpected error occurred during test execution: {e}", file=sys.stderr)
        traceback.print_exc(file=sys.stderr)

    print("\n--- GA_MODULE.PY: Standalone Test Suite Finished ---")