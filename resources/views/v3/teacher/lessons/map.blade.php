@extends('v3.layout')

@section('title', 'Teacher Lesson Map')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case</a>
    <a href="{{ route('v3.teacher.cases.progress', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workflow Progress</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Lesson Map</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Lesson Map & Work Items</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case ID: {{ $map['case_id'] }} | Map Ref: {{ $map['map_ref'] }}</p>
        </div>
        <div>
            <span class="px-3 py-1 rounded-full text-xs font-semibold
                @if($map['state'] === 'ready') bg-green-100 text-green-800
                @elseif($map['state'] === 'pending') bg-blue-100 text-blue-800
                @else bg-gray-100 text-gray-800 @endif">
                {{ strtoupper($map['state']) }}
            </span>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Work Items Overview</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Current Work Ordinal</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">W{{ $map['current_work_ordinal'] ?? 1 }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Cursor Phase</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">{{ $map['phase'] ?? 'pending' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Last Updated</dt>
                <dd class="mt-1 text-sm font-mono text-gray-700">{{ $map['updated_at'] }}</dd>
            </div>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-sm font-medium text-gray-700 uppercase tracking-wider mb-3">Mapped Work Nodes</h3>
            <div class="divide-y divide-gray-100">
                @forelse($map['work_items'] as $item)
                <div class="py-3 flex items-center justify-between">
                    <div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 mr-2">
                            W{{ $item['ordinal'] }}
                        </span>
                        <span class="text-sm font-medium text-gray-900">{{ e($item['path']) }}</span>
                        <span class="text-xs text-gray-500 ml-2">
                            (Pages: {{ implode(', ', $item['coverage_pages']) }})
                        </span>
                    </div>
                    <div class="text-sm text-gray-600">
                        <span class="font-medium text-gray-900">{{ $item['section_count'] }}</span> sections
                    </div>
                </div>
                @empty
                <p class="text-sm text-gray-500 py-4">No mapped work items found.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
