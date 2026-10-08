@extends('v3.layout')

@section('title', 'Action Conflict')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Upload Attempts</a>
@endsection

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <div class="bg-amber-50 border border-amber-200 p-6 rounded-lg">
        <h1 class="text-xl font-bold text-amber-900">Action Conflict (409)</h1>
        <p class="text-sm font-mono text-amber-800 mt-2">Code: {{ $code }}</p>
        <p class="text-sm text-amber-700 mt-2">{{ $message }}</p>

        <div class="mt-6 flex space-x-4">
            <button onclick="window.location.reload();" class="inline-flex items-center px-4 py-2 border border-amber-600 shadow-sm text-sm font-medium rounded-md text-amber-800 bg-white hover:bg-amber-50">
                Reload Page
            </button>
            <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Return to Uploads
            </a>
        </div>
    </div>
</div>
@endsection
