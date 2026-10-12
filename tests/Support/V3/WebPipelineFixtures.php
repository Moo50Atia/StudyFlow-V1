<?php

namespace Tests\Support\V3;

use App\Models\Lecture;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebPipelineFixtures
{
    public const MOCK_RUN_ID = '09a59afe-ed26-4567-906b-e42d87d53362';
    public const MOCK_IMPORT_ID = '11111111-2222-3333-4444-555555555555';

    public static function createTeacherA(): User
    {
        return AccessFixtures::createEnrolledTeacher('Teacher Alpha', 'teacher_a@example.com', true);
    }

    public static function createTeacherB(): User
    {
        return AccessFixtures::createEnrolledTeacher('Teacher Beta', 'teacher_b@example.com', true);
    }

    public static function createAdmin(): User
    {
        return AccessFixtures::createEnrolledAdmin('Pipeline Admin', 'admin_v3@example.com', true);
    }

    public static function createStudent(): User
    {
        return AccessFixtures::createStudent('Student Charlie', 'student_c@example.com');
    }

    public static function createInactiveTeacher(): User
    {
        return AccessFixtures::createEnrolledTeacher('Inactive Teacher', 'inactive_t@example.com', false);
    }

    public static function createSubjectForTeacher(User $teacher, string $name = 'Classical Mechanics'): Subject
    {
        return Subject::create([
            'name' => $name,
            'description' => 'Physics curriculum subject',
        ]);
    }

    public static function grantTeacherPermission(User $teacher, Subject $subject, Lecture $lecture): void
    {
        DB::table('teacher_permissions')->insert([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'lecture_id' => $lecture->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * V2 Static Three-File JSON Package for Scene Imports.
     */
    public static function makeValidStaticScenePackage(string $title = 'Oscillator Dynamics'): array
    {
        return [
            'schema_version' => 1,
            'files' => [
                [
                    'path' => 'index.html',
                    'content' => "<!DOCTYPE html><html><head><title>{$title}</title><link rel=\"stylesheet\" href=\"./styles.css\"></head><body><div id=\"app\"><h1>{$title}</h1><canvas id=\"scene\"></canvas></div><script src=\"./script.js\"></script></body></html>",
                ],
                [
                    'path' => 'styles.css',
                    'content' => "body { margin: 0; background: #0b0f19; color: #fff; font-family: sans-serif; } #app { padding: 16px; } canvas { width: 100%; height: 400px; display: block; }",
                ],
                [
                    'path' => 'script.js',
                    'content' => "const canvas = document.getElementById('scene'); const ctx = canvas.getContext('2d'); ctx.fillStyle = '#38bdf8'; ctx.fillRect(10, 10, 100, 100);",
                ],
            ],
        ];
    }

    public static function makeMalformedStaticScenePackage(string $reason): array
    {
        $valid = self::makeValidStaticScenePackage();

        switch ($reason) {
            case 'extra_file':
                $valid['files'][] = [
                    'path' => 'extra.txt',
                    'content' => 'Forbidden extra file',
                ];
                return $valid;

            case 'missing_file':
                // Remove script.js
                array_pop($valid['files']);
                return $valid;

            case 'path_traversal':
                $valid['files'][0]['path'] = '../index.html';
                return $valid;

            case 'empty_file':
                $valid['files'][1]['content'] = '';
                return $valid;

            case 'oversize_file':
                // > 1 MiB (1024 * 1024 + 10 bytes)
                $valid['files'][2]['content'] = str_repeat('A', 1048576 + 10);
                return $valid;

            case 'invalid_schema':
                $valid['schema_version'] = 2;
                return $valid;

            case 'duplicate_path':
                $valid['files'][] = [
                    'path' => 'index.html',
                    'content' => '<html>duplicate</html>',
                ];
                return $valid;

            default:
                throw new \InvalidArgumentException("Unknown malformation reason: {$reason}");
        }
    }

    /**
     * Eleven-field First Lesson Model.
     */
    public static function makeElevenFieldLesson(bool $withMissingFields = false): array
    {
        if ($withMissingFields) {
            return [
                'lesson_title' => 'Curvilinear Kinematics & Tangential Acceleration',
                'core_concept' => 'Decomposition of acceleration into normal and tangential components',
                'learning_objectives' => 'Calculate rho and an',
                'misconceptions' => null, // NULL field with warning
                'governing_formulas' => 'an = v^2 / rho; at = dv/dt',
                'numerical_example' => 'v = 10 m/s, rho = 50 m => an = 2 m/s^2',
                'visual_representation' => null, // NULL field
                'interactive_controls' => 'Slider for speed v, slider for radius rho',
                'variables_and_ranges' => 'v in [0, 50], rho in [10, 200]',
                'laws_if_any' => '', // Empty string is a valid supplied law, distinct from NULL
                'real_world_application' => 'Highway banked exit ramp curvature',
                'missing_fields' => ['misconceptions', 'visual_representation'],
            ];
        }

        return [
            'lesson_title' => 'Rectilinear Constant Acceleration',
            'core_concept' => 'Uniformly accelerated linear motion',
            'learning_objectives' => 'Derive s(t) from constant a',
            'misconceptions' => 'Acceleration is not speed',
            'governing_formulas' => 'v = v0 + at; s = s0 + v0*t + 0.5*a*t^2',
            'numerical_example' => 'v0 = 0, a = 9.81 m/s^2, t = 2s => s = 19.62 m',
            'visual_representation' => 'Particle on horizontal rail with velocity vector and position graph',
            'interactive_controls' => 'Slider for acceleration a, slider for initial velocity v0',
            'variables_and_ranges' => 'a in [-20, 20], v0 in [0, 50]',
            'laws_if_any' => "Newton's First and Second Laws",
            'real_world_application' => 'Free falling stone under Earth gravity',
            'missing_fields' => [],
        ];
    }

    /**
     * Build synthetic 2-PDF historical run archive for deterministic offline import testing.
     */
    public static function buildSyntheticTwoPdfArchive(string $rootDir, string $runId = self::MOCK_RUN_ID): array
    {
        $terminalRoot = $rootDir . '/terminal-root';
        $runDir = $terminalRoot . '/runs/' . $runId;
        $privateDir = $runDir . '/private';
        $exportDir = $privateDir . '/scene-prompt-exports';
        $handoffDir = $privateDir . '/lesson_handoffs';

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }
        if (!is_dir($handoffDir)) {
            mkdir($handoffDir, 0777, true);
        }

        $pdfFiles = [
            'pdf_1' => [
                'sha256' => hash('sha256', 'SAMPLE_PDF_1_BYTES_DETERMINISTIC'),
                'filename' => '01_kinematics.pdf',
                'lessons_count' => 2,
                'prompts_count' => 3,
            ],
            'pdf_2' => [
                'sha256' => hash('sha256', 'SAMPLE_PDF_2_BYTES_DETERMINISTIC'),
                'filename' => '02_kinetics.pdf',
                'lessons_count' => 1,
                'prompts_count' => 2,
            ],
        ];

        // 1. subject-config.json
        $subjectConfig = [
            'run_id' => $runId,
            'created_at' => '2026-10-10T00:00:00Z',
            'subject_name' => 'Physics Mechanics',
            'pdf_files' => [
                ['ordinal' => 1, 'filename' => '01_kinematics.pdf', 'sha256' => $pdfFiles['pdf_1']['sha256']],
                ['ordinal' => 2, 'filename' => '02_kinetics.pdf', 'sha256' => $pdfFiles['pdf_2']['sha256']],
            ],
            'framework' => [
                'sha256' => hash('sha256', 'FRAMEWORK_BYTES_DETERMINISTIC'),
                'version' => 'v1.0.0',
            ],
        ];
        file_put_contents($runDir . '/subject-config.json', json_encode($subjectConfig, JSON_PRETTY_PRINT));

        // 2. per-PDF archives
        foreach ([1, 2] as $ordinal) {
            $pdfKey = 'pdf_' . $ordinal;
            $archiveData = [
                'run_id' => $runId,
                'pdf_ordinal' => $ordinal,
                'filename' => $pdfFiles[$pdfKey]['filename'],
                'input_sha256' => $pdfFiles[$pdfKey]['sha256'],
                'status' => 'completed',
                'lesson_count' => $pdfFiles[$pdfKey]['lessons_count'],
                'prompt_count' => $pdfFiles[$pdfKey]['prompts_count'],
            ];
            file_put_contents($runDir . "/archive_pdf_{$ordinal}.json", json_encode($archiveData, JSON_PRETTY_PRINT));
        }

        // 3. scene-prompts.json
        $scenePrompts = [
            'schema_version' => 1,
            'run_id' => $runId,
            'prompts' => [
                [
                    'pdf_ordinal' => 1,
                    'lesson_ordinal' => 1,
                    'scene_ordinal' => 1,
                    'scene_title' => 'Position and Velocity Integration',
                    'scene_type' => 'Interactive Graph',
                    'prompt_text' => 'Create a 2D interactive scene demonstrating s(t) and v(t) integration.',
                ],
                [
                    'pdf_ordinal' => 1,
                    'lesson_ordinal' => 1,
                    'scene_ordinal' => 2,
                    'scene_title' => 'Acceleration Vectors on Track',
                    'scene_type' => 'Vector Visualization',
                    'prompt_text' => 'Create a particle moving with constant acceleration showing vector arrows.',
                ],
                [
                    'pdf_ordinal' => 1,
                    'lesson_ordinal' => 2,
                    'scene_ordinal' => 1,
                    'scene_title' => 'Curvilinear Normal Acceleration',
                    'scene_type' => 'Curved Path',
                    'prompt_text' => 'Create an interactive osculating circle showing an = v^2/rho.',
                ],
                [
                    'pdf_ordinal' => 2,
                    'lesson_ordinal' => 1,
                    'scene_ordinal' => 1,
                    'scene_title' => 'Newton Second Law Block Friction',
                    'scene_type' => 'FBD Simulation',
                    'prompt_text' => 'Render a block with mass m on a rough horizontal surface with pull force P.',
                ],
                [
                    'pdf_ordinal' => 2,
                    'lesson_ordinal' => 1,
                    'scene_ordinal' => 2,
                    'scene_title' => 'Dry Friction Impending Slip',
                    'scene_type' => 'Threshold Gauge',
                    'prompt_text' => 'Demonstrate static friction threshold and transition to kinetic friction.',
                ],
            ],
        ];
        file_put_contents($exportDir . '/scene-prompts.json', json_encode($scenePrompts, JSON_PRETTY_PRINT));

        return [
            'terminal_root' => $terminalRoot,
            'run_id' => $runId,
            'run_dir' => $runDir,
            'pdf_files' => $pdfFiles,
            'scene_prompts_file' => $exportDir . '/scene-prompts.json',
        ];
    }

    /**
     * Build schema-1 per-PDF nested archive with:
     * - Nested lessons[].scenes[]
     * - Per-PDF pdfs/pdf-XXXX/lesson_handoffs/
     * - Non-ASCII Arabic text
     * - Unequal section & scene counts (Lesson 1: 2 sections & 2 scenes, Lesson 2: 1 section & 1 scene)
     * - Missing First fields
     * - Optional unfinished PDF / corrupt hash flags
     */
    public static function buildSchemaOneRegressionArchive(string $rootDir, string $runId = self::MOCK_RUN_ID, array $options = []): array
    {
        $terminalRoot = $rootDir . '/terminal-root';
        $runDir = $terminalRoot . '/runs/' . $runId;
        $exportDir = $runDir . '/private/scene-prompt-exports';

        if (! is_dir($exportDir)) {
            mkdir($exportDir, 0777, true);
        }

        $includeUnfinished = $options['unfinished_pdf'] ?? false;
        $corruptHash = $options['corrupt_hash'] ?? false;

        $pdfFiles = [
            1 => [
                'ordinal' => 1,
                'filename' => 'محاضرة_الاستاتيكا_1.pdf',
                'sha256' => hash('sha256', 'SCHEMA1_PDF_1_BYTES_ARABIC'),
                'work_count' => 2,
                'scene_count' => 3,
            ],
            2 => [
                'ordinal' => 2,
                'filename' => '02_kinetics.pdf',
                'sha256' => hash('sha256', 'SCHEMA1_PDF_2_BYTES'),
                'work_count' => 1,
                'scene_count' => 1,
            ],
        ];

        if ($includeUnfinished) {
            $pdfFiles[3] = [
                'ordinal' => 3,
                'filename' => '03_unfinished.pdf',
                'sha256' => hash('sha256', 'SCHEMA1_PDF_3_UNFINISHED'),
                'work_count' => 1,
                'scene_count' => 1,
            ];
        }

        // 1. subject-config.json
        $pdfEntries = [];
        foreach ($pdfFiles as $pdf) {
            $pdfEntries[] = [
                'ordinal' => $pdf['ordinal'],
                'filename' => $pdf['filename'],
                'sha256' => $pdf['sha256'],
            ];
        }

        $subjectConfig = [
            'run_id' => $runId,
            'created_at' => '2026-10-10T00:00:00Z',
            'subject_name' => 'الهندسة الميكانيكية',
            'pdf_files' => $pdfEntries,
            'framework' => [
                'sha256' => hash('sha256', 'FRAMEWORK_BYTES_REGRESSION'),
                'version' => 'v1.0.0',
            ],
        ];
        file_put_contents($runDir . '/subject-config.json', json_encode($subjectConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 2. Per-PDF directories (pdfs/pdf-XXXX/private/archive.json and pdfs/pdf-XXXX/lesson_handoffs/lesson-YYY.json)
        foreach ($pdfFiles as $ord => $pdf) {
            if ($includeUnfinished && $ord === 3) {
                // Skip creating archive for PDF 3 so it remains unfinished
                continue;
            }

            $pdfDir = $runDir . '/pdfs/pdf-' . sprintf('%04d', $ord);
            $privDir = $pdfDir . '/private';
            $handoffDir = $pdfDir . '/lesson_handoffs';
            if (! is_dir($privDir)) {
                mkdir($privDir, 0777, true);
            }
            if (! is_dir($handoffDir)) {
                mkdir($handoffDir, 0777, true);
            }

            $archiveData = [
                'schema_version' => 1,
                'kind' => 'v3_notebooklm_pdf_archive',
                'state' => 'archived',
                'pdf_ordinal' => $ord,
                'pdf_sha256' => $corruptHash && $ord === 1 ? 'bad_hash_value' : $pdf['sha256'],
                'original_filename' => $pdf['filename'],
                'work_count' => $pdf['work_count'],
                'scene_count' => $pdf['scene_count'],
            ];
            file_put_contents($privDir . '/archive.json', json_encode($archiveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Create handoffs with raw First containing unequal sections and Arabic text
            if ($ord === 1) {
                // Lesson 1: has 2 sections in raw_first
                $rawFirstLesson1 = "Section 1: توازن الجسيمات\nSection Identity:\nتوازن القوى المستوية\n\nCore Understanding:\nمجموع القوى يساوي صفرا\n\nVisual-First Explanation:\nمخطط الجسم الحر\n\nVisual Scene Script:\nمحاكاة أسهم القوى\n\nDynamic View Blueprint:\nنقطة التأثير والمحصلة\n\nVisual Learning Outcome:\nفهم شروط الاتزان\n\nConcept Check:\nهل يتحرك الجسيم المتزن؟\n\nConceptual Summary:\nقانون نيوتن الأول\n\n• شرح للجزء بالعامية المصرية:\nلو القوى متزنة الجسم هيفضل ثابت مكانه\n\nالقوانين (إن وُجدت):\n\\sum F = 0\n\nإسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم:\nالثريا المعلقة في السقف\n\nSection 2: تحليل القوى المتعامدة\nSection Identity:\nتحليل القوة إلى مركبتين\n\nCore Understanding:\nFx = F cos theta, Fy = F sin theta\n\nVisual-First Explanation:\nمثلث القوى ومساقط المتجهات\n\nVisual Scene Script:\nتغيير زاوية القوة\n\nDynamic View Blueprint:\nزوايا ومحاور متعامدة\n\nVisual Learning Outcome:\nحساب المركبات الأفقية والرأسية\n\nConcept Check:\nمتى تنعدم المركبة الرأسية؟\n\nConceptual Summary:\nمركبات المتجهات في الإحداثيات الكارتيزية\n\n• شرح للجزء بالعامية المصرية:\nبنحلل القوة في اتجاه س واتجاه ص عشان نجمعهم بسهولة\n\nالقوانين (إن وُجدت):\nFx = F \\cos(\\theta), Fy = F \\sin(\\theta)\n\nإسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم:\nجر حقيبة السفر بمقبض مائل";

                $handoff1 = [
                    'schema_version' => 1,
                    'pdf_ordinal' => 1,
                    'lesson_ordinal' => 1,
                    'mapped_title' => 'توازن الجسيمات وتحليل القوى',
                    'raw_first' => $rawFirstLesson1,
                ];
                file_put_contents($handoffDir . '/lesson-001.json', json_encode($handoff1, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                // Lesson 2: has 1 section with MISSING fields
                $rawFirstLesson2 = "Section 1: عزم القوة حول نقطة\nSection Identity:\nتعريف العزم\n\nCore Understanding:\nالعزم هو مقدرة القوة على إحداث دوران\n\nVisual-First Explanation:\nذراع العزم وخط عمل القوة\n\nVisual Scene Script:\nمفتاح ربط يدور حول صامولة\n\nDynamic View Blueprint:\nذراع القوة وقيمة الزاوية\n\nVisual Learning Outcome:\nاستنتاج أن العزم ينعدم عند انعدام الذراع\n\n• شرح للجزء بالعامية المصرية:\nالعزم بيزيد لما طول مفتاح الربط يزيد\n\nإسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم:\nفتح الباب بالدفع عند المقبض البعيد عن المفصلة";

                $handoff2 = [
                    'schema_version' => 1,
                    'pdf_ordinal' => 1,
                    'lesson_ordinal' => 2,
                    'mapped_title' => 'عزم القوة حول نقطة',
                    'raw_first' => $rawFirstLesson2,
                ];
                file_put_contents($handoffDir . '/lesson-002.json', json_encode($handoff2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } elseif ($ord === 2) {
                // PDF 2 Lesson 1: 1 section
                $rawFirstPdf2 = "Section 1: قانون نيوتن الثاني\nSection Identity:\nالعلاقة بين القوة والعجلة\n\nCore Understanding:\nF = m * a\n\nVisual-First Explanation:\nكتلة تتسارع تحت تأثير قوة محصلة\n\nVisual Scene Script:\nتغيير الكتلة وملاحظة تغير العجلة\n\nDynamic View Blueprint:\nمنحنى السرعة مع الزمن\n\nVisual Learning Outcome:\nاستنتاج التناسب الطردي بين القوة والعجلة\n\nConcept Check:\nماذا يحدث للعجلة عند مضاعفة الكتلة؟\n\nConceptual Summary:\nالقانون الأساسي للحركة الخطية\n\n• شرح للجزء بالعامية المصرية:\nكل ما تزق الجسم بقوة أكبر هيكتسب تسارع أكبر\n\nالقوانين (إن وُجدت):\nF = m a\n\nإسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم:\nدفع سيارة معطلة";

                $handoffPdf2 = [
                    'schema_version' => 1,
                    'pdf_ordinal' => 2,
                    'lesson_ordinal' => 1,
                    'mapped_title' => 'قانون نيوتن الثاني والحركة الخطية',
                    'raw_first' => $rawFirstPdf2,
                ];
                file_put_contents($handoffDir . '/lesson-001.json', json_encode($handoffPdf2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }

        // 3. scene-prompts.json with nested lessons[].scenes[]
        $nestedLessons = [
            [
                'pdf_ordinal' => 1,
                'lesson_ordinal' => 1,
                'lesson_title' => 'توازن الجسيمات وتحليل القوى',
                'scenes' => [
                    [
                        'scene_ordinal' => 1,
                        'scene_title' => 'مخطط توازن القوى 2D',
                        'scene_type' => 'Interactive 2D FBD',
                        'prompt_text' => 'مشهد تفاعلي لعرض توازن ثلاثة قوى متلاقية في نقطة واحدة.',
                    ],
                    [
                        'scene_ordinal' => 2,
                        'scene_title' => 'تحليل المركبات المتعامدة',
                        'scene_type' => 'Vector Components Slider',
                        'prompt_text' => 'عرض ديناميكي لمساقط القوة على محوري السينات والصادات.',
                    ],
                ],
            ],
            [
                'pdf_ordinal' => 1,
                'lesson_ordinal' => 2,
                'lesson_title' => 'عزم القوة حول نقطة',
                'scenes' => [
                    [
                        'scene_ordinal' => 1,
                        'scene_title' => 'محاكاة عزم مفتاح الربط',
                        'scene_type' => 'Torque Lever Arm Demonstration',
                        'prompt_text' => 'مشهد تفاعلي يوضح تأثير طول ذراع العزم على سهولة الدوران.',
                    ],
                ],
            ],
            [
                'pdf_ordinal' => 2,
                'lesson_ordinal' => 1,
                'lesson_title' => 'قانون نيوتن الثاني والحركة الخطية',
                'scenes' => [
                    [
                        'scene_ordinal' => 1,
                        'scene_title' => 'عربة التسارع الخطية',
                        'scene_type' => 'Newton Second Law Slider',
                        'prompt_text' => 'محاكاة حركة جسم كتلته m تحت تأثير قوة شد مع رسم منحنى العجلة.',
                    ],
                ],
            ],
        ];

        $scenePrompts = [
            'schema_version' => 1,
            'run_id' => $runId,
            'lessons' => $nestedLessons,
        ];
        file_put_contents($exportDir . '/scene-prompts.json', json_encode($scenePrompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'terminal_root' => $terminalRoot,
            'run_id' => $runId,
            'run_dir' => $runDir,
            'pdf_files' => $pdfFiles,
            'scene_prompts_file' => $exportDir . '/scene-prompts.json',
        ];
    }
}
