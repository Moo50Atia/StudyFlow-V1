# 11. Coding Standards & Development Guidelines

---

## 1. Core Architecture Patterns

To maintain a clean separation of concerns and ensure testability, the platform enforces the following architectural design patterns:

* **Service-Repository Pattern:** Decouples business logic from persistence logic.
  * **Service Layer:** Houses all business rules, calculations, external calls, and workflow orchestration.
  * **Repository Layer:** Encapsulates all query building, Eloquent relationships, and raw data queries.
* **Data Transfer Objects (DTOs):** Data received from API requests or filesystem JSON files MUST be parsed into immutable DTO structures before crossing layer boundaries.
* **Form Requests:** HTTP request validation MUST be isolated in dedicated request classes.
* **Polymorphism:** Divergent route-specific behaviors (e.g. general vs. medical layouts) MUST be handled using polymorphic design patterns, avoiding multi-layered `if-else` blocks.

---

## 2. Laravel (PHP) Development Standards

### 2.1 Code Formats & Strict Typing
* Every PHP class file MUST declare strict types at the root of the file:
  `declare(strict_types=1);`
* Code formatting MUST comply with the PSR-12 styling standards.

### 2.2 Controller Design Rules
* Controllers MUST remain "thin". Their responsibilities are limited to:
  1. Capturing and validating incoming parameters via Form Requests.
  2. Resolving DTO payloads from requests.
  3. Dispatching payloads to target Service classes.
  4. Compiling and returning responses (HTML views or JSON API resources).
* Controllers MUST NOT execute database queries, compile files, or coordinate background jobs directly.

### 2.3 Service Layer Boundaries
* Service classes represent the transactional boundary of business operations.
* Services MUST use dependency injection to reference repositories and external components.
* Database modifications inside Services MUST be wrapped within transactions.
* **Mandatory Rule:** The Service-Repository layout is **mandatory** for complex aggregates (e.g. `Lecture`, `Section`, `Ingestion`). Simple CRUD operations on isolated tables (e.g. `Subject` listing) MAY bypass repositories, querying models directly to avoid boilerplate code.

### 2.4 Repository Layer Rules
* Repositories MUST only query database models.
* Repository methods MUST return typed models or collection envelopes, never raw database query builder objects.

### 2.5 Naming Standards (PHP)
* **Classes & Interfaces:** PascalCase (e.g. `IngestionCoordinatorService`, `LectureRepository`).
* **Methods & Variables:** camelCase (e.g. `syncManifestData()`, `$lectureId`).
* **Collections:** Plural noun variables (e.g. `$sections`, `$questions`).

---

## 3. Python Development Standards

### 3.1 Code Formats & Type Annotations
* Code formatting MUST comply with PEP 8 standards.
* All class declarations, function signatures, and method parameters MUST utilize strict type hints:
  ```python
  def parse_layout(self, pdf_path: str) -> list[dict]:
      pass
  ```

### 3.2 Stage Boundaries & Pydantic Validation
* Every pipeline stage script MUST execute validation checks on its input parameters using strongly typed schema wrappers (Pydantic-style declarations) before running its processing logic.
* Stages MUST output valid JSON files conforming to the contracts in [05_JSONContracts.md](file:///D:/projects/laravel_projects/college_project/Conversations/05_JSONContracts.md).

### 3.3 Logging Rules
* Under no circumstances MAY Python scripts use raw `print()` statements.
* All tracing and execution details MUST be logged using the system's standard logger module.
* Every log entry MUST include the stage identifier.

### 3.4 Persistence Isolation
* **Strict Rule:** The Python pipeline MUST remain completely isolated from the primary database. Python scripts MUST NOT install database driver packages, configure SQL connections, or execute database queries.
* All inputs, settings, and database mappings MUST be provided to Python as command-line arguments and input JSON files.

### 3.5 Naming Standards (Python)
* **Classes:** PascalCase (e.g. `TextExtractor`).
* **Functions & Methods:** snake_case (e.g. `clean_text_layout()`).
* **Variables:** snake_case (e.g. `source_file_path`).
* **Constants:** SCREAMING_SNAKE_CASE (e.g. `DEFAULT_TEMPERATURE`).

---

## 4. Shared Naming Invariants

To prevent parameter mismatch when exchanging data between Laravel and Python, the following naming patterns are fixed:
* **Correlation Signature:** The tracking ID generated at the API Gateway MUST be named `correlation_id` across both PHP and Python.
* **Page References:** Page indexing references MUST use `page_start` and `page_end` keys.
* **Identification Keys:** Unique identifiers MUST be suffixed with `_id` (e.g. `run_id`, `lecture_id`, `section_id`).
* **Arabic Analogies:** Localized Egyptian Colloquial Arabic analogy blocks MUST be named `arabic_explanation`.
