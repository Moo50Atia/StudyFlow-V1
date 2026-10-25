<?php

namespace App\Services\V3\WebPipeline;

use App\Models\User;
use App\Services\V3\Intake\PdfInspector;
use App\Services\V3\Settings\SelectCaseSettings;
use App\Services\V3\Settings\SettingsUnavailable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PrepareWebPdfs
{
    public function __construct(
        protected PdfInspector $inspector,
        protected SelectCaseSettings $settings,
        protected TerminalBridgeClient $bridge
    ) {}

    /** Prepare locally before the run's write transaction or provider admission. */
    public function prepare(User $actor, string $key, array $files, array $titles): array
    {
        try {
            $settings = DB::transaction(fn () => $this->settings->selectForNewCase());
        } catch (SettingsUnavailable|\LogicException) {
            throw new HttpException(422, 'Published pipeline settings are unavailable.');
        }
        $payload = is_array($settings->payload) ? $settings->payload : json_decode($settings->payload, true);
        $limits = $payload['limits'];
        $disk = Storage::disk('v3_private');
        $base = 'web-preparations/'.$actor->id.'/'.strtolower($key);
        $basePath = $disk->path($base);
        if (! is_dir($basePath) && ! mkdir($basePath, 0700, true) && ! is_dir($basePath)) {
            throw new HttpException(503, 'Private preparation storage is unavailable.');
        }
        $lock = fopen($basePath.'/.prepare.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new HttpException(503, 'PDF preparation is busy.');
        }
        try {
            $originals = [];
            foreach ($files as $index => $file) {
                $name = $file->getClientOriginalName();
                if (basename($name) !== $name || str_contains($name, '\\') || strlen($name) > 200
                    || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw new HttpException(422, 'Select a PDF with a valid filename.');
                }
                $size = filesize($file->getRealPath());
                if (! $size || $size > $limits['max_pdf_bytes']) {
                    throw new HttpException(422, 'The original PDF exceeds the configured upload limit.');
                }
                $inspection = $this->inspector->inspect($file->getRealPath(), $limits['max_pdf_pages']);
                if (! ($inspection['valid'] ?? false)) {
                    throw new HttpException(($inspection['error_type'] ?? '') === 'transient' ? 503 : 422,
                        'The original PDF failed validity or malware inspection.');
                }
                $sha = hash_file('sha256', $file->getRealPath());
                $target = $disk->path($base.'/input/'.sprintf('%04d', $index + 1).'/'.$name);
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                if (is_link($target) || (is_file($target) && hash_file('sha256', $target) !== $sha)) {
                    throw new HttpException(409, 'The retained original differs for this submission key.');
                }
                if (! is_file($target)) {
                    if (! copy($file->getRealPath(), $target)) {
                        throw new HttpException(503, 'Cannot retain original PDF.');
                    }
                    chmod($target, 0600);
                }
                $originals[] = ['filename' => $name, 'sha256' => $sha, 'bytes' => $size];
            }
            $result = $this->bridge->preparePdfs([
                'owner_user_id' => (int) $actor->id, 'submission_key' => strtolower($key), 'originals' => $originals,
            ]);
            $inventoryRef = $base.'/package/inventory.json';
            if (($result['inventory_ref'] ?? null) !== $inventoryRef || ! $disk->exists($inventoryRef)
                || hash_file('sha256', $disk->path($inventoryRef)) !== ($result['inventory_file_sha256'] ?? null)) {
                throw new HttpException(503, 'Preparation inventory integrity check failed.');
            }
            $inventory = json_decode($disk->get($inventoryRef), true, 512, JSON_THROW_ON_ERROR);
            if (($inventory['policy']['version'] ?? null) !== 2 || ($inventory['policy']['split_trigger_pages'] ?? null) !== 50
                || ($inventory['policy']['max_part_pages'] ?? null) !== 50
                || ($inventory['verification']['verified'] ?? false) !== true
                || ($inventory['prepared_pdfs'] ?? null) != ($result['prepared_pdfs'] ?? null)
                || count($inventory['originals'] ?? []) !== count($originals)) {
                throw new HttpException(503, 'Preparation inventory is invalid.');
            }
            foreach ($inventory['originals'] as $index => $source) {
                if ($source['source_ordinal'] !== $index + 1 || $source['sha256'] !== $originals[$index]['sha256']
                    || $source['bytes'] !== $originals[$index]['bytes'] || $source['original_filename'] !== $originals[$index]['filename']) {
                    throw new HttpException(409, 'Preparation source order or fingerprint changed.');
                }
            }
            $prepared = [];
            $partTitles = [];
            $lineages = [];
            foreach ($inventory['prepared_pdfs'] as $index => $part) {
                $ref = $part['artifact_ref'];
                $relative = $ref['path'];
                if ($part['upload_ordinal'] !== $index + 1 || ! str_starts_with($relative, 'final/')
                    || str_contains($relative, '..') || str_contains($relative, '\\')
                    || basename($relative) !== $part['filename'] || $part['page_count'] > 50) {
                    throw new HttpException(503, 'Prepared PDF ordering or range is invalid.');
                }
                $path = $disk->path($base.'/package/'.$relative);
                if (is_link($path) || ! is_file($path) || filesize($path) !== $ref['bytes'] || hash_file('sha256', $path) !== $ref['sha256']) {
                    throw new HttpException(503, 'Prepared PDF fingerprint changed.');
                }
                $source = $inventory['originals'][$part['source_ordinal'] - 1];
                $prepared[] = new UploadedFile($path, $part['filename'], 'application/pdf', UPLOAD_ERR_OK, true);
                $partTitles[] = mb_substr($titles[$part['source_ordinal'] - 1], 0, 200)
                    .($source['split_applied'] ? ' — pages '.$part['start_page'].'–'.$part['end_page'] : '');
                $lineages[] = [
                    'source_ordinal' => $part['source_ordinal'], 'part_ordinal' => $part['part_ordinal'],
                    'original_filename' => $source['original_filename'], 'original_sha256' => $source['sha256'],
                    'original_page_count' => $source['page_count'], 'start_page' => $part['start_page'],
                    'end_page' => $part['end_page'], 'page_count' => $part['page_count'],
                ];
            }
            if (! $prepared || count($prepared) > 640) {
                throw new HttpException(422, 'Prepared PDF batch exceeds supported limits.');
            }
            return ['files' => $prepared, 'titles' => $partTitles, 'lineages' => $lineages,
                'settings_version_id' => $settings->id,
                'capture' => ['inventory_ref' => $inventoryRef, 'inventory_file_sha256' => $result['inventory_file_sha256'],
                    'policy' => $inventory['policy'], 'original_count' => count($originals), 'prepared_count' => count($prepared)],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
