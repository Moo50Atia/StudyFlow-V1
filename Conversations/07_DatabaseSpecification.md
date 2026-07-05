# 07. Database Schema & Data Specification

---

## 1. Relational Database Requirements

### 1.1 ACID Compliance
The primary database system MUST support full ACID (Atomicity, Consistency, Isolation, Durability) transaction semantics to guarantee data integrity during multi-stage ingestion synchronization runs.

### 1.2 Character Encoding & Collation Requirements
The database engine MUST store text using a character representation that supports multi-byte Unicode strings (incorporating standard Arabic characters, special mathematical symbols, and clinical characters), ensuring sorting and searching are executed without character corruption.

### 1.3 Storage and Engine Expectations
The storage subsystem MUST support row-level locking, foreign key constraint enforcement, and index-based query execution plans to prevent thread lockups during active student telemetry logging.

---

## 2. Database Design Principles

### 2.1 Normalization and Denormalization Policies
* **Normalization:** Core transactional entities (Subject, Lecture, Section, Assessment) MUST be normalized to Third Normal Form (3NF) to eliminate data redundancy and prevent update anomalies.
* **Denormalization:** Analytics tables tracking student metrics MAY deploy selective denormalization (such as embedding user details or course context keys directly) to optimize read performance and reduce expensive join queries during live audits.

### 2.2 Aggregate Boundaries
The system defines the **Lecture** entity as the primary Aggregate Root. All child elements—including Chapters, Mini-Chapters, Lessons, and Sections—exist within the boundary of the Lecture. Modifying these child entities MUST trigger version updates on the parent Lecture root.

### 2.3 Entity Ownership & Lifecycle
Child entities MUST NOT exist independently of their parents. Deleting a parent Lecture MUST trigger cascading deletions of all child Lessons, Sections, and Dynamic Views to prevent orphaned rows.

### 2.4 Referential Integrity Philosophy
Foreign keys MUST be declared on all relational linkages. Deletion cascades MUST be explicitly defined. Nullability is rejected for relational keys.

### 2.5 Data Lifecycle Principles
* **Active Data:** Frequently queried core academic structures.
* **Hot Telemetry Data:** Student interaction logs from the current academic semester.
* **Archive Policy:** At the end of each semester, detailed interaction logs MUST be aggregated into historical summary tables and old raw logs migrated to archival database storage.

---

## 3. Database Naming Standards

### 3.1 Table Naming Conventions
* Table names MUST use lowercase plural nouns separated by underscores (e.g. `users`, `lectures`, `student_interaction_logs`).
* Join tables for many-to-many relationships MUST use singular names of the tables in alphabetical order (e.g. `lecture_tag`).

### 3.2 Column Naming Conventions
* Columns MUST use lowercase snake_case (e.g. `created_at`, `dynamic_view_link`).
* Primary keys MUST be named `id`.
* Foreign keys MUST use the singular table name followed by `_id` (e.g. `lecture_id`).

### 3.3 Constraint & Key Naming Standards
Constraint keys MUST be named following a standard pattern:
* Primary Keys: `pk_{table_name}`
* Foreign Keys: `fk_{table_name}_{column_name}`
* Unique Keys: `uk_{table_name}_{column_name}`

### 3.4 Index Naming Standards
* Single Column Indexes: `idx_{table_name}_{column_name}`
* Composite Indexes: `idx_{table_name}_{column1}_{column2}`

---

## 4. Concurrency Strategy

### 4.1 Optimistic Concurrency Controls
For HIL structure modifications, the `lectures` table MUST maintain an optimistic concurrency field (such as an ETag hash or version integer). When updating:
* The client request MUST send the original version identifier.
* The update transaction MUST verify that the record version on disk matches the requested version.
* If a mismatch is detected, the transaction MUST abort and return a precondition failure.

### 4.2 Pessimistic Concurrency Controls
Pessimistic locking (blocking read/write sessions) MUST be reserved for critical operations such as LTI roster sync conflicts and user creation operations.

---

## 5. Transaction Strategy

### 5.1 Ingestion Transaction Boundaries
Synchronization of pipeline output artifacts MUST execute inside a single transaction boundary:
* If the sync fails at any point (e.g., duplicate question, broken page offset, missing section ID), all database inserts and updates MUST rollback completely.

### 5.2 User Action Transaction Boundaries
Operations like bookmark toggles or audit logging MUST execute within individual, high-speed transactions to minimize lock durations.

### 5.3 Read Isolation Levels
Read actions SHOULD use the database's default read-committed isolation level, avoiding dirty reads while maintaining high query throughput.

---

## 6. Audit & Data Governance

### 6.1 Metadata Tracking Envelopes
Every core entity table MUST contain the following tracking fields:
* `created_at` / `updated_at`: Date timestamps.
* `deleted_at`: Soft delete timestamp (for recovery).
* `created_by` / `updated_by` / `deleted_by`: References to the user ID executing the action.

### 6.2 Change Audit Requirements
Any modifications made to course structures (especially Node Editor modifications by teachers) MUST log an entry in the `ingestion_audit_logs` table, storing the original state, modified state, and actor ID.

### 6.3 Retention Policies
* **Audit logs:** Retained indefinitely.
* **Student Telemetry logs:** Retained in transactional database for 1 year, then moved to cold archive.

---

## 7. Entity Architecture & Logical Data Models

### 7.1 Logical Entities Map
* **Subject:** The parent department course catalog node.
* **Lecture:** The core course curriculum document.
* **Section:** Granular conceptual lesson blocks.
* **Dynamic View:** Visual scene metadata, widget definitions, and links to storage.
* **Question:** Extensible practice and assessment queries.
* **Interaction Log:** Student telemetry events.

---

## 8. Physical Schema Specifications

### 8.1 Table Specifications (Conceptual Fields)

#### Table: `users`
* `id`: Big Integer, Auto-increment (Primary Key)
* `name`: String, Not Null
* `email`: String, Unique, Not Null
* `password`: String, Not Null
* `role`: String/Enum (Admin, Teacher, Student)
* Standard Metadata Envelope (`created_at`, `updated_at`, `created_by`, `updated_by`)

#### Table: `subjects`
* `id`: Big Integer, Auto-increment (Primary Key)
* `name`: String, Not Null
* `description`: Text, Nullable
* Standard Metadata Envelope

#### Table: `lectures`
* `id`: Big Integer, Auto-increment (Primary Key)
* `subject_id`: Big / FK (References `subjects.id`, Cascade Delete)
* `title`: String, Not Null
* `summary`: Text, Nullable
* `pdf_path`: String, Nullable
* `version_tag`: String, Not Null (For Optimistic Locking)
* Standard Metadata Envelope

#### Table: `sections`
* `id`: Big Integer, Auto-increment (Primary Key)
* `lecture_id`: Big / FK (References `lectures.id`, Cascade Delete)
* `title`: String, Not Null
* `quick_summary`: Text, Nullable
* `core_concept`: Text, Nullable
* `egyptian_explain`: Text, Nullable
* `formulas`: Text, Nullable (Canonical Formula string)
* `real_life`: Text, Nullable (Supports clinical scenario data)
* `dynamic_view_link`: String, Nullable (Path link to the compiled HTML file in Public Storage)
* Standard Metadata Envelope

#### Table: `dynamic_view_models`
* `id`: Big Integer, Auto-increment (Primary Key)
* `section_id`: Big / FK (References `sections.id`, Cascade Delete, Unique)
* `blueprint_json`: JSON, Not Null (Widget parameters, types, controls)
* `version`: Integer, Default 1
* `is_generated`: Boolean, Default False
* Standard Metadata Envelope

#### Table: `questions`
* `id`: Big Integer, Auto-increment (Primary Key)
* `lecture_id`: Big / FK (References `lectures.id`, Cascade Delete)
* `question_text`: Text, Not Null
* `question_type`: String, Not Null (Extensible MCQ, fill_blank, open_ended)
* `idea_text`: Text, Nullable
* `hint`: Text, Nullable
* `polymorphic_details`: JSON, Nullable (Options array, correct indexes, validation regexes)
* `dynamic_view_link`: String, Nullable (Optional canvas link)
* Standard Metadata Envelope

#### Table: `exam_questions`
* `id`: Big Integer, Auto-increment (Primary Key)
* `lecture_id`: Big / FK (References `lectures.id`, Cascade Delete)
* `question_text`: Text, Not Null
* `idea`: Text, Nullable
* `explanation`: Text, Nullable
* `dynamic_view_link`: String, Nullable
* Standard Metadata Envelope

#### Table: `teacher_permissions`
* `id`: Big Integer, Auto-increment (Primary Key)
* `teacher_id`: Big / FK (References `users.id`, Cascade Delete)
* `subject_id`: Big / FK (References `subjects.id`, Cascade Delete)
* `lecture_id`: Big / FK (References `lectures.id`, Cascade Delete, Nullable)
* Standard Metadata Envelope

#### Table: `user_loves`
* `id`: Big Integer, Auto-increment (Primary Key)
* `user_id`: Big / FK (References `users.id`, Cascade Delete)
* `loveable_id`: Big Integer, Not Null
* `loveable_type`: String, Not Null
* Standard Metadata Envelope

#### Table: `student_interaction_logs`
* `id`: Big / Primary Key
* `user_id`: Big / FK (References `users.id`, Cascade Delete)
* `section_id`: Big / FK (References `sections.id`, Cascade Delete)
* `session_context`: String/Enum (presentation, study)
* `time_spent_seconds`: Integer, Default 0
* `pre_score`: Integer, Nullable
* `post_score`: Integer, Nullable
* Standard Metadata Envelope

#### Table: `ingestion_audit_logs`
* `id`: Big / Primary Key
* `run_id`: String, Not Null
* `actor_id`: Big / FK (References `users.id`, Cascade Delete)
* `action`: String, Not Null
* `original_state`: JSON, Nullable
* `modified_state`: JSON, Nullable
* `created_at`: Timestamp

### 8.2 Indexing & Query Optimization Strategy
* **Polymorphic Queries:** Define composite indexes on polymorphic identifiers (e.g. `loveable_type`, `loveable_id`) to accelerate favorites lookups.
* **Telemetry Queries:** Composite index on `user_id` and `section_id` in logs to speed up student progress analytics.
* **Course Navigation:** Index on foreign keys (`subject_id` in `lectures`, `lecture_id` in `sections`) to ensure fast traversal of the course tree during LMS launches.
