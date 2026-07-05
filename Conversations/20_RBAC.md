# 20. Role-Based Access Control (RBAC) Specification

---

## 1. Security Design Principles

The platform's authorization layer MUST enforce the following security rules:
* **Least Privilege Access:** Users MUST only be granted the minimum permissions required to perform their roles.
* **Zero-Trust Security Boundaries:** Every API request crossing the subsystem boundary MUST execute explicit authentication and authorization validation.
* **Tenant Isolation:** Data access queries MUST be filtered by tenant scope at the database level, preventing cross-tenant data leaks.
* **Traceable Security Audits:** Failed authorization attempts and permissions modifications MUST be logged to the audit system.

---

## 2. System Roles Hierarchy

The platform defines four logical system roles:

```
[ Super Admin (Global Scope) ]
            │
            ▼
[ IT Admin (Tenant Scope) ]
            │
            ▼
[ Teacher / Course Coordinator ]
            │
            ▼
[ Student / Learner ]
```

* **Super Admin (Global Scope):** Unrestricted access across all tenant databases, global queue metrics, and system configuration setups.
* **IT Admin (Tenant Scope):** Administrative access restricted to a single tenant context. Manage tenant users, permissions, and view tenant billing logs.
* **Teacher / Course Coordinator:** Can upload, review, and publish courses *only* for Subjects assigned to them in the permissions records.
* **Student / Learner:** Read-only access. Can view published lessons, play interactive canvases, and bookmark favorites.

---

## 3. System Permissions Registry

The system enforces granular permission gates:
* `manage-tenants`: Create, update, or suspend tenant configurations.
* `manage-users`: Provision user accounts and assign roles.
* `manage-permissions`: Assign teacher access to subjects.
* `view-system-metrics`: Access queue performance and global token spend.
* `ingest-curriculum`: Upload textbooks and trigger ingestion runs.
* `edit-curriculum`: Modify outline nodes inside the HIL Node Editor.
* `view-analytics`: Review NAQAAE tech utilization and student mastery logs.
* `view-scenes`: Interact with published dynamic visual views.
* `toggle-bookmarks`: Favorite or unfavorite sections and questions.

---

## 4. Role-Permission Mapping Matrix

| Permission | Super Admin | IT Admin | Teacher (Permitted) | Student |
| :--- | :---: | :---: | :---: | :---: |
| `manage-tenants` | Yes | No | No | No |
| `manage-users` | Yes | Yes | No | No |
| `manage-permissions` | Yes | Yes | No | No |
| `view-system-metrics` | Yes | Yes | No | No |
| `ingest-curriculum` | Yes | Yes | Yes | No |
| `edit-curriculum` | Yes | Yes | Yes | No |
| `view-analytics` | Yes | Yes | Yes | No |
| `view-scenes` | Yes | Yes | Yes | Yes |
| `toggle-bookmarks` | Yes | Yes | Yes | Yes |

---

## 5. Laravel Enforcement Middleware Design

### 5.1 `RoleMiddleware`
* **Purpose:** Restricts route group access based on specific role parameters.
* **Logic:** Checks the authenticated user's `role` attribute. If it does not match, the middleware aborts the request, returning `403 Forbidden`.

### 5.2 `PermissionMiddleware`
* **Purpose:** Restricts endpoint access using granular permission keys.
* **Logic:** Evaluates the user's role capabilities against the permissions matrix.

### 5.3 `TenantIsolationMiddleware`
* **Purpose:** Enforces data isolation boundaries.
* **Logic:** Extracts the tenant identifier from the user's session or LTI token context. The middleware MUST append this tenant ID to the active database query filter context, ensuring all database calls are scoped to that tenant.

### 5.4 `ResourceOwnershipMiddleware` (Teacher Check)
* **Purpose:** Dynamically validates teacher ownership boundaries.
* **Logic:**
  1. If user is Super Admin or IT Admin, allow.
  2. If user is Student, block.
  3. If user is Teacher:
     * Extract target resource IDs (`subject_id`, `lecture_id`, `section_id`) from the request path.
     * Query `teacher_permissions` to verify the teacher has permissions for the parent `Subject` ID.
     * The check MUST execute a **Recursive Scope Check**: if a teacher has permissions to a Subject, they inherit access to all child lectures, sections, and questions nested within it. If no permission match is found, the middleware aborts with `403 Forbidden`.

---

## 6. LTI 1.3 Role Mapping & JIT Isolation

* **Role Mapping Whitelist:** The LTI launch gateway MUST parse incoming LMS role claims using a strict mapping whitelist:
  * Instructors / Editors (e.g. `http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor`) map to `teacher`.
  * Learners / Members (e.g. `http://purl.imsglobal.org/vocab/lis/v2/membership#Learner`) map to `student`.
  * Unrecognized or unmapped roles MUST default to `student`.
* **Telemetry Security:** The student log API (`POST /api/v1/interactions/logs`) MUST verify that the `section_id` resource belongs to the tenant organization extracted from the active LTI launch token. If a tenant mismatch is detected, the request MUST be rejected, logging a security violation.

---

## 7. Authorization Audit Logging Policy

* **Log Events:** Any failed authorization attempt on ingestion triggers, HIL Node Editor updates, or administrative portals MUST log an entry in the system audit logs.
* **Audit Metadata:** Security audit logs MUST capture:
  * Triggering User ID.
  * Request Path and IP Address.
  * Attempted Permission Key.
  * Correlation ID.
  * Timestamp.
* **Log Severity:** Failed attempts by authenticated users to access admin resources MUST be logged with `High` severity.
