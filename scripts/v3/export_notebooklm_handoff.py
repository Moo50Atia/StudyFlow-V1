#!/usr/bin/env python3
"""Export completed NotebookLM lesson prompts from one notebook to private JSON.

This is a recovery/export command for an existing provider-only run. The live
pipeline test writes the same handoff incrementally as each lesson completes.
"""
from __future__ import annotations

import argparse
import asyncio
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO_ROOT))

from v3_engine.notebooklm.bundle_parser import parse_combined_bundle
from v3_engine.notebooklm.credentials import get_storage_state_path
from v3_engine.notebooklm.lesson_archive import LessonArchiveError, archive_lesson_bundle
from v3_engine.notebooklm.ports import ProviderError
from v3_engine.notebooklm.protocol import parse_direct_divide_map
from v3_engine.notebooklm.sdk_provider import SdkSessionProvider


async def export_handoff(notebook_id: str, output_dir: Path | None, allow_partial: bool) -> tuple[Path, int, int]:
    provider = SdkSessionProvider()
    async with provider.open(storage_path=get_storage_state_path()) as session:
        notebook = await session.files.get_notebook(notebook_id)
        conversation_id = await session.client.chat.get_conversation_id(notebook_id)
        if not conversation_id:
            raise LessonArchiveError("Notebook has no active conversation to export.")
        history = await session.history(notebook_id, conversation_id=conversation_id, limit=500)

        handshake_index = max(
            (index for index, (_, answer) in enumerate(history) if answer.strip() == "System Ready"),
            default=-1,
        )
        if handshake_index < 0:
            raise LessonArchiveError("The active conversation does not contain the exact System Ready handshake.")

        map_matches = []
        for index, (_, answer) in enumerate(history[:handshake_index]):
            try:
                nodes = parse_direct_divide_map(answer)
            except ProviderError:
                continue
            work_items = [node for node in nodes if node.work_ordinal is not None]
            if work_items:
                map_matches.append(work_items)
        if not map_matches:
            raise LessonArchiveError("No valid Divide map precedes the active execution handshake.")
        work_items = map_matches[-1]

        bundle_answers = [
            answer for _, answer in history[handshake_index + 1:]
            if "=== FIRST ===" in answer and "=== SECOND ===" in answer and "=== THIRD ===" in answer
        ]
        if len(bundle_answers) > len(work_items):
            bundle_answers = bundle_answers[-len(work_items):]
        if not allow_partial and len(bundle_answers) != len(work_items):
            raise LessonArchiveError(
                f"Conversation has {len(bundle_answers)} completed lesson bundles for {len(work_items)} mapped lessons. "
                "Use --allow-partial to export completed lessons only."
            )
        if not bundle_answers:
            raise LessonArchiveError("No completed combined lesson bundles were found after the handshake.")

        source_ids: dict[str, str] = {}
        for source_id in notebook.source_ids:
            receipt = await session.files.get_source(notebook_id, source_id)
            title = (receipt.title or "").casefold()
            role = "framework_steps" if title == "steps.txt" else "original_pdf" if title.endswith(".pdf") else None
            if role:
                if role in source_ids:
                    raise LessonArchiveError(f"Notebook has multiple sources for role {role}.")
                source_ids[role] = source_id
        if set(source_ids) != {"original_pdf", "framework_steps"}:
            raise LessonArchiveError("Notebook must contain exactly one PDF source and one steps.txt source.")

        archived_path: Path | None = None
        for ordinal, answer in enumerate(bundle_answers, start=1):
            mapped_title = work_items[ordinal - 1].title
            archived_path = archive_lesson_bundle(
                notebook_id=notebook_id,
                conversation_id=conversation_id,
                source_ids=source_ids,
                lesson_ordinal=ordinal,
                lesson_title=mapped_title,
                expected_work_count=len(work_items),
                raw_response=answer,
                parse_status="raw_only",
                output_dir=output_dir,
            )
            try:
                bundle = parse_combined_bundle(answer, expected_work_ordinal=ordinal)
            except Exception:
                # Raw output has already been committed. Leave structured
                # parsing for a later repair pass and continue exporting.
                continue
            try:
                archived_path = archive_lesson_bundle(
                    notebook_id=notebook_id,
                    conversation_id=conversation_id,
                    source_ids=source_ids,
                    lesson_ordinal=ordinal,
                    lesson_title=mapped_title,
                    expected_work_count=len(work_items),
                    bundle=bundle,
                    raw_response=answer,
                    parse_status="parsed",
                    output_dir=output_dir,
                )
            except LessonArchiveError:
                # The exact response remains safely archived above.
                continue
        assert archived_path is not None
        return archived_path, len(bundle_answers), len(work_items)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--notebook-id", required=True, help="NotebookLM notebook ID containing the completed run")
    parser.add_argument("--output-dir", type=Path, help="Private JSON output directory; defaults to storage/app/private/v3/notebooklm-handoffs")
    parser.add_argument("--allow-partial", action="store_true", help="Export completed lesson bundles when the mapped run is incomplete")
    args = parser.parse_args()
    try:
        path, archived, expected = asyncio.run(export_handoff(args.notebook_id, args.output_dir, args.allow_partial))
    except (LessonArchiveError, ProviderError, OSError, ValueError) as exc:
        print(f"NotebookLM handoff export failed: {exc}", file=sys.stderr)
        return 2
    print(f"Archived {archived}/{expected} lesson(s): {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
