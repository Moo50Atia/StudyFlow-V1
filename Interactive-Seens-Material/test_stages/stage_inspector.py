import json
from pathlib import Path
from rich.console import Console
from rich.table import Table
from rich.panel import Panel

console = Console()

class StageInspector:
    """Pretty-prints stage outputs from Generating/Materials/<name>/"""

    def __init__(self, material_dir: str):
        self.material_dir = Path(material_dir)

    def inspect(self, stage: str):
        if not self.material_dir.exists():
            console.print(f"[red]Material directory not found: {self.material_dir}[/red]")
            return

        method_name = f"_inspect_{stage}"
        if hasattr(self, method_name):
            getattr(self, method_name)()
        else:
            console.print(f"[yellow]No inspector defined for stage: {stage}[/yellow]")

    def _read_json(self, filename: str) -> dict:
        filepath = self.material_dir / filename
        if not filepath.exists():
            console.print(f"[red]File not found: {filepath}[/red]")
            return {}
        try:
            with open(filepath, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception as e:
            console.print(f"[red]Error reading {filename}: {e}[/red]")
            return {}

    def _inspect_extract(self):
        meta = self._read_json("extraction_metadata.json")
        if not meta: return

        table = Table(title="Extraction Metadata")
        table.add_column("Property", style="cyan")
        table.add_column("Value", style="magenta")
        table.add_row("Extracted At", str(meta.get("extracted_at")))
        table.add_row("Total Pages", str(meta.get("total_pages")))
        table.add_row("Total Characters", str(meta.get("total_characters")))
        table.add_row("Pages Needing OCR", str(meta.get("pages_needing_ocr")))
        table.add_row("Errors", str(len(meta.get("errors", []))))
        console.print(table)

        # Show text snippet
        text_path = self.material_dir / "extracted_text.txt"
        if text_path.exists():
            with open(text_path, "r", encoding="utf-8") as f:
                text = f.read(500)
            safe_text = text.encode("cp1252", errors="replace").decode("cp1252")
            console.print(Panel(safe_text + "...\n", title="Extracted Text Preview (first 500 chars)"))

    def _inspect_ocr(self):
        report = self._read_json("ocr_report.json")
        if not report: return

        table = Table(title="OCR Report")
        table.add_column("Page", justify="right", style="cyan")
        table.add_column("Type", style="green")
        table.add_column("Confidence", justify="right", style="magenta")
        table.add_column("Engine", style="blue")

        pages = report.get("pages", [])
        for p in pages[:20]: # show first 20 max
            table.add_row(
                str(p.get("page")),
                str(p.get("type")),
                str(p.get("confidence")),
                str(p.get("ocr_engine"))
            )
        console.print(table)
        if len(pages) > 20:
            console.print(f"[italic]...and {len(pages) - 20} more pages.[/italic]")

    def _inspect_chunk(self):
        manifest = self._read_json("chunk_manifest.json")
        if not manifest: return

        table = Table(title="Chunk Manifest")
        table.add_column("Chunk ID", style="cyan")
        table.add_column("Pages", style="green")
        table.add_column("Chars", justify="right", style="magenta")
        table.add_column("Est. Tokens", justify="right", style="yellow")

        chunks = manifest.get("chunks", [])
        for c in chunks[:15]:
            table.add_row(
                c.get("id"),
                f"{c.get('start_page')}-{c.get('end_page')}",
                str(c.get("char_count")),
                str(c.get("token_estimate"))
            )
        console.print(table)
        console.print(f"Total Chunks: {manifest.get('total_chunks')}, Total Chars: {manifest.get('total_characters')}")

    def _inspect_route(self):
        data = self._read_json("route_detection.json")
        if not data: return
        table = Table(title="Route Detection")
        table.add_column("Property", style="cyan")
        table.add_column("Value", style="magenta")
        table.add_row("Domain", data.get("domain", ""))
        table.add_row("Route", data.get("route", ""))
        table.add_row("Confidence", str(data.get("confidence", "")))
        table.add_row("Fallback Used", str(data.get("fallback_used", False)))
        console.print(table)

    def _inspect_structure(self):
        data = self._read_json("structure.json")
        if not data: return
        console.print(f"[bold cyan]Material:[/bold cyan] {data.get('material')}")
        console.print(f"[bold cyan]Total Chapters:[/bold cyan] {data.get('total_chapters')}")
        console.print(f"[bold cyan]Total Lessons:[/bold cyan] {data.get('total_lessons')}")

        for ch in data.get("chapters", []):
            console.print(f"\n[bold green]Chapter:[/bold green] {ch.get('title')}")
            for mc in ch.get("mini_chapters", []):
                console.print(f"  [bold yellow]Mini-Chapter:[/bold yellow] {mc.get('title')}")
                for les in mc.get("lessons", []):
                    console.print(f"    - [white]{les.get('id')}: {les.get('title')} (Pages {les.get('page_start')}-{les.get('page_end')})[/white]")

    def _inspect_knowledge_graph(self):
        data = self._read_json("knowledge_graph.json")
        if not data: return
        console.print(f"[bold cyan]Total Nodes:[/bold cyan] {data.get('total_nodes')}")
        console.print(f"[bold cyan]Total Edges:[/bold cyan] {data.get('total_edges')}")

        table = Table(title="Top Concepts by Importance")
        table.add_column("ID", style="cyan")
        table.add_column("Label", style="green")
        table.add_column("Type", style="blue")
        table.add_column("Importance", justify="right", style="magenta")

        nodes = sorted(data.get("nodes", []), key=lambda x: x.get("importance", 0), reverse=True)
        for n in nodes[:15]:
            table.add_row(n.get("id"), n.get("label"), n.get("type"), str(n.get("importance")))
        console.print(table)

    def _inspect_questions(self):
        data = self._read_json("questions.json")
        if not data: return
        console.print(f"[bold cyan]Total Questions:[/bold cyan] {data.get('total_questions')}")

        table = Table(title="Sample Questions")
        table.add_column("ID", style="cyan")
        table.add_column("Type", style="green")
        table.add_column("Core Idea", style="yellow")
        table.add_column("Thinking", style="magenta")

        questions = data.get("questions", [])
        for q in questions[:10]:
            table.add_row(
                q.get("question_id", ""),
                q.get("question_type", ""),
                q.get("core_idea", ""),
                q.get("thinking_type", "")
            )
        console.print(table)

    def _inspect_sections(self):
        data = self._read_json("sections.json")
        if not data: return
        console.print(f"[bold cyan]Total Lessons:[/bold cyan] {data.get('total_lessons')}")
        console.print(f"[bold cyan]Total Sections:[/bold cyan] {data.get('total_sections')}")

        table = Table(title="Lessons Overview")
        table.add_column("Lesson", style="green")
        table.add_column("Sections", justify="right", style="cyan")

        for les in data.get("lessons", []):
            table.add_row(les.get("lesson_title", les.get("lesson_id", "")), str(len(les.get("sections", []))))
        console.print(table)

    def _inspect_validate(self):
        data = self._read_json("validation.json")
        if not data: return
        console.print(f"[bold cyan]Coverage:[/bold cyan] {data.get('coverage_percentage')}%")
        console.print(f"[bold cyan]Visualization Readiness:[/bold cyan] {data.get('visualization_readiness')}%")
        if data.get("warnings"):
            console.print("[bold red]Warnings:[/bold red]")
            for w in data.get("warnings"):
                console.print(f"  - {w}")

    def _inspect_view_map(self):
        data = self._read_json("dynamic_view_mapping.json")
        if not data: return
        console.print(f"[bold cyan]Total Mappings:[/bold cyan] {data.get('total_mappings')}")

        table = Table(title="Sample Mappings")
        table.add_column("Lesson", style="green")
        table.add_column("Scene Type", style="yellow")
        table.add_column("Visual Objects", style="magenta")

        mappings = data.get("mappings", [])
        for m in mappings[:10]:
            vo_list = m.get("visual_objects", [])
            if vo_list and isinstance(vo_list[0], dict):
                vo = ", ".join([v.get("object_name", "") for v in vo_list])
            else:
                vo = ", ".join([str(v) for v in vo_list])
            table.add_row(
                m.get("lesson_title", m.get("lesson_id", "")),
                m.get("scene_type", ""),
                vo[:50] + "..." if len(vo) > 50 else vo
            )
        console.print(table)

    def _inspect_view_prompt(self):
        data = self._read_json("dynamic_view_prompts.json")
        if not data: return
        console.print(f"[bold cyan]Total Prompts:[/bold cyan] {data.get('total_prompts')}")
        console.print(f"[bold cyan]Concept Prompts:[/bold cyan] {data.get('concept_prompts')}")
        console.print(f"[bold cyan]Question Prompts:[/bold cyan] {data.get('question_prompts')}")
        
        prompts = data.get("prompts", [])
        if prompts:
            p = prompts[0]
            console.print(Panel(p.get("prompt", "No prompt text"), title=f"Sample Prompt: {p.get('view_name', 'Unknown')}"))

    def _inspect_manifest(self):
        data = self._read_json("manifest.json")
        if not data: return
        console.print(f"[bold cyan]Pipeline Run ID:[/bold cyan] {data.get('pipeline_run_id')}")
        console.print(f"[bold cyan]Completed At:[/bold cyan] {data.get('completed_at')}")

        table = Table(title="Artifacts")
        table.add_column("Artifact", style="green")
        table.add_column("Status", style="cyan")
        table.add_column("Size (Bytes)", justify="right", style="magenta")

        for k, v in data.get("artifacts", {}).items():
            status = "[green]Exists[/green]" if v.get("exists") else "[red]Missing[/red]"
            size = str(v.get("size_bytes", 0)) if v.get("exists") else "-"
            table.add_row(k, status, size)
        console.print(table)
