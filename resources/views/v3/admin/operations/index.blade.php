@extends('v3.layout')

@section('title', 'Admin Operations')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Workspace</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Operations</span>
    <a href="{{ route('v3.admin.cases.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Cases</a>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Admin Operations</h1>
        <p class="text-sm text-gray-600 mt-1">
            Operational projection for V3 processing cases. Displays only closed metadata, operational control modes, and safe counts.
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
                        <th class="px-6 py-3 text-left">Control Mode</th>
                        <th class="px-6 py-3 text-left">Error Code</th>
                        <th class="px-6 py-3 text-center">Age (s)</th>
                        <th class="px-6 py-3 text-center">Idle (s)</th>
                        <th class="px-6 py-3 text-center">Dormant Reviews</th>
                        <th class="px-6 py-3 text-center">Uncertain Effects</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($cases as $item)
                    <tr>
                        <td class="px-6 py-4 font-mono text-xs text-gray-700">{{ $item['case']['case_id'] }}</td>
                        <td class="px-6 py-4 font-medium text-gray-900">{{ $item['case']['stage_code'] }}</td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                {{ $item['case']['status_code'] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs">{{ $item['control_mode'] }}</td>
                        <td class="px-6 py-4 font-mono text-xs text-gray-500">{{ $item['case']['error_code'] ?? '-' }}</td>
                        <td class="px-6 py-4 text-center text-xs text-gray-600">{{ $item['age_seconds'] }}</td>
                        <td class="px-6 py-4 text-center text-xs text-gray-600">{{ $item['idle_seconds'] }}</td>
                        <td class="px-6 py-4 text-center text-xs text-gray-700">{{ $item['dormant_review_count'] }}</td>
                        <td class="px-6 py-4 text-center text-xs text-gray-700">{{ $item['uncertain_effect_count'] }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('v3.admin.operations.show', $item['case']['case_id']) }}" class="text-indigo-600 hover:text-indigo-900 text-xs font-medium">
                                Operations &rarr;
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-gray-500">
                            No operational cases found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
