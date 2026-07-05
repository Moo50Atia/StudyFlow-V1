# 12. Workspace Folder Structure Specification

---

## 1. Repository Layout Blueprint

The workspace directory layout MUST strictly follow this structure:

```
├── app/                           # Laravel Core Subsystem
│   ├── DTOs/                      # Data Transfer Objects
│   ├── Http/
│   │   ├── Controllers/           # HTTP Request Handlers
│   │   ├── Middleware/            # RBAC and Tenant Isolation Filters
│   │   ├── Requests/              # Form Input Validation Classes
│   │   └── Resources/             # API JSON Response Serializers
│   ├── Models/                    # Eloquent Persistence Entities
│   ├── Repositories/              # Database Query Isolation Layer
│   ├── Services/                  # Business Logic & Ingestion Coordinators
│   └── Providers/                 # Application Service Providers
├── config/                        # Laravel Environment Configuration Files
├── database/
│   ├── migrations/                # Database Table Schema Migrations
│   └── seeders/                   # Local Database Testing Seeders
├── public/
│   └── storage/                   # Public Symbolic Link
│       └── interactive_scenes/    # Obfuscated & Domain-Locked HTML/JS Canvases
├── resources/                     # Blade Templates & Raw Frontend Assets
├── routes/                        # Laravel HTTP Route Mappings
├── storage/                       # Local Storage
│   ├── app/
│   │   └── public/                # Target folder for public storage symlink
│   ├── logs/                      # Laravel Subsystem Logs
│   └── framework/                 # Session and Cache Scraps
├── tests/                         # PHP Testing Suite
│   ├── Feature/                   # HTTP and LTI Launch Tests
│   └── Unit/                      # Eloquent and Helper Tests
├── Interactive-Seens-Material/    # Generation Subsystem
│   ├── D.Amged/                   # Reference and legacy generated assets
│   └── Generating/                # 12-Stage Python Processing Package
│       ├── generating/            # Central Package Root
│       │   ├── __init__.py
│       │   ├── ai/                # LLM API Client wrappers
│       │   ├── chunking/          # Text segmentation logic
│       │   ├── extraction/        # PDF layout parsing and cleaner
│       │   ├── knowledge_graph/   # Entity relation mapper
│       │   ├── ocr/               # Optical character recognition modules
│       │   ├── questions/         # Question parser and classifier
│       │   ├── route_detection/   # Domain routing module
│       │   ├── sections/          # Pedagogical rewriter
│       │   ├── structure/         # Outline mapper
│       │   ├── templates/         # Step blueprints (Medical, General)
│       │   ├── validation/        # Coverage validation engine
│       │   └── view_mapping/      # Dynamic view prompt compilers
│       ├── Materials/             # Isolated local run workspaces
│       │   └── [run_id]/          # Isolated intermediate run artifacts
│       ├── tests/                 # Python pytest Suite
│       ├── pipeline.py            # CLI Subprocess Entry script
│       └── config.py              # Local pipeline defaults configuration
```

---

## 2. Subsystem Directory Boundaries & Ownership

### 2.1 Laravel Subsystem (`app/`, `routes/`, `storage/`)
* **Ownership:** Owns all HTTP routing, user management, LTI handshake processing, persistence models, and queue management.
* **Access Rules:** Laravel components CANNOT write directly to the internal Python directories, except for placing source PDF files inside the designated run directory before starting the subprocess.

### 2.2 Python Subsystem (`Interactive-Seens-Material/Generating/`)
* **Ownership:** Owns PDF text parsing, OCR processing, LLM prompt engineering, validation calculations, and HTML canvas compilation.
* **Access Rules:** Python scripts MUST remain self-contained. They MUST NOT access `app/` folders, import Laravel namespaces, or read database configuration files.

---

## 3. Shared Public Storage and Output Assets

* **Target Directory:** `/storage/app/public/interactive_scenes/` (exposed via symbolic link at `/public/storage/interactive_scenes/`).
* **Hierarchy Policy:** To prevent directory bloat and resolve name conflicts, compiled HTML assets MUST be nested by Subject and Lecture:
  `/public/storage/interactive_scenes/{subject_id}/{lecture_id}/{view_name}.html`

---

## 4. Transient Workspace & Logging Boundaries

* **No Shared Scraps:** Python stages MUST NOT write temporary scrap files (such as raw OCR page images or unvalidated JSON fragments) to the root `Generating/` directory.
* **Run Isolation:** All intermediate run files and logs MUST reside within a run-isolated workspace:
  `/Interactive-Seens-Material/Generating/Materials/{run_id}/`
* **Lifecycle:** When an ingestion run successfully transitions to `Published` or `Failed`, intermediate files in the `{run_id}` workspace directory MUST be pruned, preserving only the `manifest.json` and final log files for audit tracking.

---

## 5. Folder Placement Rules & Constraints

* **Rule 1:** Python stages MUST be structured as a standard Python package under the `generating/` package root folder, utilizing absolute package imports (e.g. `from generating.ocr import ocr_engine`).
  * *Rationale:* Prevents import failure errors when the pipeline is executed from different working directories by the Laravel background worker.
* **Rule 2:** PHP feature and unit tests MUST reside in `tests/`, whereas Python tests MUST remain isolated inside `Generating/tests/`.
  * *Rationale:* Prevents test suite conflicts and keeps testing tools separate.
* **Rule 3:** Environmental credentials and keys MUST NOT reside inside any directory in the repository. They MUST be managed by the host environment.
  * *Rationale:* Eliminates security leaks of API keys and server paths.
