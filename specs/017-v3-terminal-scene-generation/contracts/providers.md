# Provider Interface and Wire Contract

## Interface

`AIProvider` is an abstract base class. Concrete `GeminiProvider`, `GLMProvider`, `DeepSeekProvider`, and `QwenProvider` expose:

- `async check_connection() -> ProviderCheckResult`
- `async generate_scene(request: SceneGenerationRequest, *, receipt_sink: RawReceiptSink) -> ProviderResponse`
- `async aclose() -> None` (one shared HTTP client per run; close on every CLI exit)

The factory takes captured `ProviderConfig`; the service takes an `AIProvider`. `RawReceiptSink` is an async callback bound to the current attempt, accepting body bytes plus receipt metadata and returning its committed relative reference. The adapter invokes it before response parsing. No secret is serialized in dataclass repr, progress, checkpoint or manifest. `SceneGenerationRequest` contains exact `system_text`, `prompt`, fixed output-contract text, and trusted host context. It contains no full PDF, raw First/Second output or other Lesson prompts.

Generation user message is UTF-8 JSON `{schema_version:1,prompt:<exact string>,output_contract:<ReactSceneSourceV1 instructions>,host_context:{entrypoint:"src/Scene.tsx",export:"default React component",core_dependencies:<catalog core>,optional_dependencies:<catalog options>,reserved_files:<trusted paths>}}`. JSON escaping is transport encoding; do not rewrite or trim the prompt. System text is the exact UTF-8 decoded template bytes. One independent request per Scene, no accumulated chat history, tools or streaming tokens to stdout.

## Configuration

| CLI name / stored ID | Key | Model when `--model` is absent | Endpoint |
|---|---|---|---|
| `gemini` / `gemini_api` | `GEMINI_API_KEY` (existing encoded fallback allowed) | `GEMINI_MODEL` | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` |
| `glm` / `glm_api` | `GLM_API_KEY` | `GLM_MODEL` | `GLM_BASE_URL` default `https://open.bigmodel.cn/api/paas/v4`, append `/chat/completions` |
| `deepseek` / `deepseek_api` | `DEEPSEEK_API_KEY` | `DEEPSEEK_MODEL` | `https://api.deepseek.com/chat/completions` |
| `qwen` / `qwen_api` | `QWEN_API_KEY` | `QWEN_MODEL` | Required `QWEN_BASE_URL` from the selected Alibaba workspace/region, append `/chat/completions` |

CLI `--model` takes precedence over that provider's model env variable; no default model. Strip whitespace only from configuration fields, not educational text. Blank keys/models fail before HTTP. Model is 1–100 `[A-Za-z0-9._:/-]` characters; Gemini strips optional `models/` prefix for the URL and captures normalized ID. Configured base URLs are absolute HTTPS, no userinfo/query/fragment, no embedded secret, trailing slash removed, and do not end in `/chat/completions`. Do not auto-detect region or key type. Unselected providers do not need keys. Use existing dotenv loading with process env precedence. Add labels/comments to `.env.example`; never rewrite actual `.env`.

## Wire

- Gemini: `x-goog-api-key`, `systemInstruction.parts[].text`; one user `contents` message with JSON text. Collect all non-thought text parts in order, with no invented separators. Store `modelVersion`, `responseId`, prompt/candidate token counts. Require exactly one candidate, `STOP`, and nonblank text for a complete usable response.
- GLM/DeepSeek/Qwen: bearer auth, `{model,messages:[system,user],stream:false}`. Require exactly one choice, `finish_reason="stop"`, and nonblank `message.content`; never substitute `reasoning_content` for code. Preserve returned model, ID and token usage if present. Model absent falls back to requested ID with `model_reported=false`.
- `check` uses no scene template/contract: system `Respond briefly.`, user `Reply exactly: V3_PROVIDER_OK`. One request, 30-second timeout, at most 128 output tokens for Chat APIs / 1024 Gemini including thinking. A normal textual completion passes only when `text.strip() == "V3_PROVIDER_OK"`. A refusal, truncation or other reply fails as `provider_check_failed` without asserting that the key is invalid. Generation never invokes check automatically.
- Generation request timeout defaults to 300 seconds via CLI. No HTTP library retry/fallback. Do not use arbitrary exception-text searches to decide outcome.

## Evidence and outcomes

Read response bodies with an eight-MiB limit. Persist exact received bytes and receipt through a store callback before parsing provider JSON or applying finish/content checks. `ProviderResponse` carries `outcome=complete|failed|uncertain`, `error_code`, optional HTTP status/model/response ID/finish reason/usage/text and raw receipt reference; it never drops an invalid body's evidence.

`401/403` → `failed/provider_auth`; `429` → `failed/provider_rate_limit`; other definitive `4xx` → `failed/provider_request`. Complete `2xx` with bad JSON, content refusal, nonnormal finish or unusable schema → `failed/provider_response`. `5xx`, post-send timeout/connection loss, and crash after send reservation without complete receipt → `uncertain/provider_uncertain`. Locally proven connect failure before dispatch may be `failed/provider_connection`; otherwise conservative uncertainty. Body above cap retains only capped partial bytes with `truncated=true` and `failed/provider_response_oversize`. Do not automatically resend any of these outcomes.

Missing usage values stay null; costs stay unknown rather than invented. Sanitize terminal error messages to a fixed code, provider ID, item identity and HTTP status. Raw API errors/content stay in the private receipt. Check requests do not persist educational artifacts or secrets.
