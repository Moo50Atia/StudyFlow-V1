import json
import re

# تحميل ملف البرومبتات
with open('dynamic_prompts.json', 'r', encoding='utf-8') as f:
    curriculum = json.load(f)

# دالة مساعدة لتحديد رقم الوحدة والدرس تقريبياً
def resolve_meta(lesson_name, sec_name):
    l_num_match = re.search(r'Lesson\s*(\d+)', lesson_name, re.IGNORECASE)
    l_num = l_num_match.group(1) if l_num_match else "1"
    
    s_num_match = re.search(r'Section\s*(\d+)', sec_name, re.IGNORECASE)
    s_num = s_num_match.group(1) if s_num_match else "1"

    # تصنيف رقم الوحدة حسب اسم الدرس
    u_num = "1"
    if any(k in lesson_name.lower() for k in ["algebraic", "equations"]):
        u_num = "2"
    elif any(k in lesson_name.lower() for k in ["angle", "parallelism", "triangle", "quadrilateral", "polygon", "congruence", "construction"]):
        u_num = "3"
    elif "statistics" in lesson_name.lower():
        u_num = "4"
        
    return u_num, l_num, s_num

formatted_tasks = []

for lesson, sections in curriculum.items():
    for sec, prompt in sections.items():
        u_num, l_num, s_num = resolve_meta(lesson, sec)
        
        target_route = f"/unit/{u_num}/lesson/{l_num}/section/{s_num}"
        file_path = f"src/simulations/unit{u_num}/lesson{l_num}/Sec{s_num}.tsx"
        
        message = (
            f"Target Component: {file_path}\\n"
            f"Target Route: {target_route}\\n"
            f"Lesson: {lesson} - {sec}\\n\\n"
            f"TASK:\\n"
            f"Replace the placeholder simulation for this specific route by generating the complete, "
            f"production-ready interactive 3D React component inside its dedicated file. "
            f"Connect it to the route and ensure all R3F, GSAP, and Pedagogical UI constraints are met.\\n\\n"
            f"SIMULATION SPECIFICATIONS:\\n"
            f"\\\"{prompt}\\\""
        )
        formatted_tasks.append(message)

# حفظها كملف JS جاهز للنسخ في المتصفح
with open('promptsArray.js', 'w', encoding='utf-8') as f:
    f.write("const tasksQueue = " + json.dumps(formatted_tasks, ensure_ascii=False, indent=2) + ";\n")

print(f"✅ تم تجهيز {len(formatted_tasks)} برومبت مدمج بالمسارات في ملف promptsArray.js بنجاح!")
