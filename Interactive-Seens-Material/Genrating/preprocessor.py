"""
preprocessor.py
---------------
Usage:
    python preprocessor.py "path\to\user-folder"

What it does:
    1. Reads all PDF and TXT files in the given folder
    2. Detects structure (Chapters / Mini Chapters / Lessons)
    3. Auto-detects route: Medical or General
    4. Outputs manifest.json in the same folder
"""

import sys
import os
import json
import re

# ── Optional PDF support ───────────────────────────────────────────────────────
try:
    import pdfplumber
    PDF_SUPPORT = True
except ImportError:
    PDF_SUPPORT = False

# ══════════════════════════════════════════════════════════════════════════════
# MEDICAL KEYWORDS — used for auto route detection
# ══════════════════════════════════════════════════════════════════════════════
MEDICAL_KEYWORDS = [
    "pathophysiology", "diagnosis", "treatment", "clinical", "patient",
    "symptom", "disease", "drug", "dose", "therapy", "cardiac", "ecg",
    "pharmacology", "anatomy", "physiology", "syndrome", "infection",
    "inflammation", "surgery", "mechanism", "receptor", "enzyme",
    "blood", "artery", "vein", "heart", "lung", "liver", "kidney",
    "نبضات", "قلب", "دواء", "علاج", "مريض", "تشخيص", "أعراض"
]

# ══════════════════════════════════════════════════════════════════════════════
# STRUCTURE DETECTION PATTERNS
# ══════════════════════════════════════════════════════════════════════════════
CHAPTER_PATTERNS = [
    r'^chapter\s+(\d+[\.\d]*)\s*[:\-–]?\s*(.+)$',
    r'^(\d+)\s*[:\-–]\s*(.{5,})$',
    r'^unit\s+(\d+[\.\d]*)\s*[:\-–]?\s*(.+)$',
    r'^section\s+(\d+[\.\d]*)\s*[:\-–]?\s*(.+)$',
    r'^الفصل\s+(\S+)\s*[:\-–]?\s*(.*)$',
    r'^الباب\s+(\S+)\s*[:\-–]?\s*(.*)$',
    r'^وحدة\s+(\S+)\s*[:\-–]?\s*(.*)$',
]

MINI_CHAPTER_PATTERNS = [
    r'^(\d+\.\d+)\s*[:\-–]?\s*(.+)$',
    r'^[a-z]\)\s+(.+)$',
    r'^[ivxlcdm]+\.\s+(.+)$',
]

LESSON_PATTERNS = [
    r'^(\d+\.\d+\.\d+)\s*[:\-–]?\s*(.+)$',
    r'^lesson\s+(\d+[\.\d]*)\s*[:\-–]?\s*(.+)$',
    r'^topic\s+(\d+[\.\d]*)\s*[:\-–]?\s*(.+)$',
    r'^الدرس\s+(\S+)\s*[:\-–]?\s*(.*)$',
]

# ══════════════════════════════════════════════════════════════════════════════
# HELPERS
# ══════════════════════════════════════════════════════════════════════════════

def read_txt(filepath: str) -> str:
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        return f.read()


def read_pdf(filepath: str) -> str:
    if not PDF_SUPPORT:
        print(f"  [!] pdfplumber not installed — skipping PDF: {os.path.basename(filepath)}")
        print(f"      Run:  pip install pdfplumber")
        return ""
    text = []
    with pdfplumber.open(filepath) as pdf:
        for page in pdf.pages:
            t = page.extract_text()
            if t:
                text.append(t)
    return "\n".join(text)


def detect_route(text: str) -> str:
    text_lower = text.lower()
    hits = sum(1 for kw in MEDICAL_KEYWORDS if kw in text_lower)
    return "Medical" if hits >= 3 else "General"


def match_any(patterns, line: str):
    """Returns (number, title) or None."""
    line = line.strip()
    for pat in patterns:
        m = re.match(pat, line, re.IGNORECASE)
        if m:
            groups = m.groups()
            if len(groups) == 2:
                return groups[0].strip(), groups[1].strip()
            elif len(groups) == 1:
                return "1", groups[0].strip()
    return None


def slugify(text: str, max_len: int = 60) -> str:
    text = re.sub(r'[^\w\s\u0600-\u06FF]', '', text)
    text = re.sub(r'\s+', '_', text.strip())
    return text[:max_len]


def generate_view_name(title: str, index: int) -> str:
    """Generate a descriptive view name from the lesson/section title."""
    words = re.findall(r'[A-Za-z\u0600-\u06FF]+', title)
    keyword = '_'.join(w.capitalize() for w in words[:3]) if words else f"Topic{index}"
    return keyword

# ══════════════════════════════════════════════════════════════════════════════
# STRUCTURE PARSER
# ══════════════════════════════════════════════════════════════════════════════

def parse_structure(text: str, filename: str) -> dict:
    lines = text.splitlines()
    chapters = []
    current_chapter = None
    current_mini = None
    current_lesson = None
    lesson_counter = 0

    def flush_lesson():
        nonlocal current_lesson
        if current_lesson and current_lesson.get("content_lines"):
            current_lesson["content"] = "\n".join(current_lesson["content_lines"]).strip()
            del current_lesson["content_lines"]
        current_lesson = None

    def flush_mini():
        nonlocal current_mini
        flush_lesson()
        # If mini chapter has no lessons, treat it as a lesson
        if current_mini and not current_mini.get("lessons"):
            current_mini["treated_as_lesson"] = True
            current_mini["content"] = current_mini.get("_buffer", "").strip()
        if "_buffer" in (current_mini or {}):
            del current_mini["_buffer"]
        current_mini = None

    def flush_chapter():
        nonlocal current_chapter
        flush_mini()
        if current_chapter:
            chapters.append(current_chapter)
        current_chapter = None

    for line in lines:
        stripped = line.strip()
        if not stripped:
            # accumulate empty lines in content
            if current_lesson:
                current_lesson["content_lines"].append("")
            elif current_mini:
                current_mini["_buffer"] = current_mini.get("_buffer", "") + "\n"
            continue

        # Try Chapter
        m = match_any(CHAPTER_PATTERNS, stripped)
        if m and len(stripped) < 120:
            flush_chapter()
            current_chapter = {
                "chapter": m[0],
                "title": m[1],
                "mini_chapters": []
            }
            continue

        # Try Mini Chapter
        m = match_any(MINI_CHAPTER_PATTERNS, stripped)
        if m and current_chapter is not None and len(stripped) < 120:
            flush_mini()
            current_mini = {
                "mini_chapter": m[0],
                "title": m[1],
                "lessons": [],
                "_buffer": ""
            }
            current_chapter["mini_chapters"].append(current_mini)
            continue

        # Try Lesson
        m = match_any(LESSON_PATTERNS, stripped)
        if m and len(stripped) < 120:
            flush_lesson()
            lesson_counter += 1
            current_lesson = {
                "lesson": m[0],
                "title": m[1],
                "view_name": generate_view_name(m[1], lesson_counter),
                "content_lines": []
            }
            if current_mini is not None:
                current_mini["lessons"].append(current_lesson)
            elif current_chapter is not None:
                if "lessons" not in current_chapter:
                    current_chapter["lessons"] = []
                current_chapter["lessons"].append(current_lesson)
            continue

        # Plain content line
        if current_lesson:
            current_lesson["content_lines"].append(stripped)
        elif current_mini:
            current_mini["_buffer"] = current_mini.get("_buffer", "") + stripped + "\n"

    flush_chapter()

    # Fallback: if no structure detected, treat entire file as one lesson
    if not chapters:
        lesson_counter += 1
        chapters = [{
            "chapter": "1",
            "title": os.path.splitext(filename)[0],
            "mini_chapters": [],
            "lessons": [{
                "lesson": "1",
                "title": os.path.splitext(filename)[0],
                "view_name": generate_view_name(filename, 1),
                "content": text[:50000]  # cap at 50k chars for safety
            }]
        }]

    return {"file": filename, "chapters": chapters}


# ══════════════════════════════════════════════════════════════════════════════
# COUNT TOTAL LESSONS
# ══════════════════════════════════════════════════════════════════════════════

def count_lessons(material: dict) -> int:
    total = 0
    for ch in material.get("chapters", []):
        for mini in ch.get("mini_chapters", []):
            if mini.get("treated_as_lesson"):
                total += 1
            else:
                total += len(mini.get("lessons", []))
        total += len(ch.get("lessons", []))
    return total


# ══════════════════════════════════════════════════════════════════════════════
# MAIN
# ══════════════════════════════════════════════════════════════════════════════

def main():
    if len(sys.argv) < 2:
        print("Usage: python preprocessor.py \"path\\to\\user-folder\"")
        sys.exit(1)

    folder = sys.argv[1]

    if not os.path.isdir(folder):
        print(f"[ERROR] Folder not found: {folder}")
        sys.exit(1)

    user_name = os.path.basename(folder.rstrip("/\\"))
    print(f"\n{'═'*60}")
    print(f"  PREPROCESSOR — User: {user_name}")
    print(f"  Folder: {folder}")
    print(f"{'═'*60}\n")

    # ── Collect files ──────────────────────────────────────────
    supported = ('.txt', '.pdf')
    files = [f for f in os.listdir(folder)
             if f.lower().endswith(supported) and not f.startswith('manifest')]

    if not files:
        print("[ERROR] No TXT or PDF files found in the folder.")
        sys.exit(1)

    print(f"  Found {len(files)} file(s): {', '.join(files)}\n")

    # ── Process each file ──────────────────────────────────────
    materials = []
    all_text = ""

    for filename in files:
        filepath = os.path.join(folder, filename)
        print(f"  ▸ Reading: {filename}")

        if filename.lower().endswith('.pdf'):
            text = read_pdf(filepath)
        else:
            text = read_txt(filepath)

        if not text.strip():
            print(f"    [!] Empty or unreadable — skipped.")
            continue

        all_text += text
        material = parse_structure(text, filename)
        lesson_count = count_lessons(material)
        material["lesson_count"] = lesson_count
        materials.append(material)
        print(f"    ✅ Parsed — {lesson_count} lesson(s) found")

    # ── Route detection ────────────────────────────────────────
    route = detect_route(all_text)
    total_lessons = sum(m["lesson_count"] for m in materials)

    print(f"\n  Route Detected : {route}")
    print(f"  Total Lessons  : {total_lessons}")

    # ── Build manifest ─────────────────────────────────────────
    manifest = {
        "user": user_name,
        "route": route,
        "total_lessons": total_lessons,
        "output_path": os.path.join(
            "D:\\projects\\laravel_projects\\college_project\\Interactive-Seens-Material",
            user_name
        ),
        "materials": materials
    }

    # ── Save manifest ──────────────────────────────────────────
    manifest_path = os.path.join(folder, "manifest.json")
    with open(manifest_path, 'w', encoding='utf-8') as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2)

    print(f"\n{'═'*60}")
    print(f"  ✅ manifest.json saved to:")
    print(f"     {manifest_path}")
    print(f"{'═'*60}\n")
    print(f"  Next step:")
    print(f"  Tell the AI: \"Read CMD-01 and apply it on the manifest.json")
    print(f"  located at: {manifest_path}\"")
    print(f"\n{'═'*60}\n")


if __name__ == "__main__":
    main()
