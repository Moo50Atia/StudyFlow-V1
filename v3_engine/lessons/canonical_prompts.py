import hashlib
from typing import Dict

DIVIDE_PROMPT = """First, I want you to act as a Data Architect. Your task is to structurally divide the provided textbook into a strict hierarchy.
Do NOT summarize or explain the content. ONLY map out the structure.
Ensure every piece of information is logically connected without losing any data points.

Follow this strict hierarchical mapping:
Chapter =>
Mini Chapter (if applicable) =>
Lesson (if applicable)

Output Format: Provide ONLY the structural map. Do NOT output any actual educational data or explanations from the lessons."""

EXECUTION_PROMPT = """==================================================
EXECUTION PROTOCOL (LESSON BY LESSON)
You have successfully mapped the document structure. Now, we will process the content precisely.

1. I have provided the Advanced  3-Step Framework (First, Second, Third) above.
2. You will strictly apply these 3 steps ONLY to the content of the very first "Lesson" in your structural map. Do NOT read ahead or summarize other lessons.
3. Once you generate the final Dynamic View prompt for this Lesson, STOP completely.
4. End your response with: "✅ Lesson [X] Completed. Awaiting 'Continue' for the next lesson."
5. Whenever I type ONLY the word "Continue", you will automatically move to the next Lesson in your map and apply the exact same 3 steps.
6. Note that if you didn't find a lesson in the mini Chapter Treat The Mini  Chapter as a lesson 
7. you will find the steps in file “steps.txt” 

After the Third Step If you understand this strict iterative workflow, reply ONLY with: "System Ready"""

FIRST_PROMPT = """You are an educational content architect and curriculum structurer.

You will receive ONE university-level Lecture (notes, PDF, or text).

Your task is NOT summarization.
Your task is to decompose and restructure the Lecture
for deep conceptual understanding and student-friendly learning.

# ==================================================
FOR THIS SINGLE LECTURE

1. Identify all implicit and explicit Sections.
2. Infer logical Sections if headings are missing.
3. Treat each Section as an independent learning unit.

# ==================================================
FOR EACH SECTION, GENERATE EXACTLY:

- Section Identity
- Core Understanding
- Visual-First Explanation
- Visual Scene Script
- Dynamic View Blueprint
- Visual Learning Outcome
- Concept Check
- Conceptual Summary
- • شرح للجزء بالعامية المصرية
- القوانين (إن وُجدت)
- إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم

# ==================================================
RULES

- This is a SINGLE Lecture, not a full course.
- Do NOT add exam-style questions.
- Do NOT reference Dynamic View engines explicitly.
- Focus on clarity, intuition, and visualization readiness.
- Keep explanations student-oriented and simple.

# ==================================================
OUTPUT STRUCTURE

Lecture: <Lecture Name>

Section 1:
<Full Section Content>

Section 2:
<Full Section Content>

(and so on)"""

SECOND_PROMPT = """You are a Dynamic View prompt preparation engine.

You will receive ONE Lecture that is already structured
into multiple Sections with rich educational content.

# ==================================================
YOUR TASK

Prepare each Section for automatic conversion
into Google Dynamic View scenes.

You MUST NOT:

- Change any educational content
- Rephrase explanations
- Merge or split Sections
- Add or remove concepts

# ==================================================
PROCESSING GOAL (INTERNAL ONLY)

For EACH Section, internally determine:

- Scene Type
- Visual Objects
- Animations
- Overlays
- User Interactions
- Visual Learning Goal

DO NOT explain your reasoning.
DO NOT generate the final prompts yet.

# ==================================================
OUTPUT STRUCTURE (STRICT)

Lecture: <Lecture Name>

Lec<X> (Sec<Y>):
"<Dynamic View Prompt Placeholder>\""""

THIRD_PROMPT = """Now generate the final Google Dynamic View prompts
using the prepared mappings from the previous step.

# ==================================================
DYNAMIC VIEW PROMPT RULES

Each Dynamic View Prompt MUST:

1. Start with ONE Scene Type:
    - Process
    - Comparison
    - Accumulation
    - Parallel Flow
    - Time Evolution
2. Describe the scene using OBJECTS and ACTIONS only:
    - Objects: devices, signals, graphs, meters, blocks, waves
    - Actions: move, transform, accumulate, fade, split, highlight
3. Include when applicable:
    - Overlays / annotations
    - Graphs or spatial visuals
    - Cause–effect animation
    - Optional user interaction (slider, toggle, time control)
4. Encode the Visual Learning Outcome
as the final visual state of the scene.
5. Visually correct the key misconception
implied by the Section.

# ==================================================
OUTPUT FORMAT (STRICT)

Lecture: <Lecture Name>

Lec<X> (Sec<Y>):
"<Executable Google Dynamic View Prompt>"

# ==================================================
GLOBAL CONSTRAINTS

- Output ONLY the prompts
- No teaching explanations
- No repetition
- Concise, engine-oriented language"""

CANONICAL_DIGESTS: Dict[str, str] = {
    "divide": "77620579df1bb2fb51876116b1266a32fe58ecd963486c3d76d5c98c46d012ff",
    "execution": "5ab2448f34c9b2b90199347772d10d7d217ba9fd468dffc28e3169bd0a7fdce9",
    "first": "04450e9fd7e0962ceb4f60a1f641e43b671d7be2622718e202af0ca92683b788",
    "second": "a8776affc821a1b913a4685b309dc0304afc03c7389fcfe44b366b6f5683f203",
    "third": "07798d744b973ec11248897d2efe0e769ab481c4a21cce7562696dd131217955",
}

def verify_digests() -> bool:
    prompts = {
        "divide": DIVIDE_PROMPT,
        "execution": EXECUTION_PROMPT,
        "first": FIRST_PROMPT,
        "second": SECOND_PROMPT,
        "third": THIRD_PROMPT,
    }
    for key, text in prompts.items():
        computed = hashlib.sha256(text.encode("utf-8")).hexdigest()
        if computed != CANONICAL_DIGESTS[key]:
            return False
    return True

# Ensure digests are valid on import
assert verify_digests(), "Canonical prompt digests mismatch!"
