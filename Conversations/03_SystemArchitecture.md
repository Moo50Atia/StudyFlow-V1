# 03. High-Level System Architecture Specification

---

## 1. Executive Architecture Overview

This specification defines the high-level software architecture, subsystem boundaries, data pathways, and integration protocols of the platform.

The system is architected as a hybrid application comprised of two core subsystems:
1. **The Web portal Layer:** Written in Laravel, managing multi-tenant administration, identity and access management, LTI integrations, database persistence, and orchestration queues.
2. **The Ingestion Pipeline Layer:** Written in Python, operating as a stateless CLI processing engine that extracts, structures, and compiles curriculum PDF files into interactive visual assets.

Communication between these subsystems is asynchronous and event-driven. The Web portal Layer dispatches tasks to an active background queue worker, which executes the Python pipeline via subprocess wrappers. All state changes and processed outputs are stored locally as structured JSON and compiled HTML/JS files, which are subsequently synchronized back to the primary database.

---

## 2. Architectural Principles

Every module and component within the platform MUST adhere to the following principles:

* **Single Responsibility:** Each service, class, and pipeline stage MUST perform exactly one business or processing task.
* **Separation of Concerns:** The database schema and business web portals MUST remain isolated from LLM prompt logic and file-parsing routines.
* **Loose Coupling:** Subsystems MUST interact via defined file contracts (JSON schemas) and standard process execution CLI commands, never by direct memory access or shared database updates.
* **High Cohesion:** Code files performing similar operations (e.g., LTI token decodes or prompt builders) MUST be grouped within the same directory boundary.
* **Scalability by Design:** Background workers, file ingestion processes, and interactive asset delivery channels MUST be built to scale horizontally without blocking.
* **Security by Design:** Access to API endpoints, database tables, and generated canvas views MUST be restricted based on roles and domain verification signatures at every boundary.
* **Human-in-the-Loop:** Long-running processing runs MUST support execution pause states to facilitate human audit and override capabilities.
* **Event-Driven Execution:** Platform events (e.g., pipeline completion, LTI launches) SHOULD be dispatched and handled asynchronously to maintain web portal responsiveness.

---

## 3. System Context

The following diagram illustrates the relationship between the core platform subsystems and external actors:

```
                            ┌─────────────────┐
                            │   LMS Client    │
                            └────────┬────────┘
                                     │ (LTI 1.3 Launch)
                                     ▼
┌──────────────┐            ┌─────────────────┐            ┌─────────────────┐
│  Professor/  ├───────────►│   Web portal    │◄───────────┤    Platform     │
│   Admin      │ (Ingestion)│    (Laravel)    │ (Admin UI) │    Provider     │
└──────────────┘            └────┬───────┬────┘            └─────────────────┘
                                 │       │
             (Symfony Process)   │       │ (Read/Write)
                                 ▼       ▼
                            ┌────────┐ ┌────────┐
                            │ Python │ │Primary │
                            │Pipeline│ │Database│
                            └───┬────┘ └────────┘
                                │
                      (HTTPS)   │ (Read/Write)
                                ▼
                            ┌────────┐
                            │ External│
                            │  LLMs  │
                            └────────┘
```

---

## 4. Core System Components

### A. Web portal Layer (Laravel)
* **Purpose:** Handles administrative user management, database updates, authorization checks, and LTI launches.
* **Responsibilities:** Validates uploads, dispatches queue tasks, parses manifest logs, and manages persistence.
* **Inputs:** HTTP requests, uploads (PDF), LTI launch payloads, manifest JSON files.
* **Outputs:** HTML views, REST JSON responses, LTI grade writebacks, subprocess launch commands.
* **Dependencies:** Relational database, Redis, Symfony Process.

### B. Ingestion Pipeline Layer (Python)
* **Purpose:** Performs extraction, formatting, and interactive view compilation.
* **Responsibilities:** OCR scanning, text layout chunking, route identification, pedagogical re-writing, and scene compilation.
* **Inputs:** Source PDF file, process command line flags.
* **Outputs:** Extracted text, structured mapping JSON files, compiled HTML/JS canvases.
* **Dependencies:** Local OCR libraries, third-party LLM APIs.

### C. Queue & Orchestration Layer
* **Purpose:** Manages background execution.
* **Responsibilities:** Manages task queues, schedules retries, tracks execution logs, and isolates subprocess resources.
* **Inputs:** Dispatched job instances.
* **Outputs:** Background worker activity.
* **Dependencies:** Redis / database drivers.

### D. Dynamic View Canvas Layer
* **Purpose:** Renders the interactive visual scene inside the student's LMS container.
* **Responsibilities:** Executes JavaScript variable adjustments, updates charts, and manages pre/post assessments.
* **Inputs:** Canvas HTML, user adjustments (slider, toggle clicks).
* **Outputs:** Telemetry logs, grade writeback scores.
* **Dependencies:** Web browser container.

---

## 5. Architecture Layers

```
┌────────────────────────────────────────────────────────────────────────┐
│ Presentation Layer: Blade Views, CSS, JS Portal, Interactive Canvas    │
├────────────────────────────────────────────────────────────────────────┤
│ Application Layer: Controllers, Form Requests, LTI Decoders            │
├────────────────────────────────────────────────────────────────────────┤
│ Domain Layer: Service Classes, Observers, Pipeline Stages, AI Client   │
├────────────────────────────────────────────────────────────────────────┤
│ Infrastructure Layer: Database, Redis Queue, Storage, Symfony Process  │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 6. Communication Architecture

### A. Subsystem Integration Flow (Asynchronous)
1. The professor triggers course ingestion on the frontend.
2. Laravel validates the input and dispatches a `ProcessIngestionJob` to the Redis Queue.
3. The queue worker handles the job, executing the Python script as a background process via `symfony/process`.
4. While the process executes, the worker tracks console stdout logs to write progress percentages to the database.
5. If **HIL Mode** is active, the Python process terminates with code `0` after Stage 6. The database state updates to `awaiting_approval`.
6. Once the professor saves edits in the dashboard, Laravel dispatches a new job that calls the Python engine with the `--start-from knowledge_graph` flag.
7. Upon final completion, the worker reads the final `manifest.json`, writes data structures to the database, and moves the compiled assets to public storage.

### B. Client Launch Flow (LTI 1.3)
1. A student clicks a course node in the LMS (e.g. Canvas).
2. The LMS launches a POST request containing a JWT token to the platform’s gateway.
3. Laravel verifies the signature using the LMS's JWKS public keys.
4. The user is logged in via Single-Sign-On (SSO), and the requested visual scene is rendered inside an iframe.

---

## 7. Technology Responsibilities

* **Laravel:** MUST own all database persistence operations, user authentication, session controls, LTI launches, and background worker queues.
* **Python:** MUST own all PDF parsing, OCR image processing, LLM prompt orchestration, and HTML/JS scene compilation tasks.
* **Redis:** MUST manage background job storage, queues, and rate-limiting locks.
* **Primary Database:** MUST store structured course hierarchies, sections, questions, user identities, permissions, and audit logs.
* **Public Storage:** MUST store only compiled, domain-locked, and obfuscated HTML/JS view files.
* **External LLM:** MUST process prompt templates to return structured JSON data schemas.

---

## 8. Security Architecture

* **Authentication & Authorization:** Enforced via RBAC (Admin, Teacher, Student) using custom middleware.
* **Tenant Isolation:** All database queries for course, section, and analytics data MUST be filtered by tenant/school identifiers.
* **Cryptographic Domain-Locking:** The compiled HTML views MUST be cryptographically locked. During compilation, the target domain is hashed with a secure **salt** using SHA-256. At runtime, the script validates the hostname against this hash. If a mismatch is detected, the script MUST wipe the DOM.
* **Secrets Management:** Access keys, LLM API keys, and LTI keys MUST be stored exclusively in server environment variables, never hardcoded in the codebase.

---

## 9. Scalability Strategy

* **Queue Scaling:** Ingestion workers MUST execute on separate server instances to isolate memory-heavy Python OCR and PDF parsing processes from the web portal.
* **Horizontal Web Tier:** Web portal containers MUST remain stateless, allowing horizontal scaling behind load balancers.
* **Storage Scaling:** Compiled visual assets SHOULD be served via an Object Storage Bucket (e.g. AWS S3) combined with a Content Delivery Network (CDN) to ensure fast loading times globally.

---

## 10. Availability Strategy

* **Fault Isolation:** A crash or rate limit error in the Python subprocess MUST NOT disrupt the Laravel web portal or student access to already published materials.
* **Graceful Degradation:** If an LLM API error blocks visual scene generation, the platform MUST support falling back to pre-coded, static widgets (comparison tables, process flow charts).
* **Transaction Rollbacks:** Database synchronization routines MUST execute within transactions (`DB::transaction`). Any error during execution MUST trigger a rollback.

---

## 11. Observability

* **Logging:** Laravel MUST log LTI launch failures, queue timeouts, and authorization violations. Python MUST log stage tracebacks and LLM token expenditures.
* **Metrics:** The system MUST track total LLM API costs, processing times, and student interaction durations.
* **Health Checks:** Endpoint monitors MUST regularly test database connections, Redis queue delays, and S3 availability.

---

## 12. Deployment Architecture

The system supports two primary environments:
1. **Managed Cloud:** Containers managed by a Kubernetes cluster, utilizing RDS databases, Redis cache, and S3 asset delivery networks (ideal for private institutions).
2. **On-Premise:** Packaged as a self-contained Docker Compose stack featuring local database, local Redis, and Supervisor daemons to manage queues on campus hardware.

---

## 13. Architectural Constraints

* **Business Logic Separation:** Ingestion orchestration and database sync routines MUST NOT be written inside HTTP Controllers. They MUST exist within Service classes. 
  * *Rationale:* Promotes reuse and allows queue jobs to run the same logic outside the HTTP request lifecycle.
* **Persistence Ownership:** The Python pipeline MUST NOT execute database queries or connect to the database. All persistence MUST be managed by Laravel.
  * *Rationale:* Preserves loose coupling and prevents database lockups and connection leaks from Python subprocesses.
* **AI-UI Isolation:** The AI generation pipeline MUST NOT communicate directly with the user interface. All updates MUST flow through Laravel database states.
  * *Rationale:* Guarantees the professor's veto authority and enforces HIL verification steps.
* **Asset Traceability:** Every generated section and question database record MUST contain the source PDF page and paragraph offsets.
  * *Rationale:* Necessary for NAQAAE audit compliance and academic validation.

---

## 14. Quality Attributes

* **Maintainability:** Separation of concerns ensures that prompt updates in Python do not require changes to Laravel controllers.
* **Reliability:** Background jobs isolate long-running subprocess runs from web requests.
* **Security:** Crypto domain-locks prevent unauthorized copying or hosting of visual assets.
* **Extensibility:** The LTI 1.3 standard enables plug-and-play integrations with new LMS vendors.

---

## 15. Architectural Decision Records (ADR)

### ADR 001: Subprocess-Based Pipeline Integration
* **Decision:** Run the Python generation pipeline as a CLI subprocess via Laravel's `symfony/process` instead of HTTP API requests.
* **Reason:** Ingestion requires heavy, stateful filesystem operations (PDF parsing, OCR storage). CLI execution keeps processes isolated and simplifies on-premise deployments.
* **Trade-offs:** Increases local server dependency requirements (Python, Tesseract must be on the host path).
* **Alternatives Considered:** Python FastAPI web service (rejected due to added deployment complexity on public campus servers).

### ADR 002: Encrypted Domain-Locking in Compilation
* **Decision:** Perform SHA-256 domain hashing with salt during compile-time rather than validation queries at runtime.
* **Reason:** Ensures compiled HTML views run offline on private, secure university intranet servers without requiring outbound internet calls to our SaaS servers.
* **Trade-offs:** Requires compiling a unique HTML file per tenant.

---

## 16. Future Architecture Evolution

* **3-Year Horizon:** Introduce distributed workers utilizing AWS Lambda to scale Python pipeline tasks (like OCR and chunking) serverlessly, removing execution loads from the primary server.
* **5-Year Horizon:** Transition compiled assets to fully client-side WebAssembly rendering modules, lowering hosting costs.
* **10-Year Horizon:** Decentralize course storage using secure private ledgers, allowing universities to trade and license interactive courses directly with each other.

---

## 17. Architecture Glossary

* **Pipeline:** The sequential 12-stage Python processing engine.
* **Artifact:** Any file outputted by the pipeline (e.g. `structure.json`, `manifest.json`, HTML views).
* **Coordinator:** The Laravel service class orchestrating the process execution of the pipeline.
* **Dynamic View:** The responsive, compiled canvas template file.
* **Tenant:** An isolated client workspace mapping a unique university client.
* **Worker:** The background daemon (Supervisor-managed) handling task queues.
* **LTI (Learning Tools Interoperability):** The protocol standard for connecting external tools to LMS databases.
