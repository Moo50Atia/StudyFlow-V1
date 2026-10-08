# Downstream Integration Obligations from 010

010 proves immutable original and revised prompt versions, direct typed JEV decisions, bounded Section status, a persisted 009 Third-output receipt and a signed current-pass resolver **only after its own implementation/tests and live gate pass**. This document is a contract, not evidence that those components already exist.

| Consumer | 010 supplies | Consumer must still implement/prove |
|---|---|---|
| 009 cursor/Continue | Exact ordered version-1 prompt refs and durable work-item completion receipt, with final conversation ID | 009 validates cursor/set/digest and sends literal `Continue` once; its response may contain all three later steps. 010 parses/reuses any combined material. |
| 011 scene authoring | Signed `GET /api/internal/v1/cases/{case_id}/qualified-prompts/{prompt_version_ref}` returns private exact body, digest, same-case Section and passing qualification only for current version | 011 binds ref to its own authoring request/Section/settings, creates candidate and checks 010 resolver at launch; a 010 pass does not prove a scene exists or is good. |
| 012 scene qualification and 013 review | Immutable prompt/Section lineage and qualification ref | 012 compares candidate to exact prompt and records its own scene judgment; 013 authorizes only original teacher to review a final passing scene. A 010 prompt pass never approves a scene. |
| 014 operations | Safe Section/work status, fixed uncertain/error codes, immutable send ledgers | Define audited reviewed recovery of uncertain NotebookLM/feedback effects without automatic resend or admin content access. |
| 015 history/retention | Immutable versions, decision evidence, private artifact digests, 009 receipt linkage | Export only owner-authorized evidence, preserve admin metadata boundary, and define deletion after active/uncertain references are resolved. |

No consumer may infer permission from an opaque ref alone. The Laravel case/Section owner chain remains authoritative; admin has metadata only. Do not put prompt, Section, feedback, raw JEV data or provider conversation IDs into Temporal history or 004's closed admin DTO.
