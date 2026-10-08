@extends('v3.layout')

@section('title', 'Teacher Workspace')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Upload Attempts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Teacher Workspace</h1>
        <p class="text-sm text-gray-600 mt-1">
            Welcome, {{ $teacher->name }}. You own and manage your interactive educational content here.
        </p>
    </div>

    <!-- PDF Intake Section -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Submit Course Material (PDF)</h2>
                <p class="text-sm text-gray-500">Submit a standard PDF document to begin generating interactive visual learning scenes.</p>
            </div>
            <a href="{{ route('v3.teacher.pdf-uploads.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                View Past Uploads &rarr;
            </a>
        </div>

        <form action="{{ route('v3.teacher.pdf-uploads.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label for="pdf" class="block text-sm font-medium text-gray-700">Select PDF Document</label>
                <div class="mt-1 flex items-center space-x-4">
                    <input type="file" name="pdf" id="pdf" accept=".pdf" required
                        class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-gray-300 rounded-md p-1">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none">
                        Upload PDF
                    </button>
                </div>
                <p class="mt-1 text-xs text-gray-400">Standard PDF only. Max size according to active pilot policy. Encrypted or active-content files are rejected.</p>
            </div>
        </form>
    </div>

    <div class="bg-white p-8 rounded-lg shadow-sm border border-gray-200 text-center">
        <div class="mx-auto w-12 h-12 text-gray-400 mb-3">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" class="w-12 h-12">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
            </svg>
        </div>
        <h2 class="text-lg font-medium text-gray-900">Interactive Visual Learning Processing</h2>
        <p class="text-sm text-gray-500 max-w-md mx-auto mt-2">
            Uploaded PDFs undergo structure and malware inspection before becoming ready for case creation.
        </p>
    </div>
</div>
@endsection
