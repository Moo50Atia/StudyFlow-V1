"""
pdf_to_txt.py
-------------
Usage:
    python pdf_to_txt.py "path/to/user-folder"

What it does:
    - Converts every PDF in the folder to a TXT file
    - Saves the TXT in the same folder
    - Skips files already converted
    - Reports route: Medical or General
"""

import sys
import os

sys.stdout.reconfigure(encoding='utf-8')

try:
    import pdfplumber
except ImportError:
    print("[ERROR] pdfplumber not installed.")
    print("Run: pip install pdfplumber")
    sys.exit(1)

# ── Medical keywords for route detection ──────────────────────
MEDICAL_KEYWORDS = [
    "pathophysiology", "diagnosis", "treatment", "clinical", "patient",
    "symptom", "disease", "drug", "dose", "therapy", "cardiac", "ecg",
    "pharmacology", "anatomy", "physiology", "syndrome", "infection",
    "inflammation", "surgery", "mechanism", "receptor", "enzyme",
    "blood", "artery", "vein", "heart", "lung", "liver", "kidney",
    "نبضات", "قلب", "دواء", "علاج", "مريض", "تشخيص", "أعراض"
]

def pdf_to_txt(pdf_path: str, txt_path: str) -> str:
    """Convert PDF to TXT and return extracted text."""
    pages_text = []
    with pdfplumber.open(pdf_path) as pdf:
        total = len(pdf.pages)
        for i, page in enumerate(pdf.pages):
            t = page.extract_text()
            if t:
                pages_text.append(t)
            # Progress
            print(f"    Page {i+1}/{total}", end='\r')
    
    full_text = "\n\n".join(pages_text)
    
    with open(txt_path, 'w', encoding='utf-8') as f:
        f.write(full_text)
    
    return full_text

def detect_route(all_text: str) -> str:
    text_lower = all_text.lower()
    hits = sum(1 for kw in MEDICAL_KEYWORDS if kw in text_lower)
    return "Medical" if hits >= 3 else "General"

def main():
    if len(sys.argv) < 2:
        print("Usage: python pdf_to_txt.py \"path/to/user-folder\"")
        sys.exit(1)

    folder = sys.argv[1]

    if not os.path.isdir(folder):
        print(f"[ERROR] Folder not found: {folder}")
        sys.exit(1)

    user_name = os.path.basename(folder.rstrip("/\\"))
    print(f"\n{'═'*60}")
    print(f"  PDF → TXT CONVERTER")
    print(f"  User   : {user_name}")
    print(f"  Folder : {folder}")
    print(f"{'═'*60}\n")

    # ── Find all PDFs ──────────────────────────────────────────
    pdfs = [f for f in os.listdir(folder) if f.lower().endswith('.pdf')]

    if not pdfs:
        print("[!] No PDF files found in folder.")
        sys.exit(0)

    print(f"  Found {len(pdfs)} PDF(s)\n")

    all_text = ""
    converted = []
    skipped = []

    for filename in pdfs:
        pdf_path = os.path.join(folder, filename)
        txt_name = os.path.splitext(filename)[0] + ".txt"
        txt_path = os.path.join(folder, txt_name)

        # Skip if already converted
        if os.path.exists(txt_path):
            print(f"  ⏭  Skipped (already exists): {txt_name}")
            with open(txt_path, 'r', encoding='utf-8', errors='ignore') as f:
                all_text += f.read()
            skipped.append(txt_name)
            continue

        print(f"  ▸ Converting: {filename}")
        try:
            text = pdf_to_txt(pdf_path, txt_path)
            all_text += text
            size_kb = os.path.getsize(txt_path) // 1024
            print(f"\n    ✅ Saved: {txt_name} ({size_kb} KB)")
            converted.append(txt_name)
        except Exception as e:
            print(f"\n    [ERROR] Failed: {e}")

    # ── Route detection ────────────────────────────────────────
    route = detect_route(all_text)

    # ── Summary ────────────────────────────────────────────────
    print(f"\n{'═'*60}")
    print(f"  DONE")
    print(f"  Converted : {len(converted)} file(s)")
    print(f"  Skipped   : {len(skipped)} file(s)")
    print(f"  Route     : {route}")
    print(f"{'═'*60}")
    print(f"\n  Next step — tell the AI:")
    print(f"  \"Read CMD-01 and apply it on the TXT files in:")
    print(f"   {folder}\"")
    print(f"   Route is: {route}")
    print(f"\n{'═'*60}\n")

if __name__ == "__main__":
    main()
