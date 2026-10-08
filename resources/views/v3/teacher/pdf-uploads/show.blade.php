@extends('v3.layout')

@section('title', 'Upload Attempt Details')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Upload Attempts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Upload Attempt Details</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">ID: {{ $attempt->id }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.pdf-uploads.show', $attempt->id) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Refresh Status
            </a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Current Status</dt>
                <dd class="mt-1 text-sm font-semibold">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($summary['state'] === 'accepted') bg-green-100 text-green-800
                        @elseif(in_array($summary['state'], ['rejected', 'blocked'])) bg-red-100 text-red-800
                        @else bg-yellow-100 text-yellow-800 @endif">
                        {{ $summary['state'] }}
                    </span>
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Submitted At</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $attempt->created_at }}</dd>
            </div>

            @if($summary['reason_code'])
                <div class="col-span-2 bg-red-50 p-4 rounded-md border border-red-200">
                    <dt class="text-xs font-bold text-red-800 uppercase">Rejection Reason</dt>
                    <dd class="mt-1 text-sm font-mono text-red-900">{{ $summary['reason_code'] }}</dd>
                    <p class="mt-2 text-xs text-red-700">The uploaded file did not pass verification gates and was safely purged from temporary quarantine.</p>
                </div>
            @endif

            @if($summary['state'] === 'accepted')
                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase">Document ID</dt>
                    <dd class="mt-1 text-sm font-mono text-gray-900">{{ $summary['document_id'] }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-medium text-gray-500 uppercase">Duplicate Detection</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        {{ $summary['duplicate'] ? 'Exact duplicate recognized (reusing existing document)' : 'New unique document verified' }}
                    </dd>
                </div>
            @endif
        </dl>
    </div>

    <!-- Actions / Next Steps based on state -->
    @if(in_array($summary['state'], ['receiving', 'quarantined', 'validating', 'retry_pending', 'promoting']))
        <div class="bg-blue-50 border border-blue-200 p-6 rounded-lg text-center">
            <h3 class="text-base font-medium text-blue-900">Inspection In Progress</h3>
            <p class="text-sm text-blue-700 mt-1">
                Your PDF is currently undergoing structural parsing and malware analysis. Please refresh to see the updated status.
            </p>
            <div class="mt-4">
                <a href="{{ route('v3.teacher.pdf-uploads.show', $attempt->id) }}" class="inline-flex items-center px-4 py-2 border border-blue-600 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-white hover:bg-blue-50">
                    Refresh
                </a>
            </div>
        </div>
    @elseif($summary['state'] === 'accepted')
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-4">
            <h2 class="text-lg font-semibold text-gray-900">Course Material Processing Actions</h2>

            @if($summary['latest_case_id'])
                <div class="p-4 bg-gray-50 rounded-md border border-gray-200 flex items-center justify-between">
                    <div>
                        <h4 class="text-sm font-medium text-gray-900">Existing Processing Case Found</h4>
                        <p class="text-xs text-gray-500 mt-0.5">This document has previously been submitted for case generation.</p>
                    </div>
                    <a href="{{ \Illuminate\Support\Facades\Route::has('v3.teacher.cases.show') ? route('v3.teacher.cases.show', $summary['latest_case_id']) : url('/v3/teacher/cases/' . $summary['latest_case_id']) }}" class="inline-flex items-center px-3 py-1.5 border border-indigo-600 text-xs font-medium rounded text-indigo-700 bg-white hover:bg-indigo-50">
                        Open Previous Case &rarr;
                    </a>
                </div>

                <div class="pt-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Process Again</h4>
                    <p class="text-xs text-gray-500 mb-3">You may deliberately start a new processing run with the current active generation settings.</p>
                    <form action="{{ \Illuminate\Support\Facades\Route::has('v3.teacher.cases.store') ? route('v3.teacher.cases.store') : url('/v3/teacher/cases') }}" method="POST">
                        @csrf
                        <input type="hidden" name="document_id" value="{{ $summary['document_id'] }}">
                        <input type="hidden" name="action" value="process_again">
                        <input type="hidden" name="idempotency_key" value="{{ $nextIdempotencyKey }}">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                            Process Again (New Case)
                        </button>
                    </form>
                </div>
            @else
                <div>
                    <p class="text-sm text-gray-600 mb-4">Your document is verified and ready. Start an interactive visual learning scene processing case now.</p>
                    <form action="{{ \Illuminate\Support\Facades\Route::has('v3.teacher.cases.store') ? route('v3.teacher.cases.store') : url('/v3/teacher/cases') }}" method="POST">
                        @csrf
                        <input type="hidden" name="document_id" value="{{ $summary['document_id'] }}">
                        <input type="hidden" name="action" value="process">
                        <input type="hidden" name="idempotency_key" value="{{ $nextIdempotencyKey }}">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                            Start Processing Case
                        </button>
                    </form>
                </div>
            @endif

            <div class="pt-4 border-t border-gray-200">
                <a href="{{ \Illuminate\Support\Facades\Route::has('v3.teacher.documents.download') ? route('v3.teacher.documents.download', $summary['document_id']) : url('/v3/teacher/documents/' . $summary['document_id'] . '/download') }}" class="text-xs text-indigo-600 hover:text-indigo-800">
                    Download Verified PDF &darr;
                </a>
            </div>
        </div>
    @endif
</div>
@endsection
