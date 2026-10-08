<?php

namespace App\Services\V3\Intake;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ValidateUploadInput
{
    /**
     * Validate upload request input against 006 intake rules.
     *
     * @return array{valid: bool, reason_code?: string, message?: string, file?: UploadedFile}
     */
    public function validate(Request $request, int $maxBytes): array
    {
        // 1. Check unknown application fields (only 'pdf' and framework '_token' allowed)
        $allowedKeys = ['pdf', '_token'];
        $extraKeys = array_diff(array_keys($request->all()), $allowedKeys);
        if (! empty($extraKeys)) {
            return [
                'valid' => false,
                'reason_code' => 'upload_invalid',
                'message' => 'The upload request contains unsupported fields.',
            ];
        }

        // 2. Check file presence and validity
        if (! $request->hasFile('pdf')) {
            return [
                'valid' => false,
                'reason_code' => 'upload_invalid',
                'message' => 'A PDF file is required.',
            ];
        }

        $file = $request->file('pdf');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return [
                'valid' => false,
                'reason_code' => 'upload_invalid',
                'message' => 'The uploaded file transfer was incomplete or corrupted.',
            ];
        }

        // 3. Check 0-byte file
        if ($file->getSize() === 0) {
            return [
                'valid' => false,
                'reason_code' => 'upload_invalid',
                'message' => 'The uploaded file is empty.',
            ];
        }

        // 4. Filename extension must be .pdf case-insensitively
        $clientFilename = $file->getClientOriginalName();
        if (! str_ends_with(strtolower($clientFilename), '.pdf')) {
            return [
                'valid' => false,
                'reason_code' => 'not_pdf',
                'message' => 'The uploaded file must have a .pdf extension.',
            ];
        }

        // 5. Header check: byte offset 0 must be %PDF-
        $realPath = $file->getRealPath();
        if (! $realPath || ! file_exists($realPath)) {
            return [
                'valid' => false,
                'reason_code' => 'upload_invalid',
                'message' => 'The uploaded file cannot be read.',
            ];
        }

        $fp = fopen($realPath, 'rb');
        if (! $fp) {
            return [
                'valid' => false,
                'reason_code' => 'upload_invalid',
                'message' => 'The uploaded file cannot be opened.',
            ];
        }

        $header = fread($fp, 5);
        fclose($fp);

        if ($header !== '%PDF-') {
            return [
                'valid' => false,
                'reason_code' => 'not_pdf',
                'message' => 'The file structure does not begin with the PDF magic header.',
            ];
        }

        // 6. Size limit check
        if ($file->getSize() > $maxBytes) {
            return [
                'valid' => false,
                'reason_code' => 'size_exceeded',
                'message' => 'The uploaded file exceeds the active size limit.',
            ];
        }

        return [
            'valid' => true,
            'file' => $file,
        ];
    }
}
