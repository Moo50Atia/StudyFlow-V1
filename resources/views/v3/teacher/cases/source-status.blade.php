@extends('v3.layout')

@section('title', 'Case Knowledge Source Status')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case Receipt</a>
    <a href="{{ route('v3.teacher.cases.progress', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Progress</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Source Status</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Knowledge Source Status</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case ID: {{ $sourceStatus['case_id'] }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.cases.source-status', $case->id) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Refresh Status
            </a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <dl class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">State</dt>
                <dd class="mt-1 text-sm font-semibold">
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($sourceStatus['state'] === 'bound') bg-green-100 text-green-800
                        @elseif($sourceStatus['state'] === 'blocked') bg-red-100 text-red-800
                        @elseif($sourceStatus['state'] === 'uncertain') bg-amber-100 text-amber-800
                        @else bg-blue-100 text-blue-800
                        @endif">
                        {{ ucfirst($sourceStatus['state']) }}
                    </span>
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Pages</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">
                    {{ $sourceStatus['page_count'] ?? '-' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">OCR Pages</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">
                    {{ $sourceStatus['ocr_page_count'] ?? '-' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-gray-500 uppercase">Last Updated</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900">
                    {{ $sourceStatus['updated_at'] }}
                </dd>
            </div>
        </dl>

        @if(!empty($sourceStatus['error_code']))
        <div class="mt-4 pt-4 border-t border-gray-100">
            <span class="text-xs font-medium text-red-600 uppercase">Error Code:</span>
            <span class="ml-1 text-sm font-semibold text-red-700">{{ $sourceStatus['error_code'] }}</span>
        </div>
        @endif
    </div>

    @if(!empty($sourceStatus['gaps']))
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Detected Source Gaps</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Page</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Gap Code</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($sourceStatus['gaps'] as $gap)
                    <tr>
                        <td class="px-4 py-2 text-gray-900 font-medium">Page {{ $gap['page'] }}</td>
                        <td class="px-4 py-2 text-red-600 font-mono">{{ $gap['code'] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
