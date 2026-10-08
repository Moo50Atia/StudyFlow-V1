import json
import re
import os

INPUT_JSON_FILE = "Prep3_Curriculum_Raw.json"
OUTPUT_PROMPTS_TXT = "Prep3_AI_Studio_Prompts.txt"
OUTPUT_JS_SCRIPT = "Prep3_Injection_Script.js"

TASK_TEMPLATE = """Target Component: src/simulations/unit{unit}/lesson{lesson}/Sec{section}.tsx
Target Route: /unit/{unit}/lesson/{lesson}/section/{section}
Lesson: {title} - Section {section}

TASK:
Replace the placeholder simulation for this specific route by generating the complete, production-ready interactive 3D React component inside its dedicated file. Connect it to the route and ensure all R3F, GSAP, and Pedagogical UI constraints are met.

SIMULATION SPECIFICATIONS:
"{spec}"
"""

WORD_TO_NUM = {
    "one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6,
    "first": 1, "second": 2, "third": 3, "fourth": 4, "fifth": 5
}

def extract_scenes(text, current_unit_state):
    scenes = []
    
    # 1. استخراج رقم الوحدة (يدعم الأرقام والكلمات مثل Unit One)
    unit_match = re.search(r'Unit\s*([0-9]+|[a-zA-Z]+)', text, re.IGNORECASE)
    if unit_match:
        val = unit_match.group(1).lower()
        if val.isdigit():
            current_unit_state[0] = int(val)
        elif val in WORD_TO_NUM:
            current_unit_state[0] = WORD_TO_NUM[val]

    unit_id = current_unit_state[0]

    # 2. استخراج عنوان الدرس ورقم المحاضرة
    lecture_match = re.search(r'Lecture:\s*Lesson\s*(\d+)[:\s\-]+([^\n\r]+)', text, re.IGNORECASE)
    lesson_id = int(lecture_match.group(1)) if lecture_match else 1
    lesson_title = lecture_match.group(2).strip() if lecture_match else f"Lesson {lesson_id}"
    lesson_title = re.sub(r'[\*\_#]', '', lesson_title).strip()

    # 3. استخراج البرومبتات من خطوة THIRD STEP (LecX (SecY): "...")
    # نمط صريح يلتقط نصوص المشاهد بدقة
    third_step_matches = re.findall(r'Lec\d+\s*\((?:Sec|Section)\s*(\d+)\)\s*:\s*["“]([\s\S]*?)["”]', text)

    if third_step_matches:
        for sec_str, spec_body in third_step_matches:
            sec_id = int(sec_str)
            clean_spec = re.sub(r'\s+', ' ', spec_body).strip().replace('"', '\\"')
            scenes.append((unit_id, lesson_id, sec_id, lesson_title, clean_spec))
    else:
        # نمط بديل في حال لم تكن الكلمات محاطة بأقواس تنصيص
        alt_matches = re.findall(r'(?:Section|Sec)\s*(\d+)[:\s\-]+(?:\*\*)?(Comparison|Process|Time Evolution|Parallel Flow|Accumulation)[:\s\*\-]+([^\n\r]+)', text, re.IGNORECASE)
        for sec_str, p_type, p_desc in alt_matches:
            sec_id = int(sec_str)
            spec = f"{p_type.strip()}: {p_desc.strip()}".replace('"', '\\"')
            scenes.append((unit_id, lesson_id, sec_id, lesson_title, spec))

    return scenes

def main():
    if not os.path.exists(INPUT_JSON_FILE):
        print(f"❌ لم يتم العثور على {INPUT_JSON_FILE}. شغّل كود المتصفح أولاً لتحميل الملف.")
        return

    with open(INPUT_JSON_FILE, "r", encoding="utf-8") as f:
        data = json.load(f)

    turns = data.get("turns", [])
    all_scenes = []
    current_unit = [1]  # تتبع رقم الوحدة عبر التيرنات

    for turn in turns:
        content = turn.get("content", "")
        scenes = extract_scenes(content, current_unit)
        all_scenes.extend(scenes)

    # فلترة التكرارات
    unique_scenes = []
    seen = set()
    for item in all_scenes:
        key = (item[0], item[1], item[2]) # (unit, lesson, section)
        if key not in seen:
            seen.add(key)
            unique_scenes.append(item)

    print(f"🎯 تم استخراج {len(unique_scenes)} مشهد محاكاة لمنهج Prep 3.")

    # أ) تصدير ملف النصوص
    with open(OUTPUT_PROMPTS_TXT, "w", encoding="utf-8") as f:
        for u, l, s, title, spec in unique_scenes:
            block = TASK_TEMPLATE.format(unit=u, lesson=l, section=s, title=title, spec=spec)
            f.write(block + "\n" + ("=" * 80) + "\n\n")

    print(f"📄 تم حفظ البرومبتات في: {OUTPUT_PROMPTS_TXT}")

    # ب) تصدير سكربت الحقن التلقائي الخاص بـ Prep 3
    raw_data_js = json.dumps([[u, l, s, title, spec] for u, l, s, title, spec in unique_scenes], ensure_ascii=False, indent=4)

    js_template = f"""(() => {{
    const rawData = {raw_data_js};

    const tasksQueue = rawData.map(([u, l, s, title, spec]) => {{
        return `Target Component: src/simulations/unit${{u}}/lesson${{l}}/Sec${{s}}.tsx\\n` +
               `Target Route: /unit/${{u}}/lesson/${{l}}/section/${{s}}\\n` +
               `Lesson: ${{title}} - Section ${{s}}\\n\\n` +
               `TASK:\\n` +
               `Replace the placeholder simulation for this specific route by generating the complete, production-ready interactive 3D React component inside its dedicated file. Connect it to the route and ensure all R3F, GSAP, and Pedagogical UI constraints are met.\\n\\n` +
               `SIMULATION SPECIFICATIONS:\\n` +
               `"${{spec}}"`;
    }});

    const CHECK_INTERVAL_MS = 2 * 60 * 1000;
    let currentIndex = parseInt(localStorage.getItem('gemini_queue_idx_prep3') || '0', 10);

    console.log(`%c🚀 بدء تشغيل طابور مشاهد Prep 3 التلقائي!`, "color: #00ff00; font-weight: bold; font-size: 14px;");
    console.log(`📍 البداية من السيكشن (${{currentIndex + 1}}) من أصل ${{tasksQueue.length}}`);

    function executeNextTask() {{
        if (currentIndex >= tasksQueue.length) {{
            console.log("%c🎉 اكتمل بناء جميع مشاهد الصف الثالث الإعدادي بنجاح!", "color: #00ff00; font-size: 16px; font-weight: bold;");
            clearInterval(window.queueRunnerTimerPrep3);
            return;
        }}

        const sendBtn = document.querySelector('button.send-button');
        const textarea = document.querySelector('textarea.cdk-textarea-autosize');

        if (!sendBtn || !textarea) return;

        const isRunning = sendBtn.classList.contains('running') || 
                          document.querySelector('.running-spinner') !== null || 
                          document.querySelector('.stop-square') !== null;

        if (isRunning) {{
            console.log(`⏳ الموديل لا يزال يبني المشهد السابق... فحص جديد بعد دقيقتين.`);
            return;
        }}

        console.log(`%c⚡ السيرفر جاهز! إرسال مهمة Prep 3 رقم (${{currentIndex + 1}} / ${{tasksQueue.length}})...`, "color: #00bfff; font-weight: bold;");

        const nextPrompt = tasksQueue[currentIndex];
        textarea.focus();
        textarea.select();
        document.execCommand('insertText', false, nextPrompt);
        textarea.value = nextPrompt;
        textarea.dispatchEvent(new Event('input', {{ bubbles: true }}));

        setTimeout(() => {{
            sendBtn.click();
            console.log(`%c✅ تم إرسال المهمة (${{currentIndex + 1}}) بنجاح!`, "color: #39ff14; font-weight: bold;");
            currentIndex++;
            localStorage.setItem('gemini_queue_idx_prep3', currentIndex);
        }}, 1200);
    }}

    if (window.queueRunnerTimerPrep3) clearInterval(window.queueRunnerTimerPrep3);
    executeNextTask();
    window.queueRunnerTimerPrep3 = setInterval(executeNextTask, CHECK_INTERVAL_MS);
}})();"""

    with open(OUTPUT_JS_SCRIPT, "w", encoding="utf-8") as f:
        f.write(js_template)

    print(f"🚀 تم إنشاء سكربت الحقن التلقائي: {OUTPUT_JS_SCRIPT}")

if __name__ == "__main__":
    main()
