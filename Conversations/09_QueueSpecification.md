# 09. Job Queue & Background Processing Specification

---

## 1. Queue Architecture & Requirements

### 1.1 Queue Backend Requirements
The background processing infrastructure MUST deploy a persistent, transactional messaging queue backend that guarantees:
* **Durability:** Messages MUST survive queue engine crashes or power losses.
* **Isolation:** Jobs MUST be executed in isolated threads, preventing memory leaks or processor starvation in adjacent jobs.
* **FIFO-like Ordering:** Jobs in standard queues SHOULD be processed in First-In, First-Out sequence, respecting priority classes.
* **Atomic Job Reservation:** Prevent double-delivery by ensuring a job remains locked (invisible to other workers) while a worker thread runs it.

### 1.2 Worker Runtime Requirements
Worker daemons MUST run in environments capable of spawning separate process boundaries (such as OS subprocesses). The runtime MUST allocate independent RAM and CPU thresholds to each worker thread to prevent long-running image OCR and PDF parsing tasks from exhausting resources on the primary server.

---

## 2. Job Lifecycle Definitions

Every job managed by the platform MUST transition through this lifecycle:

```
 [ Job Dispatched ] ──► Queued
                           │
                           ▼ (Worker picks up)
                        Reserved
                           │
                           ▼ (Execution begins)
                        Running
                           │
           ┌───────────────┼───────────────┐
  (Success)│        (Fail) │       (Abort) │
           ▼               ▼               ▼
       Succeeded        Retried        Cancelled
                           │
                 (Max Retries Exceeded)
                           │
                           ▼
                      Dead Letter (DLQ)
```

* **`Queued`:** Job serialized and written to the queue database with metadata, parameters, and tracking signatures.
* **`Reserved`:** Job claimed by a worker thread. Lock established; visibility timeout active.
* **`Running`:** Job logic executing. Subprocess telemetry tracked.
* **`Succeeded`:** Job logic finishes without errors. Locks released, resource cleanups executed, and down-stream chain triggers launched.
* **`Failed`:** Exception thrown during execution. Database transaction rolled back.
* **`Retried`:** Job returned to `Queued` state with an incremented execution count and exponential delay parameters applied.
* **`Cancelled`:** Run aborted by user action. Subprocess receives termination signal and exits.
* **`Dead Letter`:** Job quarantined after exceeding maximum retry limits.

---

## 3. Queue Scheduling & Priority Classes

Jobs MUST be assigned to one of three priority classes to prevent long-running tasks from starving critical operations:

1. **High Priority Class:**
   * **Scope:** LTI launches, roster updates, and grade writebacks.
   * **Allocation:** Worker threads MUST prioritize this class, processing it before Standard or Batch classes.
2. **Standard Priority Class:**
   * **Scope:** Database synchronization runs, general emails, and manual node approvals.
3. **Batch / Ingestion Processing Class:**
   * **Scope:** Large PDF parsing, OCR image processing, and LLM prompt generation.
   * **Allocation:** Processes are isolated to run on dedicated queue workers to avoid CPU starvation in the Presentation Layer.

---

## 4. Job Idempotency & Concurrency Strategy

### 4.1 Idempotency Tokens
To prevent duplicate execution during network retries:
* Every ingestion job MUST generate an idempotency token calculated as a SHA-256 hash of its input arguments (`subject_id` + PDF file size + PDF filename).
* The queue backend MUST check this token against active lock records. If the token matches a running job, the duplicate request MUST be rejected.

### 4.2 Concurrency Locking
The system MUST lock target `lecture` database records during sync phases to prevent concurrent DB write conflicts.

### 4.3 Re-execution Guards
Every job execution loop MUST verify the parent resource status (e.g. confirming a lecture is still in the `Processing` state) before executing, aborting if the state was updated to `Cancelled` or `Failed` by another thread.

---

## 5. Job Registry & Payloads

### 5.1 `ProcessIngestionJob`
* **Purpose:** Runs the Python pipeline stages.
* **Payload:** `lecture_id`, `input_path`, `run_mode`, `start_from_stage`, `stop_after_stage`.
* **Execution Logic:** Spawns Python CLI subprocess, capturing stdout logs to parse progress updates.

### 5.2 `SyncIngestionManifestJob`
* **Purpose:** Syncs compiled artifacts to the database.
* **Payload:** `lecture_id`, `material_dir`.
* **Execution Logic:** Executes in a database transaction, reading generated JSON outputs and writing to SQL tables.

### 5.3 `LtiGradeWritebackJob`
* **Purpose:** Syncs student score checks back to the LMS.
* **Payload:** `student_id`, `section_id`, `score`, `callback_url`.

### 5.4 `IngestionTimeoutWatcherJob`
* **Purpose:** Reverts expired HIL approval drafts.
* **Payload:** None (Runs on schedule).

---

## 6. Execution Policies & Configuration-Driven Limits

Job parameters MUST be read from configurations defined in [15_Configuration.md](file:///D:/projects/laravel_projects/college_project/Conversations/15_Configuration.md) rather than hardcoded:

* **Timeout Limits:**
  * `ProcessIngestionJob` timeout limit: `STUDYFLOW_INGESTION_TIMEOUT_SECONDS`.
  * `SyncIngestionManifestJob` timeout limit: `STUDYFLOW_SYNC_TIMEOUT_SECONDS`.
* **Retry Boundaries:**
  * `LtiGradeWritebackJob` max retries count: `STUDYFLOW_WRITEBACK_MAX_RETRIES`.
  * `ProcessIngestionJob` max retries count: `0` (Zero retries allowed to prevent duplicate LLM costs).
* **Backoff Strategy:** Failed retryable jobs MUST deploy exponential backoff calculations:
  $$\text{Delay Seconds} = \text{Initial Delay} \times 2^{\text{retry\_count}}$$
  where Initial Delay is read from configuration keys.
* **Concurrency Pool Limits:** Host worker concurrent execution limit is governed by `STUDYFLOW_MAX_CONCURRENT_INGESTIONS`.

---

## 7. Failure, Poison Message, & Dead Letter Queue Policies

### 7.1 Poison Message Detection
If a job payload fails schema validation, contains corrupt arguments, or crashes the worker thread immediately upon retrieval (before reaching the execution loop), the system MUST flag the message as a **Poison Message**. The job MUST NOT be returned to the queue; it MUST be quarantined immediately to prevent execution loop crashes.

### 7.2 Dead Letter Queue (DLQ) Policy
When a job exceeds its maximum retry threshold:
1. The job status transitions to `Dead Letter`.
2. The payload is moved to a quarantined DLQ registry.
3. The system logs a critical alert containing the `correlation_id` and error codes.
4. Quarantined jobs require manual administrator intervention to retry or delete.

### 7.3 Automatic Rollbacks
If a job fails mid-execution:
* All database queries inside the active transaction MUST rollback.
* Temporary files and uncompleted HTML canvases created during the failed run MUST be purged.

---

## 8. Distributed Tracing & Observability

### 8.1 Distributed Correlation ID Propagation
Every ingestion request MUST generate a unique `correlation_id` at the API Gateway. This ID MUST be injected into every downstream system boundary:
```
[API Gateway] ──► [Queue Payload] ──► [Python Subprocess CLI] ──► [Stdout Logs] ──► [Database Sync Job]
```
All logged operations, execution exceptions, and audit records MUST include this `correlation_id` to allow unified tracing across subsystems.

### 8.2 Observability Metrics
Monitoring dashboards MUST track:
* **Queue Depth:** Total number of queued jobs in each priority class.
* **Worker Utilization:** Percentage of active vs idle worker threads.
* **Job Execution Time:** Average execution times.
* **System Log Alerts:** Warning logs when queue delay exceeds threshold.
* **Estimated LLM API Costs:** Total cost logged per `correlation_id`.
