<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conflict - StudyFlow V3</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="max-w-md w-full bg-white shadow rounded-lg p-6 text-center">
        <h1 class="text-xl font-bold text-amber-600 mb-2">Access Conflict</h1>
        <p class="text-sm text-gray-600 mb-4">{{ $message ?? 'The operation could not be completed due to a conflict or policy constraint.' }}</p>
        <div class="space-x-4">
            <a href="javascript:location.reload()" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-indigo-700 bg-indigo-100 hover:bg-indigo-200">
                Reload Current State
            </a>
            <a href="{{ route('v3.entry') }}" class="text-xs text-gray-500 hover:text-gray-700">
                Back to Workspace
            </a>
        </div>
    </div>
</body>
</html>
