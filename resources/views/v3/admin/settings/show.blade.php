@extends('v3.layout')

@section('title', 'Operating Settings')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Operations</a>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
    <a href="{{ route('v3.admin.settings.show') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Settings</a>
    <a href="{{ route('v3.admin.settings.templates') }}" class="text-gray-500 hover:text-gray-700 pb-1">Templates</a>
    <a href="{{ route('v3.admin.settings.history') }}" class="text-gray-500 hover:text-gray-700 pb-1">History</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">V3 Operating Settings</h1>
        <p class="text-sm text-gray-600 mt-1">
            Current active settings version: <span class="font-semibold">{{ $activeVersion ? $activeVersion->version : 'None (0)' }}</span>
            @if($activeVersion)
                <span class="text-xs text-gray-500 ml-2">(Checksum: {{ substr($activeVersion->checksum, 0, 16) }}...)</span>
            @endif
        </p>
    </div>

    @if (session('status'))
        <div class="p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
            <p class="font-medium">Validation errors occurred:</p>
            <ul class="list-disc list-inside mt-1 text-xs">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Publish New Settings Replacement</h2>
        <form action="{{ route('v3.admin.settings.store') }}" method="POST" class="space-y-6">
            @csrf
            <input type="hidden" name="expected_active_version" value="{{ $activeVersion ? $activeVersion->version : 0 }}">

            <div class="border-b border-gray-200 pb-4">
                <h3 class="text-md font-semibold text-gray-800">Limits</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Max PDF Bytes</label>
                        <input type="number" name="limits[max_pdf_bytes]" value="{{ $settings['limits']['max_pdf_bytes'] ?? 10485760 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Max PDF Pages</label>
                        <input type="number" name="limits[max_pdf_pages]" value="{{ $settings['limits']['max_pdf_pages'] ?? 100 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Max OCR Pages</label>
                        <input type="number" name="limits[max_ocr_pages]" value="{{ $settings['limits']['max_ocr_pages'] ?? 20 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Technical Retries</label>
                        <input type="number" name="limits[technical_retries]" value="{{ $settings['limits']['technical_retries'] ?? 3 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Retry Delay Seconds</label>
                        <input type="number" name="limits[retry_delay_seconds]" value="{{ $settings['limits']['retry_delay_seconds'] ?? 60 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Prompt Revisions</label>
                        <input type="number" name="limits[prompt_revisions]" value="{{ $settings['limits']['prompt_revisions'] ?? 2 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Scene Repairs</label>
                        <input type="number" name="limits[scene_repairs]" value="{{ $settings['limits']['scene_repairs'] ?? 2 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Coding Attempts</label>
                        <input type="number" name="limits[coding_attempts]" value="{{ $settings['limits']['coding_attempts'] ?? 3 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700">Review Dormancy Days</label>
                        <input type="number" name="limits[review_dormancy_days]" value="{{ $settings['limits']['review_dormancy_days'] ?? 14 }}" class="mt-1 block w-full rounded border-gray-300 text-sm">
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded font-medium hover:bg-indigo-700 text-sm">
                    Publish Operating Settings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
