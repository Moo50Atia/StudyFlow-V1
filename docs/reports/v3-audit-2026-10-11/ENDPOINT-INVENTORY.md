# Endpoint inventory — 2026-10-11

221 registered Laravel routes; one primary HTTP verb tested per route. HEAD aliases and a second PUT/PATCH verb were not separately probed. Requests ran through the Laravel kernel with an empty JSON body, dummy identifiers, no user/signature, and an isolated SQLite database. The web feature was enabled only within this probe.

These are authentication/basic dispatch checks. A 401/403/404 response does not prove successful authenticated processing. A 404 can arise from route constraints or missing fixture identifiers. Route registration and controller method existence were checked separately. Public page successes are listed as observed responses, not security defects.

## Laravel

| Methods | URI | Anonymous HTTP | Route name | Controller action |
| --- | --- | ---: | --- | --- |
| GET / HEAD | `/` | 200 | home | `Closure` |
| POST | `api/internal/v1/admin-control-results` | 401 | api.v3.internal.admin-control-results.store | `App\Http\Controllers\V3\Internal\AdminControlResultController@recordResult` |
| POST | `api/internal/v1/case-events` | 401 | api.v3.internal.case-events.store | `App\Http\Controllers\V3\Internal\CaseEventController@store` |
| POST | `api/internal/v1/cases/{case_id}/lesson-completions` | 401 | api.v3.internal.cases.lesson-completions | `App\Http\Controllers\V3\Internal\LessonCompletionController@store` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/lesson-context/{work_ordinal}` | 401 | api.v3.internal.cases.lesson-context | `App\Http\Controllers\V3\Internal\MapContextController@lessonContext` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/lesson-queries/{kind}/{ordinal}` | 401 | api.v3.internal.cases.lesson-queries.find-direct | `App\Http\Controllers\V3\Internal\LessonQueryController@findDirect` |
| POST | `api/internal/v1/cases/{case_id}/lesson-queries/{kind}/{ordinal}/claim` | 401 | api.v3.internal.cases.lesson-queries.claim | `App\Http\Controllers\V3\Internal\LessonQueryController@claim` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/operation-control` | 401 | api.v3.internal.cases.operation-control.show | `App\Http\Controllers\V3\Internal\OperationControlController@show` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/prompt-context/{work_ordinal}` | 401 | api.v3.internal.cases.prompt-context | `App\Http\Controllers\V3\Internal\PromptContextController@getContext` |
| POST | `api/internal/v1/cases/{case_id}/prompt-feedback-effects/{effect_ref}/result` | 401 | api.v3.internal.cases.prompt-feedback-effects.result | `App\Http\Controllers\V3\Internal\PromptFeedbackController@result` |
| POST | `api/internal/v1/cases/{case_id}/prompt-feedback-effects/{effect_ref}/uncertain` | 401 | api.v3.internal.cases.prompt-feedback-effects.uncertain | `App\Http\Controllers\V3\Internal\PromptFeedbackController@uncertain` |
| POST | `api/internal/v1/cases/{case_id}/prompt-jev-attempts/{attempt_ref}/result` | 401 | api.v3.internal.cases.prompt-jev-attempts.result | `App\Http\Controllers\V3\Internal\PromptQualificationController@recordResult` |
| POST | `api/internal/v1/cases/{case_id}/prompt-jev-attempts/{attempt_ref}/technical-failure` | 401 | api.v3.internal.cases.prompt-jev-attempts.technical-failure | `App\Http\Controllers\V3\Internal\PromptQualificationController@recordTechnicalFailure` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/prompt-turns/{turn_ref}` | 401 | api.v3.internal.cases.prompt-turns.get | `App\Http\Controllers\V3\Internal\PromptTurnController@getTurn` |
| POST | `api/internal/v1/cases/{case_id}/prompt-turns/{turn_ref}/result` | 401 | api.v3.internal.cases.prompt-turns.result | `App\Http\Controllers\V3\Internal\PromptTurnController@result` |
| POST | `api/internal/v1/cases/{case_id}/prompt-turns/{turn_ref}/uncertain` | 401 | api.v3.internal.cases.prompt-turns.uncertain | `App\Http\Controllers\V3\Internal\PromptTurnController@uncertain` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback-context` | 401 | api.v3.internal.cases.prompt-versions.feedback-context | `App\Http\Controllers\V3\Internal\PromptFeedbackController@feedbackContext` |
| POST | `api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/feedback/claim` | 401 | api.v3.internal.cases.prompt-versions.feedback.claim | `App\Http\Controllers\V3\Internal\PromptFeedbackController@claimFeedback` |
| POST | `api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/jev-attempts/claim` | 401 | api.v3.internal.cases.prompt-versions.jev-attempts.claim | `App\Http\Controllers\V3\Internal\PromptQualificationController@claimAttempt` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/prompt-versions/{prompt_version_ref}/qualification-context` | 401 | api.v3.internal.cases.prompt-versions.qualification-context | `App\Http\Controllers\V3\Internal\PromptQualificationController@qualificationContext` |
| POST | `api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/complete` | 401 | api.v3.internal.cases.prompt-work-items.complete | `App\Http\Controllers\V3\Internal\PromptCompletionController` |
| POST | `api/internal/v1/cases/{case_id}/prompt-work-items/{work_item_ref}/turns/{step}/claim` | 401 | api.v3.internal.cases.prompt-turns.claim | `App\Http\Controllers\V3\Internal\PromptTurnController@claimTurn` |
| POST | `api/internal/v1/cases/{case_id}/prompt-work-items/{work_ordinal}/claim` | 401 | api.v3.internal.cases.prompt-work-items.claim | `App\Http\Controllers\V3\Internal\PromptTurnController@claimWorkItem` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/prompt-work-items/{work_ordinal}/prompt-versions` | 401 | api.v3.internal.cases.prompt-work-items.prompt-versions | `App\Http\Controllers\V3\Internal\PromptQualificationController@workPromptVersions` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/qualified-prompts/{prompt_version_ref}` | 401 | api.v3.internal.cases.qualified-prompts.show | `App\Http\Controllers\V3\Internal\QualifiedPromptController@show` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/reviews/current` | 401 | api.v3.internal.cases.reviews.current | `App\Http\Controllers\V3\Internal\ReviewReadyController@current` |
| POST | `api/internal/v1/cases/{case_id}/reviews/ready` | 401 | api.v3.internal.cases.reviews.ready | `App\Http\Controllers\V3\Internal\ReviewReadyController@ready` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-authoring/attempts/{attempt_ref}` | 401 | api.v3.internal.cases.scene-authoring.attempts.show | `App\Http\Controllers\V3\Internal\CodingAttemptController@show` |
| POST | `api/internal/v1/cases/{case_id}/scene-authoring/attempts/{attempt_ref}/result` | 401 | api.v3.internal.cases.scene-authoring.attempts.result | `App\Http\Controllers\V3\Internal\CodingAttemptController@recordResult` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-authoring/eligible-prompts` | 401 | api.v3.internal.cases.scene-authoring.eligible-prompts | `App\Http\Controllers\V3\Internal\AuthoringRequestController@eligiblePrompts` |
| POST | `api/internal/v1/cases/{case_id}/scene-authoring/requests` | 401 | api.v3.internal.cases.scene-authoring.requests.store | `App\Http\Controllers\V3\Internal\AuthoringRequestController@store` |
| POST | `api/internal/v1/cases/{case_id}/scene-authoring/requests/{request_ref}/attempts` | 401 | api.v3.internal.cases.scene-authoring.requests.attempts.reserve | `App\Http\Controllers\V3\Internal\CodingAttemptController@reserve` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-authoring/requests/{request_ref}/context` | 401 | api.v3.internal.cases.scene-authoring.requests.context | `App\Http\Controllers\V3\Internal\AuthoringRequestController@context` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-candidates/{candidate_ref}` | 401 | api.v3.internal.cases.scene-candidates.show | `App\Http\Controllers\V3\Internal\SceneCandidateController@show` |
| POST | `api/internal/v1/cases/{case_id}/scene-check-attempts` | 401 | api.v3.internal.cases.scene-check-attempts.claim | `App\Http\Controllers\V3\Internal\SceneCheckController@claim` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-check-attempts/{attempt_ref}` | 401 | api.v3.internal.cases.scene-check-attempts.show | `App\Http\Controllers\V3\Internal\SceneCheckController@show` |
| POST | `api/internal/v1/cases/{case_id}/scene-check-attempts/{attempt_ref}/result` | 401 | api.v3.internal.cases.scene-check-attempts.result | `App\Http\Controllers\V3\Internal\SceneCheckController@result` |
| POST | `api/internal/v1/cases/{case_id}/scene-check-attempts/{attempt_ref}/technical-failure` | 401 | api.v3.internal.cases.scene-check-attempts.technical-failure | `App\Http\Controllers\V3\Internal\SceneCheckController@technicalFailure` |
| POST | `api/internal/v1/cases/{case_id}/scene-jev-attempts` | 401 | api.v3.internal.cases.scene-jev-attempts.claim | `App\Http\Controllers\V3\Internal\SceneJevController@claim` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}` | 401 | api.v3.internal.cases.scene-jev-attempts.show | `App\Http\Controllers\V3\Internal\SceneJevController@show` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}/context` | 401 | api.v3.internal.cases.scene-jev-attempts.context | `App\Http\Controllers\V3\Internal\SceneJevController@context` |
| POST | `api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}/result` | 401 | api.v3.internal.cases.scene-jev-attempts.result | `App\Http\Controllers\V3\Internal\SceneJevController@result` |
| POST | `api/internal/v1/cases/{case_id}/scene-jev-attempts/{attempt_ref}/technical-failure` | 401 | api.v3.internal.cases.scene-jev-attempts.technical-failure | `App\Http\Controllers\V3\Internal\SceneJevController@technicalFailure` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-qualification/review-eligible/{candidate_ref}` | 401 | api.v3.internal.cases.scene-qualification.review-eligible | `App\Http\Controllers\V3\Internal\SceneEligibilityController@show` |
| POST | `api/internal/v1/cases/{case_id}/scene-repairs` | 401 | api.v3.internal.cases.scene-repairs.claim | `App\Http\Controllers\V3\Internal\SceneRepairController@claim` |
| POST | `api/internal/v1/cases/{case_id}/scene-repairs/{repair_ref}/candidate` | 401 | api.v3.internal.cases.scene-repairs.candidate | `App\Http\Controllers\V3\Internal\SceneRepairController@linkCandidate` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/scene-repairs/{repair_ref}/context` | 401 | api.v3.internal.cases.scene-repairs.context | `App\Http\Controllers\V3\Internal\SceneRepairController@context` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/source-bindings/{binding_ref}` | 401 | api.v3.internal.cases.source-bindings.get | `App\Http\Controllers\V3\Internal\SourceBindingController@getByCaseAndRef` |
| GET / HEAD | `api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{effect_ref}/context` | 401 | api.v3.internal.cases.teacher-prompt-corrections.context | `App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController@context` |
| POST | `api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{effect_ref}/result` | 401 | api.v3.internal.cases.teacher-prompt-corrections.result | `App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController@result` |
| POST | `api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{effect_ref}/uncertain` | 401 | api.v3.internal.cases.teacher-prompt-corrections.uncertain | `App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController@uncertain` |
| POST | `api/internal/v1/cases/{case_id}/teacher-prompt-corrections/{route_ref}/claim` | 401 | api.v3.internal.cases.teacher-prompt-corrections.claim | `App\Http\Controllers\V3\Internal\TeacherPromptCorrectionController@claim` |
| POST | `api/internal/v1/cases/{case_id}/teacher-scene-repairs` | 401 | api.v3.internal.cases.teacher-scene-repairs.claim | `App\Http\Controllers\V3\Internal\TeacherSceneRepairController@claim` |
| POST | `api/internal/v1/lesson-queries/{case_id}/{kind}/{ordinal}/claim` | 401 | api.v3.internal.lesson-queries.claim | `App\Http\Controllers\V3\Internal\LessonQueryController@claim` |
| GET / HEAD | `api/internal/v1/lesson-queries/{query_ref}` | 401 | api.v3.internal.lesson-queries.show | `App\Http\Controllers\V3\Internal\LessonQueryController@show` |
| POST | `api/internal/v1/lesson-queries/{query_ref}/combined-result` | 401 | api.v3.internal.lesson-queries.combined-result | `App\Http\Controllers\V3\Internal\NotebookProtocolController@combinedResult` |
| POST | `api/internal/v1/lesson-queries/{query_ref}/result` | 401 | api.v3.internal.lesson-queries.result | `App\Http\Controllers\V3\Internal\LessonQueryController@result` |
| POST | `api/internal/v1/lesson-queries/{query_ref}/uncertain` | 401 | api.v3.internal.lesson-queries.uncertain | `App\Http\Controllers\V3\Internal\LessonQueryController@uncertain` |
| GET / HEAD | `api/internal/v1/map-activations/{activation_id}/context` | 401 | api.v3.internal.map-activations.context | `App\Http\Controllers\V3\Internal\MapContextController@getContext` |
| GET / HEAD | `api/internal/v1/source-activations/{activation_id}/binding` | 401 | api.v3.internal.source-activations.binding | `App\Http\Controllers\V3\Internal\SourceBindingController@getByActivation` |
| GET / HEAD | `api/internal/v1/source-activations/{activation_id}/extracted-sources/{extracted_source_ref}/text` | 401 | api.v3.internal.source-activations.extraction.text | `App\Http\Controllers\V3\Internal\SourceExtractionController@getText` |
| GET / HEAD | `api/internal/v1/source-activations/{activation_id}/extraction` | 401 | api.v3.internal.source-activations.extraction.get | `App\Http\Controllers\V3\Internal\SourceExtractionController@getExtraction` |
| POST | `api/internal/v1/source-activations/{activation_id}/extraction` | 401 | api.v3.internal.source-activations.extraction.store | `App\Http\Controllers\V3\Internal\SourceExtractionController@storeExtraction` |
| GET / HEAD | `api/internal/v1/source-activations/{activation_id}/pdf` | 401 | api.v3.internal.source-activations.pdf | `App\Http\Controllers\V3\Internal\SourceInputController@streamPdf` |
| GET / HEAD | `api/internal/v1/source-activations/{activation_id}/steps` | 401 | api.v3.internal.source-activations.steps | `App\Http\Controllers\V3\Internal\SourceInputController@streamSteps` |
| POST | `api/internal/v1/source-effects/{case_id}/{operation}/claim` | 401 | api.v3.internal.source-effects.claim | `App\Http\Controllers\V3\Internal\SourceEffectController@claim` |
| POST | `api/internal/v1/source-effects/{effect_id}/result` | 401 | api.v3.internal.source-effects.result | `App\Http\Controllers\V3\Internal\SourceEffectController@result` |
| GET / HEAD | `api/lectures/{lecture}/sections` | 401 |  | `App\Http\Controllers\APIfilterController@getSections` |
| GET / HEAD | `api/subjects/{subject}/lectures` | 401 |  | `App\Http\Controllers\APIfilterController@getLectures` |
| GET / HEAD | `api/v3/internal/web-pipeline/artifacts/{ref}` | 401 | api.v3.internal.web-pipeline.artifacts.get | `App\Http\Controllers\V3\Internal\WebPipelineController@getArtifact` |
| POST | `api/v3/internal/web-pipeline/claims` | 401 | api.v3.internal.web-pipeline.claims | `App\Http\Controllers\V3\Internal\WebPipelineController@claim` |
| GET / HEAD | `api/v3/internal/web-pipeline/commands/{command}/receipt` | 401 | api.v3.internal.web-pipeline.commands.receipt | `App\Http\Controllers\V3\Internal\WebPipelineController@commandReceipt` |
| GET / HEAD | `api/v3/internal/web-pipeline/contexts/{ref}` | 401 | api.v3.internal.web-pipeline.contexts | `App\Http\Controllers\V3\Internal\WebPipelineController@getContext` |
| POST | `api/v3/internal/web-pipeline/events` | 401 | api.v3.internal.web-pipeline.events | `App\Http\Controllers\V3\Internal\WebPipelineController@recordEvent` |
| PUT | `api/v3/internal/web-pipeline/operations/{operation}/artifacts/{ref}` | 401 | api.v3.internal.web-pipeline.artifacts.put | `App\Http\Controllers\V3\Internal\WebPipelineController@putArtifact` |
| POST | `api/v3/internal/web-pipeline/results` | 401 | api.v3.internal.web-pipeline.results | `App\Http\Controllers\V3\Internal\WebPipelineController@result` |
| GET / HEAD | `confirm-password` | 401 | password.confirm | `App\Http\Controllers\Auth\ConfirmablePasswordController@show` |
| POST | `confirm-password` | 401 |  | `App\Http\Controllers\Auth\ConfirmablePasswordController@store` |
| GET / HEAD | `dashboard` | 401 | dashboard | `App\Http\Controllers\DashboardController@index` |
| POST | `email/verification-notification` | 401 | verification.send | `App\Http\Controllers\Auth\EmailVerificationNotificationController@store` |
| GET / HEAD | `exam_questions` | 401 | exam_questions.index | `App\Http\Controllers\ExamQuestionController@index` |
| POST | `exam_questions` | 401 | exam_questions.store | `App\Http\Controllers\ExamQuestionController@store` |
| GET / HEAD | `exam_questions/create` | 401 | exam_questions.create | `App\Http\Controllers\ExamQuestionController@create` |
| GET / HEAD | `exam_questions/{exam_question}` | 401 | exam_questions.show | `App\Http\Controllers\ExamQuestionController@show` |
| PUT / PATCH | `exam_questions/{exam_question}` | 401 | exam_questions.update | `App\Http\Controllers\ExamQuestionController@update` |
| DELETE | `exam_questions/{exam_question}` | 401 | exam_questions.destroy | `App\Http\Controllers\ExamQuestionController@destroy` |
| GET / HEAD | `exam_questions/{exam_question}/edit` | 401 | exam_questions.edit | `App\Http\Controllers\ExamQuestionController@edit` |
| GET / HEAD | `forgot-password` | 200 | password.request | `App\Http\Controllers\Auth\PasswordResetLinkController@create` |
| POST | `forgot-password` | 422 | password.email | `App\Http\Controllers\Auth\PasswordResetLinkController@store` |
| GET / HEAD | `lectures` | 401 | lectures.index | `App\Http\Controllers\LectureController@index` |
| POST | `lectures` | 401 | lectures.store | `App\Http\Controllers\LectureController@store` |
| GET / HEAD | `lectures/create` | 401 | lectures.create | `App\Http\Controllers\LectureController@create` |
| GET / HEAD | `lectures/{lecture}` | 401 | lectures.show | `App\Http\Controllers\LectureController@show` |
| PUT / PATCH | `lectures/{lecture}` | 401 | lectures.update | `App\Http\Controllers\LectureController@update` |
| DELETE | `lectures/{lecture}` | 401 | lectures.destroy | `App\Http\Controllers\LectureController@destroy` |
| GET / HEAD | `lectures/{lecture}/edit` | 401 | lectures.edit | `App\Http\Controllers\LectureController@edit` |
| GET / HEAD | `login` | 200 | login | `App\Http\Controllers\Auth\AuthenticatedSessionController@create` |
| POST | `login` | 422 |  | `App\Http\Controllers\Auth\AuthenticatedSessionController@store` |
| POST | `logout` | 401 | logout | `App\Http\Controllers\Auth\AuthenticatedSessionController@destroy` |
| POST | `love/toggle` | 401 | love.toggle | `App\Http\Controllers\LoveController@toggle` |
| GET / HEAD | `mohammed-test` | 200 | mohammed-test | `App\Http\Controllers\MohammedTestController@index` |
| PUT | `password` | 401 | password.update | `App\Http\Controllers\Auth\PasswordController@update` |
| GET / HEAD | `profile` | 401 | profile.edit | `App\Http\Controllers\ProfileController@edit` |
| PATCH | `profile` | 401 | profile.update | `App\Http\Controllers\ProfileController@update` |
| DELETE | `profile` | 401 | profile.destroy | `App\Http\Controllers\ProfileController@destroy` |
| GET / HEAD | `questions` | 401 | questions.index | `App\Http\Controllers\QuestionController@index` |
| POST | `questions` | 401 | questions.store | `App\Http\Controllers\QuestionController@store` |
| GET / HEAD | `questions/create` | 401 | questions.create | `App\Http\Controllers\QuestionController@create` |
| GET / HEAD | `questions/{question}` | 401 | questions.show | `App\Http\Controllers\QuestionController@show` |
| PUT / PATCH | `questions/{question}` | 401 | questions.update | `App\Http\Controllers\QuestionController@update` |
| DELETE | `questions/{question}` | 401 | questions.destroy | `App\Http\Controllers\QuestionController@destroy` |
| GET / HEAD | `questions/{question}/edit` | 401 | questions.edit | `App\Http\Controllers\QuestionController@edit` |
| GET / HEAD | `register` | 200 | register | `App\Http\Controllers\Auth\RegisteredUserController@create` |
| POST | `register` | 422 |  | `App\Http\Controllers\Auth\RegisteredUserController@store` |
| POST | `reset-password` | 422 | password.store | `App\Http\Controllers\Auth\NewPasswordController@store` |
| GET / HEAD | `reset-password/{token}` | 200 | password.reset | `App\Http\Controllers\Auth\NewPasswordController@create` |
| GET / HEAD | `sections` | 401 | sections.index | `App\Http\Controllers\SectionController@index` |
| POST | `sections` | 401 | sections.store | `App\Http\Controllers\SectionController@store` |
| GET / HEAD | `sections/create` | 401 | sections.create | `App\Http\Controllers\SectionController@create` |
| GET / HEAD | `sections/{section}` | 401 | sections.show | `App\Http\Controllers\SectionController@show` |
| PUT / PATCH | `sections/{section}` | 401 | sections.update | `App\Http\Controllers\SectionController@update` |
| DELETE | `sections/{section}` | 401 | sections.destroy | `App\Http\Controllers\SectionController@destroy` |
| GET / HEAD | `sections/{section}/edit` | 401 | sections.edit | `App\Http\Controllers\SectionController@edit` |
| GET / HEAD | `storage/{path}` | 403 | storage.local | `Closure` |
| GET / HEAD | `study-flow/exit` | 401 | study-flow.exit | `App\Http\Controllers\StudyFlowController@exit` |
| POST | `study-flow/lectures/{lecture}/sections` | 401 | study-flow.bulk-sections | `App\Http\Controllers\StudyFlowController@storeBulkSections` |
| POST | `study-flow/sections/{section}/content` | 401 | study-flow.bulk-content | `App\Http\Controllers\StudyFlowController@storeBulkContent` |
| GET / HEAD | `study-flow/start` | 401 | study-flow.start | `App\Http\Controllers\StudyFlowController@start` |
| POST | `study-flow/subjects/{subject}/lectures` | 401 | study-flow.bulk-lectures | `App\Http\Controllers\StudyFlowController@storeBulkLectures` |
| GET / HEAD | `subjects` | 401 | subjects.index | `App\Http\Controllers\SubjectController@index` |
| POST | `subjects` | 401 | subjects.store | `App\Http\Controllers\SubjectController@store` |
| GET / HEAD | `subjects/create` | 401 | subjects.create | `App\Http\Controllers\SubjectController@create` |
| GET / HEAD | `subjects/{subject}` | 401 | subjects.show | `App\Http\Controllers\SubjectController@show` |
| PUT / PATCH | `subjects/{subject}` | 401 | subjects.update | `App\Http\Controllers\SubjectController@update` |
| DELETE | `subjects/{subject}` | 401 | subjects.destroy | `App\Http\Controllers\SubjectController@destroy` |
| GET / HEAD | `subjects/{subject}/edit` | 401 | subjects.edit | `App\Http\Controllers\SubjectController@edit` |
| GET / HEAD | `teacher-study-flow/exit` | 401 | teacher-study-flow.exit | `App\Http\Controllers\TeacherStudyFlowController@exit` |
| GET / HEAD | `teacher-study-flow/lectures` | 401 | teacher-study-flow.lectures | `App\Http\Controllers\TeacherStudyFlowController@lectures` |
| POST | `teacher-study-flow/lectures` | 401 | teacher-study-flow.store-lecture | `App\Http\Controllers\TeacherStudyFlowController@storeLecture` |
| POST | `teacher-study-flow/lectures/{lecture}/sections` | 401 | teacher-study-flow.bulk-sections | `App\Http\Controllers\TeacherStudyFlowController@storeBulkSections` |
| POST | `teacher-study-flow/sections/{section}/content` | 401 | teacher-study-flow.bulk-content | `App\Http\Controllers\TeacherStudyFlowController@storeBulkContent` |
| GET / HEAD | `teacher-study-flow/start` | 401 | teacher-study-flow.start | `App\Http\Controllers\TeacherStudyFlowController@start` |
| GET / HEAD | `teacher_permissions` | 401 | teacher_permissions.index | `App\Http\Controllers\TeacherPermissionController@index` |
| POST | `teacher_permissions` | 401 | teacher_permissions.store | `App\Http\Controllers\TeacherPermissionController@store` |
| GET / HEAD | `teacher_permissions/create` | 401 | teacher_permissions.create | `App\Http\Controllers\TeacherPermissionController@create` |
| GET / HEAD | `teacher_permissions/{teacher_permission}` | 401 | teacher_permissions.show | `App\Http\Controllers\TeacherPermissionController@show` |
| PUT / PATCH | `teacher_permissions/{teacher_permission}` | 401 | teacher_permissions.update | `App\Http\Controllers\TeacherPermissionController@update` |
| DELETE | `teacher_permissions/{teacher_permission}` | 401 | teacher_permissions.destroy | `App\Http\Controllers\TeacherPermissionController@destroy` |
| GET / HEAD | `teacher_permissions/{teacher_permission}/edit` | 401 | teacher_permissions.edit | `App\Http\Controllers\TeacherPermissionController@edit` |
| GET / HEAD | `up` | 200 |  | `Closure` |
| GET / HEAD | `users` | 401 | users.index | `App\Http\Controllers\UserController@index` |
| POST | `users` | 401 | users.store | `App\Http\Controllers\UserController@store` |
| GET / HEAD | `users/create` | 401 | users.create | `App\Http\Controllers\UserController@create` |
| GET / HEAD | `users/{user}` | 401 | users.show | `App\Http\Controllers\UserController@show` |
| PUT / PATCH | `users/{user}` | 401 | users.update | `App\Http\Controllers\UserController@update` |
| DELETE | `users/{user}` | 401 | users.destroy | `App\Http\Controllers\UserController@destroy` |
| GET / HEAD | `users/{user}/edit` | 401 | users.edit | `App\Http\Controllers\UserController@edit` |
| GET / HEAD | `v3` | 401 | v3.entry | `App\Http\Controllers\V3\EntryController@index` |
| GET / HEAD | `v3/admin` | 401 | v3.admin.workspace | `App\Http\Controllers\V3\Admin\WorkspaceController@index` |
| GET / HEAD | `v3/admin/accounts` | 401 | v3.admin.accounts.index | `App\Http\Controllers\V3\Admin\AccountController@index` |
| PATCH | `v3/admin/accounts/{user}/access` | 404 | v3.admin.accounts.access.update | `App\Http\Controllers\V3\Admin\AccountController@updateAccess` |
| GET / HEAD | `v3/admin/cases` | 401 | v3.admin.cases.index | `App\Http\Controllers\V3\Admin\CaseSummaryController@index` |
| GET / HEAD | `v3/admin/cases/{case}/summary` | 401 | v3.admin.cases.summary | `App\Http\Controllers\V3\Admin\CaseSummaryController@show` |
| POST | `v3/admin/evidence-exports` | 401 | v3.admin.evidence-exports.store | `App\Http\Controllers\V3\Admin\EvidenceController@export` |
| GET / HEAD | `v3/admin/evidence-exports/{export}/download` | 401 | v3.admin.evidence-exports.download | `App\Http\Controllers\V3\Admin\EvidenceController@download` |
| GET / HEAD | `v3/admin/evidence/metrics` | 401 | v3.admin.evidence.metrics | `App\Http\Controllers\V3\Admin\EvidenceController@metrics` |
| GET / HEAD | `v3/admin/operations` | 401 | v3.admin.operations.index | `App\Http\Controllers\V3\Admin\OperationsController@index` |
| GET / HEAD | `v3/admin/operations/health` | 401 | v3.admin.operations.health | `App\Http\Controllers\V3\Admin\OperationsController@health` |
| GET / HEAD | `v3/admin/operations/{case}` | 401 | v3.admin.operations.show | `App\Http\Controllers\V3\Admin\OperationsController@show` |
| POST | `v3/admin/operations/{case}/commands` | 401 | v3.admin.operations.commands.store | `App\Http\Controllers\V3\Admin\AdminCommandController@store` |
| POST | `v3/admin/operations/{case}/deletion-assessments` | 401 | v3.admin.operations.deletion-assessments.store | `App\Http\Controllers\V3\Admin\DeletionAssessmentController@store` |
| POST | `v3/admin/operations/{case}/reviews/{review}/reminders` | 401 | v3.admin.operations.reminders.store | `App\Http\Controllers\V3\Admin\ReviewReminderController@store` |
| GET / HEAD | `v3/admin/settings` | 401 | v3.admin.settings.show | `App\Http\Controllers\V3\Admin\SettingsController@show` |
| POST | `v3/admin/settings` | 401 | v3.admin.settings.store | `App\Http\Controllers\V3\Admin\SettingsController@store` |
| GET / HEAD | `v3/admin/settings/history` | 401 | v3.admin.settings.history | `App\Http\Controllers\V3\Admin\SettingsController@history` |
| GET / HEAD | `v3/admin/settings/templates` | 401 | v3.admin.settings.templates | `App\Http\Controllers\V3\Admin\SettingsController@templates` |
| GET / HEAD | `v3/admin/subject-runs` | 401 | v3.admin.subject-runs.index | `App\Http\Controllers\V3\Admin\SubjectRunSummaryController@index` |
| POST | `v3/admin/subject-runs/{run}/commands` | 404 | v3.admin.subject-runs.commands | `App\Http\Controllers\V3\Admin\SubjectRunSummaryController@command` |
| GET / HEAD | `v3/admin/subject-runs/{run}/summary` | 404 | v3.admin.subject-runs.summary | `App\Http\Controllers\V3\Admin\SubjectRunSummaryController@show` |
| GET / HEAD | `v3/teacher` | 401 | v3.teacher.workspace | `App\Http\Controllers\V3\Teacher\WorkspaceController@index` |
| POST | `v3/teacher/cases` | 401 | v3.teacher.cases.store | `App\Http\Controllers\V3\Teacher\ProcessingCaseController@store` |
| GET / HEAD | `v3/teacher/cases/{case}` | 401 | v3.teacher.cases.show | `App\Http\Controllers\V3\Teacher\ProcessingCaseController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/approved-scenes` | 401 | v3.teacher.cases.approvals.index | `App\Http\Controllers\V3\Teacher\SceneReviewController@approvedScenes` |
| POST | `v3/teacher/cases/{case}/approved-scenes/{approval}/corrections` | 401 | v3.teacher.cases.approvals.correct | `App\Http\Controllers\V3\Teacher\PostapprovalCorrectionController@correct` |
| GET / HEAD | `v3/teacher/cases/{case}/evidence` | 401 | v3.teacher.evidence.show | `App\Http\Controllers\V3\Teacher\EvidenceController@show` |
| POST | `v3/teacher/cases/{case}/evidence-exports` | 401 | v3.teacher.evidence-exports.store | `App\Http\Controllers\V3\Teacher\EvidenceController@export` |
| GET / HEAD | `v3/teacher/cases/{case}/evidence-exports/{export}/download` | 401 | v3.teacher.evidence-exports.download | `App\Http\Controllers\V3\Teacher\EvidenceController@download` |
| GET / HEAD | `v3/teacher/cases/{case}/evidence/approvals/{approval}` | 401 | v3.teacher.evidence.approvals.show | `App\Http\Controllers\V3\Teacher\EvidenceController@approval` |
| GET / HEAD | `v3/teacher/cases/{case}/lesson-map` | 401 | v3.teacher.cases.lesson-map | `App\Http\Controllers\V3\Teacher\LessonMapController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/progress` | 401 | v3.teacher.cases.progress | `App\Http\Controllers\V3\Teacher\CaseProgressController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/prompt-status` | 401 | v3.teacher.cases.prompt-status | `App\Http\Controllers\V3\Teacher\PromptStatusController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/reviews` | 401 | v3.teacher.cases.reviews.index | `App\Http\Controllers\V3\Teacher\SceneReviewController@index` |
| GET / HEAD | `v3/teacher/cases/{case}/reviews/{review}` | 401 | v3.teacher.cases.reviews.show | `App\Http\Controllers\V3\Teacher\SceneReviewController@show` |
| POST | `v3/teacher/cases/{case}/reviews/{review}/decisions` | 401 | v3.teacher.cases.reviews.decide | `App\Http\Controllers\V3\Teacher\SceneReviewController@decide` |
| POST | `v3/teacher/cases/{case}/reviews/{review}/viewer-sessions` | 401 | v3.teacher.cases.reviews.viewer.start | `App\Http\Controllers\V3\Teacher\ReviewPreviewController@start` |
| GET / HEAD | `v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}` | 401 | v3.teacher.cases.reviews.viewer.show | `App\Http\Controllers\V3\Teacher\ReviewPreviewController@show` |
| DELETE | `v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}` | 401 | v3.teacher.cases.reviews.viewer.close | `App\Http\Controllers\V3\Teacher\ReviewPreviewController@close` |
| POST | `v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}/actions` | 401 | v3.teacher.cases.reviews.viewer.action | `App\Http\Controllers\V3\Teacher\ReviewPreviewController@action` |
| GET / HEAD | `v3/teacher/cases/{case}/reviews/{review}/viewer-sessions/{viewer}/frames/{frame_no}` | 401 | v3.teacher.cases.reviews.viewer.frame | `App\Http\Controllers\V3\Teacher\ReviewPreviewController@frame` |
| GET / HEAD | `v3/teacher/cases/{case}/scene-authoring` | 401 | v3.teacher.cases.scene-authoring | `App\Http\Controllers\V3\Teacher\SceneAuthoringStatusController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/scene-qualification` | 401 | v3.teacher.cases.scene-qualification | `App\Http\Controllers\V3\Teacher\SceneQualificationStatusController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/sections/{section}` | 401 | v3.teacher.cases.sections.show | `App\Http\Controllers\V3\Teacher\TeacherSectionController@show` |
| GET / HEAD | `v3/teacher/cases/{case}/source-status` | 401 | v3.teacher.cases.source-status | `App\Http\Controllers\V3\Teacher\CaseSourceStatusController@show` |
| GET / HEAD | `v3/teacher/documents/{document}/download` | 401 | v3.teacher.documents.download | `App\Http\Controllers\V3\Teacher\DocumentController@download` |
| POST | `v3/teacher/pdf-uploads` | 401 | v3.teacher.pdf-uploads.store | `App\Http\Controllers\V3\Teacher\PdfUploadController@store` |
| GET / HEAD | `v3/teacher/pdf-uploads` | 401 | v3.teacher.pdf-uploads.index | `App\Http\Controllers\V3\Teacher\PdfUploadController@index` |
| GET / HEAD | `v3/teacher/pdf-uploads/{attempt}` | 401 | v3.teacher.pdf-uploads.show | `App\Http\Controllers\V3\Teacher\PdfUploadController@show` |
| GET / HEAD | `v3/teacher/subject-runs/{run}` | 404 | v3.teacher.subject-runs.show | `App\Http\Controllers\V3\Teacher\SubjectRunController@show` |
| POST | `v3/teacher/subject-runs/{run}/commands` | 404 | v3.teacher.subject-runs.commands | `App\Http\Controllers\V3\Teacher\SubjectRunController@command` |
| GET / HEAD | `v3/teacher/subject-runs/{run}/events` | 404 | v3.teacher.subject-runs.events | `App\Http\Controllers\V3\Teacher\SubjectRunController@events` |
| POST | `v3/teacher/subject-runs/{run}/generation-mode` | 404 | v3.teacher.subject-runs.generation-mode | `App\Http\Controllers\V3\Teacher\SubjectRunController@updateGenerationSelection` |
| GET / HEAD | `v3/teacher/subject-runs/{run}/progress` | 404 | v3.teacher.subject-runs.progress | `App\Http\Controllers\V3\Teacher\SubjectRunController@progress` |
| GET / HEAD | `v3/teacher/subject-runs/{run}/prompt-export` | 404 | v3.teacher.subject-runs.prompt-export | `App\Http\Controllers\V3\Teacher\SubjectRunController@promptExport` |
| GET / HEAD | `v3/teacher/subject-runs/{run}/scenes` | 404 | v3.teacher.subject-runs.scenes | `App\Http\Controllers\V3\Teacher\SubjectRunController@scenes` |
| POST | `v3/teacher/subject-runs/{run}/scenes/{scene}/authoring-request` | 404 | v3.teacher.subject-runs.scenes.authoring-request | `App\Http\Controllers\V3\Teacher\SubjectRunController@requestSceneGeneration` |
| POST | `v3/teacher/subject-runs/{run}/scenes/{scene}/imports` | 404 | v3.teacher.subject-runs.scenes.imports | `App\Http\Controllers\V3\Teacher\SubjectRunController@importScene` |
| GET / HEAD | `v3/teacher/subjects/{subject}/runs` | 404 | v3.teacher.subject-runs.index | `App\Http\Controllers\V3\Teacher\SubjectRunController@index` |
| POST | `v3/teacher/subjects/{subject}/runs` | 404 | v3.teacher.subject-runs.store | `App\Http\Controllers\V3\Teacher\SubjectRunController@store` |
| GET / HEAD | `v3/teacher/subjects/{subject}/runs/create` | 404 | v3.teacher.subject-runs.create | `App\Http\Controllers\V3\Teacher\SubjectRunController@create` |
| GET / HEAD | `verify-email` | 401 | verification.notice | `App\Http\Controllers\Auth\EmailVerificationPromptController` |
| GET / HEAD | `verify-email/{id}/{hash}` | 401 | verification.verify | `App\Http\Controllers\Auth\VerifyEmailController` |

## Python bridge

22 OpenAPI operations were exercised in-process through ASGI without signatures. This excludes FastAPI documentation/OpenAPI convenience routes.

| Method | Path | Anonymous HTTP |
| --- | --- | ---: |
| POST | `/internal/v1/workflows/start` | 401 |
| GET | `/internal/v1/workflows/{workflow_id}` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/activate-source` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/activate-map` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/lesson-ready` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/notebook-reconciled` | 401 |
| GET | `/internal/v1/workflows/{workflow_id}/consumed-query` | 422 |
| POST | `/internal/v1/review-preview/sessions` | 401 |
| POST | `/internal/v1/review-preview/sessions/{viewer_ref}/actions` | 401 |
| GET | `/internal/v1/review-preview/sessions/{viewer_ref}/frames/{frame_no}` | 401 |
| DELETE | `/internal/v1/review-preview/sessions/{viewer_ref}` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/review-decisions` | 401 |
| GET | `/internal/v1/workflows/{workflow_id}/review-decisions/{decision_ref}` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/start-correction` | 401 |
| GET | `/internal/v1/workflows/{workflow_id}/describe-correction` | 401 |
| GET | `/internal/v1/health` | 401 |
| POST | `/internal/v1/workflows/{workflow_id}/admin-controls` | 401 |
| GET | `/internal/v1/workflows/{workflow_id}/admin-controls/{command_ref}` | 401 |
| POST | `/internal/v1/application-pipeline/submissions` | 401 |
| POST | `/internal/v1/application-pipeline/commands` | 401 |
| GET | `/internal/v1/application-pipeline/receipts/{command_id}` | 401 |
| GET | `/internal/v1/application-pipeline/runs/{run_id}/status` | 401 |
