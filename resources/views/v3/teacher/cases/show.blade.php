@extends('v3.layout')

@section('title', 'Processing Case Receipt')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Upload Attempts</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Case Receipt</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Processing Case Receipt</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case ID: {{ $receipt['case_id'] }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.cases.show', $receipt['case_id']) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Refresh Status
            </a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Case Status</dt>
                <dd class="mt-1 text-sm font-semibold">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        {{ $receipt['state'] }}
                    </span>
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Submitted At</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $receipt['created_at'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Document ID</dt>
                <dd class="mt-1 text-sm font-mono text-gray-900">{{ $receipt['document_id'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Settings Version</dt>
                <dd class="mt-1 text-sm text-gray-900">v{{ $receipt['settings_version_id'] }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-blue-50 border border-blue-200 p-6 rounded-lg">
        <h3 class="text-base font-medium text-blue-900">Workflow In Queue</h3>
        <p class="text-sm text-blue-700 mt-1">
            Your processing case has been registered in the system with state <code class="font-mono font-semibold">{{ $receipt['state'] }}</code>. Upstream workflow orchestration will pick up this case according to the captured settings version.
        </p>
        <div class="mt-4 flex space-x-3">
            <a href="{{ route('v3.teacher.cases.progress', $receipt['case_id']) }}" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                View Workflow Progress &rarr;
            </a>
            <a href="{{ route('v3.teacher.workspace') }}" class="inline-flex items-center px-4 py-2 border border-blue-600 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-white hover:bg-blue-50">
                &larr; Return to Workspace
            </a>
            <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                View Upload History
            </a>
        </div>
    </div>
</div>
@endsection
