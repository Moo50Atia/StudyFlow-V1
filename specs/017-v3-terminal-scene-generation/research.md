# Repository Evidence and Decisions

Reviewed 2026-10-09. This records inspection and design, not passing application tests or live provider requests.

| Inspected evidence | Decision / rationale |
|---|---|
| `scripts/v3/run_multi_pdf_notebooklm.py` writes `notebooklm_multi_pdf_lesson_handoff` and `notebooklm_multi_pdf_lesson_raw_handoff`, with `mapped_title`, not `lesson_title`. Raw mode may omit Section title/First/Second. | Support both concrete kinds. Read `generated_prompt` verbatim, prefer mapped title for display, and never require complete pedagogical fields in this stage. |
| `v3_engine/notebooklm/lesson_archive.py` writes `notebooklm_lesson_handoff` containing `lessons[]` with `lesson_ref`, title, ordinals and scenes. | Also support the archive root shape; use canonical notebook/conversation/ordinal identity rather than filename or user-controlled `lesson_ref`. |
| The legacy eight-PDF result has fourteen Lesson handoffs; the first raw handoff currently contains four prompts and labels such as `Comparison | Objects`. | Input fixtures must include variable counts and arbitrary labels. No hardcoded three-scene loop or enum check. The fourteen count is this dataset's evidence, never a general mapping rule. |
| `v3_engine/authoring/providers.py` already calls three APIs but always includes `OUTPUT_CONTRACT` for static files and returns a five-element tuple. | Reuse documented wire semantics, not those forcing wrappers. Add isolated interface/adapters rather than changing existing Temporal activity behavior. A common OpenAI Chat transport avoids three separate copies. |
| `v3_engine/tests/authoring/test_live_provider.py` passes positional arguments in the wrong order and then treats the tuple as a dict. | That file is not a credential check or proof of working live authoring. 017 creates its own selected-provider check and live sample with named fields. |
| `v3_engine/authoring/package.py` accepts bounded static HTML/JS/CSS/SVG/JSON. | This current terminal format also uses static browser files; keep its exact local three-file contract separate from 011's application transport and qualification behavior. |
| Owner direction on 2026-10-09 replaced the earlier React choice; `Google-Ai-studio-System-prompts` Section 12 now requires plain HTML/CSS/JavaScript. | Use exactly `index.html`, `styles.css`, and `script.js`; update the pinned digest; do not install or run a framework/compiler. |
| Root `package.json` is Laravel/Vite. | Leave it and its lockfile untouched; the static terminal path has no npm dependencies. |
| `v3_engine/local_env.py` loads dotenv without overriding process env and decodes `V3_GEMINI_API_KEY_B64`. | Reuse it for reading. Other keys use ordinary environment labels; no automatic browser/cookie flow, account selection, or `.env` write. |
| 005 accepts only three production coding-provider IDs; 011 depends on persisted current JEV qualifications. | Qwen and waived file handoffs are enabled only here; no new Laravel provider/state route or qualification mutation. |
| Existing archive uses atomic replace and `fcntl.flock`; Linux is the actual local environment. | Reuse that storage pattern and nonblocking OS lock; add immutable attempt receipts and recovery tests rather than Temporal infrastructure for this temporary terminal stage. |

## Confirmed owner choices

One provider per run; no automatic fallback. Alibaba Model Studio Qwen with region-appropriate configured base URL. Separate credential check. Current owner-directed template uses HTML/CSS/JavaScript. Every supplied prompt is eligible under the local JEV demo waiver. Web integration is later work.

## Primary documentation

- [Gemini generateContent](https://ai.google.dev/api/generate-content): system instructions, parts and usage metadata. Preserve all non-thought text parts, not just the first part.
- [DeepSeek Chat](https://api-docs.deepseek.com/api/create-chat-completion/): messages, model, bearer auth, finish reason and usage.
- [Z.AI Chat](https://docs.z.ai/api-reference/llm/chat-completion): compatible chat request. Existing code uses BigModel's China endpoint; 017 exposes `GLM_BASE_URL` so a corresponding Z.AI key can use its proper endpoint explicitly.
- [Alibaba compatible Chat](https://help.aliyun.com/en/model-studio/compatibility-of-openai-with-dashscope), [API keys](https://help.aliyun.com/en/model-studio/get-api-key): configured regional endpoint/key/model must agree; do not guess a Qwen account region.
- [Vite production build](https://vite.dev/guide/build): static build and relative base path. A built output is served over HTTP; browser execution of TSX directly is not supported.
- Exact dependency versions and peers were read from the public [npm registry](https://registry.npmjs.org/) without installing packages. Catalog pins belong to [source/build contract](contracts/source-package.md). Registry existence/peer inspection does not prove a build; real build gates remain implementation work.

## Alternatives

Automatic provider fallback was rejected by the owner for predictable provider/cost. Real JEV waits are explicitly deferred for the demo. Reusing production qualification routes would add unavailable prerequisites. Installing every optional renderer or executing model-supplied package/config scripts would conflict with the selected host and smallest-renderer requirement. Source-only output was offered and rejected in favor of local builds.
