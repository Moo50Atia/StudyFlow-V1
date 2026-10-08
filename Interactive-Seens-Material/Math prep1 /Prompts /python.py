import json
import re
import os

def extract_dynamic_prompts(input_file, output_file):
    # التأكد من وجود الملف
    if not os.path.exists(input_file):
        print(f"Error: File '{input_file}' not found.")
        return

    with open(input_file, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    curriculum = {}
    current_lecture = None

    # أنماط البحث المطابقة لهيكلة الملف
    lecture_pattern = re.compile(r'^Lecture:\s*(.+)$')
    prompt_pattern = re.compile(r'^Lec \(Sec (\d+)\):\s*"(.*)"$')

    for line in lines:
        line = line.strip()
        
        # التقاط اسم الدرس
        lec_match = lecture_pattern.match(line)
        if lec_match:
            current_lecture = lec_match.group(1).strip()
            if current_lecture not in curriculum:
                curriculum[current_lecture] = {}
            continue
            
        # التقاط البرومبت وربطه بالدرس الحالي
        prompt_match = prompt_pattern.match(line)
        if prompt_match and current_lecture:
            sec_num = prompt_match.group(1)
            prompt_text = prompt_match.group(2)
            
            # استخدام القاموس يمنع التكرار تلقائياً إذا تكرر نفس السيكشن في الملف
            curriculum[current_lecture][f"Section {sec_num}"] = prompt_text

    # تنظيف القاموس من أي دروس فارغة
    curriculum = {k: v for k, v in curriculum.items() if v}

    # حفظ النتيجة في ملف JSON
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(curriculum, f, ensure_ascii=False, indent=4)

    # طباعة ملخص الاستخراج
    total_prompts = sum(len(secs) for secs in curriculum.values())
    print(f"✅ تم الاستخراج بنجاح وتم الحفظ في: {output_file}")
    print(f"📚 إجمالي الدروس المستخرجة: {len(curriculum)}")
    print(f"🎯 إجمالي البرومبتات المستخرجة: {total_prompts}")

# تشغيل السكربت (تأكد من وضع مسار الملف الصحيح)
input_path = 'NotebookLM_Prompts_2026-09-09.txt'
output_path = 'dynamic_prompts.json'

extract_dynamic_prompts(input_path, output_path)
