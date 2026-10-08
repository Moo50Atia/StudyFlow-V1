# V3 local meeting walkthrough

**Purpose:** show the current V3 teacher intake interface and report the successful deterministic offline pipeline gate without presenting it as a live-provider run.

## Open the local app

The Laravel development server is **stopped**. If you choose to show the UI, start it from the repository root; it will bind to loopback only:

```sh
php artisan serve --host=127.0.0.1 --port=8087
```

The existing local seed account is `teacher@test.com` / `Password`. The local admin seed account is `admin@test.com` / `Password`. These are development-only credentials from `database/seeders/MohammedSeeder.php`; do not use them outside this local app.

## Five-minute walkthrough

1. Open `http://127.0.0.1:8087/login` and sign in as the local teacher.
2. Open `http://127.0.0.1:8087/v3/teacher`. Show the actual PDF upload form and the processing workspace.
3. Open `http://127.0.0.1:8087/v3/teacher/pdf-uploads` to show the attempt-history/status page.
4. Sign out, sign in as the local admin, and open `http://127.0.0.1:8087/v3/admin/settings`. This shows the operational settings surface; approved templates and an active settings version have not been provisioned in this local database.
5. While signed in as admin, open `http://127.0.0.1:8087/v3/admin/operations/health`. It returns the current authenticated operational probe. At review time it reported `temporal_bridge: unavailable`, `provider_health: unknown`, and zero workflow/review backlog.

The UI and route checks described above were verified locally before the server was stopped. Separately, the connected offline integration gate now pushes an accepted PDF fixture through actual extraction, Laravel persistence, local Temporal, source-scoped NotebookLM/JEV/Gemini test transports, real browser qualification and teacher review/correction. It also proves the corrected successor can be approved and its exact decision consumed. The upload page itself was not exercised in that gate. No external provider was contacted. The server should be stopped again after the walkthrough.

## Accurate status to present

The local V3 UI has a teacher PDF intake form, upload-attempt history, case progress, mapping, scene qualification, review, and admin operations/settings routes. The offline pipeline's backend lifecycle is now proven with deterministic provider fixtures, but the local environment is still not ready for a live PDF-to-scene run using external providers.

The blockers are cumulative; the JEV key is real but is not the first gate:

1. **No active approved settings version.** The local database contains zero `v3_settings_versions`, and the approved-template catalog has not been populated. Do not publish unapproved prompt templates just to make the demo progress.
2. **PDF safety tools absent.** `qpdf`, `clamdscan`, and `clamscan` are not installed. The intake is designed to fail closed without structural PDF validation and malware scanning.
3. **Temporal bridge unavailable.** The actual admin health probe currently reports this. A local durable worker/service and registered workflows are required after a case can be accepted.
4. **JEV is not configured.** `.env` has no `TYPESAFE_API_KEY` or `JEV_MODEL`; a valid JEV credential and approved model are required for typed qualification.
5. **NotebookLM is not configured for a live run.** The local environment has no authenticated NotebookLM MCP session/source binding to perform grounded mapping and prompt conversations.
6. **Internal callback secrets are absent.** `V3_BRIDGE_HMAC_KEY` and `V3_CALLBACK_HMAC_KEY` are unset. Production callbacks correctly fail closed when those secrets are missing.

Do not tell the meeting that a missing JEV key alone explains the earlier live-run blocker. A deterministic fake JEV response was enough to test the connected backend; an authorized `TYPESAFE_API_KEY` and verified `JEV_MODEL` are still required to prove a real provider call. Approved settings/templates, PDF safety tools, NotebookLM session and callback secrets are also needed for a real upload run.

## Meeting wording

> “We have the V3 teacher intake and operations surfaces. We also completed a connected offline test that took an accepted PDF through extraction, mapping, prompt qualification, scene generation, real browser checks, teacher approval, a correction, and approval of the corrected scene. NotebookLM, JEV, and Gemini were deterministic test transports, so this proves our local Laravel/Temporal/worker and browser integration, not access to the live providers. To run the same journey from the interactive upload page against live services, we still need approved templates/settings, PDF safety tools, callback secrets, NotebookLM authentication, and a JEV key/model.”

## Next evidence needed for a real pipeline demo

For a live-provider demonstration, provision approved prompt templates and publish their settings version through the admin flow; install and verify qpdf and ClamAV; set distinct explicit bridge and callback HMAC secrets; start the local Temporal service/worker with workflows registered; configure NotebookLM MCP authentication; then configure the authorized JEV API key and verified `JEV_MODEL`. Use a non-sensitive test PDF and capture each persisted state and the final teacher review. The site is currently stopped. Do not substitute mocks or placeholder templates for live acceptance evidence.
