"""
Structure Prompts — Prompt templates for AI-powered structure extraction.

These prompts replace the hardcoded chapter lists in the legacy scripts.
The AI infers document structure from the text itself.
"""


SYSTEM_PROMPT = """You are a Data Architect specializing in educational content structuring.
Your task is to analyze educational text and produce a precise structural hierarchy.
You NEVER summarize content. You ONLY map structure.
You output ONLY valid JSON — no explanations, no markdown."""


def get_structure_extraction_prompt(
    text_chunk: str,
    route: str = "General",
    domain: str = "",
    chunk_info: str = "",
) -> str:
    """
    Generate the prompt for extracting document structure from a text chunk.

    Args:
        text_chunk: The text to analyze.
        route: The detected route (Medical, Engineering, etc.).
        domain: The specific domain if known.
        chunk_info: Information about which chunk this is (e.g., "Chunk 2 of 5").

    Returns:
        Complete prompt string for the AI.
    """
    domain_context = f" in the domain of {domain}" if domain else ""

    return f"""Analyze the following {route} educational text{domain_context} and extract its structural hierarchy.
{f"Note: This is {chunk_info}. Extract structure for the content present in this chunk." if chunk_info else ""}

RULES:
1. Map the text into this strict hierarchy:
   Chapter → Mini Chapter → Lesson

2. A Chapter is a major top-level division of the material.
3. A Mini Chapter is a sub-division within a Chapter (sub-topic, sub-section).
4. A Lesson is the atomic learning unit within a Mini Chapter.
5. If a Mini Chapter has no sub-lessons, treat the Mini Chapter itself as a Lesson.
6. If the material has no clear chapters, create logical groupings based on topic shifts.
7. Every Lesson MUST include page_start and page_end referencing the source PDF pages.
8. Generate a descriptive view_name for each Lesson in the format: [Topic]_[VisualType]
   Examples: BloodFlow_FlowSim, FlipFlop_StateDiagram, Integration_StepProcess

OUTPUT FORMAT — Valid JSON matching this structure:
{{
    "chapters": [
        {{
            "id": "ch1",
            "title": "Chapter Title",
            "mini_chapters": [
                {{
                    "id": "mc1.1",
                    "title": "Mini Chapter Title",
                    "lessons": [
                        {{
                            "id": "L1",
                            "title": "Lesson Title",
                            "view_name": "Topic_VisualType",
                            "page_start": 1,
                            "page_end": 5,
                            "content_ref": {{
                                "sections_in_source": ["Section heading 1", "Section heading 2"]
                            }}
                        }}
                    ]
                }}
            ]
        }}
    ]
}}

TEXT TO ANALYZE:
{text_chunk}"""


def get_structure_merge_prompt(
    chunk_structures: list[dict],
    total_chunks: int,
) -> str:
    """
    Generate the prompt for merging structures from multiple chunks.

    When a document is split into multiple chunks, each chunk produces
    a partial structure. This prompt asks the AI to merge them into
    a single coherent hierarchy.
    """
    import json
    chunks_json = json.dumps(chunk_structures, indent=2, ensure_ascii=False)

    return f"""You are merging {total_chunks} partial structure extractions from a single educational document.

Each partial structure was extracted from a different chunk of the same document.
Merge them into ONE coherent hierarchy following these rules:

1. Combine chapters that span multiple chunks (same chapter appearing in chunk N and chunk N+1).
2. Maintain the correct sequential order.
3. Remove exact duplicates (lessons appearing in overlapping regions).
4. Preserve ALL unique lessons — do not drop any.
5. Update IDs to be globally sequential (ch1, ch2... L1, L2...).
6. Maintain page_start and page_end references from the originals.

PARTIAL STRUCTURES TO MERGE:
{chunks_json}

OUTPUT: A single merged JSON structure with the same format as the inputs.
Output ONLY valid JSON."""


def get_material_config_prompt(
    text_sample: str,
    route: str,
    domain: str,
) -> str:
    """
    Generate the prompt for creating a material_config.json.

    This replaces the concept of generated parser scripts with
    a configuration-over-code approach.
    """
    return f"""Analyze this educational text and determine the document's formatting patterns.

Detected Route: {route}
Detected Domain: {domain}

Determine:
1. The primary language of the text.
2. How chapters are marked (e.g., "Chapter X", "Unit X", numbered sections like "1.", etc.)
3. How sub-sections are marked (if any pattern exists).
4. How individual lessons/topics are marked (if any pattern exists).
5. Whether the document has a Table of Contents, and roughly which pages it spans.
6. Any special handling notes (e.g., "contains Arabic annotations", "uses Roman numeral chapters").

OUTPUT — Valid JSON:
{{
    "language": "English",
    "chapter_pattern": "Chapter",
    "mini_chapter_pattern": "",
    "lesson_pattern": "",
    "has_toc": true,
    "toc_page_range": [3, 8],
    "special_handling": {{}}
}}

TEXT SAMPLE:
{text_sample[:3000]}"""
