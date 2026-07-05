# 14. Production Deployment & Infrastructure Specification

---

## 1. Deployment Design Principles

### 1.1 Portability & Containerization
Subsystems MUST be packaged as containerized applications. Configuration variables, libraries, and binaries MUST reside entirely within the container namespace to eliminate version conflicts and ensure execution environments remain identical across staging and production hosts.

### 1.2 Data Sovereignty & Compliance
To satisfy data-sovereignty mandates (such as public university policies), the deployment architecture MUST support isolation:
* All database transactions, student telemetry logs, and compiled interactive canvas assets MUST reside on hosting servers physically located within the client's country or on-premise university intranet.

### 1.3 Environment Parity Policy
Configuration configurations across Development, Testing, Staging, and Production environments MUST remain identical. Variations between environments are restricted to scaling sizes (e.g. CPU/RAM limits, replica counts) and access secrets, never software versions or package libraries.

---

## 2. Host System Dependencies

### 2.1 Required Host Binaries
For environments executing the Python pipeline, the base image MUST provide:
* **Python Runtime:** Python 3.10 or higher.
* **OCR Binary:** Tesseract OCR.
* **Poppler Utilities:** Native system utilities required to render PDF page streams to raw image buffers.

### 2.2 Execution Resource Constraints
The ingestion queue worker container MUST be allocated a minimum of **2 CPU cores** and **4GB RAM** to execute OCR and layout chunking tasks without trigger thread crashes.

---

## 3. Deployment Topologies

```
┌────────────────────────────────────────────────────────────────────────┐
│ 1. Single-Server Stack (Docker Compose)                                │
│ [App Container] + [Worker Container] + [Cache/Queue] + [Local Database]│
└────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ (Scale Out)
┌────────────────────────────────────────────────────────────────────────┐
│ 2. Multi-Node Scaled Stack                                             │
│ Web Node(s) ──► Load Balancer                                          │
│ Worker Node(s) ──► Isolated Ingestion processing                       │
│ Persistence ──► Dedicated Database Node + Dedicated Cache/Queue Node   │
└────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ (Enterprise HA)
┌────────────────────────────────────────────────────────────────────────┐
│ 3. High Availability Kubernetes Topology                               │
│ Stateless Web Pods (Autoscaling)                                       │
│ Isolated Ingestion Worker Pods (Scale-to-zero / Queue-depth triggered) │
│ Replicated DB (Primary/Secondary) + Redundant Cache Cluster            │
└────────────────────────────────────────────────────────────────────────┘
```

### 3.1 Single-Server Containerized Stack
A single-host deployment containing the Web portal, Ingestion Worker, Cache, and Database in isolated containers. Shared persistent volumes connect the Worker's output directory to the Web portal's public assets folder.

### 3.2 Multi-Node Scaled Stack
Web traffic and processing loads are separated onto isolated hosts:
* **Web Nodes:** Stateless containers handling user logins, dashboard, and LTI launches.
* **Worker Nodes:** Dedicated containers executing background queue jobs and Python pipeline subprocesses.
* **Persistence Tier:** Dedicated database and queue nodes.

### 3.3 High Availability (HA) Enterprise Architecture
Web nodes are deployed behind an active Load Balancer. The database uses a primary-secondary replication structure with automated failover. Active queues are clustered across multiple nodes to prevent single-point-of-failure outages.

### 3.4 Managed Orchestration Topology (Kubernetes)
Web and Worker subsystems are deployed as stateless, auto-scaling pods. Ingestion Worker pods are scaled dynamically based on the active queue depth of the `ingestion` queue, scaling down to zero when the queue is empty to conserve CPU resources.

---

## 4. Secrets & Configuration Management

### 4.1 Secrets Lifecycle & Encryption
All cryptographic credentials, LTI private keys, database users, and LLM API keys MUST be treated as secret configurations:
* Secrets MUST be encrypted at rest on the hosting infrastructure.
* Secrets access permissions MUST restrict retrieval to only the executing worker process.

### 4.2 Rotation Strategy
* LTI keys and database users credentials MUST be rotated annually.
* LLM API keys MUST be rotated every 90 days.
* System configurations MUST support seamless key rotation without requiring application downtime.

### 4.3 Secure Injection Methodologies
Secrets MUST NOT be written to files inside the codebase repository. They MUST be injected as environment variables directly into the executing container runtime at launch.

### 4.4 Environment Isolation Bounds
Production secrets MUST remain isolated. Staging and Testing containers MUST NOT have access to production databases, S3 buckets, or encryption keys.

---

## 5. Scalability Strategy

* **5.1 Horizontal Web Scaling:** As student traffic peaks, additional web portal containers MUST scale out behind the Load Balancer.
* **5.2 Worker Pool Scaling:** Ingestion workers MUST scale independently based on the size of the ingestion queue.
* **5.3 Database & Cache Scaling:** Read-heavy analytics queries (NAQAAE metrics) MUST route to database read replicas, preventing locking contentions on the primary write database.
* **5.4 Storage Scaling:** Compiled visual HTML files MUST be stored on a highly available Object Storage service and served via a Content Delivery Network (CDN) to ensure low latency globally.

---

## 6. Operational Monitoring & Health Checks

* **6.1 Liveness & Readiness Probes:**
  * **Liveness Probe:** Periodically checks that the PHP-FPM and Python worker daemons are running, restarting containers if a thread lock is detected.
  * **Readiness Probe:** Checks database connectivity and public storage availability before allowing the Load Balancer to route traffic to the container.
* **6.2 Metrics and Logs Collection:** All log outputs (stdout/stderr) MUST be collected by log aggregators.
* **6.3 Observability Alerting Criteria:** Alerts MUST trigger immediately if:
  * Ingestion queue depth exceeds thresholds defined in `15_Configuration.md`.
  * The Dead Letter Queue (DLQ) receives a message.
  * Health check probes return failure states for more than 3 consecutive checks.

---

## 7. Backup & Disaster Recovery

* **7.1 Backup Strategy:**
  * **Transactional Database:** Daily full backups, plus hourly incremental log backups.
  * **Compiled Assets:** Daily snapshot backups of object storage paths.
* **7.2 Recovery Point Objective (RPO) and Recovery Time Objective (RTO):**
  * **RPO (Maximum acceptable data loss):** 1 Hour.
  * **RTO (Maximum acceptable recovery duration):** 4 Hours.
* **7.3 Restore Verification Protocols:** Backup restoration scripts MUST be executed monthly in an isolated staging namespace to verify database tables mount and decrypt correctly.

---

## 8. Release & Rollback Strategy

### 8.1 Zero-Downtime Releases
Code deployments MUST use rolling updates. A new container instance is launched and must pass its readiness probe before the load balancer routes traffic to it and terminates the old instance.

### 8.2 Application & Queue Rollback Policies
If a release fails validation checks:
* The container orchestration tier MUST instantly roll back to the previous stable image tag.
* Database migrations MUST support reversing actions via down-migration paths.
* Active queue jobs MUST be paused and restored to their pre-deployment state.

### 8.3 Generated Asset Rollback Policies
Changes to the compiler engine that produce corrupt HTML visual assets MUST NOT overwrite existing published versions. Rollbacks to previous stable versions of the compiler engine MUST compile and restore the visual library using cached JSON structures.

---

## 9. Deployment Validation Checklist

The following verification steps MUST execute successfully post-deployment before traffic is routed to the new release:

* **[ ] Database Migrations Check:** Verify all tables are successfully updated and schema versions match.
* **[ ] Active Queue Worker Handshake Check:** Verify that the queue worker daemon is active and listening to the `high`, `default`, and `ingestion` queues.
* **[ ] Storage Symlink Check:** Verify that the public storage link exists and `/public/storage/interactive_scenes/` resolves to `/storage/app/public/interactive_scenes/`.
* **[ ] LTI Connection Handshake Verification:** Run keyset check endpoint (`GET /lti/v1/jwks`) to confirm public keys are accessible.
* **[ ] Python Pipeline Subprocess Verification Test:** Run a test pipeline script using a sample 1-page PDF fixture to confirm Tesseract, Poppler, and Python libraries are working.
* **[ ] Liveness Probe Verification:** Confirm that health check endpoints report success states.
