# 06. REST API Specification

---

## 1. API Design Principles

### 1.1 REST Principles
The API is designed as a resource-oriented architecture. Resources are represented by logical URIs, and operations on these resources are executed using standard HTTP verbs, maintaining a stateless connection environment.

### 1.2 Resource and URI Naming Conventions
* **Plural Nouns:** URIs MUST represent collections using plural nouns (e.g. `/subjects`, `/lectures`, `/ingestion/runs`).
* **Hierarchy Representation:** Child resources MUST follow logical path nesting:
  `/api/v1/subjects/{subject_id}/lectures`
* **Casing:** All paths, query parameters, and JSON payload attributes MUST use lowercase snake_case (e.g. `/ingestion/runs`, `run_id`, `per_page`).

### 1.3 HTTP Verb Usage
* **`GET`:** Retrieve resource representations. MUST be safe and idempotent.
* **`POST`:** Create new resources or execute non-idempotent operations.
* **`PUT`:** Replace existing resources in their entirety or update resources. MUST be idempotent.
* **`DELETE`:** Remove resources. MUST be idempotent.

### 1.4 Statelessness & Idempotency
* **Statelessness:** Every API request MUST contain all authentication tokens and parameters necessary to execute, independent of server-side session states.
* **Idempotency:** A client executing the same `GET`, `PUT`, or `DELETE` request multiple times MUST receive the same result state as the initial execution. `POST` requests for resource creation are explicitly non-idempotent.

### 1.5 HTTP Status Code Philosophy
* **`200 OK`:** Successful retrieval or synchronous update.
* **`201 Created`:** Successful synchronous resource creation.
* **`202 Accepted`:** Successful initiation of an asynchronous, long-running operation.
* **`400 Bad Request`:** Malformed request body or invalid parameters.
* **`401 Unauthorized`:** Missing or invalid authentication token.
* **`403 Forbidden`:** Valid token, but lacks permissions to access target resource.
* **`404 Not Found`:** Resource does not exist.
* **`412 Precondition Failed`:** Optimistic locking check failed.
* **`422 Unprocessable Content`:** Request payload failed validation rules.
* **`429 Too Many Requests`:** Rate limit exceeded.
* **`500 Internal Server Error`:** Unhandled server exception.

---

## 2. API Versioning Strategy

### 2.1 URI Versioning Policy
All API endpoints MUST include the API version identifier at the root of the path:
`/api/v1/`
Breaking changes to endpoints MUST trigger an increment of the version prefix (e.g. to `/api/v2/`).

### 2.2 Deprecation and Sunset Policy
When an API version is scheduled for retirement:
* **`Deprecation` Header:** Responses from deprecated endpoints MUST return a `Deprecation: <timestamp>` header containing the date of deprecation.
* **`Sunset` Header:** Responses MUST return a `Sunset: <timestamp>` header defining the date when the endpoint will be turned off.
* **Grace Period:** Active API versions MUST be maintained for a minimum of 12 months after deprecation before sunsetting.

### 2.3 API Compatibility Rules
Additive changes (optional attributes, new fields) are allowed in minor updates and MUST NOT trigger version increments. Clients MUST ignore unknown fields.

---

## 3. Request & Response Collection Standards

### 3.1 Pagination
Collections containing lists of resources MUST support pagination:
* **Parameters:** `page` (default = 1), `per_page` (default = 15, max = 100).
* **Metadata Wrapper:**
  ```json
  {
    "data": [],
    "pagination": {
      "total": 120,
      "per_page": 15,
      "current_page": 1,
      "last_page": 8
    }
  }
  ```

### 3.2 Filtering
Resources collections MUST allow attribute filters passed via query strings (e.g., `/api/v1/subjects?status=active`).

### 3.3 Sorting
Sorting parameters MUST use the `sort` query key. Direction is indicated by prefix:
* `sort=title` (Ascending)
* `sort=-created_at` (Descending)

### 3.4 Searching
Search queries MUST utilize the `q` parameter, executing partial-text matching indexes on the backend.

---

## 4. Security & Authentication Registry

* **OAuth2 Bearer Tokens:** Access to `/api/v1/*` routes requires a Bearer token:
  `Authorization: Bearer <token>`
* **LTI Launch Tokens:** Handled via JWT payloads signed by the LMS and verified against the public keyset.
* **Tenant Isolation:** Every request context MUST extract and enforce tenant parameters to prevent cross-tenant data leaks.

---

## 5. API Request Lifecycle

The lifecycle of an incoming API request MUST execute in this exact sequence:
1. **Routing:** Matches request to endpoint and checks version path.
2. **Authentication:** Decodes JWT Bearer token or LTI signature.
3. **Rate-Limiting:** Increments client throttle count, rejecting requests exceeding limit with `429`.
4. **Validation:** Checks request parameters against schema constraints; rejects malformed inputs with `422`.
5. **Authorization:** Matches authenticated user roles against RBAC permission requirements.
6. **Execution:** Service layer executes business logic.
7. **Response Compilation:** Formats output payload and sets appropriate HTTP status codes.

---

## 6. Long Running Ingestion Operations

### 6.1 Ingestion Inception (202 Accepted)
Triggering a PDF ingestion initiates an asynchronous task:
* **Request:** `POST /api/v1/ingestion/runs`
* **Response (202 Accepted):** Returns execution run ID and resource location path.
  * **Headers:** `Location: /api/v1/ingestion/runs/{run_id}`, `Retry-After: 10`

### 6.2 Polling Strategy & Operation Status Resources
Clients MUST track ingestion progress by executing polling checks against the URL returned in the `Location` header.
* **Status Resource:** `GET /api/v1/ingestion/runs/{run_id}`
* **Response:** Returns pipeline stage status, current percentage, and artifacts generated.

### 6.3 Retry-After & Throttle Gates
Clients SHOULD respect the `Retry-After` header values (specified in seconds) when polling. Requests polling faster than the `Retry-After` limit will be throttled.

### 6.4 Job Cancellation
Active runs can be aborted:
* **Request:** `POST /api/v1/ingestion/runs/{run_id}/cancellation`
* **Response:** `200 OK` confirming execution cancel request sent.

---

## 7. Ingestion & Curriculum Retrieval API Reference

### 7.1 Ingestion Management Endpoints

#### Create Ingestion Run
* **Verb & Path:** `POST /api/v1/ingestion/runs`
* **Request Payload:**
  * `subject_id` (Integer, Required)
  * `file` (Binary PDF, Required, max 50MB)
  * `mode` (Enum: `auto`, `hil`, Required)
  * `mark_as_exam` (Boolean, Required)
* **Response (202 Accepted):**
  * **Payload:** `{ "run_id": "run_938fa8cd", "status": "processing" }`
  * **Headers:** `Location: /api/v1/ingestion/runs/run_938fa8cd`, `Retry-After: 10`

#### Get Ingestion Status
* **Verb & Path:** `GET /api/v1/ingestion/runs/{run_id}`
* **Response (200 OK):**
  * **Payload:**
    ```json
    {
      "run_id": "run_938fa8cd",
      "status": "awaiting_approval",
      "progress_percentage": 50,
      "current_stage": "structure"
    }
    ```

#### Resume HIL Run
* **Verb & Path:** `POST /api/v1/ingestion/runs/{run_id}/approvals`
* **Response (202 Accepted):**
  * **Payload:** `{ "run_id": "run_938fa8cd", "status": "resumed" }`

### 7.2 HIL Node Editor API

#### Get Draft Structure
* **Verb & Path:** `GET /api/v1/ingestion/runs/{run_id}/structure`
* **Response (200 OK):**
  * **Payload:** Matches `structure.json` contract (from [05_JSONContracts.md](file:///D:/projects/laravel_projects/college_project/Conversations/05_JSONContracts.md)).
  * **Headers:** `ETag: "w/398facd928b9"` (checksum hash of the file).

#### Update Structure (Optimistic Locking Enforced)
* **Verb & Path:** `PUT /api/v1/ingestion/runs/{run_id}/structure`
* **Headers Required:** `If-Match: "w/398facd928b9"`
* **Request Payload:** Modified structure object.
* **Response (200 OK):** Updates structure successfully.
* **Error Response (412 Precondition Failed):** If ETag has changed on disk since retrieval.

---

## 8. LTI 1.3 Advantage Integration API Reference

### 8.1 OIDC Initializer (`GET /lti/v1/login`)
* **Purpose:** Handles the initial OIDC handshake from the LMS, redirecting the browser to the LMS authorization gate.

### 8.2 Launch Gateway (`POST /lti/v1/launch`)
* **Purpose:** Target link endpoint that decodes the LMS JWT token, logs in the student, and launches the iframe view player.

### 8.3 JWKS Keysets (`GET /lti/v1/jwks`)
* **Purpose:** Public JWK keyset endpoint accessed by the LMS to verify platform launch tokens.

### 8.4 Deep Linking Tool Selection (`POST /lti/v1/select`)
* **Purpose:** Returns deep-linked resources selected by the professor to the LMS.

---

## 9. Event-Driven Notifications & Integration Policy

### 9.1 Webhook & Streaming Decision
The platform MUST NOT support real-time WebSockets, Webhooks, or Server-Sent Events (SSE) for ingestion monitoring. 
* **Rationale:** Higher education IT policies and local proxy servers frequently block persistent connection pipes (WebSockets/SSE). To guarantee portability across restricted technological university intranets, progress tracking MUST rely strictly on HTTP polling strategies.

---

## 10. Unified Error Framework

### 10.1 Standard Error Response Contract
All validation and runtime errors MUST return a standardized JSON envelope:
```json
{
  "error_code": "RESOURCE_NOT_FOUND",
  "message": "The requested course asset does not exist.",
  "correlation_id": "err_01j198fa8bc",
  "errors": []
}
```

### 10.2 Public-Safe Error Enforcement
Under no circumstances MAY the API expose raw stack traces, Python tracebacks, Tesseract exit codes, or LLM API exception payloads to the client. All internal process failures MUST be logged securely on the server with a unique `correlation_id`, returning only public-safe messages and error codes to the user.
