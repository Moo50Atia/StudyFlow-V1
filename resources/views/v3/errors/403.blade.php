<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Denied - StudyFlow V3</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="max-w-md w-full bg-white shadow rounded-lg p-6 text-center">
        <h1 class="text-xl font-bold text-red-600 mb-2">Access Denied</h1>
        <p class="text-sm text-gray-600 mb-4">{{ $message ?? 'V3 access is unavailable.' }}</p>
        <a href="{{ route('home') }}" class="text-sm text-indigo-600 hover:text-indigo-500 font-medium">
            Return to Homepage
        </a>
    </div>
</body>
</html>
