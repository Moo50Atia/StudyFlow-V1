import re
import sys
import os

sys.stdout.reconfigure(encoding='utf-8')

def parse_ccs(filepath):
    # This is a structured ESC guideline. Let's define the clean, exact hierarchy manually
    # or programmatically to match the guideline TOC.
    # Based on the file contents, let's define the structure.
    
    structure = []
    
    # Chapter 1
    structure.append({
        "chapter": "Chapter 1: Preamble",
        "minis": [
            {
                "title": "Mini Chapter 1.1: Preamble",
                "lessons": [] # treated as lesson itself
            }
        ]
    })
    
    # Chapter 2
    structure.append({
        "chapter": "Chapter 2: Introduction",
        "minis": [
            {"title": "Mini Chapter 2.1: Evolving pathophysiological concepts of chronic coronary syndromes", "lessons": []},
            {"title": "Mini Chapter 2.2: Chronic coronary syndromes: clinical presentations", "lessons": []},
            {"title": "Mini Chapter 2.3: Changing epidemiology and management strategies", "lessons": []},
            {"title": "Mini Chapter 2.4: What is new", "lessons": []}
        ]
    })
    
    # Chapter 3
    structure.append({
        "chapter": "Chapter 3: Stepwise approach to the initial management of individuals with suspected chronic coronary syndrome",
        "minis": [
            {
                "title": "Mini Chapter 3.1: STEP 1: General clinical examination",
                "lessons": [
                    "Lesson 3.1.1: History, differential diagnosis, and physical examination",
                    "Lesson 3.1.2: Basic testing: 12-lead electrocardiogram and biochemistry"
                ]
            },
            {
                "title": "Mini Chapter 3.2: STEP 2: Further evaluation",
                "lessons": [
                    "Lesson 3.2.1: Pre-test clinical likelihood of obstructive atherosclerotic coronary artery disease",
                    "Lesson 3.2.2: Transthoracic echocardiography and cardiac magnetic resonance at rest",
                    "Lesson 3.2.3: Exercise electrocardiogram testing",
                    "Lesson 3.2.4: Chest X-ray",
                    "Lesson 3.2.5: Ambulatory electrocardiogram monitoring"
                ]
            },
            {
                "title": "Mini Chapter 3.3: STEP 3: Confirming the diagnosis",
                "lessons": [
                    "Lesson 3.3.1: Anatomical imaging: coronary computed tomography angiography",
                    "Lesson 3.3.2: Functional imaging",
                    "Lesson 3.3.3: Invasive tests",
                    "Lesson 3.3.4: Diagnostic algorithm and selection of appropriate tests",
                    "Lesson 3.3.5: Adverse-event risk assessment"
                ]
            },
            {
                "title": "Mini Chapter 3.4: STEP 4: Initial therapy",
                "lessons": [] # treated as lesson itself
            }
        ]
    })
    
    # Chapter 4
    structure.append({
        "chapter": "Chapter 4: Guideline-directed therapy",
        "minis": [
            {
                "title": "Mini Chapter 4.1: Patient education, lifestyle optimization for risk-factor control, and exercise therapy",
                "lessons": [
                    "Lesson 4.1.1: Patient education",
                    "Lesson 4.1.2: Key lifestyle interventions for risk-factor control",
                    "Lesson 4.1.3: Exercise therapy"
                ]
            },
            {
                "title": "Mini Chapter 4.2: Antianginal/anti-ischaemic medication",
                "lessons": [
                    "Lesson 4.2.1: General strategy",
                    "Lesson 4.2.2: Beta blockers",
                    "Lesson 4.2.3: Combination therapy"
                ]
            },
            {
                "title": "Mini Chapter 4.3: Medical therapy for event prevention",
                "lessons": [
                    "Lesson 4.3.1: Antithrombotic drugs",
                    "Lesson 4.3.2: Lipid-lowering drugs",
                    "Lesson 4.3.3: Renin–angiotensin–aldosterone blockers/angiotensin receptor neprilysin inhibitor",
                    "Lesson 4.3.4: Sodium–glucose cotransporter 2 inhibitors and glucagonlike peptide-1 receptor agonists",
                    "Lesson 4.3.5: Anti-inflammatory agents for event prevention"
                ]
            },
            {
                "title": "Mini Chapter 4.4: Revascularization for chronic coronary syndromes",
                "lessons": [
                    "Lesson 4.4.1: Appropriate indication for myocardial revascularization",
                    "Lesson 4.4.2: Additional considerations on reduced systolic left ventricular function: viability, revascularization, and modality",
                    "Lesson 4.4.3: Additional considerations—complete vs. partial revascularization",
                    "Lesson 4.4.4: Assessment of clinical risk and anatomical complexity",
                    "Lesson 4.4.5: Choice of myocardial revascularization modality",
                    "Lesson 4.4.6: Patient–physician shared decision-making",
                    "Lesson 4.4.7: Institutional protocols, clinical pathways, and quality of care"
                ]
            }
        ]
    })
    
    # Chapter 5
    structure.append({
        "chapter": "Chapter 5: Optimal assessment and treatment of specific groups",
        "minis": [
            {
                "title": "Mini Chapter 5.1: Coronary artery disease and heart failure",
                "lessons": [] # treated as lesson itself
            },
            {
                "title": "Mini Chapter 5.2: Angina/ischaemia with non-obstructive coronary arteries",
                "lessons": [
                    "Lesson 5.2.1: Definition",
                    "Lesson 5.2.2: Angina/ischaemia with non-obstructive coronary arteries endotypes",
                    "Lesson 5.2.3: Clinical presentations",
                    "Lesson 5.2.4: Short- and long-term prognosis",
                    "Lesson 5.2.5: Diagnosis",
                    "Lesson 5.2.6: Management of ANOCA/INOCA"
                ]
            },
            {
                "title": "Mini Chapter 5.3: Other specific patient groups",
                "lessons": [
                    "Lesson 5.3.1: Older adults",
                    "Lesson 5.3.2: Sex differences in chronic coronary syndromes",
                    "Lesson 5.3.3: High bleeding-risk patients",
                    "Lesson 5.3.4: Inflammatory rheumatic diseases",
                    "Lesson 5.3.5: Hypertension",
                    "Lesson 5.3.6: Atrial fibrillation",
                    "Lesson 5.3.7: Valvular heart disease",
                    "Lesson 5.3.8: Chronic kidney disease",
                    "Lesson 5.3.9: Cancer",
                    "Lesson 5.3.10: Optimal treatment of patients with human immunodeficiency virus",
                    "Lesson 5.3.11: Socially and geographically diverse groups"
                ]
            },
            {
                "title": "Mini Chapter 5.4: Screening for coronary artery disease in asymptomatic individuals",
                "lessons": [] # treated as lesson itself
            }
        ]
    })
    
    # Chapter 6
    structure.append({
        "chapter": "Chapter 6: Long-term follow-up and care",
        "minis": [
            {
                "title": "Mini Chapter 6.1: Voice of the patient",
                "lessons": [
                    "Lesson 6.1.1: Communication",
                    "Lesson 6.1.2: Depression and anxiety"
                ]
            },
            {
                "title": "Mini Chapter 6.2: Adherence and persistence",
                "lessons": [
                    "Lesson 6.2.1: Adherence to healthy lifestyle behaviours",
                    "Lesson 6.2.2: Adherence to medical therapy"
                ]
            },
            {
                "title": "Mini Chapter 6.3: Diagnosis of disease progression",
                "lessons": [
                    "Lesson 6.3.1: Risk factors for recurrent coronary artery disease events",
                    "Lesson 6.3.2: Organization of long-term follow-up",
                    "Lesson 6.3.3: Non-invasive diagnostic testing"
                ]
            },
            {
                "title": "Mini Chapter 6.4: Treatment of myocardial revascularization failure",
                "lessons": [
                    "Lesson 6.4.1: Percutaneous coronary intervention failure",
                    "Lesson 6.4.2: Managing graft failure after coronary artery bypass grafting"
                ]
            },
            {
                "title": "Mini Chapter 6.5: Recurrent or refractory angina/ischaemia",
                "lessons": [] # treated as lesson itself
            },
            {
                "title": "Mini Chapter 6.6: Treatment of disease complications",
                "lessons": [] # treated as lesson itself
            }
        ]
    })
    
    # Chapters 7 to 14
    ch_list = [
        ("Chapter 7: Key messages", "Mini Chapter 7.1: Key messages"),
        ("Chapter 8: Gaps in evidence", "Mini Chapter 8.1: Gaps in evidence"),
        ("Chapter 9: 'What to do' and 'What not to do' messages from the guidelines", "Mini Chapter 9.1: 'What to do' and 'What not to do' messages"),
        ("Chapter 10: Evidence tables", "Mini Chapter 10.1: Evidence tables"),
        ("Chapter 11: Data availability statement", "Mini Chapter 11.1: Data availability statement"),
        ("Chapter 12: Author information", "Mini Chapter 12.1: Author information"),
        ("Chapter 13: Appendix", "Mini Chapter 13.1: Appendix"),
        ("Chapter 14: References", "Mini Chapter 14.1: References")
    ]
    for ch, mini in ch_list:
        structure.append({
            "chapter": ch,
            "minis": [
                {
                    "title": mini,
                    "lessons": []
                }
            ]
        })
        
    return structure

def parse_to_the_point_clean(filepath):
    # Let's map each chapter from Chapter 1 to Chapter 36.
    # Since we parsed it earlier and got many subsections, let's clean it up.
    # Under each chapter, let's extract the main subheadings (Mini Chapters) and their lessons.
    # To keep it extremely high quality and avoid junk, we define the main outline of each chapter.
    
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()

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

    chapter_matches = list(re.finditer(r'(?:^|\n|\x0c)Chapter\s+(\d+)\b', content))
    
    parsed_chapters = []
    for i, match in enumerate(chapter_matches):
        ch_num = int(match.group(1))
        if ch_num not in chapter_titles:
            continue
        
        start_idx = match.end()
        end_idx = chapter_matches[i+1].start() if i + 1 < len(chapter_matches) else len(content)
        
        ch_content = content[start_idx:end_idx]
        ch_lines = ch_content.split('\n')
        
        # We will parse out the Mini Chapters and Lessons for each chapter.
        # Mini Chapters are lines that end with a colon (e.g. Classification:, Investigations:)
        # Lessons are the first level of bullet points under them.
        minis = []
        current_mini = None
        
        # We will filter out lines that are too long, or contain page numbers, or references.
        for line in ch_lines:
            line_str = line.strip()
            if not line_str:
                continue
            if re.match(r'^Page\s*\|\s*.*', line_str, re.IGNORECASE) or line_str.startswith('\x0cPage'):
                continue
            if len(line_str) > 100:
                continue
            
            # Check for a Mini Chapter heading ending with ":"
            if re.match(r'^[A-Z][a-zA-Z\s\-\(\)\/\,\&\’]+:$', line_str):
                title = line_str[:-1].strip()
                if title not in ["CRITERIA", "NOTE", "Mechanism", "Mechanism of production", "Cardic causes", "Non cardic causes"]:
                    current_mini = {
                        "title": title,
                        "lessons": []
                    }
                    minis.append(current_mini)
            # Check for a Lesson bullet under it
            elif (line_str.startswith('▪') or line_str.startswith('•') or line_str.startswith('o') or line_str.startswith('-') or line_str.startswith('*')) and current_mini is not None:
                lesson_title = re.sub(r'^[▪•o\-\*]\s*', '', line_str).strip()
                if lesson_title.endswith(':'):
                    lesson_title = lesson_title[:-1].strip()
                # Exclude noisy fragments
                if len(lesson_title) > 5 and not lesson_title.startswith('According to') and not lesson_title.startswith('Universal classification') and not lesson_title.startswith('The NYHA'):
                    # Clean up first letter lower-case continuation fragments
                    if not lesson_title[0].islower():
                        if lesson_title not in current_mini["lessons"]:
                            current_mini["lessons"].append(lesson_title)
                            
        # If a chapter has no minis (e.g. some brief chapters), treat the chapter itself as a mini
        # Or if a mini has no lessons, it is treated as a lesson itself.
        parsed_chapters.append({
            "num": ch_num,
            "title": chapter_titles[ch_num],
            "minis": minis
        })
        
    return parsed_chapters

def format_output(ccs_struct, ttp_struct):
    output_lines = []
    total_lessons = 0
    
    # 1. chronic coronary syndromes
    output_lines.append("MATERIAL: chronic coronary syndromes.txt")
    output_lines.append("───────────────────────────────────────")
    for ch in ccs_struct:
        output_lines.append(ch["chapter"])
        for mini in ch["minis"]:
            if not mini["lessons"]:
                output_lines.append(f"  {mini['title']}   ← (No Lessons → treated as Lesson itself)")
                total_lessons += 1
            else:
                output_lines.append(f"  {mini['title']}")
                for les in mini["lessons"]:
                    output_lines.append(f"    {les}")
                    total_lessons += 1
    output_lines.append("")
    
    # 2. To The Point
    output_lines.append("MATERIAL: To The Point - Second Edition.txt")
    output_lines.append("───────────────────────────────────────")
    for ch in ttp_struct:
        output_lines.append(f"Chapter {ch['num']}: {ch['title']}")
        if not ch["minis"]:
            output_lines.append(f"  Mini Chapter {ch['num']}.1: {ch['title']}   ← (No Lessons → treated as Lesson itself)")
            total_lessons += 1
        else:
            for j, mini in enumerate(ch["minis"]):
                mini_num = f"{ch['num']}.{j+1}"
                if not mini["lessons"]:
                    output_lines.append(f"  Mini Chapter {mini_num}: {mini['title']}   ← (No Lessons → treated as Lesson itself)")
                    total_lessons += 1
                else:
                    output_lines.append(f"  Mini Chapter {mini_num}: {mini['title']}")
                    for k, les in enumerate(mini["lessons"]):
                        les_num = f"{ch['num']}.{j+1}.{k+1}"
                        output_lines.append(f"    Lesson {les_num}: {les}")
                        total_lessons += 1
                        
    output_lines.append("")
    output_lines.append(f"TOTAL LESSONS TO PROCESS: {total_lessons}")
    output_lines.append("ROUTE: Medical")
    
    return "\n".join(output_lines), total_lessons

ccs = parse_ccs(r"Content/D.Amged/chronic coronary syndromes.txt")
ttp = parse_to_the_point_clean(r"Content/D.Amged/To The Point - Second Edition.txt")

out_str, total = format_output(ccs, ttp)

# Write to Content/D.Amged/D.Amged_Structure_Route.txt
out_dir = r"Content/D.Amged"
out_path = os.path.join(out_dir, "D.Amged_Structure_Route.txt")
with open(out_path, "w", encoding="utf-8") as f:
    f.write(out_str)

print(f"Success! Total lessons parsed: {total}")
