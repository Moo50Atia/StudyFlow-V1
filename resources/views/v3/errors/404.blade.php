<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resource Unavailable - StudyFlow V3</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="max-w-md w-full bg-white shadow rounded-lg p-6 text-center">
        <h1 class="text-xl font-bold text-gray-800 mb-2">Resource Unavailable</h1>
        <p class="text-sm text-gray-600 mb-4">{{ $message ?? 'The requested V3 resource is unavailable.' }}</p>
        <a href="{{ route('v3.entry') }}" class="text-sm text-indigo-600 hover:text-indigo-500 font-medium">
            Return to Workspace
        </a>
    </div>
</body>
</html>
