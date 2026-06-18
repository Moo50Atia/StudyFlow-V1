"""
Dynamic View Prompts — Templates for Dynamic View generation.

Implements the "Second" and "Third" steps from the route templates.
Supports both Concept Prompts and Question Prompts.
"""

from Generating.config import DV_SCENE_TYPES


DV_PREPARE_SYSTEM_PROMPT = """You are a Dynamic View prompt preparation engine.
You prepare educational sections for conversion into interactive visual scenes.
You MUST NOT change educational content, rephrase explanations, merge or split sections.
Output ONLY valid JSON."""

DV_GENERATE_SYSTEM_PROMPT = """You are a Dynamic View prompt generator.
You create executable prompts for interactive educational visualizations.
Output ONLY valid JSON."""


def get_view_mapping_prompt(
    lesson_title: str,
    sections: list[dict],
    route: str = "General",
) -> str:
    """
    Generate the prompt for mapping sections to Dynamic View specifications.

    Implements "Second Step" from the route templates.
    """
    scene_types = ", ".join(DV_SCENE_TYPES)

    sections_text = ""
    for sec in sections:
        sections_text += f"\n  Section {sec.get('section_id', '')}: {sec.get('title', '')}"
        sections_text += f"\n    Core Concept: {sec.get('core_concept', '')}"
        sections_text += f"\n    Visual Metaphor: {sec.get('visual_metaphor', '')}"

    medical_extra = ""
    if route == "Medical":
        medical_extra = """
    For Medical content, also determine:
    - Medical 3D Objects (e.g., Vessels, Myocardium, Valves)
    - Clinical Overlays (e.g., ECG traces, Blood Pressure monitors)
    - Clinical User Interactions (e.g., Administer Drug, Deploy Stent)
    - Multi-Perspective Toggle (Mechanical, Chemical, Diagnostic, Interventional)"""

    return f"""Prepare Dynamic View specifications for each Section in this Lesson.

LESSON: {lesson_title}
SECTIONS: {sections_text}

For EACH Section, determine:
- scene_type: One of [{scene_types}]
- visual_objects: List of objects in the scene
- animations: List of animations/transitions
- overlays: Text overlays, labels, annotations
- user_interactions: Interactive controls (sliders, toggles, buttons)
- visual_learning_goal: What the user should understand after interacting
{medical_extra}

Also generate a descriptive view_name for each in format: [Topic]_[VisualType]
Examples: BloodPressure_FlowSim, FlipFlop_StateDiagram, Integration_StepProcess

OUTPUT — Valid JSON:
{{
    "lesson_title": "{lesson_title}",
    "mappings": [
        {{
            "section_id": "S1",
            "view_name": "Topic_VisualType",
            "scene_type": "Process",
            "visual_objects": ["object1", "object2"],
            "animations": ["animation1"],
            "overlays": ["overlay1"],
            "user_interactions": ["slider: Parameter X", "toggle: View Mode"],
            "visual_learning_goal": "...",
            "perspectives": []
        }}
    ]
}}"""


def get_prompt_generation_prompt(
    lesson_title: str,
    mapping: dict,
    section_content: dict,
    route: str = "General",
    is_question_prompt: bool = False,
    question_data: dict = None,
) -> str:
    """
    Generate the prompt for creating the final Dynamic View prompt.

    Implements "Third Step" from the route templates.
    Supports both Concept Prompts and Question Prompts.
    """
    section_id = mapping.get("section_id", "")
    view_name = mapping.get("view_name", "")
    scene_type = mapping.get("scene_type", "Process")

    # Determine prompt type
    if is_question_prompt and question_data:
        prompt_type = "Question Dynamic View"
        extra_context = f"""
QUESTION CONTEXT:
- Question: {question_data.get('question_text', '')}
- Core Idea: {question_data.get('core_idea', '')}
- Required Components: {', '.join(question_data.get('required_components', []))}
- Thinking Type: {question_data.get('thinking_type', '')}

QUESTION DYNAMIC VIEW RULES:
- Show the final correct behavior
- Show wiring/connections
- Show signals/data flow
- Show simulation
- Show all valid input states
- Do NOT solve the question — let the student discover through interaction"""
    else:
        prompt_type = "Concept Dynamic View"
        extra_context = ""

    medical_perspective = ""
    if route == "Medical":
        perspectives = mapping.get("perspectives", [])
        if perspectives:
            medical_perspective = f"""
MULTI-PERSPECTIVE REQUIREMENTS:
This is a medical scene. Implement a "View Mode Toggle" with these perspectives:
{chr(10).join(f"  - {p}" for p in perspectives)}

Each perspective shows different visual context for the same concept:
- Mechanical/Hemodynamic: Physical structures, fluid dynamics
- Chemical/Pharmacological: Cellular/molecular level, receptors, drug molecules
- Diagnostic/Imaging: 3D anatomy → 2D clinical tests (ECG, Echo, CCTA)
- Interventional/Surgical: Procedural mechanics (PCI, CABG)"""

    return f"""Generate the final {prompt_type} prompt for this section.

LESSON: {lesson_title}
SECTION: {section_id}
VIEW NAME: {view_name}
SCENE TYPE: {scene_type}
VISUAL OBJECTS: {', '.join(mapping.get('visual_objects', []))}
INTERACTIONS: {', '.join(mapping.get('user_interactions', []))}
LEARNING GOAL: {mapping.get('visual_learning_goal', '')}
{extra_context}
{medical_perspective}

DYNAMIC VIEW PROMPT RULES:
1. Start with the Scene Type: {scene_type}
2. Describe the scene using OBJECTS and ACTIONS only
3. Include overlays, graphs, and cause-effect animations
4. Include user interactions (sliders, toggles, time controls)
5. Encode the Visual Learning Outcome as the final visual state
6. Visually correct the key misconception

OUTPUT — Valid JSON:
{{
    "section_id": "{section_id}",
    "view_name": "{view_name}",
    "scene_type": "{scene_type}",
    "prompt_type": "{prompt_type.lower().replace(' ', '_')}",
    "prompt": "Scene Setup: [3D environment description]\\nInteractions: [controls]\\nAnimation: [sequence]\\nFinal State: [learning outcome]",
    "file_name": "Material_L1-Topic_{view_name}.html"
}}"""
