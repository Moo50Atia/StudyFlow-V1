# Live outbound worker progress

The admin page refreshed every five seconds but could only show imported Laravel counts. NotebookLM lesson bundles remained in the laptop's terminal workspace until the PDF handoff, so its running saved count stayed invisible to the product.

Added a progress-only outbound companion (`eduplatform-local-progress.service`) under `bin/local-worker-runtime`. It reads the existing claim and terminal link, verifies the terminal identity, queries the terminal CLI's read-only status, and posts allowlisted scalar counters through the existing enrolled device authentication. It does not claim tasks, renew leases, release holds, resume workflows, or contact NotebookLM. The runtime installer/start/stop workflow manages this service alongside the existing three services. It can observe a currently held/expired assignment without changing its authority, allowing an already-running terminal to remain visible while Laravel awaits reconciliation.

`POST /api/v3/local-worker/v1/tasks/{task}/progress` verifies the device grant, authoring capability, assignment identity, and latest epoch. Unknown fields and stale epochs are rejected. The server retains the most recent sequence in its operational cache for 24 hours; old snapshots cannot overwrite newer ones. Reports cannot change task/job/run states or final imported lesson records. The same `RunProgress` projection now supplies the counters to teacher and admin views, with receipt timestamp and stale-report indication. Admin uses the shared `LessonProgress` component and Inertia polling every five seconds. The per-PDF table explicitly labels its imported counts; worker saved counts are separate. No lesson text, prompt bodies, filenames, or paths are transmitted in telemetry.

A separate hold occurred at 16:11:52 UTC: a terminal **status** subprocess exited with native signal `-11`. The NotebookLM workflow continued; the adapter treated the lost observation as an ambiguous effect and held its hosted task. Status commands now retry up to three times. Mutating commands remain single-send; timeouts on mutations are not retried. Existing holds are not silently cleared.

Validation:

- PHP local-worker, web-pipeline, and access suites: 260 passed, one existing risky readiness test, 2,796 assertions.
- Python local-worker suite: 70 passed, one gated test skipped.
- New regression coverage: fencing, unknown-field rejection, out-of-order snapshots, unchanged hold/lease, status crash retry, and no mutation retry. Progress reporter tested to issue only read-only observation plus its telemetry post.
- Production frontend build and 353-pattern frontend route audit passed.
- Live Chromium loaded the actual admin run page, observed the worker count (16 lessons), and verified automatic polling returned HTTP 200 with the current snapshot, without rendering errors.
- All eight product containers and all four local services were healthy. Terminal execution was not interrupted; the existing hosted reconciliation hold was preserved for owner recovery.
