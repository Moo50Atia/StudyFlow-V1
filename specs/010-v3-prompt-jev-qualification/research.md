# Research and Decisions: 010

## Authority and repository evidence

Conversation 23 makes qualification per learning unit and separates JEV's decision from a feedback text generator. Its older “usable verdict” wording is superseded by the owner's later instruction to consume successful typed JEV decisions directly. The owner also confirmed during this review that **NotebookLM's ongoing conversation** produces final Third-step Section scene prompts; `providers.prompt` remains a required captured 005 setting but 010 does not call it. The owner confirmed that Noul's yes-probability is both the yes decision input (`≥0.5`) and confidence for a yes response. Neither correction changes any canonical 009 bytes.

The repository presently has Laravel 12/PHP ^8.2, V2 `sections`/`lectures`/`dynamic_view_models`, existing `routes/web.php` teacher/admin and `/api/lectures` paths, SQLite in-memory routine tests, and no V3 Python worker, JEV client, 010 tables or routes. Thus all `v3_engine/...` and V3 Laravel paths in the plan are **new**, while 004–009 services are prerequisites to extend when implemented. A sequential SQLite in-memory test cannot prove a two-claim race; use a shared disposable database and two real processes/connections.

009 currently returns only Section refs/digests at its 010 worker handoff; 010 needs the eleven Section fields and recorded raw `Continue` answer to avoid resending Second/Third. 009's plan sends Divide/First and explicitly leaves Execution/Second/Third to 010. 010 therefore adds a signed, case-scoped private context read over 009's existing records, sends exact Execution once after the first First, and records a combined response without duplicate later sends. It does not change 009's docs or add a teacher content route. 009's receipt trusts 010's Third-output refs; 010 must durably store and verify those before calling it. 011's `author` requires a qualified prompt ref; 010 supplies a current-pass resolver, while 011 still must enforce its own same-Section authoring request.

## JEV wire evidence and direct rule

The [current official TypeSafe OpenAPI](https://api.typesafe.ai/openapi.json) (`0.2.0` when reviewed) defines Bearer `POST /v1/systemone` and `GET /v1/models`, named `choice|score|noul` questions, a Choice answer with confidence/probabilities, a Score answer with confidence/legend/probabilities, and a Noul answer with **only** `noul` yes probability. The [official model endpoint](https://api.typesafe.ai/docs) identifies available models per account. Since Score criteria positions define numeric levels starting at zero, 010 asks 101 levels for an actual 0–100 score. The selected account/model's ability to accept that rubric and complete within deployment limits is a live gate, not a claim proved by this document.

005's older rule says all three answers have confidence. The approved interpretation uses `noul` as confidence of a yes answer, while still requiring `noul≥0.5`. Threshold comparison uses unrounded decimal values and inclusive boundaries. Transport/auth/rate-limit/schema failures have no verdict. A successful typed low score, Choice `revise`/`block` or Noul no is a policy decision. No second model is invoked to assess JEV output. The vendor response's model name, answer types and numeric ranges are checked as a wire contract, not a separate quality judgment.

For feedback, 005 allows `gemini_api|glm_api|deepseek_api` without a wire endpoint. The fixed 010 adapter maps those IDs to [Google `generateContent`](https://ai.google.dev/api/generate-content), [Z.AI GLM chat completions](https://docs.z.ai/guides/capabilities/mcp-call) and [DeepSeek chat completions](https://api-docs.deepseek.com/api/create-chat-completion/) respectively. This is a 010 technical binding, not a claim that a given admin-selected model/credential is live. Z.AI is chosen as the `glm_api` host because its official GLM chat-completions example documents that URL and response shape; an account issued for another GLM host is not silently swapped. A live selected-provider test must establish actual credential/model compatibility. Each adapter receives the same immutable approved system template and untrusted JSON user payload, with tools/streaming disabled.

## Design choices and rejected alternatives

| Decision | Evidence/rationale | Rejected behavior |
|---|---|---|
| NotebookLM produces original Third prompt on 008-bound source | Owner correction, exact 009 Second/Third and Continue contracts | Call `providers.prompt` after Third or remap locally |
| Send exact Execution once and reuse complete combined output | 009 leaves Execution to 010; canonical protocol can produce later steps in one answer | Skip Execution or blindly send Second/Third again |
| Persist original Third refs before 009 receipt | 009 attests rather than independently verifies 010 storage | Synthetic refs or completion before files commit |
| Case/Section scoped immutable versions and one current pointer | 006 immutable owner, 011 current-pass precondition | Mutable prompt row or title-based identity |
| Stateful-send ledger for NotebookLM and feedback | Provider calls have no proven idempotency/replay key | Automatic resend after ambiguous timeout |
| Pure JEV retry with same digest, first valid committed answer wins | Evaluation is read-only; 007 captured technical retries | Convert outage into fail or accept later changed verdict |
| Keep `providers.feedback`/approved template exact capture | 005 operator-imported catalog and case-captured settings; official provider-specific wire mapping | Production default template, ad hoc provider or mid-case active setting |
| No content user route | 004 admin closed DTO/original teacher; teacher reviews final scene | Admin prompt/source browsing or teacher prompt approval |

## Prerequisites and remaining risks

No unresolved owner business choice remains for 010 after the two answers above. **Implementation is still conditional on** completed 004–009 production contracts, a trusted operator-imported approved feedback template, working selected NotebookLM MCP session/source, reachable TypeSafe account/model and feedback provider credential. A live run must verify Execution's actual response, the 101-level Score question and combined/staged Continue behavior. If the selected vendor rejects the documented wire shape or a complete later-step parse, stop and report the actual schema/response; do not change canonical prompts or quietly substitute a model. 014 owns operator remediation for an uncertain sent provider effect, and 015 owns eventual retention. This review did not perform those integrations.
