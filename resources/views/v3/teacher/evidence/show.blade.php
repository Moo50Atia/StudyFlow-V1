@extends('v3.layout')

@section('title', 'Case Evidence & Lineage')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case Receipt</a>
    <a href="{{ route('v3.teacher.cases.progress', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Progress</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Evidence & Lineage</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Case Evidence & History</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case Ref: {{ $evidence['case_ref'] }}</p>
        </div>
        <div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $evidence['integrity'] === 'complete' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                Lineage Integrity: {{ ucfirst($evidence['integrity']) }}
            </span>
        </div>
    </div>

    <!-- Approvals Summary -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white p-5 rounded-lg border border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Current Approvals ({{ count($evidence['current_approval_refs']) }})</h3>
            @if(empty($evidence['current_approval_refs']))
                <p class="text-sm text-gray-500 mt-2">No active current approvals.</p>
            @else
                <ul class="mt-3 space-y-2">
                    @foreach($evidence['current_approval_refs'] as $appRef)
                        <li>
                            <a href="{{ route('v3.teacher.evidence.approvals.show', ['case' => $case->id, 'approval' => $appRef]) }}" class="text-sm font-mono text-indigo-600 hover:underline">
                                {{ $appRef }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="bg-white p-5 rounded-lg border border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Historical Approvals ({{ count($evidence['historical_approval_refs']) }})</h3>
            @if(empty($evidence['historical_approval_refs']))
                <p class="text-sm text-gray-500 mt-2">No historical approvals.</p>
            @else
                <ul class="mt-3 space-y-2">
                    @foreach($evidence['historical_approval_refs'] as $appRef)
                        <li>
                            <a href="{{ route('v3.teacher.evidence.approvals.show', ['case' => $case->id, 'approval' => $appRef]) }}" class="text-sm font-mono text-gray-600 hover:underline">
                                {{ $appRef }} (Historical)
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <!-- Timeline Events Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Recorded Timeline Events</h2>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recorded At (UTC)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kind</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event / Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">State</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($evidence['events'] as $evt)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-mono">{{ $evt['recorded_at'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $evt['kind'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ $evt['event_type'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ $evt['state'] }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 py-0.5 rounded text-xs {{ $evt['content_state'] === 'available' ? 'bg-green-100 text-green-800' : ($evt['content_state'] === 'missing_or_corrupt' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700') }}">
                            {{ $evt['content_state'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">No events recorded.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if($evidence['meta']['has_more'] || $evidence['meta']['page'] > 1)
        <div class="px-6 py-3 border-t border-gray-200 flex items-center justify-between">
            <div>
                @if($evidence['meta']['page'] > 1)
                <a href="{{ route('v3.teacher.evidence.show', ['case' => $case->id, 'page' => $evidence['meta']['page'] - 1]) }}" class="text-sm font-medium text-indigo-600 hover:underline">&larr; Previous Page</a>
                @endif
            </div>
            <div>
                @if($evidence['meta']['has_more'])
                <a href="{{ route('v3.teacher.evidence.show', ['case' => $case->id, 'page' => $evidence['meta']['page'] + 1]) }}" class="text-sm font-medium text-indigo-600 hover:underline">Next Page &rarr;</a>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
