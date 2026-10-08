@extends('v3.layout')

@section('title', 'Case Operation Details')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Workspace</a>
    <a href="{{ route('v3.admin.operations.index') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Operations</a>
    <a href="{{ route('v3.admin.cases.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Cases</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Case Operational Detail</h1>
        <p class="font-mono text-sm text-gray-500 mt-1">{{ $summary['case']['case_id'] }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-4">
            <h2 class="text-lg font-semibold text-gray-900">Case Status</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Stage</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['case']['stage_code'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Status</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['case']['status_code'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Control Mode</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['control_mode'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Error Code</dt>
                    <dd class="font-mono text-gray-900">{{ $summary['case']['error_code'] ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Age (seconds)</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['age_seconds'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Idle (seconds)</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['idle_seconds'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Attempt Count</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['case']['attempt_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Scene Count</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['case']['scene_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Approved Scenes</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['case']['approved_scene_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Dormant Reviews</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['dormant_review_count'] }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Uncertain Effects</dt>
                    <dd class="font-medium text-gray-900">{{ $summary['uncertain_effect_count'] }}</dd>
                </div>
            </dl>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-4">
            <h2 class="text-lg font-semibold text-gray-900">Action Targets</h2>
            @if(empty($actionTargets))
                <p class="text-sm text-gray-500">No recoverable action targets currently available for this case.</p>
            @else
                <ul class="divide-y divide-gray-200">
                    @foreach($actionTargets as $target)
                        <li class="py-2 text-sm">
                            <span class="font-semibold">{{ $target['kind'] }}</span>:
                            <span class="font-mono text-xs text-gray-600">{{ $target['ref'] }}</span>
                            <span class="text-xs text-gray-500">({{ $target['state'] }})</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
