# 010 Acceptance Matrix

IDs are stable. Planned tests are future implementation evidence, not tests run by this documentation review. `P18` is a mandatory live gate and cannot be passed by a fake adapter.

| ID | Requirement | Planned test file(s) | Expected outcome |
|---|---|---|---|
| P01 | FR-001,002 | `v3_engine/tests/prompts/test_generation.py` | First work sends exact Execution bytes once after First; `System Ready` leads to one Second/Third send, while complete combined later steps are reused; one original prompt per Section. |
| P02 | FR-001,002 | `v3_engine/tests/prompts/test_generation.py` | Complete later `Continue` Second/Third material is reused with zero duplicate sends; First-only material causes one Second and one Third send. |
| P03 | FR-001,002 | `v3_engine/tests/prompts/test_generation.py` | Partial/ambiguous later output, wrong work marker, missing/duplicate Section, malformed scene type or incomplete eleven fields blocks without receipt. |
| P04 | FR-002,007 | `tests/Feature/V3/Prompts/PromptTurnTest.php` | Private raw turns and exact original Third refs are durable before exact 009 completion; storage failure or stale cursor sends none. |
| P05 | FR-003,004,006 | `v3_engine/tests/prompts/test_jev_policy.py`, `tests/Feature/V3/Prompts/QualificationTest.php` | Typed pass at equality persists evidence and unlocks current version exactly once. |
| P06 | FR-003,004 | `v3_engine/tests/prompts/test_jev_policy.py` | Choice `revise`/`block`, low fractional score/confidence and Noul no take distinct recorded policy paths; no second judge call. |
| P07 | FR-003,007 | `v3_engine/tests/prompts/test_jev_policy.py`, `tests/Feature/V3/Prompts/QualificationTest.php` | 401/429/5xx/timeout/schema mismatch creates no verdict; identical JEV request retries only within captured technical budget. |
| P08 | FR-004,007 | `tests/Feature/V3/Prompts/QualificationTest.php` | Duplicate exact valid answer returns same decision; changed later answer or stale prompt is 409 and cannot flip a pass. |
| P09 | FR-005,006 | `v3_engine/tests/prompts/test_revision.py`, `v3_engine/tests/prompts/test_feedback_adapters.py`, `tests/Feature/V3/Prompts/RevisionTest.php` | All three captured provider descriptors map to their fixed wire/schema in fixtures; selected provider/template creates one same-Section successor that is reevaluated. |
| P10 | FR-005,006 | `v3_engine/tests/prompts/test_revision.py` | `block`, zero/exhausted budget, or template mismatch creates no successor or coding request; other Sections continue. |
| P11 | FR-007 | `tests/Integration/V3/Prompts/PromptRaceTest.php` | Two processes/connections with a barrier claim same NotebookLM/feedback effect; exactly one `send_now`, changed concurrent payload conflicts. |
| P12 | FR-007 | `tests/Feature/V3/Prompts/PromptTurnTest.php`, `tests/Feature/V3/Prompts/RevisionTest.php` | Lost result acknowledgement reconciles exact recorded result; post-send timeout remains `uncertain` and never auto-resends. |
| P13 | FR-008 | `tests/Feature/V3/Prompts/WorkerBoundaryTest.php` | Bad HMAC denied before lookup; foreign case/work/Section refs indistinguishable from missing; no raw content in errors/logs. |
| P14 | FR-008 | `tests/Feature/V3/Prompts/AccessRegressionTest.php` | Admin, unrelated/inactive teacher, and actual V2 routes cannot access a V3 prompt or Section body; original teacher sees only existing 009 Section/progress surface. |
| P15 | FR-004,006,008 | `tests/Feature/V3/Prompts/ProjectionTest.php` | 007 event provenance and admin DTO contain safe scalar codes only; original-owner `/prompt-status` shows partial Section statuses, no prompt/JEV/feedback values. |
| P16 | FR-002,006 | `tests/Feature/V3/Prompts/HandoffTest.php` | 011's qualified-ref resolver rejects unknown, foreign, superseded, failed and unrecorded refs; accepts only current same-Section pass. |
| P17 | FR-007,008 | `tests/Feature/V3/Prompts/RetentionTest.php` | Private artifact digest checked on read; no deletion while referenced or uncertain; warnings rate-limited and content-free. |
| P18 | FR-001,003,005 | `v3_engine/tests/prompts/test_live_prompt_cycle.py` | Disposable source and real selected NotebookLM/JEV/feedback providers prove Execution/first + continued work, typed wire contract and one revision; absent credentials/adapter is an incomplete release gate. |

P18 requires live selected-adapter/account evidence. Mock tests for P01–P17 cannot substitute for that gate.
