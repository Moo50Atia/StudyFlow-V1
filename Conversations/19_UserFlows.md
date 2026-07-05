# 19. User Flows & System Journeys

---

## 1. User Flow Design Principles

All user interaction pathways and system workflows MUST comply with these design rules:
* **Task-Oriented Progression:** Keep screens clean and focused on a single primary goal (e.g. upload, node edit, study player).
* **Graceful Exception Recovery:** Provide actionable, public-safe error screens at every boundary (referencing the active `correlation_id` for IT diagnostics).
* **Session Isolation:** Student study and telemetry logs MUST remain strictly isolated to the authenticated LTI tenant context.
* **JIT Provisioning:** The LTI launch gateway MUST support Just-In-Time (JIT) user account creation if a validated LMS launch payload references a new student identifier.

---

## 2. Professor Ingestion & HIL Approval Journey

This journey maps the step-by-step workflow for a course coordinator uploading, reviewing, and publishing a digitized curriculum.

```
 [Professor]              [Laravel Portal]            [Python Engine]          [LMS Client]
      │                           │                          │                      │
      │── 1. Uploads PDF ────────►│                          │                      │
      │   (Sets HIL Mode)         │── 2. Dispatches Job ────►│                      │
      │                           │                          │── 3. Runs Stages ──┐ │
      │                           │◄─ 4. Suspends Run ◄──────┴──  1-6 Complete ◄──┘ │
      │◄─ 5. Node Editor Active ──│                          │                      │
      │                           │                          │                      │
      │── 6. Saves Node Edits ───►│ (Validates Pages)        │                      │
      │                           │── 7. Resumes Worker ────►│                      │
      │                           │                          │── 8. Runs Stages ──┐ │
      │                           │◄─ 9. Compiles Manifest ──┴──  7-12 Complete ◄─┘ │
      │                           │                          │                      │
      │                           │── 10. Links LTI Node ──────────────────────────►│
```

### 2.1 Preconditions
* The professor is authenticated and assigned permissions for the target Subject.
* The PDF curriculum file is formatted correctly and is under the file size limit (50MB).

### 2.2 Happy Path Sequence
1. **Upload Initiation:** The professor clicks "Ingest PDF" inside their course catalog, selects **HIL Mode**, and uploads the file.
2. **Analysis Subprocess:** The Laravel portal dispatches a queue job, launching the Python engine. The dashboard displays the progress modal.
3. **Approval Suspension:** Once Stage 6 completes, the Python engine writes the draft `structure.json` file to disk and exits with code `0`. Laravel updates the Lecture status to `Awaiting Approval` and alerts the professor.
4. **Node Alignment:** The professor opens the HIL Node Editor, edits the lessons tree layout, and clicks "Save & Publish".
5. **Ingestion Completion:** Laravel validates the modified structure parameters, writes changes to `structure.json` on disk, and resumes the Python pipeline with the `--start-from knowledge_graph` command line argument.
6. **Manifest Synchronization:** The pipeline executes stages 7–12, compiling domain-locked assets, and writes `manifest.json`. Laravel synchronizes the metadata to the database, updates the Lecture status to `Published`, and links LTI resource triggers to the LMS.

### 2.3 Exception Paths
* **Structure Validation Rejection:** If the professor saves a lesson where `page_start` > `page_end`, the Node Editor blocks submission, highlighting the invalid fields with inline warning text.
* **HIL Rejection:** If the professor rejects the AI-generated structure completely, the run transitions to `Rejected`. The system releases resource locks and returns to the initial upload dashboard.

---

## 3. Student Self-Study Learning Journey

This journey maps how a student accesses and interacts with visual learning modules.

### 3.1 Preconditions
* The course lecture LTI link is embedded in the LMS course timeline.
* The student is authenticated inside their LMS (Canvas, Moodle, or Blackboard).

### 3.2 Happy Path Sequence
1. **LMS Navigation:** The student opens their LMS course space and clicks the embedded lecture link.
2. **SSO Redirect & JIT Launch:** The LMS sends a JWT payload to the platform's launch gateway. Laravel validates the signature, JIT-provisions the student record if missing, and renders the Split-Screen Player view.
3. **Pre-Interaction Assessment:** The student is prompted with a 1-question pre-test check modal. Once answered, the score is saved, and the player interface unlocks.
4. **Interactive Exploration:**
   * The student reads the concept text in the left panel.
   * They click the toggle button to expand the **Colloquial Arabic Analogy** to clear up abstract confusion.
   * They interact with the visual canvas slider/controls in the right panel.
5. **Post-Interaction Assessment:** Once the student completes exploration, they click "Finish Lesson", opening the post-test check modal.
6. **Two-Phase Grade Sync:** 
   * **Phase 1 (Local Write):** The score and session duration metrics are saved to the platform's `student_interaction_logs` table.
   * **Phase 2 (Remote Write):** The system immediately dispatches the `LtiGradeWritebackJob` to submit the scores to the LMS Gradebook in the background, allowing the student to close the tab without score loss.

### 3.3 Exception Paths
* **Grade Writeback Failure:** If the LMS Gradebook API is down, the background job executes retries with exponential backoff. If it still fails after maximum retries, the job is quarantined in the DLQ, and an alert is flagged in the teacher's diagnostics log.

---

## 4. Admin System Management & Audits Journey

### 4.1 Happy Path Sequence
1. **Provisioning:** The Admin logs into the portal and creates a new Teacher account.
2. **Permission Mapping:** The Admin navigates to the permissions matrix, mapping the teacher to a specific Subject.
3. **Resource Auditing:** The Admin monitors the health dashboards, checking Redis queue depth, and reviewing active API token cost metrics.
4. **Audit Log Inspection:** The Admin checks the `ingestion_audit_logs` table to verify historical state changes and professor node editor revisions.
