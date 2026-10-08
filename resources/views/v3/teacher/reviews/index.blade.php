@extends('v3.layout')

@section('title', 'Teacher Scene Reviews')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Scene Reviews</span>
@endsection

@section('content')
<div class="space-y-6">
    @if(isset($reminders) && $reminders->isNotEmpty())
        <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded shadow-sm">
            <div class="flex">
                <div class="ml-3">
                    <p class="text-sm font-medium text-amber-800">
                        A scene is ready for your review
                    </p>
                    <div class="mt-2 text-sm text-amber-700 space-y-1">
                        @foreach($reminders as $reminder)
                            <div>
                                <a href="{{ route('v3.teacher.cases.reviews.show', [$case->id, $reminder->review_item_id]) }}" class="font-medium underline hover:text-amber-900">
                                    Review scene ({{ $reminder->review_item_id }})
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Current Scene Reviews</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Case Ref: {{ $case->id }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.cases.approvals.index', $case->id) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Approved Scenes
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Review Ref</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section Ref</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Version</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ready At</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($reviews['data'] as $item)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">{{ $item['review_ref'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500">{{ $item['section_ref'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">v{{ $item['candidate_version'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                {{ $item['status'] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $item['ready_at'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="{{ route('v3.teacher.cases.reviews.show', [$case->id, $item['review_ref']]) }}" class="text-indigo-600 hover:text-indigo-900">Review Scene</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">No pending scene reviews.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
