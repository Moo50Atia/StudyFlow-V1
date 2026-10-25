# Process each PDF separately in one subject notebook

The Temporal terminal pipeline accepts `submit --pdf-processing per-pdf`. The manifest equivalent is `"pdf_processing": "per_pdf"` on a new subject. The default remains `joint`; the earlier `run_multi_pdf_notebooklm.py` command still processes all PDFs together.

## What the system does

1. Verify the saved NotebookLM session and ownership of the approved proof notebook. Create a new subject notebook; the proof notebook is not used for processing.
2. Upload the unchanged captured `steps.txt` once. Upload the first original PDF without local extraction or OCR.
3. Wait for that PDF and the framework source to become ready. Send the unchanged Divide prompt with only that PDF selected.
4. Send the unchanged Execution prompt with that PDF and framework selected. Require `System Ready`, then send `Start with First lesson` and literal `Continue` until that PDF's map is exhausted.
5. Save exact responses, parsed maps, lesson titles and scene prompts under that PDF's directory. Seal an archive with hashes of the receipts, captures and handoffs.
6. Between PDFs, clear the completed **server conversation history** only after its local archive verifies. This permanently removes those turns from NotebookLM's chat history; their exact answers remain in the local archive. Keep all PDF sources uploaded. Upload the next PDF and repeat with an empty chat history and its own map. Google may reuse the same conversation ID; the local PDF ordinal also forms part of lesson and scene identity.
7. After all PDFs finish, either export every scene prompt for manual use or generate and save the scenes. The last PDF's server conversation remains available.

The browser therefore shows one Divide in the current chat after earlier PDF chats have been cleared. This does not mean the PDFs were mapped together. Every PDF has its own retained `private/turn-captures/divide.json`, and every chat request supplies explicit source IDs: the active PDF alone for Divide, or the active PDF plus `steps.txt` for Execution and lessons. Older uploaded PDFs remain stored, but are excluded from these requests. The API source scope is authoritative; the source checkboxes in a separately opened browser tab need not display the same selection.

Every lesson uses its local PDF ordinal and lesson ordinal. Lesson 1 of PDF 2 cannot overwrite Lesson 1 of PDF 1. Scene counts are taken from the answers, with no fixed three-scene limit or new scene-type restriction. Separate maps reduce cross-lecture grouping; they do not prove that NotebookLM covered every topic.

The existing Brave/MCP credential setup is unchanged. Runtime uses the saved approved account session with notebooklm-py and never launches a login browser. A private session copy is retained per subject. Temporal history and the operational journal contain references and progress; educational responses are kept in private files.

## Local PDF Prerequisites

Local preparation requires the `qpdf` CLI tool to be installed and available on `PATH`. The system invokes `qpdf` using direct argv argument vectors (never through a shell) with strict execution timeouts:
- Inspection timeout: 60 seconds (`qpdf --check`, `qpdf --is-encrypted`, `qpdf --show-npages`).
- Extraction timeout: 120 seconds per final leaf part (`qpdf ORIGINAL --pages . START-END -- OUTPUT`).

Prerequisite verification:
```bash
which qpdf
qpdf --version
```
Inspection checks:
- Clean structure: `qpdf --check <file>` must return exit code 0. Any warning (exit code 3) or error (exit code 2 or other non-zero) causes deterministic local rejection before admission or upload.
- Encryption: `qpdf --is-encrypted <file>` must exit with 2 (unencrypted). Exit code 0 (encrypted) is rejected immediately.
- Positive physical page count: `qpdf --show-npages <file>` must return an integer >= 1. Zero-page files are rejected.

## Deterministic Local PDF Preparation & Splitting

Large PDFs can produce inconsistent lesson segmentation in NotebookLM. The pipeline uses deterministic local preparation to divide large originals before upload:
- **Trigger limit (policy v2)**: New web uploads, terminal per-PDF captures, and `prepare-pdfs` split originals with strictly **more than 50 pages**. Originals of 1–50 pages stay intact without range suffixes. Existing policy-v1 packages retain their original >100-page decision and hashes.
- **Terminal limit**: Once triggered, recursive halving divides **both** branches until every resulting part contains **at most 50 pages**.
- **Odd-length halving rule**: For an odd range of length $L$, the first half receives $\lfloor L / 2 \rfloor$ pages and the second receives the remainder.
- **Direct page preservation**: Pages are copied directly from retained originals using `qpdf` without text extraction, OCR, summarization, or re-rendering. 100% of physical pages (including diagrams, rotations, and blank pages) are preserved in order.

### Division Examples
- **100 pages**: 2 parts: `1–50`, `51–100`.
- **114 pages**: 4 parts: `1–28`, `29–57`, `58–85`, `86–114`.
- **101 pages**: 3 parts: `1–50` (50 pages), `51–75` (25 pages), `76–101` (26 pages).
- **150 pages**: 4 parts: `1–37` (37 pages), `38–75` (38 pages), `76–112` (37 pages), `113–150` (38 pages).
- **200 pages**: 4 parts: `1–50`, `51–100`, `101–150`, `151–200` (50 pages each).
- **1000 pages**: 32 parts of 31 or 32 pages each covering `1–1000`.

### Package Layout & Naming
Every preparation package consists of:
```text
PACKAGE_DIR/
  originals/               # Retained original copies (never uploaded)
    0001--Mechanics.pdf
  final/                   # Verified final upload PDFs only
    0001--Mechanics--pages-0001-0050.pdf
    0001--Mechanics--pages-0051-0075.pdf
    0001--Mechanics--pages-0076-0101.pdf
    0002--Small.pdf
  inventory.json           # Canonical record with checksums, ranges, and qpdf verification
```
- Intact files retain their sanitized basename without a range suffix: `{source_ordinal:04d}--{stem}.pdf`.
- Split files contain padded original ranges: `{source_ordinal:04d}--{stem}--pages-{start:04d}-{end:04d}.pdf`.
- Ascending alphabetical sorting of `final/` filenames strictly matches the upload inventory and reading order.

### Standalone Local Preparation Command
Operators can prepare PDFs offline without connecting to Temporal or external providers:
```bash
# Directory input (Unicode filename order)
.venv/bin/python scripts/v3/run_temporal_pipeline.py prepare-pdfs \
  --pdf-dir /path/to/source-folder \
  --output /path/to/output-package [--json]

# Explicit file list (preserves command-line order)
.venv/bin/python scripts/v3/run_temporal_pipeline.py prepare-pdfs \
  --pdf-file /path/to/first.pdf --pdf-file /path/to/second.pdf \
  --output /path/to/output-package [--json]
```

### Retry and Immutability Rules
- An unchanged submission ID reuses existing verified preparation and captures without regenerating.
- If original bytes, order, policy, or output files change under an existing submission ID, the system rejects with `input_changed`.
- Resumed runs consume captured final references directly.
- Scope applies only to newly captured terminal per-PDF subjects; joint mode, legacy captures, and saved handoffs are unaffected.
- **Caveat**: Physical page splitting controls file size but does not guarantee educational completeness, topic continuity across page cuts, or exemption from provider notebook quotas.

## Start the local services

From `/home/moahmmed/college_project`, start the persistent Temporal server in one terminal if it is not already running:

```bash
mkdir -p storage/app/private/v3/temporal
/home/moahmmed/.local/bin/temporal server start-dev \
  --ip 127.0.0.1 --port 7233 --ui-port 8233 \
  --db-filename "$PWD/storage/app/private/v3/temporal/server.sqlite" \
  --namespace v3-terminal
```

In another terminal, start the production workers:

```bash
cd /home/moahmmed/college_project
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py worker
```

Use the existing saved credentials. Generation also requires the configured Gemini API key; the sample manifest explicitly selects `gemini-2.5-flash`. It retains the existing captured thinking budget (default 24,576) and output-token limit (default 65,536). No `.env` changes are required for the new processing mode.

## Run the existing eight mechanics PDFs

First create a private operator manifest with a fresh submission ID:

```bash
.venv/bin/python - <<'PY'
import json
from pathlib import Path
import uuid

document = json.loads(Path('scripts/v3/examples/per_pdf_mechanics.json').read_text())
document['submission_id'] = str(uuid.uuid4())
target = Path('storage/app/private/v3/mechanics-per-pdf-submission.json')
if target.exists():
    raise SystemExit('Manifest already exists. Reuse it to recover acknowledgement; choose a new filename for a new run.')
target.parent.mkdir(parents=True, exist_ok=True)
target.write_text(json.dumps(document, ensure_ascii=False, indent=2) + '\n')
print(target)
PY
```

To process all eight PDFs and export their prompts **without scene-provider requests**:

```bash
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py submit \
  --manifest storage/app/private/v3/mechanics-per-pdf-submission.json \
  --pdf-processing per-pdf --export-prompts --json
```

For a new full run including paid scene generation, create a manifest with a new filename and new submission ID, then omit `--export-prompts`:

```bash
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py submit \
  --manifest storage/app/private/v3/mechanics-per-pdf-generation.json \
  --pdf-processing per-pdf --json
```

The second filename must first be created using the preparation snippet with its `target` changed. Repeating an unchanged submission ID returns its existing admission; changing its settings or bytes is rejected. Generate a new ID for a genuinely new run, not for recovery after a lost acknowledgement. `--pdf-processing` overrides every new subject's captured mode; saved-handoff subjects use their existing recovery path.

## Monitor and find the results

Use the returned `subject_run_id`:

```bash
V3_DEMO_RUN_ID='paste-subject-run-id'
.venv/bin/python scripts/v3/run_temporal_pipeline.py status --run-id "$V3_DEMO_RUN_ID" --json
.venv/bin/python -u scripts/v3/run_temporal_pipeline.py logs --run-id "$V3_DEMO_RUN_ID" --follow --json
```

Temporal UI: <http://127.0.0.1:8233>, namespace `v3-terminal`. Stopping the log viewer does not cancel processing.

Results are under `${V3_TERMINAL_ROOT:-storage/app/private/v3/terminal}/runs/<subject_run_id>/`:

| Path | Contents |
|---|---|
| `pdfs/pdf-0001/private/turn-captures/` | First PDF's Divide, Execution and lesson captures, including exact answers |
| `pdfs/pdf-0001/lesson_handoffs/lesson-001.json` | Named lesson and all extracted scene prompts, with PDF provenance |
| `pdfs/pdf-0001/private/archive.json` | Verified archive references and source/conversation identities |
| `private/turn-receipts/` | Unique receipts for every PDF and turn; repeated Continue text does not reuse identities |
| `private/scene-prompt-exports/scene-prompts.json` | Export-mode combined prompts, each linked to its PDF and named lesson |
| `pdfs/pdf-0001/scene-generation/` | Generated source, provider receipts and scene builds for the first PDF |
| `pdfs/pdf-0001/scene-generation/lesson-0001/scene-0001/current.json` | Pointer to that scene's verified latest build; `dist/index.html` is in the referenced build directory |
| `final-report.json` | Outcome, counts derived from retained artifacts and each PDF's archive |

PDF 2 uses `pdf-0002`, and so on. In generation mode, the handoffs already contain all prompts even without a separate manual export. JEV remains the existing explicitly recorded demo waiver; local completion does not prove live JEV qualification or teacher approval.

## Resume and failure boundaries

For an existing run, the worker reads its isolated session copy, rather than the main imported credential file. Use the same run directory on both the connection check and the manual import:

```bash
.venv/bin/python scripts/v3/check_notebooklm.py \
  --adapter notebooklm_py --notebook-id <processing-notebook-id> \
  --run-dir storage/app/private/v3/terminal/runs/<subject-run-id>
.venv/bin/python scripts/v3/setup_local_credentials.py \
  --refresh-notebooklm --manual-notebooklm --bridge-sdk \
  --run-dir storage/app/private/v3/terminal/runs/<subject-run-id>
```

For a custom terminal root, substitute its actual run directory. The first command succeeds with exit 0 when the requested notebook can be read; an authentication failure returns exit 2. The second command asks for the complete Cookie header at a hidden prompt and updates both the configured main SDK file and the selected existing run's isolated copy. It does not request a Gemini key. Repeat the first command before resuming. Importing cookies is not proof of live access, and identical values are accepted without a comparison gate.

Copy the header from the intended account/profile's NotebookLM Network request (`batchexecute` in the DevTools filter), using the entire Request Headers `Cookie` value. Select the app origin where that request was captured using `--notebooklm-origin` on import and `--origin` on check if it differs from the configured `NOTEBOOKLM_ORIGIN`. The bridge keeps `OSID` / `__Secure-OSID` scoped to that app host, while the SDK now uses the configured origin for actual requests. A token bootstrap failure can mean that the SDK could not find the page's CSRF token; the checker does not assert cookie expiration based on that error alone.

Restarting the worker resumes the existing Temporal execution and reuses completed receipts. Keep the same persistent Temporal database and terminal root. Pause, resume, cancel and scene retry remain available through the existing CLI; obtain `state_version` from status before issuing a control.

A reset interrupted after deletion is reconciled by inspecting the current conversation and its turns. It does not blindly delete again. The pinned SDK's reset marker is restored after confirming that the exact archived chat is empty, because each Activity opens a new client and Google can still return the deleted ID. Missing/corrupt archives stop reset. An uncertain Divide is inspected through its retained intent and server history; it is not sent again. Other uncertain paid turns also retain their receipts for reconciliation. A quota/authentication failure can still stop processing; this mode cannot remove provider limits. These guards protect already captured lessons and avoid accidental repeated `Continue` calls.

## Verification commands

```bash
.venv/bin/python -m pytest --import-mode=importlib \
  v3_engine/tests/terminal_pipeline v3_engine/tests/notebooklm/test_sdk_contract.py \
  v3_engine/tests/scene_generation \
  -m 'not temporal and not live and not integration' -q
.venv/bin/python -m pytest v3_engine/tests/terminal_pipeline \
  -m 'temporal and not live and not integration' -q
```

The new `test_per_pdf.py` tests use production workers and services, substituting only external providers. Live testing is separate and incurs provider work:

```bash
V3_TERMINAL_LIVE=1 GEMINI_MODEL=gemini-2.5-flash \
V3_PROOF_NOTEBOOK_ID=85b76e80-ea8a-4f86-9311-185634fcd2ed \
V3_TERMINAL_LIVE_PDF_PROCESSING=per_pdf \
V3_TERMINAL_LIVE_PDF=/absolute/path/to/small-first.pdf \
V3_TERMINAL_LIVE_PDF2=/absolute/path/to/small-second.pdf \
.venv/bin/python -m pytest v3_engine/tests/terminal_pipeline/test_live_pipeline.py \
  -m 'live and temporal and integration' -q -rs
```

This requires a working saved session, the approved owner-proof notebook and configured provider key. Skips are open gates, not proof of live operation. The live fixture starts and stops an isolated local Temporal server and saves its run under pytest's temporary directory.
