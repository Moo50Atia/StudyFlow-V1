# Unified lessons acceptance, 2026-10-12

## Implemented release

- `/dashboard` is the role workspace. Teachers can create owned subjects, add manual lectures, submit ordered PDFs, inspect runs and projected lessons, and publish each complete lesson. Students see published generated lessons. The platform owner can inspect content, use audited publication controls, and manage questions and mind maps. The limited admin retains operational pages.
- Completed V3 sections project into linked legacy sections in the commit transaction. Projection is idempotent and fills empty fields while preserving manual edits. `v3:backfill-legacy-lessons --dry-run` reports missing fields before an idempotent write.
- Publication is per lesson; legacy corrections revoke it. Scene release is separate and only creates a student route for an approved, released scene under a published lesson.
- Questions, exam questions, related favorites, and private educational images are owner only. New teacher submissions use manual scene export. Paid generation requires the explicit server capability flag.

## Verification

- Focused web pipeline, NotebookLM commit, lesson access, and review tests: **157 passed, 1,115 assertions**.
- Clean SQLite migration and Blade compilation passed. Existing public mind map, question, and exam image directories contain **zero files** after private relocation.
- Desktop (1280 × 800) and mobile (390 × 844) dashboards were inspected for teacher, student, owner, and limited admin with empty, running, failed, and completed sample states. No horizontal overflow was observed.
- A live NotebookLM session passed the health check. A new two-PDF direct provider run completed: both PDFs uploaded, two work items ready, two lessons and their scene prompts saved. Private run evidence is under `storage/app/private/v3/unified-live-acceptance-20261012/run/`.
- A separate real two-PDF terminal archive was imported into a disposable database. It produced two linked legacy lectures and two linked sections with all six mapped legacy fields populated and both lessons unpublished. Replaying the import did not add sections. The backfill dry run reported zero pending links; both lessons could then be published independently.
- A joined live web run also passed after two provider-format fixes. A teacher uploaded two real PDFs in the browser; the real NotebookLM session processed them through the web pipeline into two linked legacy lectures and two linked sections. Each section had all six mapped legacy fields, and both lessons remained unpublished. The preserved evidence database is `storage/testing/harness_674509_1791823530/test_pipeline.sqlite` (run `26afcc33-22ef-44b3-9dfe-c7790c61f040`).
- Follow-up focused tests cover email verification, linked content access, exact-title normalization, divide parsing, and final run status. The final callback now marks a run completed after its last item completes.
- The complete PHP suite passes: **734 tests, 7,041 assertions**. Blade compilation and `git diff --check` pass.

## Final acceptance

The joined live web-to-legacy acceptance gate passed twice. The final run used a real healthy NotebookLM session and two teacher-uploaded PDFs. In the preserved database `storage/testing/harness_675897_1791824258/test_pipeline.sqlite`, run `3568322f-b0cb-4adb-bdfd-016de85e1824` is `completed`; both items are `completed` with one saved lesson and prompt each. There are two distinct linked legacy lectures and sections, all six mapped fields are filled on each section, and both lessons remain unpublished for review. The live test passed in 203 seconds. Paid scene generation is not part of this gate.

The web uploader remains limited to 20 PDFs per run. Spec 020 terminal preparation can yield 32 parts from a 1,000-page source; larger web batches require a separate implementation.
