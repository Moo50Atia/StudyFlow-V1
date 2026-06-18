"""
Pipeline Orchestrator — Main entry point for the Generating pipeline.

Runs all 12 stages sequentially or individually. Each stage reads the
previous stage's JSON output and produces its own JSON output.
All state is stored in Materials/[name]/ as JSON files — no conversational memory.

Usage:
    # Full pipeline
    python pipeline.py --input "path/to/file.pdf" --name "CardioLecture"

    # Single stage
    python pipeline.py --stage structure --name "CardioLecture"

    # List available stages
    python pipeline.py --list-stages
"""

import json
import logging
import shutil
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

# ── Setup logging before imports ───────────────────────────────────────────────
from Generating.config import LOG_FORMAT, LOG_LEVEL, PIPELINE_RUN_ID_PREFIX

logging.basicConfig(
    level=getattr(logging, LOG_LEVEL, logging.INFO),
    format=LOG_FORMAT,
)
logger = logging.getLogger("pipeline")


# ── Pipeline Stage Registry ───────────────────────────────────────────────────

STAGES = [
    "extract",          # 1-2: PDF Intake + Text Extraction
    "ocr",              # 3: OCR Detection & Processing
    "chunk",            # 4: Text Chunking
    "route",            # 5: Route Detection
    "structure",        # 6: Structure Extraction
    "knowledge_graph",  # 7: Knowledge Graph
    "questions",        # 8: Question Extraction
    "sections",         # 9: Section Generation
    "validate",         # 10: Validation
    "view_map",         # 11: Dynamic View Mapping
    "view_prompt",      # 12: Dynamic View Prompt Generation
    "manifest",         # Final: Manifest Update
]


class Pipeline:
    """
    Main pipeline orchestrator.

    Coordinates all 12 stages, manages state via JSON files,
    and provides independent stage execution.

    Usage:
        pipeline = Pipeline(material_name="CardioLecture")
        pipeline.run_full(pdf_path="path/to/file.pdf")
    """

    def __init__(
        self,
        material_name: str,
        api_key: Optional[str] = None,
        ai_provider: Optional[str] = None,
        ai_model: Optional[str] = None,
    ):
        from Generating.config import get_material_dir

        self.material_name = material_name
        self.material_dir = get_material_dir(material_name)
        self.run_id = f"{PIPELINE_RUN_ID_PREFIX}_{uuid.uuid4().hex[:8]}"

        # Lazy-initialized AI client
        self._ai_client = None
        self._api_key = api_key
        self._ai_provider = ai_provider
        self._ai_model = ai_model

        logger.info(f"Pipeline initialized: {material_name} (run: {self.run_id})")
        logger.info(f"Working directory: {self.material_dir}")

    @property
    def ai_client(self):
        """Lazy-initialize the AI client."""
        if self._ai_client is None:
            from Generating.AI.client import AIClient
            self._ai_client = AIClient(
                provider=self._ai_provider,
                api_key=self._api_key,
                model=self._ai_model,
            )
        return self._ai_client

    def run_full(
        self, 
        pdf_path: str, 
        skip_stages: list[str] = None,
        start_from: str = None,
        stop_after: str = None,
    ):
        """
        Run the complete pipeline from PDF to final prompts.

        Args:
            pdf_path: Path to the source PDF.
            skip_stages: List of stage names to skip.
            start_from: Stage name to start execution from.
            stop_after: Stage name to stop execution after.
        """
        skip = set(skip_stages or [])
        logger.info(f"{'═' * 60}")
        logger.info(f"  FULL PIPELINE RUN: {self.material_name}")
        logger.info(f"  PDF: {pdf_path}")
        logger.info(f"  Run ID: {self.run_id}")
        logger.info(f"{'═' * 60}")

        # Copy source PDF to material directory if we're starting at the beginning
        if start_from is None or start_from == "extract":
            pdf_dest = self.material_dir / "source.pdf"
            if not pdf_dest.exists():
                shutil.copy2(pdf_path, pdf_dest)
                logger.info(f"Copied source PDF to: {pdf_dest}")

        start_idx = STAGES.index(start_from) if start_from in STAGES else 0
        stop_idx = STAGES.index(stop_after) if stop_after in STAGES else len(STAGES) - 1

        from Generating.config import REQUIRE_HUMAN_APPROVAL_AFTER_STAGE_5

        for idx, stage in enumerate(STAGES):
            if idx < start_idx or idx > stop_idx:
                continue

            if stage in skip:
                logger.info(f"\n⏭  Skipping stage: {stage}")
                continue

            logger.info(f"\n{'─' * 40}")
            logger.info(f"▸ Stage: {stage}")
            logger.info(f"{'─' * 40}")

            try:
                self.run_stage(stage)
                logger.info(f"✅ Stage {stage} complete")
                
                # HITL Pause Check
                if stage == "structure" and REQUIRE_HUMAN_APPROVAL_AFTER_STAGE_5:
                    if stop_after != "structure":  # If it's already meant to stop, don't trigger pause message
                        logger.warning(
                            "\n⏸ HITL PAUSE: Pipeline paused after Stage 5 (Structure). "
                            "Waiting for human review of structure.json. "
                            "Resume pipeline with: --start-from knowledge_graph"
                        )
                        break
                        
            except Exception as e:
                logger.error(f"✗ Stage {stage} failed: {e}")
                raise

        logger.info(f"\n{'═' * 60}")
        logger.info(f"  ✅ PIPELINE COMPLETE: {self.material_name}")
        logger.info(f"  Output: {self.material_dir}")
        logger.info(f"{'═' * 60}")

    def run_stage(self, stage: str):
        """Run a single pipeline stage."""
        if stage not in STAGES:
            raise ValueError(
                f"Unknown stage: {stage}. Available: {', '.join(STAGES)}"
            )

        method = getattr(self, f"_stage_{stage}", None)
        if method is None:
            raise NotImplementedError(f"Stage {stage} not implemented")

        method()

    # ── Stage Implementations ─────────────────────────────────────────────────

    def _stage_extract(self):
        """Stage 1-2: PDF Intake + Text Extraction."""
        from Generating.Extraction.pdf_extractor import PDFExtractor
        from Generating.Extraction.text_cleaner import TextCleaner
        from Generating.config import OCR_BACKEND, OCR_LANGUAGE, OCR_TEXT_DENSITY_THRESHOLD

        pdf_path = self.material_dir / "source.pdf"
        if not pdf_path.exists():
            raise FileNotFoundError(f"Source PDF not found: {pdf_path}")

        extractor = PDFExtractor(
            text_density_threshold=OCR_TEXT_DENSITY_THRESHOLD,
            ocr_backend=OCR_BACKEND,
            ocr_language=OCR_LANGUAGE,
        )
        result = extractor.extract(str(pdf_path), str(self.material_dir))

        # Clean the extracted text
        cleaner = TextCleaner()
        cleaned_text = cleaner.clean(result.full_text)

        # Overwrite with cleaned text
        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "w", encoding="utf-8") as f:
            f.write(cleaned_text)

    def _stage_ocr(self):
        """Stage 3: OCR Detection & Processing (full report)."""
        from Generating.OCR.ocr_processor import OCRProcessor
        from Generating.config import OCR_BACKEND, OCR_LANGUAGE

        # Check if OCR is needed (based on extraction metadata)
        meta_path = self.material_dir / "extraction_metadata.json"
        if meta_path.exists():
            with open(meta_path, "r") as f:
                meta = json.load(f)
            ocr_pages = meta.get("ocr_page_numbers", [])
            if not ocr_pages:
                logger.info("No pages need OCR — skipping full OCR scan")
                # Still generate an empty report
                report = {"pages": [], "total_pages_processed": 0}
                report_path = self.material_dir / "ocr_report.json"
                with open(report_path, "w") as f:
                    json.dump(report, f, indent=2)
                return

        # Run full OCR report
        processor = OCRProcessor(backend=OCR_BACKEND, language=OCR_LANGUAGE)
        pdf_path = self.material_dir / "source.pdf"
        report = processor.process_pdf(str(pdf_path))
        report.save(str(self.material_dir / "ocr_report.json"))

    def _stage_chunk(self):
        """Stage 4: Text Chunking."""
        from Generating.Chunking.chunk_manager import ChunkManager

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            text = f.read()

        # Build page offsets from extraction metadata if available
        page_offsets = None
        meta_path = self.material_dir / "extraction_metadata.json"
        if meta_path.exists():
            with open(meta_path, "r") as f:
                meta = json.load(f)
            # Build approximate page offsets from page character counts
            pages = meta.get("pages", [])
            if pages:
                offset = 0
                page_offsets = []
                chars_per_page = len(text) / max(len(pages), 1)
                for p in pages:
                    page_num = p.get("page", 0)
                    page_chars = int(chars_per_page)
                    page_offsets.append({
                        "page": page_num,
                        "start": offset,
                        "end": offset + page_chars,
                    })
                    offset += page_chars

        manager = ChunkManager()
        manifest = manager.chunk_text(text, page_offsets, "source.pdf")
        manager.save_manifest(manifest, str(self.material_dir))

    def _stage_route(self):
        """Stage 5: Route Detection."""
        from Generating.RouteDetection.detector import RouteDetector

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            text = f.read()

        detector = RouteDetector()
        result = detector.detect(text, use_ai=True, ai_client=self.ai_client)
        detector.save_result(result, str(self.material_dir))

    def _stage_structure(self):
        """Stage 6: Structure Extraction."""
        from Generating.Chunking.chunk_manager import ChunkManager
        from Generating.Structure.structure_extractor import StructureExtractor

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            text = f.read()

        # Load route detection
        route_path = self.material_dir / "route_detection.json"
        route = "General"
        domain = ""
        if route_path.exists():
            with open(route_path, "r") as f:
                route_data = json.load(f)
            route = route_data.get("route", "General")
            domain = route_data.get("domain", "")

        # Load chunks
        chunk_manager = ChunkManager()
        try:
            chunk_manifest = chunk_manager.load_manifest(str(self.material_dir))
            chunk_texts = [
                chunk_manager.get_chunk_text(text, chunk)
                for chunk in chunk_manifest.chunks
            ]
        except FileNotFoundError:
            chunk_texts = [text]

        # Extract structure
        extractor = StructureExtractor(self.ai_client)
        structure = extractor.extract(
            text, chunk_texts, route, domain,
            self.material_name, "source.pdf", str(self.material_dir)
        )
        extractor.save_structure(structure, str(self.material_dir))

        # Generate material config
        config = extractor.generate_material_config(
            text, route, domain, self.material_name
        )
        extractor.save_material_config(config, str(self.material_dir))

    def _stage_knowledge_graph(self):
        """Stage 7: Knowledge Graph Construction."""
        from Generating.KnowledgeGraph.graph_builder import GraphBuilder
        from Generating.Structure.structure_extractor import StructureExtractor

        # Load structure and text
        extractor = StructureExtractor(self.ai_client)
        structure = extractor.load_structure(str(self.material_dir))

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            text = f.read()

        builder = GraphBuilder(self.ai_client)
        graph = builder.build(structure, text)
        builder.save_graph(graph, str(self.material_dir))

    def _stage_questions(self):
        """Stage 8: Question Extraction & Mapping."""
        from Generating.Questions.question_extractor import QuestionExtractor
        from Generating.Questions.question_classifier import QuestionClassifier

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            text = f.read()

        # Load route
        route_path = self.material_dir / "route_detection.json"
        route, domain = "General", ""
        if route_path.exists():
            with open(route_path, "r") as f:
                rd = json.load(f)
            route = rd.get("route", "General")
            domain = rd.get("domain", "")

        # Extract questions
        extractor = QuestionExtractor(self.ai_client)
        questions = extractor.extract(text, "source.pdf", route, domain)

        # Classify questions
        structure_path = self.material_dir / "structure.json"
        chapters = []
        if structure_path.exists():
            with open(structure_path, "r") as f:
                chapters = json.load(f).get("chapters", [])

        classifier = QuestionClassifier(self.ai_client)
        classified = classifier.classify(questions, chapters)

        extractor.save_questions(classified, str(self.material_dir))

    def _stage_sections(self):
        """Stage 9: Educational Section Generation."""
        from Generating.Sections.section_generator import SectionGenerator
        from Generating.Structure.structure_extractor import StructureExtractor

        # Load structure and text
        struct_extractor = StructureExtractor(self.ai_client)
        structure = struct_extractor.load_structure(str(self.material_dir))

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            text = f.read()

        kg_path = self.material_dir / "knowledge_graph.json"
        kg_data = json.load(open(kg_path, encoding="utf-8")) if kg_path.exists() else None

        q_path = self.material_dir / "questions.json"
        q_data = json.load(open(q_path, encoding="utf-8")) if q_path.exists() else None

        generator = SectionGenerator(self.ai_client)
        sections = generator.generate(
            structure, text, structure.route, structure.domain, kg_data, q_data
        )
        generator.save_sections(sections, str(self.material_dir))

    def _stage_validate(self):
        """Stage 10: Validation."""
        from Generating.Validation.validator import Validator

        text_path = self.material_dir / "extracted_text.txt"
        with open(text_path, "r", encoding="utf-8") as f:
            source_text = f.read()

        sections_path = self.material_dir / "sections.json"
        with open(sections_path, "r", encoding="utf-8") as f:
            sections_data = json.load(f)

        validator = Validator(self.ai_client)
        result = validator.validate(source_text, sections_data)
        validator.save_validation(result, str(self.material_dir))

    def _stage_view_map(self):
        """Stage 11: Dynamic View Mapping."""
        from Generating.DynamicView.view_mapper import ViewMapper

        sections_path = self.material_dir / "sections.json"
        with open(sections_path, "r", encoding="utf-8") as f:
            sections_data = json.load(f)

        # Load validation if available
        validation_data = None
        validation_path = self.material_dir / "validation.json"
        if validation_path.exists():
            with open(validation_path, "r") as f:
                validation_data = json.load(f)

        route_path = self.material_dir / "route_detection.json"
        route = "General"
        if route_path.exists():
            with open(route_path, "r") as f:
                route = json.load(f).get("route", "General")

        mapper = ViewMapper(self.ai_client)
        mapping = mapper.map_sections(sections_data, route, validation_data)
        mapper.save_mapping(mapping, str(self.material_dir))

    def _stage_view_prompt(self):
        """Stage 12: Dynamic View Prompt Generation."""
        from Generating.DynamicView.prompt_generator import PromptGenerator

        mapping_path = self.material_dir / "dynamic_view_mapping.json"
        with open(mapping_path, "r", encoding="utf-8") as f:
            mapping_data = json.load(f)

        sections_path = self.material_dir / "sections.json"
        with open(sections_path, "r", encoding="utf-8") as f:
            sections_data = json.load(f)

        # Load questions and mapping if available
        questions = None
        question_mapping = None

        questions_path = self.material_dir / "questions.json"
        if questions_path.exists():
            with open(questions_path, "r") as f:
                qd = json.load(f)
                questions = qd.get("questions", [])

        qmap_path = self.material_dir / "question_mapping.json"
        if qmap_path.exists():
            with open(qmap_path, "r") as f:
                question_mapping = json.load(f)

        route_path = self.material_dir / "route_detection.json"
        route = "General"
        if route_path.exists():
            with open(route_path, "r") as f:
                route = json.load(f).get("route", "General")

        generator = PromptGenerator(self.ai_client, str(self.material_dir))
        prompts = generator.generate(
            mapping_data, sections_data, route, question_mapping, questions
        )
        generator.save_prompts(prompts)

    def _stage_manifest(self):
        """Final: Update master manifest."""
        manifest = {
            "material": self.material_name,
            "pipeline_run_id": self.run_id,
            "completed_at": datetime.now(timezone.utc).isoformat(),
            "usage_metrics": self.ai_client.get_usage() if self._ai_client else {},
            "artifacts": {},
        }

        # Check which artifacts exist
        artifact_files = {
            "source_pdf": "source.pdf",
            "extracted_text": "extracted_text.txt",
            "extraction_metadata": "extraction_metadata.json",
            "ocr_report": "ocr_report.json",
            "chunk_manifest": "chunk_manifest.json",
            "route_detection": "route_detection.json",
            "material_config": "material_config.json",
            "structure": "structure.json",
            "knowledge_graph": "knowledge_graph.json",
            "questions": "questions.json",
            "question_mapping": "question_mapping.json",
            "sections": "sections.json",
            "validation": "validation.json",
            "dynamic_view_mapping": "dynamic_view_mapping.json",
            "dynamic_view_prompts": "dynamic_view_prompts.json",
            "dynamic_view_cache": "dynamic_view_cache.json",
        }

        for key, filename in artifact_files.items():
            filepath = self.material_dir / filename
            if filepath.exists():
                manifest["artifacts"][key] = {
                    "file": filename,
                    "exists": True,
                    "size_bytes": filepath.stat().st_size,
                }
            else:
                manifest["artifacts"][key] = {
                    "file": filename,
                    "exists": False,
                }

        # Load key metrics from existing artifacts
        try:
            struct_path = self.material_dir / "structure.json"
            if struct_path.exists():
                with open(struct_path, "r") as f:
                    struct = json.load(f)
                manifest["total_chapters"] = struct.get("total_chapters", 0)
                manifest["total_lessons"] = struct.get("total_lessons", 0)
                manifest["route"] = struct.get("route", "General")
                manifest["domain"] = struct.get("domain", "")
        except Exception:
            pass

        try:
            val_path = self.material_dir / "validation.json"
            if val_path.exists():
                with open(val_path, "r") as f:
                    val = json.load(f)
                manifest["coverage_percentage"] = val.get("coverage_percentage", 0)
                manifest["visualization_readiness"] = val.get("visualization_readiness", 0)
        except Exception:
            pass

        # Save manifest
        manifest_path = self.material_dir / "manifest.json"
        with open(manifest_path, "w", encoding="utf-8") as f:
            json.dump(manifest, f, indent=2, ensure_ascii=False)
        logger.info(f"Saved master manifest: {manifest_path}")


# ── CLI Entry Point ───────────────────────────────────────────────────────────

def main():
    """CLI entry point for the pipeline."""
    try:
        import click
    except ImportError:
        # Fallback to argparse if click is not installed
        import argparse

        parser = argparse.ArgumentParser(description="StudyFlow Generating Pipeline")
        parser.add_argument("--input", "-i", help="Path to source PDF")
        parser.add_argument("--name", "-n", required=True, help="Material name")
        parser.add_argument("--stage", "-s", help="Run a specific stage only")
        parser.add_argument("--api-key", help="AI API key")
        parser.add_argument("--provider", default="gemini", help="AI provider")
        parser.add_argument("--model", help="AI model name")
        parser.add_argument(
            "--list-stages", action="store_true", help="List available stages"
        )

        args = parser.parse_args()

        if args.list_stages:
            print("Available pipeline stages:")
            for i, stage in enumerate(STAGES, 1):
                print(f"  {i:2d}. {stage}")
            return

        pipeline = Pipeline(
            material_name=args.name,
            api_key=args.api_key,
            ai_provider=args.provider,
            ai_model=args.model,
        )

        if args.stage:
            pipeline.run_stage(args.stage)
        elif args.input:
            pipeline.run_full(args.input)
        else:
            print("Error: --input or --stage required")
            parser.print_help()
            sys.exit(1)

        return

    # Click-based CLI (if click is installed)
    @click.command()
    @click.option("--input", "-i", "pdf_path", help="Path to source PDF")
    @click.option("--name", "-n", "material_name", required=True, help="Material name")
    @click.option("--stage", "-s", help="Run a specific stage only")
    @click.option("--api-key", envvar="STUDYFLOW_AI_API_KEY", help="AI API key")
    @click.option("--provider", default="gemini", help="AI provider")
    @click.option("--model", help="AI model name")
    @click.option("--list-stages", is_flag=True, help="List available stages")
    @click.option("--skip", multiple=True, help="Stages to skip")
    @click.option("--start-from", help="Stage to start from")
    @click.option("--stop-after", help="Stage to stop after")
    def cli(pdf_path, material_name, stage, api_key, provider, model, list_stages, skip, start_from, stop_after):
        if list_stages:
            click.echo("Available pipeline stages:")
            for i, s in enumerate(STAGES, 1):
                click.echo(f"  {i:2d}. {s}")
            return

        pipeline = Pipeline(
            material_name=material_name,
            api_key=api_key,
            ai_provider=provider,
            ai_model=model,
        )

        if stage:
            pipeline.run_stage(stage)
        elif pdf_path or start_from:
            # If starting from the middle, pdf_path might not be required as source.pdf is already copied
            pdf_src = pdf_path or str(pipeline.material_dir / "source.pdf")
            pipeline.run_full(pdf_src, skip_stages=list(skip), start_from=start_from, stop_after=stop_after)
        else:
            click.echo("Error: --input, --stage, or --start-from required")
            raise click.Abort()

    cli()


if __name__ == "__main__":
    main()
