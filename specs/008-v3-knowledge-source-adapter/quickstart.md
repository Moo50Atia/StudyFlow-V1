# Future Implementer Verification: 008

These are **instructions for the future implementer after application work**, not commands passed during this documentation review. Run from `/home/moahmmed/college_project` with implemented 004–007, disposable DB/private disk, Python environment, local persisted Temporal server, 007 bridge/worker/callback keys, Poppler/Tesseract and a dedicated disposable NotebookLM account. Do not use teacher production PDFs or a production NotebookLM notebook for smoke tests.

```sh
php -v
python3 --version
node --version
pdftoppm -v
tesseract --version
tesseract --list-langs
temporal --version
npx --yes @m4ykeldev/notebooklm-mcp@0.3.1 --version
php artisan route:list --path=v3
php artisan route:list --path=api/internal/v1
php artisan schedule:list
php artisan migrate:status
```

Expect one owner-only source-status route, signed worker PDF/extraction/effect/binding routes, signed bridge activation route, one-minute `v3:dispatch-sources`, four 008 tables and installed OCR/MCP tools. The MCP server must expose the six required tool names/fields in [contract](contracts/knowledge-source-adapter.md); record its actual `tools/list` schema in the implementation report. A missing binary, cookie/session or tool is an incomplete environment gate.

```sh
python3 -m pip install -e 'v3_engine[dev]'
php artisan test tests/Feature/V3/Sources/SourceSchemaTest.php tests/Feature/V3/Sources/ActivationTest.php
php artisan test tests/Integration/V3/Sources/ActivationRaceTest.php
php artisan test tests/Feature/V3/Sources/WorkerSourceBoundaryTest.php tests/Feature/V3/Sources/ExtractionStoreTest.php
python3 -m pytest v3_engine/tests/sources/test_workflow_handoff.py v3_engine/tests/sources/test_extraction.py
php artisan test tests/Feature/V3/Sources/SourceEffectTest.php
python3 -m pytest v3_engine/tests/sources/test_notebooklm_adapter.py v3_engine/tests/sources/test_adapter_contract.py
php artisan test tests/Feature/V3/Sources/SourceAccessTest.php tests/Feature/V3/Sources/PrivacyTest.php tests/Integration/V3/Sources/SourceIsolationTest.php
```

Expect all isolated tests green. Races must report actual overlapping independent processes/connections and a shared DB. Extraction fixtures must show page markers/order, OCR budget and blocked gaps; fake MCP tests must show `send_count≤1` and no binding from add's message alone. Owner/worker tests must exercise actual 004/007 middleware, including legacy and error responses.

For live disposable integration, start a persisted local Temporal server and run the 007 Laravel server, bridge, worker, queue and scheduler as documented by 007. Configure 008's pinned MCP stdio command and auth in the worker's secret environment, then run:

```sh
temporal server start-dev --db-filename /tmp/v3-temporal-source-test.db
php artisan v3:dispatch-sources
python3 -m pytest v3_engine/tests/sources/test_live_mcp.py
php artisan test tests/Integration/V3/Sources/LiveNotebookLMTest.php tests/Integration/V3/Sources/LiveSourceWorkflowTest.php
```

The first command stays running in its own terminal; run the remaining commands in other terminals. Expect a real 007-started case to retain its original workflow ID, read only its verified 006 PDF, produce one private ready artifact and one NotebookLM notebook/source whose fetched content matches the canonical text. Safe events advance extraction/sourcing and then park for 009; no map/Section/prompt is produced. Force a lost create/add acknowledgement: a single proven source is adopted, or the effect stays uncertain with no second send. Check owner source-status, foreign/missing equal 404, admin exact 004 closed DTO, no-store and log redaction. Record any provider content-normalization/schema incompatibility as a mandatory blocked gate, not a passing mock result.

```sh
php artisan test tests/Feature/V3/Sources tests/Integration/V3/Sources
python3 -m pytest v3_engine/tests/sources
php artisan test tests/Feature/V3/Access tests/Feature/V3/Settings tests/Feature/V3/Intake tests/Feature/V3/Workflow
```

Expect 004–007 affected regressions and all 008 tests green. Report pinned package/tool versions, live versus doubled evidence, provider notebook/source comparison, private transfer digest/attempt/proof times and every unresolved gate. No application test is claimed to have run in this documentation review.
