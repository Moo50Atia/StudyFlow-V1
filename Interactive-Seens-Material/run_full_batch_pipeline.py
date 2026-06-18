#!/usr/bin/env python
"""
StudyFlow Batch Pipeline Orchestrator & HTML Generator
------------------------------------------------------
Processes folders containing lectures/PDFs sequentially.
Runs the 12-stage pipeline to extract structured concepts, questions,
and dynamic view prompts, then automatically calls the AI to generate
standalone, interactive, beautiful educational HTML visual scenes.
"""

import os
import sys
import json
import logging
import re
import shutil
from pathlib import Path
from typing import Optional, List, Dict

# Force UTF-8 encoding for stdout/stderr
sys.stdout.reconfigure(encoding='utf-8')

# Add the project root to sys.path to allow imports from Generating
project_root = Path(__file__).parent.resolve()
sys.path.append(str(project_root))

# Disable HITL Stage 5 Pause to ensure fully automated run
os.environ["STUDYFLOW_HITL_STAGE_5"] = "false"

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger("batch_orchestrator")

try:
    import click
except ImportError:
    print("[ERROR] Click package is not installed. Please run: pip install click")
    sys.exit(1)

# Import StudyFlow Modules
try:
    from Generating.pipeline import Pipeline, STAGES
    from Generating.AI.client import AIClient
    from Generating.config import MATERIALS_PATH
except ImportError as e:
    print(f"[ERROR] Failed to import StudyFlow Generating module. Error: {e}")
    sys.exit(1)


# ── HTML Generation System Prompt ─────────────────────────────────────────────

HTML_GEN_SYSTEM_PROMPT = """You are an expert Educational Interactive Web Developer and Frontend UI/UX Designer.
Your task is to generate a single, 100% standalone, fully interactive HTML file that visualizes the educational concept described in the Dynamic View Prompt.
The page MUST look highly professional, modern, and beautiful, creating a premium "wow" factor for students.

Follow these strict design and implementation rules:

1. Styling & Theme (Dark Academic Mode):
   - Background color: #0d1117 (dark slate-grey/blue).
   - Text color: #c9d1d9.
   - Primary Accent color: #58a6ff (bright active blue).
   - Secondary accents: #3fb950 (success/active), #ff7b72 (danger/receptors/inflammation), #d2a8ff (chemical/pharmacological).
   - Load modern fonts (e.g., 'Inter' or 'Outfit') from Google Fonts. Do NOT use default serif/sans-serif.
   - Use premium glassmorphism panels (background: rgba(22, 27, 34, 0.75), border: 1px solid rgba(48, 54, 61, 0.8), backdrop-filter: blur(12px), box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4)).

2. Structure & Layout:
   - Centered container with a clean academic header showing the Concept Title.
   - Narrator Panel: A visible, styled text paragraph below the title (e.g., color: #94a3b8) that dynamically updates to explain the active step/state of the visualization. Set a fixed height to prevent layout shifts.
   - Central Interactive Workspace: A box where the visualization occurs. Use absolute/relative positioned divs, canvas, or inline SVGs with glowing effects (box-shadow or SVG filter glow) to represent particles, flows, receptors, curves, structures, or logic gates.
   - Controls Panel: Interactive sliders, check-boxes, play/pause buttons. Sliders must update the visualization values and state in real-time.
   - If Medical (Multi-Perspective): You MUST create a "View Mode Toggle" showing distinct perspective buttons (e.g., "Mechanical/Hemodynamic", "Chemical/Pharmacological", "Diagnostic/Imaging", "Interventional"). Clicking these buttons must completely change the visual context, objects, colors, and narrator explanation of the active workspace.
   - Collapsible Egyptian Arabic Panel: At the bottom, add a collapsible panel (<details> styled nicely or a custom button-controlled collapsible div) titled "الشرح بالعامية المصرية" (Explanation in Egyptian Dialect). Inside, put the provided Egyptian dialect summary to simplify complex medical/scientific mechanics.

3. Code & Animation Mechanics:
   - Write clean, modular, vanilla JavaScript (embedded in the script tag). No external scripts or libraries (except maybe simple SVG/canvas drawing or lightweight CDNs if absolutely necessary, but vanilla JS is strongly preferred).
   - Sequential, staged animations: Use an async/await simulation function with a sleep helper (const sleep = ms => new Promise(r => setTimeout(r, ms))) to play animations in labeled stages.
   - Double-click protection: Use an isRunning guard variable to disable start buttons while animations are running.
   - Full Reset & Replay: Implement a dedicated "Reset/Replay" button. After the animation finishes, update the start button to say "Replay Simulation". The reset logic MUST cleanly restore ALL visual states (positions, classes, styles, overlays, opacity) to their exact original values.
   - Value Labels: Any slider must have a live numeric or text label that updates instantly as the user drags it.

4. Content Integrity:
   - Copy any clinical/scientific numbers, criteria, ECG findings, or doses EXACTLY. Never paraphrase clinical facts or numbers.
   - Ensure the final animation state visually resolves the core misconception and demonstrates the learning outcome.

Output ONLY the clean, functional HTML code. Do NOT wrap it in any markdown code fences in your final text, just output the raw HTML, OR if required by formatting, use simple ```html blocks. Keep comments in JS clear for debugging.
"""


class BatchProcessor:
    """
    Manages batch execution of the StudyFlow pipeline on folders of PDFs
    and handles automatic compilation of generated prompts into interactive HTML files.
    """

    def __init__(
        self,
        api_key: Optional[str] = None,
        ai_provider: str = "gemini",
        ai_model: str = "gemini-2.5-flash",
        output_base: Optional[Path] = None,
    ):
        self.api_key = api_key or os.environ.get("STUDYFLOW_AI_API_KEY", "")
        self.ai_provider = ai_provider
        self.ai_model = ai_model
        self.output_base = Path(output_base or project_root)
        
        # Verify key exists
        if not self.api_key:
            raise ValueError(
                "API Key is missing. Set the STUDYFLOW_AI_API_KEY environment variable or pass --api-key."
            )

        # Lazy-initialized AI client for HTML generation phase
        self._ai_client = None

    @property
    def ai_client(self) -> AIClient:
        """Lazy-initialize the AIClient."""
        if self._ai_client is None:
            self._ai_client = AIClient(
                provider=self.ai_provider,
                api_key=self.api_key,
                model=self.ai_model,
            )
        return self._ai_client

    def sanitize_name(self, filename: str) -> str:
        """Create a clean, alphanumeric material name from a filename."""
        name = Path(filename).stem
        # Remove spaces and non-alphanumeric chars
        name = re.sub(r'[^a-zA-Z0-9_\-]', '', name)
        # Ensure it starts with a letter
        if not re.match(r'^[a-zA-Z]', name):
            name = "Material_" + name
        return name

    def process_folder(self, input_folder_path: str):
        """Scan folder for PDFs and process them sequentially."""
        input_path = Path(input_folder_path)
        if not input_path.exists():
            logger.error(f"Input path does not exist: {input_folder_path}")
            sys.exit(1)

        pdf_files = []
        if input_path.is_file():
            if input_path.suffix.lower() == ".pdf":
                pdf_files.append(input_path)
            else:
                logger.error(f"Selected file is not a PDF: {input_path}")
                sys.exit(1)
        else:
            pdf_files = sorted([p for p in input_path.glob("*.pdf")])
            if not pdf_files:
                logger.warning(f"No PDF files found in folder: {input_folder_path}")
                return

        # Determine input folder name for output structuring
        input_folder_name = input_path.name if input_path.is_dir() else input_path.parent.name
        
        logger.info(f"==================================================")
        logger.info(f" Found {len(pdf_files)} PDF(s) to process in '{input_folder_name}'")
        logger.info(f" Output base directory: {self.output_base}")
        logger.info(f"==================================================\n")

        summary = []

        for idx, pdf in enumerate(pdf_files, 1):
            material_name = self.sanitize_name(pdf.name)
            logger.info(f"--- [{idx}/{len(pdf_files)}] Starting Lecture: {pdf.name} (Material Name: {material_name}) ---")
            
            # Setup output folder for this PDF: [OutputBase]/[InputFolderName]/[MaterialName]/
            lecture_output_dir = self.output_base / input_folder_name / material_name
            lecture_output_dir.mkdir(parents=True, exist_ok=True)

            try:
                # 1. Run the 12-stage pipeline
                logger.info(f"Step 1: Running the 12-stage metadata extraction pipeline...")
                pipeline = Pipeline(
                    material_name=material_name,
                    api_key=self.api_key,
                    ai_provider=self.ai_provider,
                    ai_model=self.ai_model,
                )
                pipeline.run_full(pdf_path=str(pdf))

                # 2. Automatically generate the interactive HTML pages
                logger.info(f"Step 2: Commencing interactive HTML generation...")
                generated_files = self.generate_html_pages(
                    material_name=material_name,
                    output_dir=lecture_output_dir
                )

                summary.append({
                    "pdf": pdf.name,
                    "material": material_name,
                    "status": "SUCCESS",
                    "files_generated": len(generated_files),
                    "error": None
                })
                logger.info(f"✓ Lecture {pdf.name} completed successfully. Generated {len(generated_files)} HTML files.\n")

            except Exception as e:
                logger.error(f"✗ Lecture {pdf.name} failed during processing: {e}")
                summary.append({
                    "pdf": pdf.name,
                    "material": material_name,
                    "status": "FAILED",
                    "files_generated": 0,
                    "error": str(e)
                })
                logger.info(f"Continuing to next lecture...\n")

        # Print final report
        self.print_summary_report(summary)

    def generate_html_pages(self, material_name: str, output_dir: Path) -> List[str]:
        """Reads pipeline JSON outputs and generates standalone HTML files via AIClient."""
        material_dir = MATERIALS_PATH / material_name
        prompts_file = material_dir / "dynamic_view_prompts.json"
        sections_file = material_dir / "sections.json"

        if not prompts_file.exists():
            logger.error(f"Missing dynamic_view_prompts.json in {material_dir}. Stage 12 must complete.")
            raise FileNotFoundError(f"Missing dynamic_view_prompts.json in {material_dir}")

        with open(prompts_file, "r", encoding="utf-8") as f:
            prompts_data = json.load(f)

        route = prompts_data.get("route", "General")
        prompt_list = prompts_data.get("prompts", [])

        if not prompt_list:
            logger.warning(f"No view prompts found in dynamic_view_prompts.json for {material_name}")
            return []

        # Build sections lookup for Arabic explanation and other metadata
        sections_lookup = {}
        if sections_file.exists():
            try:
                with open(sections_file, "r", encoding="utf-8") as f:
                    sec_data = json.load(f)
                for lesson in sec_data.get("lessons", []):
                    for section in lesson.get("sections", []):
                        sec_id = section.get("section_id")
                        if sec_id:
                            sections_lookup[sec_id] = section
            except Exception as e:
                logger.warning(f"Failed to load sections.json for detailed lookup: {e}")

        generated_files = []

        for p_idx, prompt_item in enumerate(prompt_list, 1):
            section_id = prompt_item.get("section_id", "")
            view_name = prompt_item.get("view_name", f"View_{p_idx}")
            scene_type = prompt_item.get("scene_type", "Process")
            prompt_text = prompt_item.get("prompt", "")
            file_name = prompt_item.get("file_name", f"{material_name}_L{p_idx}_{view_name}.html")

            # Extract Arabic explanation and title from sections
            section_data = sections_lookup.get(section_id, {})
            content_data = section_data.get("content", {})
            
            arabic_explanation = content_data.get("arabic_explanation", "لا يوجد شرح بالعامية المصرية متوفر حالياً.")
            concept_title = section_data.get("title", view_name.replace("_", " "))

            logger.info(f"  ▸ Generating HTML [{p_idx}/{len(prompt_list)}]: {file_name}")

            # Construct details prompt for AI
            user_prompt = f"""Generate the interactive educational HTML file.
            
CONCEPT TITLE: {concept_title}
ROUTE: {route}
SCENE TYPE: {scene_type}
DYNAMIC VIEW PROMPT DESCRIPTION:
{prompt_text}

EGYPTIAN ARABIC EXPLANATION (Place inside the collapsible panel at the bottom):
{arabic_explanation}

EXPECTED OUTPUT FILENAME: {file_name}
"""

            try:
                # Call Gemini / OpenAI to generate HTML content
                response = self.ai_client.generate(user_prompt, system_prompt=HTML_GEN_SYSTEM_PROMPT)

                # Clean response (remove markdown code fences if present)
                html_content = response.strip()
                if html_content.startswith("```html"):
                    html_content = html_content[7:]
                elif html_content.startswith("```"):
                    html_content = html_content[3:]
                if html_content.endswith("```"):
                    html_content = html_content[:-3]
                html_content = html_content.strip()

                # Save HTML file to output folder
                output_file_path = output_dir / file_name
                with open(output_file_path, "w", encoding="utf-8") as out_f:
                    out_f.write(html_content)

                generated_files.append(str(output_file_path))
                logger.info(f"    ✓ Saved: {file_name}")

            except Exception as e:
                logger.error(f"    ✗ Failed to generate HTML for {file_name}: {e}")

        return generated_files

    def print_summary_report(self, summary: List[Dict]):
        """Prints a final summary table of the batch operation."""
        logger.info(f"\n==================================================")
        logger.info(f"             BATCH EXECUTION SUMMARY              ")
        logger.info(f"==================================================")
        
        success_count = sum(1 for item in summary if item["status"] == "SUCCESS")
        fail_count = sum(1 for item in summary if item["status"] == "FAILED")
        total_files = sum(item["files_generated"] for item in summary)

        for item in summary:
            status_symbol = "✅" if item["status"] == "SUCCESS" else "❌"
            logger.info(f" {status_symbol} {item['pdf']} -> {item['status']} ({item['files_generated']} HTML file(s) generated)")
            if item["error"]:
                logger.info(f"    Error: {item['error']}")

        logger.info(f"--------------------------------------------------")
        logger.info(f" Success lectures : {success_count}")
        logger.info(f" Failed lectures  : {fail_count}")
        logger.info(f" Total HTML files : {total_files}")
        
        # Estimate API costs
        usage = self.ai_client.get_usage()
        logger.info(f" Estimated API Usage & Costs:")
        logger.info(f"   - Total Prompt Tokens     : {usage['total_prompt_tokens']}")
        logger.info(f"   - Total Completion Tokens : {usage['total_completion_tokens']}")
        logger.info(f"   - Estimated cost (USD)    : ${usage['estimated_cost_usd']}")
        logger.info(f"==================================================")


# ── Click Command Line Interface ──────────────────────────────────────────────

@click.command()
@click.option(
    "--input", "-i", "input_path",
    required=True,
    help="Path to the PDF file or folder containing lectures/PDFs."
)
@click.option(
    "--output-base", "-o", "output_base",
    default=str(project_root),
    help="Base output directory where the folder structure will be generated."
)
@click.option(
    "--api-key",
    envvar="STUDYFLOW_AI_API_KEY",
    help="Google Gemini / OpenAI API key."
)
@click.option(
    "--provider",
    default="gemini",
    show_default=True,
    help="AI provider ('gemini' or 'openai')."
)
@click.option(
    "--model",
    default="gemini-2.5-flash",
    show_default=True,
    help="AI model name to use."
)
def main(input_path, output_base, api_key, provider, model):
    """StudyFlow Batch Process CLI."""
    try:
        processor = BatchProcessor(
            api_key=api_key,
            ai_provider=provider,
            ai_model=model,
            output_base=Path(output_base)
        )
        processor.process_folder(input_path)
    except Exception as e:
        logger.error(f"Initialization or processing failed: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
