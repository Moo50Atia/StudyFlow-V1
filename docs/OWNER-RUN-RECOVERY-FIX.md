# Owner recovery dashboard repair — 2026-10-22

The frontend linked to `/v3/admin/workspace`, but Laravel exposed only `/v3/admin`. The alias now renders the existing Operations Console. The held local-worker run had no dashboard recovery controls even though the underlying owner APIs existed.

The owner Dashboard and Global Subject Runs now link held authoring tasks to an owner-only recovery page. **Check worker** creates the existing read-only reconciliation assignment. The running outbound daemon collects the retained terminal evidence automatically, once per assignment. After evidence arrives, the owner can select **Continue processing**, which uses the existing hash/task/terminal-identity checks and fenced resume path. Evidence submission alone never releases the hold. Other administrators and teachers cannot authorize recovery. Expired/corrupt/unsupported evidence cannot be used by the existing resolve endpoint.

The actual uploaded run `35b4ca42-5d45-4e2d-a06b-27d26c93e7a5` is still paused at its saved terminal authentication checkpoint. Its terminal ID remains `db7ec876-3cae-5f1a-8500-22a1985d0759`; no replacement submission or provider prompt was sent during this repair. It is left held for the user's manual test.

Validation:

- PHP local-worker/access/progress suites: **66 passed, 553 assertions**. Regression coverage includes the workspace alias, actual recovery Inertia component, owner restriction, evidence projection, corrupted-evidence rejection, and existing fenced recovery authorization.
- Python local-worker suite: **67 passed, 1 gated live test skipped**. New coverage verifies automatic evidence collection only for an owner-issued assignment, once per assignment epoch, without invoking authoring/resume during evidence collection.
- Frontend production build passed.
- Updated PHP/frontend images were built and deployed for app/web; all eight persistent product services remained healthy afterwards. The standalone outbound daemon was restarted to load its evidence-collection handler.
- Authenticated HTTP checks against the actual product returned 200 with the expected Inertia components for `/v3/admin/workspace`, `/dashboard`, `/v3/admin/subject-runs`, and the actual task's reconciliation page. The actual owner Dashboard exposes the run's recovery link, and its recovery page reports **Check worker** available. The temporary verification login session was removed afterwards.

Recovery page for the current run:
`http://127.0.0.1:8766/v3/admin/local-worker/tasks/ae2cec73-969a-4208-95dc-94eb3a94c678/reconciliation`
