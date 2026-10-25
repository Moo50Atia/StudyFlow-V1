# Research, Observed Baseline and Design Assumptions

**Date**: 2026-10-17. Sources are context, not proof of Feature 021 readiness. No services were started or provider requests made while creating this specification.

## Observed application baseline

- Actual working application: `/home/moahmmed/college_project`, HEAD `cae7313` with substantial uncommitted changes. The presentation task's worktree is older. Relevant source fingerprints are in [baseline-source.json](baseline-source.json); they are an audit sample, not a whole-repository reproducible release manifest.
- The actual application already has Docker, Inertia teacher pages, server PDF preparation policy v2 and manual prompt/scene flows. Current hosted/local filesystem separation is NOT implemented.
- Existing Docker mounts application DB/storage/secrets and local NotebookLM cache into same-host Python services. That topology must not be copied to the hosted/local deployment.
- Existing current server preparation uses >50-page trigger and <=50-page leaves. Historical Spec 020 describes an older v1 threshold; preserve historical captures.
- Existing `export_manual`/prompt export and three-file manual scene candidate paths are real reuse points. Generic automation for every paid consumer web account is not an existing feature.
- Current `.specify/memory/constitution.md` is an unfilled template. Sample bracketed text is not ratified policy.

## Retained run evidence — historical, not new feature tests

1. Mechanics run `09a59afe-ed26-4567-906b-e42d87d53362` under `storage/app/private/v3/eight_pdf_runs/8a1a5113dd01/terminal-root/`: 8 PDFs, 63 lessons, 89 exported scene prompts. The local event index records 2026-10-10T00:11:06.468603Z through 03:59:37.872053Z. There are 79 retained core mapping/execution/lesson request receipts. These do not establish future API cost or throughput.
2. Medical-informatics run `1d5f25a8-7842-4769-a34e-7b712de25eb7` under `storage/app/private/v3/terminal/`: final report says `prompts_exported`, 11 lessons and 11 scene prompts from one 37-page original. No scene generation was executed in that run.
3. Recorded 233,039 scene-generation input tokens and 1,485,189 output tokens cover final code artifacts of the mechanics scene batch; they exclude replacement of NotebookLM, unmeasured reasoning, retries, review and hosting. They must never become a default measured cost for a new run.

Do not modify, copy wholesale, republish or reuse credentials from those retained runs to fabricate acceptance for Feature 021.

## Official research findings and implementation implications

- [Gemini 2.5 Pro model documentation](https://ai.google.dev/gemini-api/docs/models/gemini-2.5-pro): input limit is 1,048,576 tokens, not two million. Current access is restricted for new users/projects; model/provider selection must be versioned and checked, not hard-coded as an assumed guaranteed service.
- [Google's NotebookLM update](https://blog.google/innovation-and-ai/products/notebooklm/better-research-notebooklm/): NotebookLM's application includes retrieval/context/orchestration behavior and has changed underlying models. Replacing it is a provider/context adapter comparison, not proof of equivalence from choosing an old model name.
- [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) and [context caching](https://ai.google.dev/gemini-api/docs/generate-content/caching): model API usage is billed separately from application-server runtime. Cache reads/storage/TTL, thinking tokens and request context tiers matter. There is no mandatory $15/hour GPU merely to call the API. Prices are not normative constants in this feature.
- [Gemini File Search](https://ai.google.dev/gemini-api/docs/file-search): managed retrieval is a future evaluation candidate with explicit model support, embedding/indexing and normal inference billing. It is not implemented by this specification or a guarantee of complete curriculum coverage.
- [Enterprise notebook APIs](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/overview): reviewed documentation provides notebook/source/audio management. No equivalent documented chat-loop API was established by this research; investigate actual support rather than inventing an enterprise chat endpoint.
- [Notebook usage limits](https://support.google.com/gemininotebook/answer/17670842): subscription processing capacity depends on workload and current plan limits. A local operator workflow cannot promise unlimited or zero-cost production.
- [Gemini API terms](https://ai.google.dev/gemini-api/terms): product-specific audience/data restrictions differ from browser subscriptions and Cloud offerings; the Developer API's current under-18 application restriction is relevant before a school-facing rollout. The paid-service data rules also differ from unpaid services. No legal certification or authorization to automate consumer interfaces is inferred here.
- [Batch API](https://ai.google.dev/gemini-api/docs/batch-api): future independent scene batches may trade turnaround for price; it does not automatically accelerate dependent lesson conversations.

Implementers must recheck current official service conditions when choosing a new adapter. This package authorizes no paid API calls, new subscriptions or public deployment.

## Explicit design defaults, not measured findings

Protocol v1's device token expiry, invitation TTL, claim poll interval, heartbeat/lease timing, artifact caps, retry backoff, output scopes and single-task concurrency are chosen engineering defaults. They are testable configuration boundaries, not demonstrated capacity, provider limits or promises to customers.

Device/server identity and immutable content hashes protect transport lineage. They cannot independently prove educational truth or authenticate arbitrary third-party model output submitted by a trusted operator. Preserve that distinction in UI, audit and acceptance reports.
