<?php

namespace App\Services\V3\LocalWorker\Protocol;

class LocalWorkerV1Validator
{
    public const SCHEMA_VERSION = 1;
    public const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const SHA256_REGEX = '/^[0-9a-f]{64}$/';

    /**
     * Check if a string is a valid UUID.
     */
    public static function assertUuid(mixed $value, string $fieldName): void
    {
        if (!is_string($value) || !preg_match(self::UUID_REGEX, $value)) {
            throw new LocalWorkerProtocolException(
                "Field '{$fieldName}' must be a valid UUID",
                'invalid_uuid',
                422
            );
        }
    }

    /**
     * Check if a string is a valid lowercase 64-character hex SHA-256 digest.
     */
    public static function assertSha256(mixed $value, string $fieldName): void
    {
        if (!is_string($value) || !preg_match(self::SHA256_REGEX, $value)) {
            throw new LocalWorkerProtocolException(
                "Field '{$fieldName}' must be a 64-character lowercase hexadecimal SHA-256 digest",
                'invalid_sha256',
                422
            );
        }
    }

    /**
     * Enforce strict keys: all required must exist, no unknown keys permitted.
     */
    public static function assertExactKeys(array $payload, array $requiredKeys, array $optionalKeys = [], string $context = 'payload'): void
    {
        $allowed = array_merge($requiredKeys, $optionalKeys);
        $allowedMap = array_flip($allowed);

        foreach ($requiredKeys as $req) {
            if (!array_key_exists($req, $payload)) {
                throw new LocalWorkerProtocolException(
                    "Missing required field '{$req}' in {$context}",
                    'missing_required_field',
                    422
                );
            }
        }

        foreach ($payload as $key => $val) {
            if (!isset($allowedMap[$key])) {
                throw new LocalWorkerProtocolException(
                    "Unknown field '{$key}' is not permitted in {$context}",
                    'unknown_field',
                    422
                );
            }
        }
    }

    /**
     * Validate an input manifest.
     */
    public static function validateInputManifest(array $manifest): void
    {
        self::assertExactKeys($manifest, [
            'schema_version',
            'kind',
            'task_id',
            'job_id',
            'run_id',
            'task_kind',
            'execution_mode',
            'output_scope',
            'protocol_id',
            'protocol_version',
            'settings_snapshot_id',
            'settings_sha256',
            'runtime_settings_artifact_id',
            'runtime_settings_sha256',
            'preparation',
            'items',
            'instruction_artifacts',
            'artifacts',
            'required_outputs',
            'resource_budget',
            'capabilities',
        ], [
            'required_scenes',
        ], 'input_manifest');

        if ($manifest['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException(
                "Unsupported schema_version '{$manifest['schema_version']}' in input_manifest; expected " . self::SCHEMA_VERSION,
                'unsupported_schema_version',
                422
            );
        }

        if ($manifest['kind'] !== 'local_worker_input_manifest') {
            throw new LocalWorkerProtocolException("Invalid manifest kind '{$manifest['kind']}'", 'invalid_kind', 422);
        }

        self::assertUuid($manifest['task_id'], 'task_id');
        self::assertUuid($manifest['job_id'], 'job_id');
        self::assertUuid($manifest['run_id'], 'run_id');

        if (!in_array($manifest['task_kind'], ['author_prompts', 'return_scenes'], true)) {
            throw new LocalWorkerProtocolException("Invalid task_kind '{$manifest['task_kind']}'", 'invalid_task_kind', 422);
        }

        if ($manifest['execution_mode'] !== 'local_worker_v1') {
            throw new LocalWorkerProtocolException("Invalid execution_mode '{$manifest['execution_mode']}'", 'invalid_execution_mode', 422);
        }

        if (!in_array($manifest['output_scope'], ['prompts_only', 'prompts_and_scenes'], true)) {
            throw new LocalWorkerProtocolException("Invalid output_scope '{$manifest['output_scope']}'", 'invalid_output_scope', 422);
        }

        self::assertSha256($manifest['settings_sha256'], 'settings_sha256');
        self::assertUuid($manifest['runtime_settings_artifact_id'], 'runtime_settings_artifact_id');
        self::assertSha256($manifest['runtime_settings_sha256'], 'runtime_settings_sha256');

        if (!is_array($manifest['items']) || empty($manifest['items'])) {
            throw new LocalWorkerProtocolException("Manifest 'items' must be a non-empty array", 'invalid_items', 422);
        }

        foreach ($manifest['items'] as $idx => $item) {
            self::assertExactKeys($item, [
                'item_id',
                'original_artifact_id',
                'original_sha256',
                'original_byte_count',
                'original_page_count',
                'upload_ordinal',
                'source_ordinal',
                'part_ordinal',
                'original_page_start',
                'original_page_end',
                'prepared_artifact_id',
                'prepared_sha256',
                'prepared_byte_count',
                'prepared_page_count',
            ], [], "items[{$idx}]");

            self::assertUuid($item['item_id'], "items[{$idx}].item_id");
            self::assertUuid($item['original_artifact_id'], "items[{$idx}].original_artifact_id");
            self::assertSha256($item['original_sha256'], "items[{$idx}].original_sha256");
            self::assertUuid($item['prepared_artifact_id'], "items[{$idx}].prepared_artifact_id");
            self::assertSha256($item['prepared_sha256'], "items[{$idx}].prepared_sha256");
            if (($item['upload_ordinal'] ?? null) !== $idx + 1) {
                throw new LocalWorkerProtocolException("upload_ordinal must match manifest order", 'invalid_numeric_bounds', 422);
            }

            foreach ([
                'original_byte_count', 'original_page_count', 'upload_ordinal', 'source_ordinal', 'part_ordinal',
                'original_page_start', 'original_page_end', 'prepared_byte_count', 'prepared_page_count',
            ] as $field) {
                if (!is_int($item[$field]) || $item[$field] < 1) {
                    throw new LocalWorkerProtocolException("{$field} must be a positive integer", 'invalid_numeric_bounds', 422);
                }
            }
            if ($item['original_page_end'] > $item['original_page_count']) {
                throw new LocalWorkerProtocolException("Page range exceeds original page count", 'invalid_page_range', 422);
            }
            if ($item['prepared_page_count'] !== $item['original_page_end'] - $item['original_page_start'] + 1) {
                throw new LocalWorkerProtocolException("Invalid page range", 'invalid_page_range', 422);
            }
        }

        if (!is_array($manifest['artifacts'])) {
            throw new LocalWorkerProtocolException("Manifest 'artifacts' must be an array", 'invalid_artifacts', 422);
        }
        $artifactById = [];
        $logicalNames = [];
        $allowedPurposes = ['runtime_settings', 'framework_body', 'instruction_bundle', 'original_pdf', 'prepared_pdf', 'source_pdf', 'preparation_inventory'];
        foreach ($manifest['artifacts'] as $idx => $artifact) {
            self::assertExactKeys($artifact, [
                'artifact_id', 'purpose', 'size_bytes', 'digest_sha256', 'media_type', 'logical_name',
            ], ['sha256'], "artifacts[{$idx}]");
            self::assertUuid($artifact['artifact_id'], "artifacts[{$idx}].artifact_id");
            self::assertSha256($artifact['digest_sha256'], "artifacts[{$idx}].digest_sha256");
            if (!is_int($artifact['size_bytes']) || $artifact['size_bytes'] < 1) {
                throw new LocalWorkerProtocolException('Artifact size_bytes must be a positive integer', 'invalid_artifacts', 422);
            }
            $name = $artifact['logical_name'];
            if (!is_string($name) || $name === '' || in_array($name, ['.', '..'], true)
                || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
                throw new LocalWorkerProtocolException('Artifact logical_name must be a safe filename', 'invalid_artifacts', 422);
            }
            if (isset($artifactById[$artifact['artifact_id']]) || isset($logicalNames[$name])) {
                throw new LocalWorkerProtocolException('Artifact identities and names must be unique', 'invalid_artifacts', 422);
            }
            if (!in_array($artifact['purpose'], $allowedPurposes, true) || !is_string($artifact['media_type'])) {
                throw new LocalWorkerProtocolException('Artifact purpose or media_type is invalid', 'invalid_artifacts', 422);
            }
            if (isset($artifact['sha256']) && $artifact['sha256'] !== $artifact['digest_sha256']) {
                throw new LocalWorkerProtocolException('Artifact digest aliases disagree', 'invalid_artifacts', 422);
            }
            $artifactById[$artifact['artifact_id']] = $artifact;
            $logicalNames[$name] = true;
        }
        if ($manifest['task_kind'] === 'author_prompts') {
            $runtime = $artifactById[$manifest['runtime_settings_artifact_id']] ?? null;
            if (!$runtime || $runtime['purpose'] !== 'runtime_settings'
                || $runtime['digest_sha256'] !== $manifest['runtime_settings_sha256']) {
                throw new LocalWorkerProtocolException('Runtime settings artifact is not bound by artifacts', 'settings_snapshot_mismatch', 422);
            }
        }

        if (!is_array($manifest['instruction_artifacts'])) {
            throw new LocalWorkerProtocolException("Manifest 'instruction_artifacts' must be an array", 'invalid_instructions', 422);
        }
        $instructionNames = [];
        foreach ($manifest['instruction_artifacts'] as $idx => $instruction) {
            self::assertExactKeys($instruction, ['name', 'version', 'artifact_id', 'sha256', 'size_bytes'], [], "instruction_artifacts[{$idx}]");
            if (!is_string($instruction['name']) || $instruction['name'] === '' || isset($instructionNames[$instruction['name']])) {
                throw new LocalWorkerProtocolException('Instruction names must be unique non-empty strings', 'invalid_instructions', 422);
            }
            self::assertUuid($instruction['artifact_id'], "instruction_artifacts[{$idx}].artifact_id");
            self::assertSha256($instruction['sha256'], "instruction_artifacts[{$idx}].sha256");
            $artifact = $artifactById[$instruction['artifact_id']] ?? null;
            $expectedPurpose = match ($instruction['name']) {
                'framework' => 'framework_body',
                'notebooklm_protocol' => 'instruction_bundle',
                'scene_system_template' => 'instruction_bundle',
                default => null,
            };
            if (!$artifact || $expectedPurpose === null || $artifact['purpose'] !== $expectedPurpose
                || $artifact['digest_sha256'] !== $instruction['sha256']
                || $artifact['size_bytes'] !== $instruction['size_bytes']) {
                throw new LocalWorkerProtocolException('Instruction artifact does not match artifact inventory', 'invalid_instructions', 422);
            }
            $instructionNames[$instruction['name']] = true;
        }
        if ($manifest['task_kind'] === 'author_prompts' && $manifest['protocol_id'] === 'notebooklm_v1') {
            $names = array_keys($instructionNames);
            sort($names);
            if ($names !== ['framework', 'notebooklm_protocol', 'scene_system_template']) {
                throw new LocalWorkerProtocolException('Authoring manifest must seal framework, NotebookLM protocol, and scene template instructions', 'invalid_instructions', 422);
            }
        }
    }

    /**
     * Validate an authoring result.
     */
    public static function validateAuthoringResult(array $result): void
    {
        self::assertExactKeys($result, [
            'schema_version',
            'kind',
            'result_id',
            'task_id',
            'job_id',
            'run_id',
            'assignment_id',
            'assignment_epoch',
            'input_manifest_sha256',
            'protocol_version',
            'settings_sha256',
            'preparation_sha256',
            'artifact_references',
            'lessons',
            'archive_inventory',
            'metadata',
        ], [], 'authoring_result');

        if ($result['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException(
                "Unsupported schema_version '{$result['schema_version']}' in authoring_result",
                'unsupported_schema_version',
                422
            );
        }

        if ($result['kind'] !== 'local_worker_authoring_result') {
            throw new LocalWorkerProtocolException("Invalid result kind '{$result['kind']}'", 'invalid_kind', 422);
        }

        self::assertUuid($result['result_id'], 'result_id');
        self::assertUuid($result['task_id'], 'task_id');
        self::assertUuid($result['job_id'], 'job_id');
        self::assertUuid($result['run_id'], 'run_id');
        self::assertUuid($result['assignment_id'], 'assignment_id');

        if (!is_int($result['assignment_epoch']) || $result['assignment_epoch'] < 1) {
            throw new LocalWorkerProtocolException("assignment_epoch must be integer >= 1", 'invalid_numeric_bounds', 422);
        }

        self::assertSha256($result['input_manifest_sha256'], 'input_manifest_sha256');
        self::assertSha256($result['settings_sha256'], 'settings_sha256');
        self::assertSha256($result['preparation_sha256'], 'preparation_sha256');

        if (!is_array($result['lessons']) || empty($result['lessons'])) {
            throw new LocalWorkerProtocolException("Result 'lessons' must be a non-empty array", 'invalid_lessons', 422);
        }

        if (!is_array($result['archive_inventory']) || empty($result['archive_inventory'])) {
            throw new LocalWorkerProtocolException("Result 'archive_inventory' must contain the retained PDF maps.", 'incomplete_result', 422);
        }
        if (!is_array($result['artifact_references']) || empty($result['artifact_references'])) {
            throw new LocalWorkerProtocolException("Result 'artifact_references' must identify the retained evidence.", 'incomplete_result', 422);
        }
        $seenArtifactRefs = [];
        foreach ($result['artifact_references'] as $idx => $reference) {
            self::assertExactKeys($reference, ['artifact_id', 'digest_sha256'], [], "artifact_references[{$idx}]");
            self::assertUuid($reference['artifact_id'], "artifact_references[{$idx}].artifact_id");
            self::assertSha256($reference['digest_sha256'], "artifact_references[{$idx}].digest_sha256");
            if (isset($seenArtifactRefs[$reference['artifact_id']])) {
                throw new LocalWorkerProtocolException('Artifact references must be unique.', 'duplicate_artifact_reference', 422);
            }
            $seenArtifactRefs[$reference['artifact_id']] = true;
        }
        $archiveItems = [];
        $archiveOrdinals = [];
        foreach ($result['archive_inventory'] as $idx => $archive) {
            self::assertExactKeys($archive, [
                'item_id', 'pdf_ordinal', 'archive_artifact_id', 'archive_sha256',
            ], [], "archive_inventory[{$idx}]");
            self::assertUuid($archive['item_id'], "archive_inventory[{$idx}].item_id");
            self::assertUuid($archive['archive_artifact_id'], "archive_inventory[{$idx}].archive_artifact_id");
            self::assertSha256($archive['archive_sha256'], "archive_inventory[{$idx}].archive_sha256");
            if (!is_int($archive['pdf_ordinal']) || $archive['pdf_ordinal'] < 1
                || isset($archiveItems[$archive['item_id']]) || isset($archiveOrdinals[$archive['pdf_ordinal']])) {
                throw new LocalWorkerProtocolException('Archive inventory item and PDF ordinals must be unique positive values.', 'invalid_archive_inventory', 422);
            }
            $archiveItems[$archive['item_id']] = $archive;
            $archiveOrdinals[$archive['pdf_ordinal']] = true;
        }

        // Validate lessons and scene lineage
        $seenTuples = [];
        $lessonsByItem = [];

        foreach ($result['lessons'] as $lIdx => $lesson) {
            self::assertExactKeys($lesson, [
                'item_id',
                'lesson_ordinal',
                'lesson_title',
                'raw_response_artifact_id',
                'raw_response_sha256',
                'scenes',
            ], [], "lessons[{$lIdx}]");

            self::assertUuid($lesson['item_id'], "lessons[{$lIdx}].item_id");
            self::assertUuid($lesson['raw_response_artifact_id'], "lessons[{$lIdx}].raw_response_artifact_id");
            self::assertSha256($lesson['raw_response_sha256'], "lessons[{$lIdx}].raw_response_sha256");

            if (!is_int($lesson['lesson_ordinal']) || $lesson['lesson_ordinal'] < 0) {
                throw new LocalWorkerProtocolException("lesson_ordinal must be integer >= 0", 'invalid_numeric_bounds', 422);
            }

            if (!is_array($lesson['scenes']) || empty($lesson['scenes'])) {
                throw new LocalWorkerProtocolException("Lesson must contain non-empty scenes array", 'empty_scenes', 422);
            }

            $itemId = $lesson['item_id'];
            $lessonsByItem[$itemId][] = $lesson['lesson_ordinal'];

            foreach ($lesson['scenes'] as $sIdx => $scene) {
                self::assertExactKeys($scene, [
                    'scene_ordinal',
                    'scene_title',
                    'prompt_text',
                    'prompt_sha256',
                    'generation_context',
                ], [], "lessons[{$lIdx}].scenes[{$sIdx}]");

                if (!is_int($scene['scene_ordinal']) || $scene['scene_ordinal'] < 0) {
                    throw new LocalWorkerProtocolException("scene_ordinal must be integer >= 0", 'invalid_numeric_bounds', 422);
                }

                if (empty(trim((string)$scene['prompt_text']))) {
                    throw new LocalWorkerProtocolException("prompt_text must not be empty or whitespace only", 'empty_prompt', 422);
                }

                self::assertSha256($scene['prompt_sha256'], "lessons[{$lIdx}].scenes[{$sIdx}].prompt_sha256");

                // Verify prompt_sha256 matches actual prompt_text
                $calculatedDigest = hash('sha256', (string)$scene['prompt_text']);
                if ($calculatedDigest !== $scene['prompt_sha256']) {
                    throw new LocalWorkerProtocolException(
                        "prompt_sha256 mismatch for scene '{$scene['scene_title']}': declared {$scene['prompt_sha256']}, computed {$calculatedDigest}",
                        'digest_mismatch',
                        422
                    );
                }

                $tupleKey = "{$itemId}:{$lesson['lesson_ordinal']}:{$scene['scene_ordinal']}";
                if (isset($seenTuples[$tupleKey])) {
                    throw new LocalWorkerProtocolException("Duplicate (item_id, lesson_ordinal, scene_ordinal) tuple: {$tupleKey}", 'duplicate_tuple', 422);
                }
                $seenTuples[$tupleKey] = true;
            }
        }

        // Validate contiguous ordinals per item
        foreach ($lessonsByItem as $itemId => $ordinals) {
            sort($ordinals);
            foreach ($ordinals as $idx => $ord) {
                if ($ord !== $idx) {
                    throw new LocalWorkerProtocolException("Non-contiguous lesson ordinals for item {$itemId}", 'non_contiguous_ordinals', 422);
                }
            }
        }

        ksort($archiveItems);
        $orderedArchiveOrdinals = array_keys($archiveOrdinals);
        sort($orderedArchiveOrdinals);
        if ($orderedArchiveOrdinals !== range(1, count($archiveOrdinals))) {
            throw new LocalWorkerProtocolException('Archive inventory PDF ordinals must be contiguous and begin at one.', 'invalid_archive_inventory', 422);
        }
    }

    /**
     * Validate a scene result.
     */
    public static function validateSceneResult(array $result): void
    {
        self::assertExactKeys($result, [
            'schema_version',
            'kind',
            'result_id',
            'task_id',
            'job_id',
            'run_id',
            'assignment_id',
            'assignment_epoch',
            'control_version',
            'input_manifest_sha256',
            'submissions',
        ], [], 'scene_result');

        if ($result['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException(
                "Unsupported schema_version '{$result['schema_version']}' in scene_result",
                'unsupported_schema_version',
                422
            );
        }

        if ($result['kind'] !== 'local_worker_scene_result') {
            throw new LocalWorkerProtocolException("Invalid result kind '{$result['kind']}'", 'invalid_kind', 422);
        }

        self::assertUuid($result['result_id'], 'result_id');
        self::assertUuid($result['task_id'], 'task_id');
        self::assertUuid($result['job_id'], 'job_id');
        self::assertUuid($result['run_id'], 'run_id');
        self::assertUuid($result['assignment_id'], 'assignment_id');

        if (!is_int($result['assignment_epoch']) || $result['assignment_epoch'] < 1) {
            throw new LocalWorkerProtocolException("assignment_epoch must be integer >= 1", 'invalid_numeric_bounds', 422);
        }
        if (!is_int($result['control_version']) || $result['control_version'] < 1) {
            throw new LocalWorkerProtocolException("control_version must be integer >= 1", 'invalid_numeric_bounds', 422);
        }

        self::assertSha256($result['input_manifest_sha256'], 'input_manifest_sha256');

        if (!is_array($result['submissions']) || empty($result['submissions'])) {
            throw new LocalWorkerProtocolException("Scene result submissions must be a non-empty array", 'empty_submissions', 422);
        }

        $seenSubmissionIds = [];
        $seenSceneIds = [];
        foreach ($result['submissions'] as $idx => $sub) {
            self::assertExactKeys($sub, [
                'submission_id',
                'scene_id',
                'prompt_version_id',
                'prompt_sha256',
                'package_artifact_id',
                'package_sha256',
                'provenance',
            ], [], "submissions[{$idx}]");

            self::assertUuid($sub['submission_id'], "submissions[{$idx}].submission_id");
            self::assertUuid($sub['scene_id'], "submissions[{$idx}].scene_id");
            if (isset($seenSubmissionIds[$sub['submission_id']])) {
                throw new LocalWorkerProtocolException('Scene submission IDs must be unique within a result.', 'duplicate_submission_id', 422);
            }
            if (isset($seenSceneIds[$sub['scene_id']])) {
                throw new LocalWorkerProtocolException('A scene may be submitted only once in a result batch.', 'duplicate_scene_submission', 422);
            }
            $seenSubmissionIds[$sub['submission_id']] = true;
            $seenSceneIds[$sub['scene_id']] = true;
            self::assertUuid($sub['prompt_version_id'], "submissions[{$idx}].prompt_version_id");
            self::assertSha256($sub['prompt_sha256'], "submissions[{$idx}].prompt_sha256");
            self::assertUuid($sub['package_artifact_id'], "submissions[{$idx}].package_artifact_id");
            self::assertSha256($sub['package_sha256'], "submissions[{$idx}].package_sha256");

            self::assertExactKeys($sub['provenance'], [
                'mode',
                'operator_id',
                'device_id',
                'provider',
                'model',
                'effort',
                'measurement_status',
            ], [], "submissions[{$idx}].provenance");

            if (!in_array($sub['provenance']['mode'], ['manual_web', 'approved_api'], true)) {
                throw new LocalWorkerProtocolException("Invalid provenance mode '{$sub['provenance']['mode']}'", 'invalid_provenance_mode', 422);
            }
            if (!in_array($sub['provenance']['measurement_status'], ['not_recorded', 'measured', 'estimated'], true)) {
                throw new LocalWorkerProtocolException("Invalid measurement_status '{$sub['provenance']['measurement_status']}'", 'invalid_measurement_status', 422);
            }
            if (!is_int($sub['provenance']['operator_id']) || $sub['provenance']['operator_id'] < 1) {
                throw new LocalWorkerProtocolException('provenance.operator_id must be a positive user ID', 'invalid_operator_id', 422);
            }
            foreach (['provider', 'model', 'effort'] as $reportedField) {
                $value = $sub['provenance'][$reportedField];
                if ($value !== null && (!is_string($value) || trim($value) === '')) {
                    throw new LocalWorkerProtocolException("provenance.{$reportedField} must be a non-empty string or null", 'invalid_reported_provenance', 422);
                }
            }
            self::assertUuid($sub['provenance']['device_id'], "submissions[{$idx}].provenance.device_id");
        }
    }

    /**
     * Validate static_three_file_v1 scene package.
     */
    public static function validateScenePackage(array $package): void
    {
        if (($package['profile'] ?? null) !== 'manual_scene_files_v1') {
            self::validateStaticThreeFilePackage($package);
            return;
        }
        self::assertExactKeys($package, ['schema_version', 'profile', 'files'], [], 'manual_scene_files_package');
        if ($package['schema_version'] !== 1 || !is_array($package['files']) || count($package['files']) < 1 || count($package['files']) > 3) {
            throw new LocalWorkerProtocolException('Manual package requires index.html and optional styles.css/script.js.', 'invalid_package_files_count', 422);
        }
        $seen = [];
        foreach ($package['files'] as $file) {
            self::assertExactKeys($file, ['path', 'content'], [], 'manual_scene_file');
            $path = $file['path'];
            if (!in_array($path, ['index.html', 'styles.css', 'script.js'], true) || isset($seen[$path])) {
                throw new LocalWorkerProtocolException('Invalid or duplicate scene file path.', 'disallowed_package_file', 422);
            }
            $seen[$path] = true;
            if (!is_string($file['content']) || str_contains($file['content'], "\0") || !mb_check_encoding($file['content'], 'UTF-8')) {
                throw new LocalWorkerProtocolException('Scene file must contain valid UTF-8 text without NUL.', 'invalid_content', 422);
            }
            if (strlen($file['content']) > 1048576) {
                throw new LocalWorkerProtocolException('Scene file exceeds transfer limit.', 'file_size_exceeded', 413);
            }
            if ($path === 'index.html' && trim($file['content']) === '') {
                throw new LocalWorkerProtocolException('Scene entrypoint is empty.', 'empty_package_file', 422);
            }
        }
        if (!isset($seen['index.html'])) {
            throw new LocalWorkerProtocolException('Scene entrypoint is missing.', 'missing_entrypoint', 422);
        }
    }

    public static function validateStaticThreeFilePackage(array $package): void
    {
        self::assertExactKeys($package, [
            'schema_version',
            'files',
        ], [], 'static_three_file_package');

        if ($package['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException(
                "Unsupported package schema_version '{$package['schema_version']}'",
                'unsupported_schema_version',
                422
            );
        }

        if (!is_array($package['files']) || count($package['files']) !== 3) {
            throw new LocalWorkerProtocolException(
                "Package 'files' must contain exactly 3 files (index.html, styles.css, script.js)",
                'invalid_package_files_count',
                422
            );
        }

        $expectedPaths = ['index.html' => false, 'styles.css' => false, 'script.js' => false];
        $totalBytes = 0;

        foreach ($package['files'] as $fIdx => $file) {
            self::assertExactKeys($file, [
                'path',
                'content',
            ], [], "files[{$fIdx}]");

            $path = $file['path'];
            if (!array_key_exists($path, $expectedPaths)) {
                throw new LocalWorkerProtocolException(
                    "Unexpected file path '{$path}' in static_three_file package",
                    'disallowed_package_file',
                    422
                );
            }
            if ($expectedPaths[$path] === true) {
                throw new LocalWorkerProtocolException(
                    "Duplicate file path '{$path}' in static_three_file package",
                    'duplicate_package_file',
                    422
                );
            }
            $expectedPaths[$path] = true;

            $content = (string)$file['content'];
            $fileLen = strlen($content);

            if ($fileLen === 0) {
                throw new LocalWorkerProtocolException(
                    "File '{$path}' must not be empty",
                    'empty_package_file',
                    422
                );
            }

            if ($fileLen > 1048576) { // 1 MiB
                throw new LocalWorkerProtocolException(
                    "File '{$path}' exceeds 1 MiB limit ({$fileLen} bytes)",
                    'file_size_exceeded',
                    413
                );
            }

            if (str_contains($content, "\0")) {
                throw new LocalWorkerProtocolException(
                    "File '{$path}' contains forbidden null bytes",
                    'null_byte_detected',
                    422
                );
            }

            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new LocalWorkerProtocolException(
                    "File '{$path}' contains invalid UTF-8 encoding",
                    'invalid_utf8',
                    422
                );
            }

            $totalBytes += $fileLen;
        }

        if ($totalBytes > 4194304) { // 4 MiB
            throw new LocalWorkerProtocolException(
                "Total package decoded content exceeds 4 MiB limit ({$totalBytes} bytes)",
                'package_size_exceeded',
                413
            );
        }
    }

    /**
     * Validate a claim request.
     */
    public static function validateClaimRequest(array $claim): void
    {
        self::assertExactKeys($claim, [
            'schema_version',
            'claim_id',
            'protocol_version',
            'capabilities',
            'available_slots',
        ], [
            'task_id',
        ], 'claim_request');

        if ($claim['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version '{$claim['schema_version']}'", 'unsupported_schema_version', 422);
        }
        self::assertUuid($claim['claim_id'], 'claim_id');
        if (!is_int($claim['protocol_version']) || $claim['protocol_version'] < 1) {
            throw new LocalWorkerProtocolException("protocol_version must be integer >= 1", 'invalid_protocol_version', 422);
        }
        if (!is_array($claim['capabilities']) || empty($claim['capabilities'])) {
            throw new LocalWorkerProtocolException("capabilities must be a non-empty array of strings", 'invalid_capabilities', 422);
        }
        foreach ($claim['capabilities'] as $cap) {
            if (!is_string($cap) || empty(trim($cap))) {
                throw new LocalWorkerProtocolException("capabilities must be non-empty strings", 'invalid_capabilities', 422);
            }
        }
        if (!is_int($claim['available_slots']) || $claim['available_slots'] < 1) {
            throw new LocalWorkerProtocolException("available_slots must be integer >= 1", 'invalid_numeric_bounds', 422);
        }
        if (isset($claim['task_id'])) {
            self::assertUuid($claim['task_id'], 'task_id');
        }
    }

    /**
     * Validate a heartbeat request.
     */
    public static function validateHeartbeatRequest(array $hb): void
    {
        self::assertExactKeys($hb, [
            'schema_version',
            'observed_control_version',
        ], [], 'heartbeat_request');

        if ($hb['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version", 'unsupported_schema_version', 422);
        }
        if (!is_int($hb['observed_control_version']) || $hb['observed_control_version'] < 1) {
            throw new LocalWorkerProtocolException("observed_control_version must be integer >= 1", 'invalid_numeric_bounds', 422);
        }
    }

    /**
     * Validate a release request.
     */
    public static function validateReleaseRequest(array $rel): void
    {
        self::assertExactKeys($rel, [
            'schema_version',
            'reason',
        ], [], 'release_request');

        if ($rel['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version", 'unsupported_schema_version', 422);
        }
        if (!is_string($rel['reason']) || empty(trim($rel['reason']))) {
            throw new LocalWorkerProtocolException("reason must be non-empty string", 'invalid_reason', 422);
        }
    }

    /**
     * Validate an upload slot creation request.
     */
    public static function validateUploadSlotRequest(array $slot): void
    {
        self::assertExactKeys($slot, [
            'schema_version',
            'upload_id',
            'artifact_id',
            'purpose',
            'media_type',
            'expected_bytes',
            'expected_sha256',
        ], [], 'upload_slot_request');

        if ($slot['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version", 'unsupported_schema_version', 422);
        }
        self::assertUuid($slot['upload_id'], 'upload_id');
        self::assertUuid($slot['artifact_id'], 'artifact_id');
        if (!is_string($slot['purpose']) || empty(trim($slot['purpose']))) {
            throw new LocalWorkerProtocolException("purpose must be non-empty string", 'invalid_purpose', 422);
        }
        if (!is_string($slot['media_type']) || empty(trim($slot['media_type']))) {
            throw new LocalWorkerProtocolException("media_type must be non-empty string", 'invalid_media_type', 422);
        }
        if (!is_int($slot['expected_bytes']) || $slot['expected_bytes'] <= 0) {
            throw new LocalWorkerProtocolException("expected_bytes must be integer > 0", 'invalid_numeric_bounds', 422);
        }
        self::assertSha256($slot['expected_sha256'], 'expected_sha256');
    }

    /**
     * Validate an effect reservation request.
     */
    public static function validateEffectReserveRequest(array $req): void
    {
        self::assertExactKeys($req, [
            'schema_version',
            'effect_id',
            'logical_effect_key',
            'operation_kind',
            'request_digest',
        ], [
            'pdf_ordinal',
            'lesson_ordinal',
            'scene_ordinal',
        ], 'effect_reserve_request');

        if ($req['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version", 'unsupported_schema_version', 422);
        }
        self::assertUuid($req['effect_id'], 'effect_id');
        if (!is_string($req['logical_effect_key']) || empty(trim($req['logical_effect_key']))) {
            throw new LocalWorkerProtocolException("logical_effect_key must be non-empty string", 'invalid_key', 422);
        }
        if (!is_string($req['operation_kind']) || empty(trim($req['operation_kind']))) {
            throw new LocalWorkerProtocolException("operation_kind must be non-empty string", 'invalid_operation', 422);
        }
        self::assertSha256($req['request_digest'], 'request_digest');
    }

    /**
     * Validate an effect dispatch request.
     */
    public static function validateEffectDispatchRequest(array $req): void
    {
        self::assertExactKeys($req, [
            'schema_version',
            'worker_process_id',
        ], [], 'effect_dispatch_request');

        if ($req['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version", 'unsupported_schema_version', 422);
        }
        if (!is_string($req['worker_process_id']) || empty(trim($req['worker_process_id']))) {
            throw new LocalWorkerProtocolException("worker_process_id must be non-empty string", 'invalid_process_id', 422);
        }
    }

    /**
     * Validate an effect outcome request.
     */
    public static function validateEffectOutcomeRequest(array $req): void
    {
        self::assertExactKeys($req, [
            'schema_version',
            'outcome_ref',
            'status',
        ], [
            'evidence_artifact_id',
            'evidence_sha256',
            'metadata',
        ], 'effect_outcome_request');

        if ($req['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LocalWorkerProtocolException("Unsupported schema_version", 'unsupported_schema_version', 422);
        }
        if (!is_string($req['outcome_ref']) || empty(trim($req['outcome_ref']))) {
            throw new LocalWorkerProtocolException("outcome_ref must be non-empty string", 'invalid_ref', 422);
        }
        $validStatuses = ['succeeded', 'failed_no_effect', 'unknown', 'authentication_required', 'rate_limited'];
        if (!in_array($req['status'], $validStatuses, true)) {
            throw new LocalWorkerProtocolException("status must be one of: " . implode(', ', $validStatuses), 'invalid_status', 422);
        }
        if (isset($req['evidence_artifact_id'])) {
            self::assertUuid($req['evidence_artifact_id'], 'evidence_artifact_id');
        }
        if (isset($req['evidence_sha256'])) {
            self::assertSha256($req['evidence_sha256'], 'evidence_sha256');
        }
    }
}
