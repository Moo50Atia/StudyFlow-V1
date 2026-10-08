@extends('v3.layout')

@section('title', 'My PDF Upload Attempts')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Upload Attempts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">PDF Upload Attempts</h1>
            <p class="text-sm text-gray-600 mt-1">
                Your submitted course material submissions and their inspection statuses.
            </p>
        </div>
        <a href="{{ route('v3.teacher.workspace') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
            Submit New PDF
        </a>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        @if($attempts->isEmpty())
            <div class="p-8 text-center text-gray-500">
                No upload attempts found. Submit a PDF from your workspace to begin.
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attempt ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($attempts as $attempt)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                                {{ $attempt->id }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    @if($attempt->state === 'accepted') bg-green-100 text-green-800
                                    @elseif(in_array($attempt->state, ['rejected', 'blocked'])) bg-red-100 text-red-800
                                    @else bg-yellow-100 text-yellow-800 @endif">
                                    {{ $attempt->state }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                @if($attempt->reason_code)
                                    <span class="font-mono text-xs text-red-600">{{ $attempt->reason_code }}</span>
                                @elseif($attempt->duplicate)
                                    <span class="text-xs text-indigo-600">Existing Document Reused</span>
                                @else
                                    <span class="text-xs text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $attempt->created_at?->diffForHumans() }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="{{ route('v3.teacher.pdf-uploads.show', $attempt->id) }}" class="text-indigo-600 hover:text-indigo-900">
                                    View
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-gray-200">
                {{ $attempts->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
