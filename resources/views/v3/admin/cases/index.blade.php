@extends('v3.layout')

@section('title', 'Processing Cases Operations')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Operations</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Cases</span>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Cases Operational Monitoring</h1>
        <p class="text-sm text-gray-600 mt-1">
            Closed operational metadata projection for V3 processing cases. No educational content, prompts, or personal identifiers are displayed.
        </p>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase font-medium">
                    <tr>
                        <th class="px-6 py-3 text-left">Case ID</th>
                        <th class="px-6 py-3 text-left">Stage</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Error</th>
                        <th class="px-6 py-3 text-left">Created At</th>
                        <th class="px-6 py-3 text-left">Updated At</th>
                        <th class="px-6 py-3 text-center">Attempts</th>
                        <th class="px-6 py-3 text-center">Scenes</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($cases as $caseItem)
                    <tr>
                        <td class="px-6 py-4 font-mono text-xs text-gray-700">{{ $caseItem['case_id'] }}</td>
                        <td class="px-6 py-4 font-medium text-gray-900">{{ $caseItem['stage_code'] }}</td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-0.5 rounded text-xs font-medium
                                @if($caseItem['status_code'] === 'running') bg-blue-100 text-blue-800
                                @elseif($caseItem['status_code'] === 'queued') bg-gray-100 text-gray-800
                                @elseif($caseItem['status_code'] === 'failed') bg-red-100 text-red-800
                                @elseif($caseItem['status_code'] === 'blocked') bg-amber-100 text-amber-800
                                @else bg-green-100 text-green-800
                                @endif">
                                {{ $caseItem['status_code'] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $caseItem['error_code'] ?? '-' }}</td>
                        <td class="px-6 py-4 text-xs text-gray-500">{{ $caseItem['created_at'] }}</td>
                        <td class="px-6 py-4 text-xs text-gray-500">{{ $caseItem['updated_at'] }}</td>
                        <td class="px-6 py-4 text-center text-xs text-gray-700">{{ $caseItem['attempt_count'] }}</td>
                        <td class="px-6 py-4 text-center text-xs text-gray-700">{{ $caseItem['scene_count'] }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('v3.admin.cases.summary', $caseItem['case_id']) }}" class="text-indigo-600 hover:text-indigo-900 text-xs font-medium">
                                Details &rarr;
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-6 py-8 text-center text-gray-500">
                            No processing cases found in the system.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($paginator) && ($paginator->currentPage() > 1 || $paginator->hasMorePages()))
        <div class="px-6 py-3 border-t border-gray-200 flex justify-between items-center text-sm">
            @if($paginator->currentPage() > 1)
                <a href="{{ route('v3.admin.cases.index', ['page' => $paginator->currentPage() - 1]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">&larr; Previous Page</a>
            @else
                <span class="text-gray-400">&larr; Previous Page</span>
            @endif

            <span class="text-gray-600">Page {{ $paginator->currentPage() }}</span>

            @if($paginator->hasMorePages())
                <a href="{{ route('v3.admin.cases.index', ['page' => $paginator->currentPage() + 1]) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Next Page &rarr;</a>
            @else
                <span class="text-gray-400">Next Page &rarr;</span>
            @endif
        </div>
        @endif
    </div>
</div>
@endsection
