@extends('v3.layout')

@section('title', 'Case Operational Summary')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Operations</a>
    <a href="{{ route('v3.admin.cases.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Cases</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Case Summary</span>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Case Operational Summary</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case ID: {{ $case['case_id'] }}</p>
        </div>
        <div>
            <a href="{{ route('v3.admin.cases.summary', $case['case_id']) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Refresh Status
            </a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Operational Stage</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $case['stage_code'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Operational Status</dt>
                <dd class="mt-1 text-sm font-semibold">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($case['status_code'] === 'running') bg-blue-100 text-blue-800
                        @elseif($case['status_code'] === 'queued') bg-gray-100 text-gray-800
                        @elseif($case['status_code'] === 'failed') bg-red-100 text-red-800
                        @elseif($case['status_code'] === 'blocked') bg-amber-100 text-amber-800
                        @else bg-green-100 text-green-800
                        @endif">
                        {{ $case['status_code'] }}
                    </span>
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Error Code</dt>
                <dd class="mt-1 text-sm font-mono text-gray-900">{{ $case['error_code'] ?? 'None' }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Registered At</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $case['created_at'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Last Updated</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $case['updated_at'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Execution Attempts</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $case['attempt_count'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Total Generated Scenes</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $case['scene_count'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Approved Scenes</dt>
                <dd class="mt-1 text-sm text-gray-900">{{ $case['approved_scene_count'] }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-gray-50 border border-gray-200 p-4 rounded-lg">
        <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider">Privacy & Closed Operational Boundary</h4>
        <p class="text-xs text-gray-500 mt-1">
            This administrative view exposes only allowlisted operational scalars. Document content, teacher identity, educational prompt text, generated scenes, and evaluation notes are protected under strict tenant isolation boundaries.
        </p>
    </div>

    <div>
        <a href="{{ route('v3.admin.cases.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            &larr; Back to Cases List
        </a>
    </div>
</div>
@endsection
