<?php

namespace App\Http\Controllers\V3;

use App\Http\Controllers\Controller;
use App\Models\V3\ApprovedScene;
use App\Services\LearningAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StudentSceneController extends Controller
{
    public function publication(Request $request, ApprovedScene $approval)
    {
        $source = $approval->section;
        abort_unless($source, 404);
        $link = DB::table('v3_section_pipeline_links')->where('teacher_section_id', $source->id)->first();
        abort_unless($link, 404);
        abort_unless(app(LearningAccess::class)->isOwner($request->user()) || (int) $link->owner_user_id === (int) $request->user()->id, 403);
        $data = $request->validate(['published' => 'required|boolean']);
        $publish = (bool) $data['published'];
        if ($publish) {
            abort_if($approval->postapprovalCorrection()->exists(), 422);
            abort_unless($approval->candidate && Storage::disk('v3_private')->exists($approval->candidate->manifest_path), 422);
        }
        DB::transaction(function () use ($approval, $source, $link, $request, $publish) {
            if ($publish) {
                DB::table('v3_scene_publications')->updateOrInsert(['approval_id' => $approval->id], ['published_by' => $request->user()->id, 'published_at' => now()]);
            } else {
                DB::table('v3_scene_publications')->where('approval_id', $approval->id)->delete();
            }
            $lessonPublished = DB::table('v3_lesson_publications')->where('work_node_id', $source->work_node_id)->whereNotNull('published_at')->exists();
            DB::table('sections')->where('id', $link->section_id)->update(['dynamic_view_link' => $publish && $lessonPublished ? route('student-scenes.file', [$approval->id, 'index.html']) : null]);
            DB::table('v3_lesson_publication_events')->insert([
                'work_node_id' => $source->work_node_id, 'actor_user_id' => $request->user()->id,
                'action' => $publish ? 'scene_publish' : 'scene_unpublish',
                'details' => json_encode(['approval_id' => $approval->id]), 'created_at' => now(),
            ]);
        });
        return back();
    }

    public function file(Request $request, ApprovedScene $approval, string $file)
    {
        abort_unless(in_array($file, ['index.html', 'styles.css', 'script.js'], true), 404);
        $source = $approval->section;
        abort_unless($source && DB::table('v3_scene_publications')->where('approval_id', $approval->id)->exists(), 404);
        $link = DB::table('v3_section_pipeline_links')->where('teacher_section_id', $source->id)->first();
        abort_unless($link, 404);
        if ($request->user()->role === 'teacher') abort_unless((int) $link->owner_user_id === (int) $request->user()->id, 403);
        if ($request->user()->role === 'admin') abort_unless(app(LearningAccess::class)->isOwner($request->user()), 403);
        abort_unless(DB::table('v3_lesson_publications')->where('work_node_id', $source->work_node_id)->whereNotNull('published_at')->exists(), 404);
        abort_if($approval->postapprovalCorrection()->exists(), 404);
        $candidate = $approval->candidate;
        abort_unless($candidate, 404);
        $disk = Storage::disk('v3_private');
        abort_unless($disk->exists($candidate->manifest_path), 404);
        $manifestText = $disk->get($candidate->manifest_path);
        abort_unless(hash('sha256', $manifestText) === $candidate->manifest_sha256, 404);
        $manifest = json_decode($manifestText, true);
        $entry = collect($manifest['files'] ?? [])->firstWhere('path', $file);
        abort_unless($entry && str_ends_with($candidate->manifest_path, '/manifest.json'), 404);
        $path = dirname($candidate->manifest_path) . '/' . $file;
        abort_unless($disk->exists($path), 404);
        $body = $disk->get($path);
        abort_unless(hash('sha256', $body) === $entry['sha256'], 404);
        if ($file === 'index.html') {
            $assets = [];
            foreach (['styles.css', 'script.js'] as $asset) {
                $assetEntry = collect($manifest['files'] ?? [])->firstWhere('path', $asset);
                $assetPath = dirname($candidate->manifest_path) . '/' . $asset;
                abort_unless($assetEntry && $disk->exists($assetPath), 404);
                $assets[$asset] = $disk->get($assetPath);
                abort_unless(hash('sha256', $assets[$asset]) === $assetEntry['sha256'], 404);
            }
            // The CSP sandbox gives this document an opaque origin. Inlining
            // verified package assets keeps them usable without relaxing it.
            $inline = '<style>' . str_ireplace('</style', '<\\/style', $assets['styles.css']) . '</style>'
                . '<script>' . str_ireplace('</script', '<\\/script', $assets['script.js']) . '</script>';
            $body = str_contains(strtolower($body), '</body>')
                ? str_ireplace('</body>', $inline . '</body>', $body)
                : $body . $inline;
        }
        return response($body, 200, [
            'Content-Type' => ['index.html' => 'text/html; charset=utf-8', 'styles.css' => 'text/css; charset=utf-8', 'script.js' => 'text/javascript; charset=utf-8'][$file],
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
