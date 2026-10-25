# NotebookLM recovery verification fix

The outbound worker already delegates processing to the terminal CLI, whose verification activity uses `NotebookTerminalService.verify_session` and the SDK session provider. The run's isolated credential file passed live notebook-ownership verification.

The false `account_mismatch` came after authentication: `verify_notebook_session` recorded a success under the same journal event identity as an earlier failed verification. The journal correctly rejected the conflicting immutable payload, but the activity caught that storage exception together with provider exceptions and reported another account mismatch. Repeated recovery therefore returned to the hold despite usable credentials.

Changes:

- Give verification journal events a stable Temporal workflow-run/activity/attempt identity, allowing a newly scheduled recovery check to coexist with earlier failures while repeated delivery of the same activity remains idempotent.
- Keep provider verification errors separate from journal failures. A journal failure reports `storage_unavailable`, not an account mismatch.
- Make `check_notebooklm.py --adapter notebooklm_py --notebook-id ...` call the same shared terminal service and ownership check. A library read alone still explicitly does not prove ownership.
- Preserve existing authentication import, isolated credential copies, historical events, terminal identity, and held run.

Validation:

- Two regression tests failed before the fix and passed afterward: successful auth following an old journal failure; journal errors classified as storage errors.
- Focused credential/journal/setup coverage: 19 passed.
- Terminal pipeline, terminal bridge, and SDK contracts: 150 passed, 22 gated tests skipped. No live generation gate was enabled.
- The corrected terminal verification activity was exercised with the existing isolated credentials and a temporary journal seeded with the old failure. Live result: `complete / ok`; both failure and success remained in the isolated journal.
- The diagnostic command's shared ownership check passed for the configured proof notebook.
- Restarted the local terminal worker to load the fix; local Temporal, terminal, and outbound-worker services are active. The actual workflow remains paused, awaiting owner-issued recovery; no provider prompt or run continuation was submitted by these checks.
