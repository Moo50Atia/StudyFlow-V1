# Web and Application API

All teacher paths use existing session/CSRF, v3.private and fresh V3AccessResolver. JSON guests401; browser guests redirect sign-in; inactive/student/admin content access403; foreign teacher404. Subject submission requires TeacherPermission. Private/no-store responses; owner-scoped binding. Validation422; digest/version conflicts409; unavailable503; no raw exception text.

## Teacher routes under /v3/teacher

| Method/path | Request | Result |
|---|---|---|
| GET /subjects/{subject}/runs | cursor; limit1..100 default25 | owner run summaries |
| POST /subjects/{subject}/runs | multipart submission_key UUID, files[1..20], titles same length, mode export_manual(default)/api_generate, qualification_mode owner_waived_demo/real_jev, waiver_acknowledged when demo |202 run_id,state=staging,state_version1; exact duplicate same202; changed409 submission_conflict |
| GET /subject-runs/{run} | none | owner HTML view |
| GET /subject-runs/{run}/progress | none | projection below |
| GET /subject-runs/{run}/events | after_sequence>=0 default0,limit1..100 default100 | safe events,next_cursor |
| GET /subject-runs/{run}/prompt-export | optional item_id or scene_id UUID | exact JSON export and manual-use text; no remote calls |
| POST /subject-runs/{run}/commands | command_id UUID,expected_version integer,action pause/resume/cancel/retry/generate,selected_scene_ids[],retry_phase nullable response/source/build/generation/notebook,duplicate_risk_consent boolean |202 requested receipt; exact duplicate prior receipt; changed409 control_conflict; stale409 stale_version |
| POST /subject-runs/{run}/scenes/{scene}/imports | multipart JSON<=4 MiB,command_id,expected_version,prompt_version_id,optional reported provider/model,waiver_acknowledged |201 candidate/import IDs; identical retry200; stale409 prompt_conflict; invalid422 scene_package_invalid |

scene_id is local TeacherSection UUID. Selection snapshots exact current prompt_version IDs. Generate requires nonempty selections and captured provider/model/settings; config invalid422 before provider send. No paid check-chat/probe or fallback. Explicit settings changes create a new immutable command capture, not modification of run settings. Only scene generation may change settings this way; source/framework/protocol remain immutable.

Demo acknowledgment schema1 text: “JEV is waived for this demo. Artifact/browser checks and my final scene review remain required. This does not establish production qualification.” Store exact acknowledgment digest and original owner. Each manual/generation scene command acknowledges the captured demo policy. Real mode never falls back on errors. Admin cannot waive or approve content.

## Progress projection

schema_version1,run_id,state,generation_phase,state_version,last_updated_at ISOUTC,projection_source live/last_known,projection_age_seconds,control{command_id,state,action},counts{pdfs_total,pdfs_accepted,pdfs_failed,lessons_total:null|int,lessons_saved,prompts_saved,scenes_total:null|int,scenes_generated,scenes_built,scenes_checked,scenes_real_qualified,scenes_demo_review_ready,reviews_pending,scenes_approved}. Paged items contain ordinal/state/safe_code/counts; teacher titles are a separate owner response. Online query timeout2s then retained projection; polling5s active/15s waiting, stopped on page unload. Event filter precedes100-row limit. Unknown totals remain null.

## Existing-route protection

Extend SubjectController/LectureController/SectionController, TeacherStudyFlowController, StudyFlowController, APIfilterController, MohammedTestController and LectureResource and linked question/exam/dynamic-view bulk/CRUD paths. Filter unauthorized linked records before counts/pagination/next-lecture/navigation. Linked legacy mutations return403 pipeline_managed; owner reads use canonical projection. Unlinked legacy behavior keeps current rules. The unauthenticated /mohammed-test route must exclude all pipeline-linked records before resource serialization; it remains unrelated legacy output only. No public pdf_path/dynamic_view_link/Storage::url for linked data. Reuse owner case download/review/preview/evidence routes with new gate resolver. Never serve executable candidate code at application origin.

Admin GET /v3/admin/subject-runs and /v3/admin/subject-runs/{run}/summary return safe metadata only, not titles, notes, hashes, private paths, bodies or remote IDs. POST /v3/admin/subject-runs/{run}/commands permits pause/resume/cancel only with command/version fences. It cannot generate, import scenes, waive, preview or decide.

## Worker routes under /v3/internal/web-pipeline

Existing worker HMAC authenticates exact serialized bytes, timestamp and replay headers using explicit configured secrets. No test signer. Private/no-store. UUID refs resolve approved roots; no arbitrary supplied paths.

| Operation | Input | Result |
|---|---|---|
| POST /claims | run_id,operation_id,kind,item_id nullable,expected_version,request_sha256 |201 lease/context_ref or200 retained receipt;409 operation_conflict |
| GET /contexts/{ref} | signed scope | private scoped context retrieved outside workflow payload |
| POST /results | operation_id,lease_token,expected_version,result_ref,result_sha256,outcome,safe_code |201 event/version/receipt;duplicate200;divergent409 |
| GET /commands/{command}/receipt | signed run scope | consumed bool,exact command digest/version |
| POST /events | id,run_id,operation_id,attempt,kind,phase,outcome,safe_code,counts,expected_version |201/duplicate200/conflict409 |

Metadata requests<=32 KiB; unknown keys422. Private streamed artifact GET /artifacts/{ref} and PUT /operations/{operation}/artifacts/{ref} require lease/scoped ref, exact byte count/digest and type-specific limits: accepted PDF<=100 MiB, raw turn<=8 MiB, static package<=4 MiB, metadata<=32 KiB. Pre-register artifact UUID/type/length/digest in claim; verify before publishing. Lease expiry cannot authorize uncertain resend. Callbacks from a stale lease cannot advance pointers. Offline callbacks remain retained for idempotent delivery, and their persistence failure blocks next mutation.

## Upload transport prerequisites

Pilot PHP upload_max_filesize must allow100 MiB, post_max_size at least1100 MiB for the1 GiB aggregate plus multipart overhead, max_file_uploads>=20, and upload_tmp_dir must be configured under a private local staging directory. Any reverse proxy must match the captured aggregate byte limit plus overhead. Readiness checks reject incompatible transport configuration before presenting an enabled batch-upload form. Request rejected upstream with413 is not reported as successful staging; returned file-upload errors become422 upload_incomplete. Stream/hash/stage files without loading a batch into application memory. Enforce actual accepted byte limits independently of PHP MIME labels.
