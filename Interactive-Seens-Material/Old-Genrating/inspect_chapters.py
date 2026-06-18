import re
import sys

# Configure stdout to use UTF-8
sys.stdout.reconfigure(encoding='utf-8')

def analyze_chronic_coronary_syndromes(filepath):
    # This is a guideline text. We want to extract Chapter -> Mini Chapter -> Lesson.
    # In the index we saw:
    # 1. Preamble
    # 2. Introduction
    # 2.1. Evolving...
    # 3. Stepwise approach...
    # 3.1. STEP 1...
    # 3.1.1. History...
    # Let's map this hierarchy:
    # Chapter: 1. Preamble, 2. Introduction, 3. Stepwise approach..., 4. Guideline-directed therapy... etc.
    # Mini Chapter: e.g. 2.1, 2.2, 3.1, 3.2, 3.3
    # Lesson: e.g. 3.1.1, 3.1.2, 3.2.1, etc.
    # If a Mini Chapter has no Lessons, treat it as a Lesson itself.
    
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
        
    # Let's find all numbered headers in the table of contents or in the file.
    # The table of contents seems to end around "References".
    # Let's parse the table of contents from the text.
    # Since we printed the TOC earlier, let's look at the TOC lines.
    # The TOC lines look like:
    # "1. Preamble ...................................................."
    # Let's find all such lines.
    toc_matches = []
    lines = content.split('\n')
    
    # We can scan the lines in the file and find TOC entries
    # Or we can scan the file for headings in the main body.
    # Let's find TOC lines first. In chronic coronary syndromes.txt:
    # Let's extract all lines that match: (digits).(digits)... (title) ...... (page)
    # e.g., 2.1. Evolving pathophysiological concepts of chronic coronary syndromes ..... 3423
    # e.g., 3. Stepwise approach to the initial management ... 3433
    
    # Let's write a regular expression to match TOC lines
    toc_re = re.compile(r'^(\d+(?:\.\d+)*)\.?\s+(.*?)\s*\.{3,}\s*\d+', re.UNICODE)
    
    # Let's search only the first 400 lines of chronic coronary syndromes.txt (where TOC resides)
    for idx, line in enumerate(lines[:400]):
        m = toc_re.match(line.strip())
        if m:
            toc_matches.append((m.group(1), m.group(2).strip()))
        else:
            # Multi-line TOC entry? Let's check if the next line has dots
            # e.g. 
            # 2.1. Evolving pathophysiological concepts of chronic coronary
            # syndromes .......................................................................................................... 3423
            # We can handle this by joining if a line starts with number but doesn't have dots, and the next line has dots.
            pass

    # Let's dump all matching lines in TOC to see what we get
    return toc_matches

def analyze_to_the_point(filepath):
    # Let's see how chapters are structured in "To The Point - Second Edition.txt".
    # We saw:
    # Section I: Heart failure and specific cardiomyopathies:
    # Chapter 1
    # Chronic heart failure
    # 01
    # Let's extract the index from the first 800 lines of To The Point.
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        lines = f.readlines()
        
    toc_lines = []
    for idx in range(45, 800):
        if idx < len(lines):
            toc_lines.append(lines[idx].strip())
            
    return toc_lines

# Let's run analysis and write to temp_structure.txt
with open("temp_structure.txt", "w", encoding="utf-8") as out:
    out.write("=== CHRONIC CORONARY SYNDROMES TOC ===\n")
    for num, title in analyze_chronic_coronary_syndromes(r"Content/D.Amged/chronic coronary syndromes.txt"):
        out.write(f"{num} {title}\n")
        
    out.write("\n=== TO THE POINT TOC LINES ===\n")
    for line in analyze_to_the_point(r"Content/D.Amged/To The Point - Second Edition.txt"):
        if line:
            out.write(line + "\n")

print("Done! Check temp_structure.txt")
