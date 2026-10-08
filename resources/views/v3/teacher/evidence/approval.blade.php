@extends('v3.layout')

@section('title', 'Approval Lineage Graph')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case Receipt</a>
    <a href="{{ route('v3.teacher.evidence.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Evidence</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Approval Graph</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Approval Lineage Graph</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Approval Ref: {{ $graph['approval_ref'] }}</p>
        </div>
        <div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $graph['integrity'] === 'complete' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                Status: {{ $graph['current'] ? 'Current Approval' : 'Historical Approval' }} ({{ ucfirst($graph['integrity']) }})
            </span>
        </div>
    </div>

    @if(!empty($graph['gaps']))
    <div class="bg-red-50 border border-red-200 p-4 rounded-lg">
        <h3 class="text-sm font-semibold text-red-800">Lineage Gaps Detected</h3>
        <ul class="mt-2 list-disc list-inside text-sm text-red-700">
            @foreach($graph['gaps'] as $gap)
                <li>Gap: <span class="font-mono font-medium">{{ $gap['code'] }}</span> (Parent: {{ $gap['parent_ref'] }})</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Nodes List -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Graph Nodes ({{ count($graph['nodes']) }})</h2>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kind</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ref</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">State</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($graph['nodes'] as $node)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $node['kind'] }}</td>
                    <td class="px-6 py-3 text-sm font-mono text-gray-600">{{ $node['ref'] }}</td>
                    <td class="px-6 py-3 text-sm text-gray-700">{{ $node['state'] }}</td>
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-0.5 rounded text-xs {{ $node['content_state'] === 'available' ? 'bg-green-100 text-green-800' : ($node['content_state'] === 'missing_or_corrupt' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700') }}">
                            {{ $node['content_state'] }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-sm">
                        @if(!empty($node['content_ref']))
                            <a href="{{ $node['content_ref'] }}" class="text-indigo-600 hover:underline">View Resource</a>
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
