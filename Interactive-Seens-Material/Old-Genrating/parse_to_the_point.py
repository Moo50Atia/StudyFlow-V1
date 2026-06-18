import re
import sys

sys.stdout.reconfigure(encoding='utf-8')

def parse_to_the_point(filepath):
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()

    # The file has chapters from Chapter 1 to Chapter 36.
    # Let's locate the chapters.
    # Chapters in body usually start with "Chapter X" or "\x0cChapter X" or "Chapter X\nTitle"
    # Let's find all occurrences of "Chapter X" in the text
    chapters = []
    # We can split the content by chapter headers.
    # Let's use a regex to find Chapter markers.
    chapter_matches = list(re.finditer(r'(?:^|\n|\x0c)Chapter\s+(\d+)\b', content))
    
    print(f"Found {len(chapter_matches)} chapter markers in text.")
    
    # We can also get chapter titles from the Index of Contents that we printed earlier:
    chapter_titles = {
        1: "Chronic heart failure",
        2: "Acute heart failure",
        3: "Mechanical circulatory support (MCS)",
        4: "Cardiomyopathies",
        5: "Chronic coronary syndrome",
        6: "Universal definition of MI",
        7: "ST elevation myocardial infarction",
        8: "Non ST elevation ACS",
        9: "Antithrombotic in ischemic heart diseases",
        10: "Coronary Assessment and Intervention",
        11: "Valvular heart diseases",
        12: "Infective Endocarditis",
        13: "Rheumatic fever",
        14: "General Approach to Tachy-arrhythmias",
        15: "Supraventricular tachycardia",
        16: "Atrial Fibrillation",
        17: "Ventricular Arrhythmia",
        18: "Bradyarrhythmia",
        19: "Cardic Pacing",
        20: "Syncope",
        21: "Pericardial diseases",
        22: "Congenital Heart Diseases",
        23: "Pulmonary Embolism",
        24: "Pulmonary Hypertension",
        25: "Aortic diseases",
        26: "Peripheral Arterial Disease",
        27: "Cardiovascular Diseases Prevention",
        28: "Hypertension",
        29: "Dyslipidemia",
        30: "Cardiodiabetes",
        31: "Cardiovascular management in non-cardic surgery",
        32: "Cardio obstetrics",
        33: "Cardio-oncology",
        34: "Sports cardiology",
        35: "Cardiac involvement in systemic diseases",
        36: "Cardiovascular Pharmacology"
    }

    # For each chapter, let's extract its content block and find subheadings.
    parsed_chapters = []
    for i, match in enumerate(chapter_matches):
        ch_num = int(match.group(1))
        if ch_num not in chapter_titles:
            continue
        
        start_idx = match.end()
        end_idx = chapter_matches[i+1].start() if i + 1 < len(chapter_matches) else len(content)
        
        ch_content = content[start_idx:end_idx]
        ch_lines = ch_content.split('\n')
        
        # Let's find subheadings in this chapter's lines.
        # Subheadings: lines that end with a colon or have strong bullet patterns, etc.
        # But we only want major subheadings (Mini Chapters) and their sub-subheadings (Lessons).
        # Let's extract lines that:
        # 1. Are relatively short (e.g. < 80 chars)
        # 2. End with ":" or are starting with "▪" or "•"
        # We can filter out lines that contain page numbers, figure/table captions, etc.
        
        mini_chapters = []
        current_mini = None
        
        for line in ch_lines:
            line_str = line.strip()
            if not line_str:
                continue
            # Skip page markers
            if re.match(r'^Page\s*\|\s*.*', line_str, re.IGNORECASE) or line_str.startswith('\x0cPage'):
                continue
            # Skip tables or very long lines
            if len(line_str) > 100:
                continue
                
            # Check for Mini Chapter candidates (e.g., words ending with colon, no leading bullets)
            # e.g., "Classification:", "Epidemiology:", "Pathophysiology of HFrEF:"
            if re.match(r'^[A-Z][a-zA-Z\s\-\(\)\/\,\&\’]+:$', line_str):
                title = line_str[:-1].strip()
                # Check for duplicates or noise
                if title not in ["CRITERIA", "NOTE", "Mechanism", "Mechanism of production", "Cardic causes", "Non cardic causes"]:
                    current_mini = {
                        "title": title,
                        "lessons": []
                    }
                    mini_chapters.append(current_mini)
                    
            # Check for Lesson candidates (starting with ▪ or • or o or -)
            elif (line_str.startswith('▪') or line_str.startswith('•') or line_str.startswith('o') or line_str.startswith('-') or line_str.startswith('*')) and current_mini is not None:
                # Remove the bullet char
                lesson_title = re.sub(r'^[▪•o\-\*]\s*', '', line_str).strip()
                if lesson_title.endswith(':'):
                    lesson_title = lesson_title[:-1].strip()
                # Skip short noise
                if len(lesson_title) > 3 and not lesson_title.startswith('According to') and not lesson_title.startswith('Universal classification') and not lesson_title.startswith('The NYHA'):
                    if lesson_title not in current_mini["lessons"]:
                        current_mini["lessons"].append(lesson_title)
                        
        parsed_chapters.append({
            "num": ch_num,
            "title": chapter_titles[ch_num],
            "minis": mini_chapters
        })
        
    return parsed_chapters

results = parse_to_the_point(r"Content/D.Amged/To The Point - Second Edition.txt")

with open("to_the_point_parsed.txt", "w", encoding="utf-8") as out:
    for ch in results:
        out.write(f"Chapter {ch['num']}: {ch['title']}\n")
        if not ch['minis']:
            out.write(f"  Mini Chapter {ch['num']}.1: {ch['title']} (treated as Lesson itself)\n")
        else:
            for j, mini in enumerate(ch['minis']):
                mini_num = f"{ch['num']}.{j+1}"
                out.write(f"  Mini Chapter {mini_num}: {mini['title']}\n")
                if not mini['lessons']:
                    # treated as Lesson itself
                    out.write(f"    (treated as Lesson itself)\n")
                else:
                    for k, les in enumerate(mini['lessons']):
                        les_num = f"{ch['num']}.{j+1}.{k+1}"
                        out.write(f"    Lesson {les_num}: {les}\n")

print("Done parsing To The Point!")
