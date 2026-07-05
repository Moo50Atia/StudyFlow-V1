# 04. AI Content Processing & Scene Generation Pipeline Specification

---

## 1. Executive Overview

The AI Ingestion and Generation Pipeline is the core processing engine of the platform. Its purpose is to programmatically ingest flat curriculum source files (PDFs) and restructure them into interactive, visually responsive, and pedagogically sound learning scenes (Dynamic Views). 

By executing a sequence of isolated, verifiable processing stages, the pipeline extracts structural blueprints, constructs logical knowledge dependencies, generates localized conceptual text, extracts questions, validates curriculum coverage, and compiles declarative visual rendering prompts. This specification establishes the logical blueprint for the pipeline, defining how information flows from unstructured text to execution-ready interactive files.

---

## 2. Pipeline Philosophy

Every component and processing stage within the pipeline MUST comply with the following architectural tenets:

* **Incremental Processing:** The pipeline MUST execute step-by-step, converting content incrementally rather than in a single monolithic pass.
* **Artifact-Based State:** Stages MUST communicate exclusively by reading and writing files (artifacts) in the filesystem. The pipeline MUST NOT maintain in-memory conversational states between executions.
* **Human-in-the-Loop (HIL):** The pipeline MUST support pausing after structural mapping to allow human review, editing, and approval before consuming tokens for generation.
* **AI-Assisted, Human-Controlled:** AI models are utilized for text parsing, mapping, and re-writing. AI MUST NOT override human configuration edits or generate content without a source reference.
* **Validation First:** Every stage output MUST be verified against semantic schemas or validation checks before launching subsequent stages.
* **Immutable Outputs:** Artifacts written by a completed stage MUST NOT be modified by subsequent stages.
* **Repeatability:** Given the same input files and identical temperature configurations, a stage SHOULD yield equivalent structural outputs.
* **Stage Isolation:** A failure in a later stage MUST NOT corrupt or require re-running of previous successful stages.

---

## 3. Pipeline Modes

The orchestrator MUST execute in one of two modes, determined at launch:

### A. Automatic Mode
* **Process:** Stages 1 through 12 run continuously in a single subprocess instance without interruption.
* **Advantages:** High throughput; ideal for rapid bulk ingestion of large course libraries.
* **Trade-offs:** Zero manual quality gate; errors in AI-driven structure classification flow directly into published assets.

### B. Human-in-the-Loop (HIL) Mode
* **Process:** The pipeline executes stages 1 through 6 (`structure` extraction), writes `structure.json`, and pauses execution. A Laravel dashboard presents the structure to the professor. Once edited and approved, a second process runs stages 7 through 12 passing the `--start-from` argument.
* **Advantages:** Guarantees structural accuracy, prevents wasted token expenditure on misclassified lesson scopes.
* **Trade-offs:** Introduces latency; requires active human presence.

---

## 4. Pipeline Lifecycle

```
[ PDF Upload ] ──► Stage 1-2: Extract Text ──► Stage 3: OCR Detection
                                                   │
   ┌───────────────────────────────────────────────┘
   ▼
Stage 4: Chunk Text ──► Stage 5: Route Detection ──► Stage 6: Structure Mapping
                                                          │
          ┌───────────────────────────────────────────────┘
          ├──────────► [ HIL Pause: Professor Review & Node Edit ]
          ▼
Stage 7: Knowledge Graph ──► Stage 8: Question Ingest ──► Stage 9: Section Rewrite
                                                              │
          ┌───────────────────────────────────────────────────┘
          ▼
Stage 10: Validation ──► Stage 11: View Mapping ──► Stage 12: Scene Prompt Compile
                                                         │
                                                         ▼
                                                   manifest.json ──► Database Sync
```

---

## 5. Pipeline Stages Specification

### Stage 1-2: PDF Intake & Text Extraction (`extract`)
* **Purpose:** Convert unstructured PDF file into raw cleaned text.
* **Responsibilities:** Reads PDF streams, extracts characters, maps initial page offsets, and cleans formatting symbols.
* **Input:** `source.pdf`.
* **Output:** `extracted_text.txt`, `extraction_metadata.json` (page character offsets).
* **Dependencies:** System PDF extraction binaries.
* **Success Conditions:** Extraction completes; output text file matches the character footprint of the PDF.
* **Failure Conditions:** Zero characters extracted; file read permissions error.
* **Validation:** Asserts output character length is greater than zero.
* **Expected Logs:** File dimensions, page counts, character density counts.

### Stage 3: OCR Processing (`ocr`)
* **Purpose:** Extract text from scanned or non-searchable PDF pages.
* **Responsibilities:** Scans pages with low character counts, renders page frames to images, and runs character recognition.
* **Input:** `source.pdf`, `extraction_metadata.json`.
* **Output:** `ocr_report.json`, updated `extracted_text.txt`.
* **Dependencies:** Local OCR engine binaries.
* **Success Conditions:** Scanned pages are successfully translated to characters.
* **Failure Conditions:** OCR engine fails to initialize or execution times out.
* **Validation:** Validates output text density against threshold.
* **Expected Logs:** Processed page indices, confidence scores.

### Stage 4: Text Chunking (`chunk`)
* **Purpose:** Divide the raw text into manageable processing limits.
* **Responsibilities:** Splits raw text into segments of approximately 80,000 characters, maintaining overlapping boundary buffers.
* **Input:** `extracted_text.txt`, `extraction_metadata.json`.
* **Output:** `chunk_manifest.json`.
* **Dependencies:** None.
* **Success Conditions:** Chunks generated cover 100% of the raw text characters.
* **Failure Conditions:** Overlap boundaries drop context; total chunk characters do not match source text.
* **Validation:** Asserts chunk sum matches raw character count (accounting for overlapping buffers).
* **Expected Logs:** Total chunks created, chunk token sizes.

### Stage 5: Route Detection (`route`)
* **Purpose:** Classify the academic domain and prompt templates route.
* **Responsibilities:** Runs keyword matching and executing LLM classification to assign a route.
* **Input:** `extracted_text.txt`.
* **Output:** `route_detection.json`.
* **Dependencies:** `Templates/route_keywords.json`, AI Client.
* **Success Conditions:** Returns exactly one supported route with confidence above the threshold.
* **Failure Conditions:** Classification output is empty or invalid.
* **Validation:** Asserts route matches the supported list (`SUPPORTED_ROUTES`).
* **Expected Logs:** Keyword frequencies, LLM confidence values.

### Stage 6: Structure Extraction (`structure`)
* **Purpose:** Generate the curriculum lesson hierarchy.
* **Responsibilities:** Processes chunks to extract Chapters, Mini-Chapters, and Lessons, compiling `structure.json`.
* **Input:** `extracted_text.txt`, `chunk_manifest.json`, `route_detection.json`.
* **Output:** `structure.json`, `material_config.json`.
* **Dependencies:** AI Client.
* **Success Conditions:** Lesson nodes are generated with valid title mappings.
* **Failure Conditions:** Lessons map to page ranges outside the PDF boundaries.
* **Validation:** Asserts `page_start` <= `page_end` for every lesson node.
* **Expected Logs:** Total chapters extracted, lesson mappings count.

### Stage 7: Knowledge Graph Construction (`knowledge_graph`)
* **Purpose:** Maps logical learning dependencies.
* **Responsibilities:** Builds a conceptual dependency map, linking formulas, terms, and sections.
* **Input:** `structure.json`, `extracted_text.txt`.
* **Output:** `knowledge_graph.json`.
* **Dependencies:** AI Client.
* **Success Conditions:** Key terms and formulas are linked to lesson node IDs.
* **Failure Conditions:** Circular dependencies found in graph nodes.
* **Validation:** Asserts no isolated nodes without links.
* **Expected Logs:** Total nodes and edges created.

### Stage 8: Question Extraction (`questions`)
* **Purpose:** Extract practice check questions.
* **Responsibilities:** Scans lessons to extract practice problems and maps them to target lesson titles.
* **Input:** `extracted_text.txt`, `structure.json`.
* **Output:** `questions.json`.
* **Dependencies:** AI Client.
* **Success Conditions:** Generates practice questions with valid correct answers.
* **Failure Conditions:** Questions extracted have zero answers or choices.
* **Validation:** Asserts all multiple-choice entries have correct option keys present.
* **Expected Logs:** Total questions parsed.

### Stage 9: Section Generation (`sections`)
* **Purpose:** Restructure lesson contents into detailed, localized sections.
* **Responsibilities:** Rewrites content into visual explanations, core concepts, concept checks, and **Egyptian Arabic analogies**.
* **Input:** `structure.json`, `extracted_text.txt`, `knowledge_graph.json`, `questions.json`.
* **Output:** `sections.json`.
* **Dependencies:** AI Client, Route templates.
* **Success Conditions:** Complete pedagogical re-writes generated for all lessons.
* **Failure Conditions:** Crucial textbook details omitted or lost.
* **Validation:** Asserts `arabic_explanation` is populated for every section.
* **Expected Logs:** Sections generated per lesson.

### Stage 10: Validation & Coverage Check (`validate`)
* **Purpose:** Validate curriculum integrity.
* **Responsibilities:** Compares `sections.json` content back to `extracted_text.txt` to calculate coverage.
* **Input:** `extracted_text.txt`, `sections.json`.
* **Output:** `validation.json` (Coverage reports).
* **Dependencies:** AI Client.
* **Success Conditions:** Coverage rating passes the validation threshold (>= 85%).
* **Failure Conditions:** Coverage rating drops below threshold.
* **Validation:** Asserts coverage score >= 85.
* **Expected Logs:** Missing concepts flagged.

### Stage 11: Dynamic View Mapping (`view_map`)
* **Purpose:** Map sections to interactive template structures.
* **Responsibilities:** Matches sections to one of the 5 visual structures (*Process, Comparison, Accumulation, Parallel Flow, Time Evolution*).
* **Input:** `sections.json`, `validation.json`.
* **Output:** `dynamic_view_mapping.json`.
* **Dependencies:** AI Client.
* **Success Conditions:** Every section maps to a valid scene type and visual objects list.
* **Failure Conditions:** Incomplete or unmapped sections.
* **Validation:** Asserts scene type is in the approved list.
* **Expected Logs:** Mapped sections count.

### Stage 12: Dynamic View Prompt & Canvas Compilation (`view_prompt`)
* **Purpose:** Compile dynamic view prompts and output HTML scenes.
* **Responsibilities:** Generates prompts and outputs compiled HTML canvas assets.
* **Input:** `dynamic_view_mapping.json`, `sections.json`.
* **Output:** `dynamic_view_prompts.json`, `dynamic_view_cache.json`, compiled `.html` files.
* **Dependencies:** AI Client, Obfuscation compiler.
* **Success Conditions:** HTML scene files are compiled with domain-locks applied.
* **Failure Conditions:** HTML compilation contains malformed JS or invalid syntax.
* **Validation:** Test load compiled HTML assets to assert no render crashes.
* **Expected Logs:** Compiled HTML filenames.

### Final Stage: Manifest Update (`manifest`)
* **Purpose:** Output execution run summaries.
* **Responsibilities:** Compiles run ID, token usage counts, costs, processing times, and artifact lists.
* **Input:** All stage JSON outputs.
* **Output:** `manifest.json`.
* **Dependencies:** None.
* **Success Conditions:** Save `manifest.json` correctly.
* **Failure Conditions:** Manifest file write fails.
* **Validation:** Asserts manifest contains all completed artifact paths.
* **Expected Logs:** Total API cost and execution speed.

---

## 6. Pipeline Artifacts Lifecycle

All pipeline artifacts are stored in `Generating/Materials/[material_name]/` and MUST follow this lifecycle:
* **Creation:** Generated by its designated stage using the write tool.
* **Immutability:** Once written and validated, the file MUST NOT be edited by subsequent stages (except for `manifest.json` which aggregates results).
* **Versioning:** Re-running the pipeline from a specific stage MUST overwrite the corresponding JSON file and increment the version key in the master manifest.
* **Security:** Artifact directory permissions MUST restrict access to only the Laravel queue worker and the Python execution runtime.

---

## 7. Pipeline JSON Contracts (High-Level)

Subsystems exchange data using defined structures:
* **Structure Contract:** Holds material metadata, route classification, chapter hierarchies, and lesson page boundaries.
* **Section Content Contract:** Holds pedagogical text structures, formulas, Egyptian Arabic analogies, and concept check options.
* **Question Contract:** Holds question texts, hints, correct options, and mapped lesson identifiers.
* **Dynamic View Prompt Contract:** Holds compiled prompt strings, target canvas filenames, and visual object arrays.
* **Manifest Contract:** Holds run metrics, total token counts, estimated dollar costs, route detection results, and validation coverage percentages.

---

## 8. Validation Strategy

To guarantee curriculum accuracy, the pipeline enforces a multi-layered validation strategy:
1. **Factual Integrity Check:** Stage 10 compares output explanations against source text, asserting no additions of facts or formulas not present in the PDF.
2. **Schema Compliance:** All JSON files written by Python MUST validate against defined Pydantic structures before the stage completes.
3. **Completeness Validation:** The coverage score MUST prove that at least 85% of the source PDF context is accounted for in the generated lessons.
4. **Interactive Integrity Check:** Compiled HTML files MUST pass runtime check assertions, confirming that domain-locking scripts execute and DOM elements mount successfully.

---

## 9. Failure Recovery & Self-Repair

If a stage fails:
1. **AI Client Level Retries:** Transient HTTP errors (e.g. 429 rate limit, 503 gateway) are intercepted by the Python AI Client, which retries using exponential backoff (up to 3 times).
2. **LLM Output Self-Repair:** If LLM output fails JSON parsing, the stage executes a single **Self-Repair Pass**, sending the raw text and parsing error trace back to the LLM to get a corrected structure.
3. **Queue Fallback:** If the error persists after self-repair, the Python subprocess terminates with an exit code `1`. Laravel captures the failure, rolls back database transactions, and marks the lecture state as `failed`.
4. **Manual Intervention:** If OCR or structure extraction fails, the HIL Node Editor allows professors to override titles or input text manually.

---

## 10. Observability

* **Log Structure:** Logs MUST be written to `Materials/[material_name]/pipeline.log` following the standard format: `[Timestamp] [LogLevel] [Stage] Message`.
* **Execution History:** Every run receives a unique ID (`run_` + 8 hex chars), logged in the master manifest.
* **Token & Cost Tracking:** Active token metrics and estimated USD costs MUST be tracked per stage and aggregated in the final manifest.

---

## 11. Pipeline Constraints

* **Validation Dependency:** No stage output MAY be synchronized to the database or written to public storage without passing schema validation checks.
  * *Rationale:* Prevents corrupt data or malformed JSON from breaking LMS client rendering views.
* **Stateless Subprocesses:** The Python pipeline MUST remain stateless. It MUST NOT connect to the primary database or store credentials.
  * *Rationale:* Minimizes integration complexity and ensures Python can run on isolated workers without database connection overhead.
* **Asset Immutability:** Once an interactive canvas HTML file is compiled, domain-locked, and obfuscated, it MUST NOT be edited manually.
  * *Rationale:* Prevents manual alterations from bypassing security checks or introducing rendering syntax errors.
* **Page Traceability:** Every section, question, and formula record generated MUST retain reference indices pointing to the source PDF pages.
  * *Rationale:* Required to generate audit logs for quality assurance and NAQAAE accreditation verification.

---

## 12. Architectural Decision Records (ADR)

### ADR 003: Stateless File-Based Stage Transitions
* **Decision:** Store intermediate stage states in individual JSON files on disk rather than utilizing shared database records or in-memory caches.
* **Reason:** Ensures pipeline execution can be stopped, inspected, and resumed at any stage without DB transactional dependency. It allows developers to mock inputs easily for testing.
* **Trade-offs:** Increases disk read/write cycles.
* **Alternatives Considered:** In-memory queue state (rejected due to HIL pause requirements).

---

## 13. Future Evolution

* **Multi-Modal Processing:** Future extraction stages will utilize multi-modal models to parse textbook diagrams, graphs, and medical illustrations directly, linking them to interactive visual canvases.
* **Multi-Agent Collaboration:** Future implementation may replace single-prompt generations with collaborative agent networks (e.g. a "Syllabus Decomposer Agent" collaborating with an "Accreditation Auditor Agent" to validate sections).
* **Streaming Compile:** Compiling and serving interactive views dynamically as WebAssembly modules.

---

## 14. Pipeline Glossary

* **Stage:** An isolated execution script in the pipeline with a single responsibility.
* **Artifact:** Any filesystem asset outputted by a stage.
* **Chunk:** A segment of raw text (approx. 80,000 characters) parsed for token limits.
* **Knowledge Graph:** Dependency schema mapping links between course concepts.
* **Manifest:** The summary file (`manifest.json`) detailing pipeline run metrics.
* **Self-Repair:** The automated LLM routine that corrects invalid JSON formats.
* **Domain Lock:** A compiled security check checking that the asset is loaded inside authorized server environments.
