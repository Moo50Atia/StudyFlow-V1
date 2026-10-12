# Future Web Integration Boundary

017 supplies local JSON-to-React-source-to-static-build artifacts with Lesson/prompt/template/provider provenance. It does not register an application ProcessingCase, qualified prompt, SceneCandidate, SceneQualification, ReviewItem or approval. `qualification.mode=owner_waived_terminal_demo` is never equivalent to 010/012 passing rows.

| Consumer | What is supplied now | What later integration must implement |
|---|---|---|
| Terminal NotebookLM stage | Reads existing single/multi-PDF handoffs without querying the notebook or changing credentials/prompts. | Automatic launch after complete handoff, if requested later, with explicit Subject/run identity. |
| 005 provider/settings catalog | Common API adapter interface including local Qwen; no production catalog change. | Explicitly version Qwen identifier, endpoint/credential policy and captured settings before enabling it through Laravel. |
| 011 authoring | Isolated provider abstraction, source collector and compiler; local static manifest remains distinct from `ScenePackageV1`. | Retain current-pass handoff/attempt policy, decide versioned source/build candidate transport, private binary asset limits and durable provenance. Do not silently cast 017 manifests to old 011 packages. |
| 007 Temporal | Library methods accept injected dependencies and do not depend on CLI globals. | Activities, durable effect identities, receipt reconciliation and histories containing opaque references only. Local checkpoints are temporary and do not replace Temporal. |
| 012 qualification | Compiled files, retained generated source and build evidence. | Real runtime/browser/safety/interaction checks and typed JEV, handling binary assets, plus repair budgets. A compile or local waiver cannot satisfy those gates. |
| 013 teacher review | Original Lesson/scene association remains in manifest. | Application owner association and actual original-teacher preview/review decisions. Never create approval from CLI success. |
| 015 evidence | Per-attempt receipts, hashes and complete vs partial status. | Owner-scoped persisted evidence chain and production retention/access policy. |

Do not edit other numbered specs or implement these integrations as part of 017. Report any required amendment separately. A live local sample proves only the selected API, requested model, captured template, source collection and build on that run.
