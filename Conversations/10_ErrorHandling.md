# 10. Error Handling, Fallbacks, & Recovery Specification

---

## 1. Error Severity Model

The platform distinguishes between **Error Severity** (the operational impact of a failure on users and business processes) and **Error Taxonomy** (the logical component or boundary where the failure occurred).

* **`Critical`:** System-wide outage. Core features are unusable for all users (e.g. database connection loss, LTI handshake certificates validation failure). Requires immediate administrator escalation.
* **`High`:** Core feature is unusable for a specific user segment or course (e.g., ingestion pipeline crashes in Stage 6, preventing structure creation). Requires automatic retry and developer alert.
* **`Medium`:** Feature is degraded but has a fallback pathway (e.g., Dynamic View canvas fails verification checks at Stage 12, falling back to static presentation widgets). 
* **`Low`:** Non-blocking error. System functions normally (e.g., student telemetry fails to sync one click log, bookmark action logs a slow response query).
* **`Informational`:** Expected runtime alerts, system logs, or successfully resolved automatic retry events.

---

## 2. Error Ownership Matrix

To establish accountability and direct remediation, errors are mapped to specific subsystem owners:

* **Laravel Web portal:** Owns authentication, session management, LTI handshake validation, job queue serialization, database persistence, and API rate limits.
* **Python Pipeline:** Owns PDF layout extraction, OCR parsing, local filesystem manipulation, stage validation parsing, and canvas HTML compiler logic.
* **AI Providers:** Owns API timeouts, token limits exhaustion, rate limits (429 errors), and structural formatting deviations in raw outputs.
* **Database Engine:** Owns lock contentions, serialization blocks, constraint violations, and connection timeouts.
* **External LMS:** Owns callback URL validation, token expiration, and Gradebook writeback interface availability.

---

## 3. Recovery & Compensation Decisions

### 3.1 Recovery Decision Matrix

When an error occurs, the subsystem MUST evaluate the failure against this decision matrix:

| Condition / Error Type | Recovery Action | Action Definition |
| :--- | :--- | :--- |
| **Transient network drops, LLM API 429 rate limits, Database lock timeouts** | **`Retry`** | Re-queue the operation with exponential backoff delays. |
| **Malformed JSON output from LLM, missing non-critical structure nodes** | **`Repair`** | Trigger an automated self-repair pass, returning the error to the LLM. |
| **Partial sync crash, orphaned physical assets written to disk** | **`Compensate`** | Execute cleanup hooks to remove orphaned files and restore original states. |
| **Corrupt PDF file, unsearchable scan with zero OCR confidence, model failure** | **`Abort`** | Halt execution immediately, flag status as failed, and release locks. |
| **Critical database loss, permanent LTI handshake failure, system-wide timeouts** | **`Escalate`** | Alert system administrators and log correlation traces. |
| **Telemetry tracking loss for single mouse click, non-critical warning logs** | **`Ignore`** | Log the warning in the log files and continue normal execution. |

### 3.2 Compensation Strategies
For partially completed distributed operations where standard database rollbacks are insufficient:
* **Storage Cleanup:** If database synchronization fails during `Processing` ──► `Published` transition, the sync coordinator MUST trigger a cleanup hook to delete the compiled HTML assets written to public storage.
* **Lock Releases:** Aborted runs MUST release any active Redis/database concurrency locks on the associated course node to prevent permanent dashboard lockouts.

---

## 4. Error Boundary & Propagation Policies

### 4.1 Error Boundary Policy
Every subsystem boundary MUST act as an exception boundary:
* **Exceptions boundary:** Raw framework exceptions (e.g. PHP PDOExceptions, Python TesseractNotFoundError, OpenAI APIError) MUST be caught at the subsystem border and translated into standardized domain-safe error models.
* **No Propagation:** Raw exceptions MUST NOT propagate across subsystem interfaces.

### 4.2 Error Propagation Policy
Failures traveling across subsystems MUST maintain the original correlation signature:
1. **Python CLI:** Catches OCR/LLM errors, outputs an error block conforming to `05_JSONContracts.md`, and exits with code `1`.
2. **Queue Worker:** Reads exit code `1`, parses the error JSON from the run folder, logs it using the `correlation_id`, and flags the job status as failed.
3. **Laravel API:** Returns a public-safe standard JSON error payload to the dashboard client.
4. **Frontend UI:** Displays a user-friendly error card referencing the `correlation_id` for IT diagnostics.

### 4.3 Tracing and Leakage Prevention
All internal stack traces, API keys, database column names, and system paths MUST be stripped from public API responses, remaining visible only in server logs linked by the `correlation_id`.

---

## 5. Ingestion Failures & Partial Success Policy

### 5.1 Ingestion Subprocess Failures
If the Python engine crashes before completing structural TOC mapping (Stage 6):
* The run transitions to `Failed`.
* The upload lock is released.
* The professor is prompted to upload a different PDF or manually review formatting.

### 5.2 Partial Success Policy
If the pipeline completes Stage 6 (`structure.json` exists and is validated) but crashes during downstream generation (Stages 7–12):
* The Lecture is set to `Awaiting Approval` or `Failed` with a partial flag.
* The professor's dashboard MUST display the parsed curriculum structure in the Node Editor, allowing them to manually resume generation or convert sections to static fallback widgets.

---

## 6. Dynamic View Canvas Render Fallbacks

### 6.1 Verification Failures Handler
If Stage 12 compiles the visual canvas, but the browser verification test fails:
* The canvas status is marked `Unverified`.
* The system MUST block the compiled HTML page from publishing to public storage.
* The Section's status is set to use the fallback widget routing.

### 6.2 Fallback Widgets Suite
The platform MUST provide pre-coded, stable widgets that render raw section text when dynamic simulations fail:
* **Comparison Grid:** Displays comparison texts in responsive columns.
* **Process Steps Flow:** Renders step scripts in a numbered timeline layout.
* **Concept definitions:** Accordion cards matching terms and summaries.

---

## 7. Platform Error Catalog

The system MUST enforce standardized platform-wide error codes grouped by taxonomy:

### 7.1 AI Processing Errors (`ERR_AI_*`)
* `ERR_AI_RATE_LIMIT`: LLM API rate limits exceeded.
* `ERR_AI_TIMEOUT`: LLM API request timed out.
* `ERR_AI_JSON_PARSE`: LLM returned invalid JSON structure after self-repair.

### 7.2 OCR Conversion Errors (`ERR_OCR_*`)
* `ERR_OCR_ENGINE_FAIL`: Tesseract/EasyOCR binary failed to execute.
* `ERR_OCR_LOW_CONFIDENCE`: Extracted text confidence fell below threshold.

### 7.3 Interoperability Errors (`ERR_LTI_*`)
* `ERR_LTI_SIGNATURE_INVALID`: JWT handshake validation failed.
* `ERR_LTI_TIMEOUT`: Keyset URL request timed out.
* `ERR_LTI_WRITEBACK_FAILED`: LMS Gradebook API rejected score post.

### 7.4 Queue & Orchestration Errors (`ERR_QUEUE_*`)
* `ERR_QUEUE_WORKER_TIMEOUT`: Job execution duration exceeded.
* `ERR_QUEUE_LOCK_FAILED`: Concurrency lock collision.

### 7.5 Validation Errors (`ERR_VALIDATION_*`)
* `ERR_VALIDATION_COVERAGE_DROP`: Curriculum mapping coverage fell below 85%.
* `ERR_VALIDATION_VERIFY_FAIL`: Compiled canvas crashed during render test.

### 7.6 Storage Errors (`ERR_STORAGE_*`)
* `ERR_STORAGE_WRITE_BLOCKED`: Disk permissions error blocking file copy.

---

## 8. Incident Response & Escalation Policy

### 8.1 Automatic Escalation Triggers
The platform MUST alert system administrators immediately if:
* More than three consecutive `Critical` errors occur within a 5-minute window.
* Ingestion queue latency (wait time in queue) exceeds the threshold set by `STUDYFLOW_MAX_QUEUE_LATENCY`.

### 8.2 Incident Remediation Workflows
Administrators can trigger manual rollbacks through the Admin console:
* Purges failed intermediate assets folder.
* Resets parent lecture status back to `Draft` or `Failed`.
* Releases database transaction locks.

### 8.3 Configuration-Driven AI Fallbacks
Model fallbacks (switching models during rate limits or timeouts) MUST be resolved dynamically by referencing model configurations defined in [15_Configuration.md](file:///D:/projects/laravel_projects/college_project/Conversations/15_Configuration.md) (e.g. `STUDYFLOW_FALLBACK_MODEL`), preventing hardcoded vendor loops in the Python client.
