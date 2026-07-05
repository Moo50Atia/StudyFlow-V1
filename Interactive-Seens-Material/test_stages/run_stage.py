import os
import sys
import io
import click
import traceback
from pathlib import Path

# Force UTF-8 output to prevent Windows cp1252 encoding crashes
if sys.stdout.encoding.lower() != 'utf-8':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

# Add project root to path
project_root = Path(__file__).parent.parent.resolve()
sys.path.append(str(project_root))

from Generating.pipeline import Pipeline, STAGES
from test_stages.stage_inspector import StageInspector
from rich.console import Console

console = Console()

@click.command()
@click.option("--pdf", help="Path to source PDF file")
@click.option("--name", required=True, help="Material name")
@click.option("--stage", type=click.Choice(STAGES), help="Run a specific stage")
@click.option("--inspect", type=click.Choice(STAGES), help="Inspect the output of a specific stage without running it")
@click.option("--all", is_flag=True, help="Run all stages sequentially")
@click.option("--start", type=click.Choice(STAGES), help="Stage to start from")
@click.option("--stop", type=click.Choice(STAGES), help="Stage to stop after")
@click.option("--pause", is_flag=True, help="Pause between stages")
@click.option("--api-key", envvar="STUDYFLOW_AI_API_KEY", help="Gemini API Key")
def main(pdf, name, stage, inspect, all, start, stop, pause, api_key):
    """Manual Stage-by-Stage Test Runner"""
    
    if api_key:
        os.environ["STUDYFLOW_AI_API_KEY"] = api_key

    pipeline = Pipeline(material_name=name, api_key=api_key)
    inspector = StageInspector(str(pipeline.material_dir))

    if inspect:
        console.print(f"[bold green]Inspecting output for stage: {inspect}[/bold green]")
        inspector.inspect(inspect)
        return

    if stage:
        if pdf and stage == "extract":
            pipeline.material_dir.mkdir(parents=True, exist_ok=True)
            pdf_dest = pipeline.material_dir / "source.pdf"
            import shutil
            shutil.copy2(pdf, pdf_dest)
            console.print(f"[cyan]Copied PDF to {pdf_dest}[/cyan]")
        
        console.print(f"\n[bold yellow]=== Running Stage: {stage} ===[/bold yellow]")
        try:
            pipeline.run_stage(stage)
            console.print(f"[bold green]OK Stage {stage} completed successfully.[/bold green]\n")
            inspector.inspect(stage)
        except Exception as e:
            console.print(f"[bold red]ERROR Stage {stage} failed:[/bold red]")
            traceback.print_exc()
        return

    if all or start or stop:
        if pdf:
            pipeline.material_dir.mkdir(parents=True, exist_ok=True)
            pdf_dest = pipeline.material_dir / "source.pdf"
            import shutil
            shutil.copy2(pdf, pdf_dest)
            console.print(f"[cyan]Copied PDF to {pdf_dest}[/cyan]")
        elif not (pipeline.material_dir / "source.pdf").exists():
            console.print("[red]Error: source.pdf not found in material directory. Provide --pdf.[/red]")
            return

        start_idx = STAGES.index(start) if start else 0
        stop_idx = STAGES.index(stop) if stop else len(STAGES) - 1

        for idx in range(start_idx, stop_idx + 1):
            s = STAGES[idx]
            console.print(f"\n[bold yellow]=== Running Stage: {s} ===[/bold yellow]")
            try:
                pipeline.run_stage(s)
                console.print(f"[bold green]OK Stage {s} completed successfully.[/bold green]\n")
                inspector.inspect(s)
                
                if pause and idx < stop_idx:
                    click.pause("\nPress any key to continue to the next stage...")
            except Exception as e:
                console.print(f"[bold red]ERROR Stage {s} failed:[/bold red]")
                traceback.print_exc()
                break

if __name__ == "__main__":
    main()
