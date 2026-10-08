@extends('v3.layout')

@section('title', 'Admin Operational Metrics')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Workspace</a>
    <a href="{{ route('v3.admin.operations.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Operations</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Metrics</span>
    <a href="{{ route('v3.admin.cases.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Cases</a>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Admin Operational Metrics</h1>
        <p class="text-sm text-gray-600 mt-1">
            Aggregated operational metrics for V3 processing cases within period [{{ $report['from'] }} to {{ $report['to'] }}). Strictly redacts teacher identity and educational content.
        </p>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="text-xs font-medium text-gray-500 uppercase">Total Cases</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $report['case_count'] }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="text-xs font-medium text-gray-500 uppercase">Current Approved Scenes</div>
            <div class="text-2xl font-bold text-indigo-600 mt-1">{{ $report['current_approved_scene_count'] }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="text-xs font-medium text-gray-500 uppercase">Historical Approvals</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $report['historical_approval_count'] }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="text-xs font-medium text-gray-500 uppercase">Gaps Encountered</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ $report['gap_count'] }}</div>
        </div>
    </div>

    <!-- Operational Iteration Counts -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Pipeline Iteration & Retries</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Technical Retries:</span>
                <span class="font-semibold text-gray-900 ml-1">{{ $report['technical_retry_count'] }}</span>
            </div>
            <div>
                <span class="text-gray-500">Prompt Revisions:</span>
                <span class="font-semibold text-gray-900 ml-1">{{ $report['prompt_revision_count'] }}</span>
            </div>
            <div>
                <span class="text-gray-500">Scene Repairs:</span>
                <span class="font-semibold text-gray-900 ml-1">{{ $report['scene_repair_count'] }}</span>
            </div>
            <div>
                <span class="text-gray-500">Teacher Change Requests:</span>
                <span class="font-semibold text-gray-900 ml-1">{{ $report['teacher_change_count'] }}</span>
            </div>
        </div>
    </div>

    <!-- Cost & Usage -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Cost & Model Usage</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
            <div>
                <h3 class="font-medium text-gray-700 mb-2">Cost Accounting</h3>
                <p><span class="text-gray-500">Status:</span> <span class="font-mono text-xs uppercase px-2 py-0.5 rounded bg-gray-100">{{ $report['cost']['status'] }}</span></p>
                <p class="mt-1"><span class="text-gray-500">Unknown Components:</span> {{ $report['cost']['unknown_component_count'] }}</p>
                <p class="mt-1"><span class="text-gray-500">Per-Scene Reason:</span> {{ $report['cost']['per_scene_reason'] ?? 'N/A' }}</p>
                <div class="mt-2">
                    <span class="text-gray-500">Known Subtotals:</span>
                    <ul class="list-disc list-inside mt-1">
                        @forelse($report['cost']['known_subtotals'] as $sub)
                            <li>{{ $sub['currency'] }}: {{ $sub['amount_minor'] }} minor units</li>
                        @empty
                            <li class="text-gray-400">None</li>
                        @endforelse
                    </ul>
                </div>
            </div>
            <div>
                <h3 class="font-medium text-gray-700 mb-2">Usage Counts</h3>
                <p><span class="text-gray-500">Known Input Tokens:</span> {{ number_format($report['usage']['known_input_tokens']) }}</p>
                <p class="mt-1"><span class="text-gray-500">Known Output Tokens:</span> {{ number_format($report['usage']['known_output_tokens']) }}</p>
                <p class="mt-1"><span class="text-gray-500">Unknown Operations:</span> {{ $report['usage']['unknown_operation_count'] }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
