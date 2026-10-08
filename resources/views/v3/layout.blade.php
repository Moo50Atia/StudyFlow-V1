<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'V3 Workspace') - StudyFlow V3</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900">
    <header class="bg-white border-b border-gray-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-6">
                <a href="{{ route('v3.entry') }}" class="font-bold text-lg text-indigo-600">
                    StudyFlow V3
                </a>
                <nav class="flex space-x-4 text-sm font-medium">
                    @yield('nav')
                </nav>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-sm text-gray-600">
                    {{ Auth::user()?->name }} ({{ Auth::user()?->role }})
                </span>
                <a href="{{ route('dashboard') }}" class="text-xs text-gray-500 hover:text-gray-700">
                    Legacy Dashboard
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-xs text-red-600 hover:text-red-800">
                        Log Out
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 rounded-md bg-green-50 text-green-800 border border-green-200">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 p-4 rounded-md bg-red-50 text-red-800 border border-red-200">
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</body>
</html>
