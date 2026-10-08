@extends('v3.layout')

@section('title', 'Case Workflow Progress')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Upload Attempts</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case Receipt</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Workflow Progress</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Case Workflow Progress</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case ID: {{ $progress['case_id'] }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.cases.progress', $case->id) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Refresh Status
            </a>
        </div>
    </div>

    @if(!empty($progress['delayed']))
    <div class="bg-amber-50 border border-amber-200 p-4 rounded-lg flex items-start space-x-3">
        <div class="text-amber-600 text-lg">&#9888;</div>
        <div>
            <h3 class="text-sm font-semibold text-amber-800">Processing Delayed</h3>
            <p class="text-sm text-amber-700 mt-0.5">
                This case has experienced a transient delay or is awaiting worker resources. Automatic orchestration remains active and will resume as soon as services are available.
            </p>
        </div>
    </div>
    @endif

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <dl class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Stage</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $progress['stage_code'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Status</dt>
                <dd class="mt-1 text-sm font-semibold">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium 
                        @if($progress['status_code'] === 'running') bg-blue-100 text-blue-800
                        @elseif($progress['status_code'] === 'queued') bg-gray-100 text-gray-800
                        @elseif($progress['status_code'] === 'failed') bg-red-100 text-red-800
                        @elseif($progress['status_code'] === 'blocked') bg-amber-100 text-amber-800
                        @else bg-green-100 text-green-800
                        @endif">
                        {{ $progress['status_code'] }}
                    </span>
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">State Version</dt>
                <dd class="mt-1 text-sm text-gray-900 font-mono">v{{ $progress['state_version'] }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Error Code</dt>
                <dd class="mt-1 text-sm text-gray-900 font-mono">{{ $progress['error_code'] ?? 'None' }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-900">Recorded Milestones</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-medium">
                    <tr>
                        <th class="px-6 py-3 text-left">Seq</th>
                        <th class="px-6 py-3 text-left">Stage</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Error</th>
                        <th class="px-6 py-3 text-left">Recorded At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($progress['events'] as $event)
                    <tr>
                        <td class="px-6 py-4 font-mono text-gray-700">#{{ $event['sequence'] }}</td>
                        <td class="px-6 py-4 font-medium text-gray-900">{{ $event['stage_code'] }}</td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-0.5 rounded text-xs font-medium
                                @if($event['status_code'] === 'running') bg-blue-100 text-blue-800
                                @elseif($event['status_code'] === 'queued') bg-gray-100 text-gray-800
                                @elseif($event['status_code'] === 'failed') bg-red-100 text-red-800
                                @elseif($event['status_code'] === 'blocked') bg-amber-100 text-amber-800
                                @else bg-green-100 text-green-800
                                @endif">
                                {{ $event['status_code'] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $event['error_code'] ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-500">{{ $event['recorded_at'] }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                            No milestone events have been committed yet for this case.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($progress['pagination']['page'] > 1 || $progress['pagination']['has_more'])
        <div class="px-6 py-3 border-t border-gray-200 flex justify-between items-center text-sm">
            @if($progress['pagination']['page'] > 1)
                <a href="{{ route('v3.teacher.cases.progress', ['case' => $case->id, 'page' => $progress['pagination']['page'] - 1]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">&larr; Previous Page</a>
            @else
                <span class="text-gray-400">&larr; Previous Page</span>
            @endif

            <span class="text-gray-600">Page {{ $progress['pagination']['page'] }}</span>

            @if($progress['pagination']['has_more'])
                <a href="{{ route('v3.teacher.cases.progress', ['case' => $case->id, 'page' => $progress['pagination']['page'] + 1]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Next Page &rarr;</a>
            @else
                <span class="text-gray-400">Next Page &rarr;</span>
            @endif
        </div>
        @endif
    </div>

    <div class="flex space-x-3">
        <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            &larr; View Case Receipt
        </a>
        <a href="{{ route('v3.teacher.workspace') }}" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
            Return to Workspace
        </a>
    </div>
</div>
@endsection
