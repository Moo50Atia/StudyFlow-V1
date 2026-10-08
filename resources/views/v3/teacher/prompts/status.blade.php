@extends('v3.layout')

@section('title', 'Prompt Qualification Status')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case</a>
    <a href="{{ route('v3.teacher.cases.progress', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workflow Progress</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Prompt Status</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Prompt Qualification Status</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case ID: {{ $data['case_id'] }}</p>
        </div>
        <div>
            <span class="text-xs text-gray-400">Updated: {{ $data['updated_at'] }}</span>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Sections Status</h2>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Work Ordinal</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section Ordinal</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section Ref</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Error Code</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revisions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($data['sections'] as $sec)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">W{{ $sec['work_ordinal'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">Sec {{ $sec['ordinal_in_work'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-gray-500">{{ $sec['section_ref'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                @if($sec['status'] === 'qualified') bg-green-100 text-green-800
                                @elseif($sec['status'] === 'blocked') bg-red-100 text-red-800
                                @else bg-yellow-100 text-yellow-800 @endif">
                                {{ strtoupper($sec['status']) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $sec['error_code'] ?? '—' }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $sec['revision_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No sections available yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
