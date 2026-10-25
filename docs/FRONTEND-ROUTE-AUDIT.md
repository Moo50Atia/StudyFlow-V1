# Frontend route repair — 2026-10-22

The run link `/v3/teacher/runs/{run}` was unregistered. Admin navigation also linked into teacher-only pages. Added ownership-checked legacy redirects, an admin operational run detail page, and corrected admin navigation without granting admins teacher-content access.

Added the missing teacher review queue (scoped to the authenticated teacher), admin workspace/case/evidence entry points, and corrected approved-scene and private image URLs. The evidence entry point defaults to the last seven days; the strict metrics endpoint retains its contract.

Validation:

- `npm run audit:routes`: 353 frontend URL/method patterns, zero mismatches. This checks static route shapes and admin links into teacher-only pages; it does not prove every data-dependent request succeeds.
- All 270 registered controller actions are public and exist (810 assertions).
- Access, admin, local-worker, review, and web-pipeline feature suites: 345 passed, 1 risky, 2,936 assertions. The preexisting readiness test can make no assertions when CLI upload limits already meet its threshold.
- Production frontend build passed.
- New regression coverage verifies role-aware redirects, ownership denial, admin run rendering, evidence entry point, case alias, and teacher-isolated review queue.

The original uploaded PDF run is preserved. These checks do not authorize or perform reconciliation, provider generation, or run continuation.

Live authenticated HTTP checks after deployment passed: the exact reported run URL resolves to `Admin/RunShow` for the owner and `Teacher/RunShow` for the owning teacher; admin run detail, workspace, dashboard, run index, evidence, and recovery pages all returned HTTP 200 with the expected Inertia components. The teacher global review queue also returned HTTP 200. Recovery remains available and the original run remains held.

## Browser rendering follow-up

HTTP 200 and valid Inertia props did not establish that React rendered successfully. Chromium reproduced a blank green page on the admin subject-runs index: React error 31, caused by rendering `{code, visibility, message}` failure objects directly as children. The index now renders their message/code text. Its confirmation dialog now uses the actual `description`, `variant`, and `isLoading` props.

Browser navigation also found two separate issues: desktop/mobile shared navigation exposed teacher-only links to the owner, and the operations index treated nested `OperationSummaryV1.case` records as flat rows, producing `/v3/admin/operations/undefined`. Both are corrected without relaxing route permissions.

Repeatable browser audit:

```sh
python3 scripts/audit/rendered-pages.py \
  --run-id 35b4ca42-5d45-4e2d-a06b-27d26c93e7a5 \
  --report /tmp/frontend-render-final.json
```

Requires Python Playwright with Chromium and the local running Docker product. The script creates and removes temporary sessions for existing admin, run-owning teacher, and student accounts, and checks guest pages. It follows navigation links, samples up to two existing records per URL shape, records JavaScript errors, failed assets/API responses, malformed links, empty pages, and unexpected HTTP errors. Expected role denials on explicit seed probes are recorded separately. It blocks non-read HTTP methods, skips logout/download links, and does not click recovery/continuation or submit forms. It also checks run-list search and opens/closes the cancel dialog without submitting. Coverage depends on existing data; it does not claim every possible record/state or destructive action was tested.

A separate live Chromium check confirmed the run list in light/dark modes and mobile navigation, including the visible recovery action and absence of teacher-only links for admins. Production build and the 353-pattern static route audit passed again. All eight product containers and all three local-worker services were healthy after deployment.

The extended crawl also found a teacher-only syllabus-upload action on shared subject pages. It is now offered to teachers only; owner lecture management remains available. An existing teacher case without a saved lesson map previously linked to a 404. Its authorized HTML page now explains that no map has been saved yet; JSON still returns the existing missing-map 404, other teachers still receive 404, and admins remain forbidden. Regression verification: access and authoring-access suites passed 63 tests with 1,432 assertions, including this waiting-page behavior. Prompt-export download URLs are excluded from the page-render crawl because they return attachments.

Final deployed-build Chromium audit: 139 page visits across admin, teacher, student, and guest roles, covering 56 React page components. Zero JavaScript/empty-page/rendering failures and zero unexpected HTTP navigation errors. Four expected question-bank access denials were preserved for teacher/student probes. The original uploaded run remained held; no continuation or provider work was submitted.
