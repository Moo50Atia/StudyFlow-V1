# 15. System Configuration & Environment Variables

---

## 1. Configuration Design Principles

### 1.1 Configuration Hierarchy & Resolution Order
To resolve configurations across diverse environments, the system MUST enforce this precedence hierarchy:

```
[ Tier 1: In-Code Defaults ]  <-- Lowest Precedence (config.py / config/*.php)
            │
            ▼
[ Tier 2: Environment Variables ]  <-- System-level configuration (.env)
            │
            ▼
[ Tier 3: Deployment Overrides ]  <-- Container environment settings
            │
            ▼
[ Tier 4: Subprocess CLI Flags ]  <-- Highest Precedence (arguments passed to python)
```

### 1.2 Configuration Validation Policy
* **Required Keys:** Configuration items flagged as Required MUST be validated at subsystem startup. If a required key is missing or empty, the application MUST crash immediately and log a startup failure.
* **Type Validation:** Input configurations MUST be parsed and validated against strict types (e.g. Integer, Float, Boolean, String, Array).
* **Range Validation:** Threshold limits, temperatures, and timeouts MUST fall within defined boundary limits (e.g. AI temperature: `0.0 <= temp <= 1.0`).
* **Startup Validation:** Ingestion queue workers and Web portal containers MUST execute pre-flight health validations (database connection, storage link, OCR binary check) before declaring readiness to route traffic.

### 1.3 Configuration Versioning & Compatibility
When new configuration keys are introduced, they MUST provide non-breaking default fallback values to maintain backward compatibility. Modifications that remove or change the type of existing keys require a Major application version increment.

---

## 2. Configuration Security & Governance

### 2.1 Configuration Ownership
* **Laravel Web portal:** Owns, validates, and manages environmental configurations (`.env`) for web portals, persistence tiers, and background queues.
* **Python CLI:** Owns and validates configurations for AI prompts, OCR execution engines, and local parsing boundaries.

### 2.2 Access Control & Secrets Masking
All configuration parameters containing passwords, credentials, or API keys:
* MUST NOT be committed to the code repository.
* MUST be masked with asterisks (`******`) when rendered in application log files or output to console screens during pipeline execution tracing.

### 2.3 Audit Logging of Configuration Changes
Any runtime changes to feature flags or dynamic parameters via the Admin console MUST log an entry in the system audit logs, tracking the changing actor ID and the transition values.

---

## 3. Configuration Lifecycle & Reload Policy

### 3.1 Static Configuration (Requires Application/Daemon Restart)
Changes to core connectivity settings (e.g. DB connection keys, Redis URLs, container environment ports) MUST require a restart of the web container and worker daemons to take effect.

### 3.2 Dynamic Configuration (Supports Runtime Reload)
Changes to feature flags, AI model settings, and timeout threshold parameters MUST support dynamic reloading at execution time without requiring container or daemon restarts.

---

## 4. Configuration Metadata Schema

Every configuration registry entry MUST be specified using the following metadata template structure:
* **Name:** Unique string identifier (typically uppercase snake_case).
* **Purpose:** A brief description of what the parameter controls.
* **Type:** The data type (String, Integer, Boolean, Float).
* **Required:** Boolean flag (`True` or `False`).
* **Default Value:** Fallback value if configuration is missing.
* **Allowed Values:** Bounds, enums, or range parameters.
* **Used By:** The subsystem consuming the value.
* **Reference Documents:** Mapped architectural design documents.

---

## 5. Web portal (Laravel) Environment Registry

### 5.1 Application Configuration
* **Name:** `APP_ENV`
  * **Purpose:** Defines the runtime hosting stage.
  * **Type:** String | **Required:** True
  * **Default Value:** `production`
  * **Allowed Values:** `local`, `testing`, `staging`, `production`
  * **Used By:** Laravel Web portal
  * **Reference Documents:** [03_SystemArchitecture.md](file:///D:/projects/laravel_projects/college_project/Conversations/03_SystemArchitecture.md)

* **Name:** `APP_DEBUG`
  * **Purpose:** Toggles verbose debugging displays.
  * **Type:** Boolean | **Required:** True
  * **Default Value:** `false`
  * **Allowed Values:** `true`, `false`
  * **Used By:** Laravel Web portal
  * **Reference Documents:** [10_ErrorHandling.md](file:///D:/projects/laravel_projects/college_project/Conversations/10_ErrorHandling.md)

### 5.2 Database & Cache Configuration
* **Name:** `DB_CONNECTION`
  * **Purpose:** Sets the database driver.
  * **Type:** String | **Required:** True
  * **Default Value:** `mysql`
  * **Allowed Values:** `mysql`, `pgsql`, `sqlite`
  * **Used By:** Laravel Web portal
  * **Reference Documents:** [07_DatabaseSpecification.md](file:///D:/projects/laravel_projects/college_project/Conversations/07_DatabaseSpecification.md)

### 5.3 Queue Configuration
* **Name:** `STUDYFLOW_INGESTION_TIMEOUT_SECONDS`
  * **Purpose:** Sets the timeout threshold before killing active Python runs.
  * **Type:** Integer | **Required:** False
  * **Default Value:** `1800`
  * **Allowed Values:** `300` to `3600`
  * **Used By:** Laravel Queue Worker
  * **Reference Documents:** [09_QueueSpecification.md](file:///D:/projects/laravel_projects/college_project/Conversations/09_QueueSpecification.md)

### 5.4 LTI 1.3 Credentials Configuration
* **Name:** `LTI_PRIVATE_KEY_PATH`
  * **Purpose:** Identifies the file path to the secure LTI launch certificate.
  * **Type:** String | **Required:** True
  * **Default Value:** `/var/www/storage/keys/lti_private.key`
  * **Allowed Values:** Valid filesystem paths.
  * **Used By:** Laravel LTI Middleware
  * **Reference Documents:** [06_APISpecification.md](file:///D:/projects/laravel_projects/college_project/Conversations/06_APISpecification.md)

---

## 6. Python Runtime Configuration

### 6.1 AI Engine Configuration
* **Name:** `STUDYFLOW_PRIMARY_MODEL`
  * **Purpose:** Sets the primary model for stage text parsing.
  * **Type:** String | **Required:** True
  * **Default Value:** `gemini-2.5-flash`
  * **Allowed Values:** Supported LLM model strings.
  * **Used By:** Python AI Client
  * **Reference Documents:** [04_Pipeline.md](file:///D:/projects/laravel_projects/college_project/Conversations/04_Pipeline.md)

* **Name:** `STUDYFLOW_FALLBACK_MODEL`
  * **Purpose:** Sets the fallback model if the primary model fails.
  * **Type:** String | **Required:** True
  * **Default Value:** `gpt-4o`
  * **Allowed Values:** Supported LLM model strings.
  * **Used By:** Python AI Client
  * **Reference Documents:** [10_ErrorHandling.md](file:///D:/projects/laravel_projects/college_project/Conversations/10_ErrorHandling.md)

* **Name:** `STUDYFLOW_AI_TEMPERATURE`
  * **Purpose:** Controls LLM output determinism.
  * **Type:** Float | **Required:** False
  * **Default Value:** `0.2`
  * **Allowed Values:** `0.0` to `1.0`
  * **Used By:** Python AI Client
  * **Reference Documents:** [16_PromptSpecification.md](file:///D:/projects/laravel_projects/college_project/Conversations/16_PromptSpecification.md)

### 6.2 System Dependencies Configuration
* **Name:** `STUDYFLOW_TESSERACT_PATH`
  * **Purpose:** Overrides the path to the Tesseract OCR binary.
  * **Type:** String | **Required:** False
  * **Default Value:** Null (resolves to system path)
  * **Allowed Values:** Valid binary path.
  * **Used By:** Python OCR stage
  * **Reference Documents:** [12_FolderStructure.md](file:///D:/projects/laravel_projects/college_project/Conversations/12_FolderStructure.md)

### 6.3 Validation Thresholds Configuration
* **Name:** `STUDYFLOW_MIN_COVERAGE`
  * **Purpose:** The minimum coverage score required to pass Stage 10 validation.
  * **Type:** Float | **Required:** False
  * **Default Value:** `85.0`
  * **Allowed Values:** `50.0` to `100.0`
  * **Used By:** Python Validation Stage
  * **Reference Documents:** [13_TestingStrategy.md](file:///D:/projects/laravel_projects/college_project/Conversations/13_TestingStrategy.md)

---

## 7. Dynamic Feature Flags & Telemetry Registry

### 7.1 Feature Flags Suite
* **Name:** `STUDYFLOW_COLLOQUIAL_ENABLED`
  * **Purpose:** Toggles the rendering of the Egyptian Colloquial Arabic analogy box on student views.
  * **Type:** Boolean | **Required:** False
  * **Default Value:** `true`
  * **Allowed Values:** `true`, `false`
  * **Used By:** Presentation Layer View Player
  * **Reference Documents:** [02_Business.md](file:///D:/projects/laravel_projects/college_project/Conversations/02_Business.md)

* **Name:** `STUDYFLOW_DOMAIN_LOCKING_ENABLED`
  * **Purpose:** Toggles the cryptographic domain locking checking inside compiled visual canvases.
  * **Type:** Boolean | **Required:** False
  * **Default Value:** `true`
  * **Allowed Values:** `true`, `false`
  * **Used By:** Python Canvas Compiler / Dynamic View Engine
  * **Reference Documents:** [08_StateMachine.md](file:///D:/projects/laravel_projects/college_project/Conversations/08_StateMachine.md)

### 7.2 Telemetry Configuration
* **Name:** `STUDYFLOW_TELEMETRY_RETENTION_DAYS`
  * **Purpose:** Defines the retention duration for raw student interaction logs.
  * **Type:** Integer | **Required:** False
  * **Default Value:** `365`
  * **Allowed Values:** Minimum `90`
  * **Used By:** Laravel Maintenance Scheduler
  * **Reference Documents:** [07_DatabaseSpecification.md](file:///D:/projects/laravel_projects/college_project/Conversations/07_DatabaseSpecification.md)
