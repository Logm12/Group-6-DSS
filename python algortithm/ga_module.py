import random
import copy
from utils import check_time_overlap # Có thể cần thêm các hàm tiện ích khác
from collections import defaultdict # <<< THÊM DÒNG NÀY
from datetime import datetime, date # <<< THÊM DÒNG NÀY (CẦN CHO datetime.combine)


class GeneticAlgorithmScheduler:
    def __init__(self, processed_data, initial_population,
                 population_size=50, generations=100,
                 crossover_rate=0.8, mutation_rate=0.1,
                 tournament_size=5):

        self.data = processed_data 
        self.initial_population = initial_population 
        
        self.population_size = population_size
        self.generations = generations
        self.crossover_rate = crossover_rate
        self.mutation_rate = mutation_rate
        self.tournament_size = tournament_size

        self.population = []
        
        self.penalty_student_clash = 100
        self.penalty_lecturer_overload = 50 
        self.penalty_lecturer_min_load = 30 
        self.penalty_lecturer_no_break = 40
        self.penalty_classroom_underutilized = 5 
        self.penalty_classroom_overutilized_soft = 10 

        self.student_courses_map = self.data.get("students_courses_map", {})
        self.course_students_map = self.data.get("student_enrollments_map", {})


    def _initialize_population(self):
        self.population = []
        if not self.initial_population:
            print("GA Error: No initial population provided from CP. Cannot proceed.")
            return False

        # initial_population là list của các schedule (mỗi schedule là list các event)
        for schedule_cp in self.initial_population:
            if schedule_cp and isinstance(schedule_cp, list) and \
               all(isinstance(event, dict) for event in schedule_cp):
                 self.population.append(copy.deepcopy(schedule_cp))
            # Không cần trường hợp list của list nữa vì cp_module_ortools.py trả về list of schedule
        
        if not self.population:
            print("GA Error: Population is empty after processing initial_population. Cannot proceed.")
            return False

        if len(self.population) > self.population_size:
            self.population = self.population[:self.population_size]
        
        while len(self.population) < self.population_size and self.population:
            self.population.append(copy.deepcopy(random.choice(self.population)))
        
        if not self.population and self.population_size > 0 : # Xử lý trường hợp population_size > 0 nhưng không có gì để nhân bản
             print("GA Error: Could not populate to desired size. Initial population was empty or became empty.")
             return False

        print(f"GA: Initialized population with {len(self.population)} individuals.")
        return True


    def _calculate_fitness(self, schedule):
        total_penalty = 0
        
        # 1. Giảm thiểu trùng lịch cho sinh viên
        student_time_slots = defaultdict(list) 
        for event in schedule:
            course_id = event.get("course_id") # Sử dụng .get() để an toàn hơn
            timeslot_id = event.get("timeslot_id")
            
            if not course_id or not timeslot_id: continue # Bỏ qua event không hợp lệ

            timeslot_details = self.data["timeslots"].get(timeslot_id)
            if not timeslot_details: continue

            students_in_course = self.course_students_map.get(course_id, [])
            event_time_info = (
                timeslot_details["day_of_week"],
                timeslot_details["start_time"],
                timeslot_details["end_time"]
            )
            for student_id in students_in_course:
                for existing_event_time in student_time_slots[student_id]:
                    if existing_event_time[0] == event_time_info[0]: 
                        if check_time_overlap(existing_event_time[1], existing_event_time[2],
                                              event_time_info[1], event_time_info[2]):
                            total_penalty += self.penalty_student_clash
                student_time_slots[student_id].append(event_time_info)

        # 2. Giảm tải lịch giảng dạy cho giảng viên
        lecturer_slot_counts = defaultdict(int)
        lecturer_schedules = defaultdict(list) 

        for event in schedule:
            lecturer_id = event.get("lecturer_id")
            timeslot_id = event.get("timeslot_id")
            course_id = event.get("course_id") 
            
            if not lecturer_id or not timeslot_id or not course_id: continue

            course_details = self.data["courses"].get(course_id)
            timeslot_details = self.data["timeslots"].get(timeslot_id)
            if not course_details or not timeslot_details: continue

            num_slots_for_event = course_details["duration_slots"] 
            lecturer_slot_counts[lecturer_id] += num_slots_for_event
            lecturer_schedules[lecturer_id].append({
                "day": timeslot_details["day_of_week"],
                "start": timeslot_details["start_time"],
                "end": timeslot_details["end_time"],
                "duration": num_slots_for_event 
            })

        for lecturer_id, count in lecturer_slot_counts.items():
            lect_details = self.data["lecturers"].get(lecturer_id)
            if lect_details:
                max_slots = lect_details.get("max_slots") 
                min_slots = lect_details.get("min_slots") 
                if max_slots is not None and count > max_slots:
                    total_penalty += self.penalty_lecturer_overload * (count - max_slots)
                if min_slots is not None and count < min_slots:
                    total_penalty += self.penalty_lecturer_min_load * (min_slots - count)
        
        # 3. Đảm bảo giờ nghỉ giữa giờ cho các giảng viên
        for lecturer_id, schedule_items in lecturer_schedules.items():
            schedule_items.sort(key=lambda x: (x["day"], x["start"]))
            for i in range(len(schedule_items) - 1):
                event1 = schedule_items[i]
                event2 = schedule_items[i+1]
                if event1["day"] == event2["day"]:
                    # Cần import datetime và date từ module datetime
                    # giả định today là một ngày bất kỳ để so sánh time objects
                    arbitrary_date = date.min # Hoặc date.today()
                    try:
                        dt_event1_end = datetime.combine(arbitrary_date, event1["end"])
                        dt_event2_start = datetime.combine(arbitrary_date, event2["start"])
                        time_between_events_minutes = (dt_event2_start - dt_event1_end).total_seconds() / 60
                    except TypeError as e:
                        # print(f"DEBUG: TypeError in time_between_events calculation for lecturer {lecturer_id}. Event1: {event1}, Event2: {event2}. Error: {e}")
                        # Có thể do event1["end"] hoặc event2["start"] không phải là time object hợp lệ
                        continue # Bỏ qua cặp event này nếu có lỗi type


                    desired_break_minutes = self.data["settings"].get("basic_slot_duration_minutes", 50) 
                    
                    if 0 <= time_between_events_minutes < desired_break_minutes : 
                        total_penalty += self.penalty_lecturer_no_break
        
        return total_penalty


    def _selection(self, evaluated_population):
        selected = []
        # Đảm bảo evaluated_population không rỗng và tournament_size không lớn hơn kích thước population
        if not evaluated_population:
            return []
        
        actual_tournament_size = min(self.tournament_size, len(evaluated_population))
        if actual_tournament_size == 0 : return [] # Không thể chọn nếu tournament size là 0
        
        for _ in range(len(evaluated_population)): 
            tournament = random.sample(evaluated_population, actual_tournament_size)
            tournament.sort(key=lambda x: x[0]) 
            selected.append(tournament[0][1]) 
        return selected

    def _crossover(self, parent1, parent2):
        if random.random() > self.crossover_rate:
            return copy.deepcopy(parent1), copy.deepcopy(parent2)

        if len(parent1) != len(parent2) or len(parent1) < 2 : 
            return copy.deepcopy(parent1), copy.deepcopy(parent2)

        point = random.randint(1, len(parent1) - 1)
        
        # Tạo child schedules bằng cách kết hợp các events
        # Đảm bảo rằng chúng ta đang xử lý danh sách các dictionary (event)
        child1_events = parent1[:point] + parent2[point:]
        child2_events = parent2[:point] + parent1[point:]
        
        # Tạo một mapping từ course_id sang event cho mỗi parent để dễ dàng xây dựng child
        # Điều này đảm bảo child vẫn có đủ tất cả các course, chỉ thay đổi gán của chúng
        
        # Lấy danh sách course_id theo đúng thứ tự từ parent1 (hoặc parent2, chúng nên giống nhau về tập course)
        course_order = [event['course_id'] for event in parent1]

        child1_map = {event['course_id']: event for event in child1_events}
        child2_map = {event['course_id']: event for event in child2_events}

        # Xây dựng lại child1 và child2 theo đúng thứ tự course và đảm bảo không mất course
        child1 = [child1_map.get(cid) for cid in course_order if child1_map.get(cid) is not None]
        child2 = [child2_map.get(cid) for cid in course_order if child2_map.get(cid) is not None]

        # Nếu sau khi lai ghép, số lượng course bị thay đổi (do trùng course_id từ 2 parent khác nhau ở điểm cắt)
        # thì cần cơ chế sửa chữa phức tạp hơn.
        # Hiện tại, giả định rằng parent1 và parent2 có cùng tập các course_id.
        # Cách lai ghép này chỉ hoán đổi cách gán (Lecturer, Room, Timeslot) cho các course ở nửa sau.
        # Để an toàn, kiểm tra lại số lượng course.
        if len(child1) != len(parent1) or len(child2) != len(parent1):
            # print("Warning: Crossover resulted in change in number of courses. Reverting to parents.")
            return copy.deepcopy(parent1), copy.deepcopy(parent2)

        return child1, child2


    def _mutate(self, schedule):
        mutated_schedule = copy.deepcopy(schedule)
        if not mutated_schedule: return [] # Nếu schedule rỗng

        for i in range(len(mutated_schedule)):
            if random.random() < self.mutation_rate:
                event_to_mutate = mutated_schedule[i]
                course_id = event_to_mutate.get("course_id")
                if not course_id: continue
                
                course_details = self.data["courses"].get(course_id)
                if not course_details: continue

                mutation_type = random.choice(["lecturer", "classroom", "timeslot"])

                original_lecturer = event_to_mutate.get("lecturer_id")
                original_classroom = event_to_mutate.get("classroom_id")
                original_timeslot = event_to_mutate.get("timeslot_id")

                if mutation_type == "lecturer" and original_timeslot is not None:
                    possible_new_lecturers = [
                        l_id for l_id in self.data["lecturers"] 
                        if l_id != original_lecturer and \
                           self._is_lecturer_available(l_id, original_timeslot)
                    ]
                    if possible_new_lecturers:
                        new_lecturer_id = random.choice(possible_new_lecturers)
                        mutated_schedule[i]["lecturer_id"] = new_lecturer_id
                        mutated_schedule[i]["lecturer_name"] = self.data["lecturers"][new_lecturer_id]["name"]

                elif mutation_type == "classroom":
                    possible_new_classrooms = [
                        r_id for r_id, r_details in self.data["classrooms"].items()
                        if r_id != original_classroom and \
                           r_details["capacity"] >= course_details["expected_students"]
                    ]
                    if possible_new_classrooms:
                        new_classroom_id = random.choice(possible_new_classrooms)
                        mutated_schedule[i]["classroom_id"] = new_classroom_id
                        mutated_schedule[i]["room_code"] = self.data["classrooms"][new_classroom_id]["room_code"]

                elif mutation_type == "timeslot" and original_lecturer is not None:
                    possible_new_timeslots = [
                        ts_id for ts_id, ts_details in self.data["timeslots"].items()
                        if ts_id != original_timeslot and \
                           ts_details["effective_basic_slots"] == course_details["duration_slots"] and \
                           self._is_lecturer_available(original_lecturer, ts_id)
                    ]
                    if possible_new_timeslots:
                        new_ts_id = random.choice(possible_new_timeslots)
                        mutated_schedule[i]["timeslot_id"] = new_ts_id
                        ts_details_new = self.data["timeslots"][new_ts_id]
                        mutated_schedule[i]["timeslot_info"] = f"{ts_details_new['day_of_week']} {ts_details_new['start_time'].strftime('%H:%M')}-{ts_details_new['end_time'].strftime('%H:%M')}"
        return mutated_schedule

    def _is_lecturer_available(self, lecturer_id, timeslot_id_to_check):
        if lecturer_id is None or timeslot_id_to_check is None: return False
        
        timeslot_details = self.data["timeslots"].get(timeslot_id_to_check)
        if not timeslot_details: return False 

        lecturer_unavailable_slots = self.data["instructor_unavailable_map"].get(lecturer_id, [])
        for unavailable in lecturer_unavailable_slots:
            if unavailable["day_of_week"] == timeslot_details["day_of_week"]:
                if check_time_overlap(timeslot_details["start_time"], timeslot_details["end_time"],
                                      unavailable["start_time"], unavailable["end_time"]):
                    return False 
        return True 

    def run(self):
        if not self._initialize_population():
            return None, float('inf') 

        best_schedule_overall = None
        # Khởi tạo với penalty của cá thể tốt nhất trong quần thể ban đầu (nếu có)
        if self.population:
            initial_eval = [(self._calculate_fitness(ind), ind) for ind in self.population]
            initial_eval.sort(key=lambda x:x[0])
            lowest_penalty_overall = initial_eval[0][0]
            best_schedule_overall = copy.deepcopy(initial_eval[0][1])
        else: # Trường hợp population rỗng sau initialize (dù không nên)
            return None, float('inf')


        print(f"GA Starting. Population: {len(self.population)}. Generations: {self.generations}. Initial best penalty: {lowest_penalty_overall}")

        for generation in range(self.generations):
            evaluated_population = [] 
            for individual_schedule in self.population:
                if not individual_schedule: continue # Bỏ qua nếu cá thể rỗng
                penalty = self._calculate_fitness(individual_schedule)
                evaluated_population.append((penalty, individual_schedule))
            
            if not evaluated_population: # Nếu tất cả cá thể đều rỗng
                print(f"Generation {generation+1}: Population evaluation resulted in no valid individuals. Stopping.")
                break

            evaluated_population.sort(key=lambda x: x[0])
            current_best_penalty_in_gen = evaluated_population[0][0]
            current_best_schedule_in_gen = evaluated_population[0][1]

            if current_best_penalty_in_gen < lowest_penalty_overall:
                lowest_penalty_overall = current_best_penalty_in_gen
                best_schedule_overall = copy.deepcopy(current_best_schedule_in_gen)
                print(f"Generation {generation+1}: New best penalty = {lowest_penalty_overall}")
            elif (generation + 1) % 10 == 0 : 
                 print(f"Generation {generation+1}: Best in gen = {current_best_penalty_in_gen}, Overall best = {lowest_penalty_overall}")

            selected_parents = self._selection(evaluated_population)
            if not selected_parents : # Nếu không chọn được cha mẹ nào
                print(f"Generation {generation+1}: No parents selected. Population may have collapsed. Using current bests.")
                # Giữ lại quần thể hiện tại hoặc chỉ giữ lại các cá thể tốt nhất
                self.population = [sched for _, sched in evaluated_population[:self.population_size]]
                if not self.population and best_schedule_overall: # Nếu population rỗng hoàn toàn, thử dùng best_schedule_overall
                    self.population = [copy.deepcopy(best_schedule_overall)] * self.population_size
                elif not self.population: # Vẫn rỗng
                    print("Critical: Population and best_schedule_overall are empty. Stopping GA.")
                    break
                continue


            next_population = []
            
            if best_schedule_overall: 
                next_population.append(copy.deepcopy(best_schedule_overall)) 
            
            while len(next_population) < self.population_size:
                if len(selected_parents) < 2: 
                    if selected_parents: 
                         next_population.append(copy.deepcopy(selected_parents[0]))
                    # Nếu selected_parents cũng rỗng, cần có cơ chế tạo cá thể mới hoặc dừng
                    elif next_population: # Lấy từ những gì đã có trong next_population
                         next_population.append(copy.deepcopy(random.choice(next_population)))
                    else: # Không còn gì để thêm
                        break 
                    continue

                # Chọn 2 cha mẹ khác nhau nếu có thể
                p1_idx = random.randrange(len(selected_parents))
                p2_idx = random.randrange(len(selected_parents))
                # Đảm bảo p1 và p2 khác nhau nếu quần thể có nhiều hơn 1 cá thể
                if len(selected_parents) > 1:
                    while p1_idx == p2_idx:
                        p2_idx = random.randrange(len(selected_parents))
                
                parent1 = selected_parents[p1_idx]
                parent2 = selected_parents[p2_idx]

                child1, child2 = self._crossover(parent1, parent2)
                
                if child1 : next_population.append(self._mutate(child1))
                if len(next_population) < self.population_size and child2:
                    next_population.append(self._mutate(child2))
            
            self.population = next_population[:self.population_size] 

            if not self.population:
                print("GA Error: Population became empty during evolution. Stopping.")
                break
        
        print(f"GA Finished. Lowest penalty found: {lowest_penalty_overall}")
        return best_schedule_overall, lowest_penalty_overall