<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                📚 Teacher Study Flow - Lectures
            </h2>
            <div class="flex items-center space-x-3">
                <a href="{{ route('teacher-study-flow.exit') }}" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg font-semibold text-xs uppercase tracking-widest hover:bg-gray-600 transition-all duration-200">
                    Exit Flow
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('info'))
            <div class="mb-6 bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-xl">
                {{ session('info') }}
            </div>
            @endif

            @if(session('success'))
            <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-xl">
                {{ session('success') }}
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Existing Lectures -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate-fade-in-up">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                        <span class="w-10 h-10 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3">📖</span>
                        My Lectures
                    </h3>

                    @if($lectures->count() > 0)
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        @foreach($lectures as $lecture)
                        <a href="{{ route('lectures.show', $lecture) }}" class="block p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:shadow-md hover:-translate-y-0.5 transition-all duration-200">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $lecture->title }}</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $lecture->subject->name ?? 'N/A' }}</p>
                            <div class="mt-2 flex items-center space-x-4 text-xs text-gray-400">
                                <span>{{ $lecture->sections->count() ?? 0 }} sections</span>
                                <span>{{ $lecture->questions->count() ?? 0 }} questions</span>
                            </div>
                        </a>
                        @endforeach
                    </div>
                    @else
                    <p class="text-gray-500 dark:text-gray-400">No lectures yet. Create your first one!</p>
                    @endif
                </div>

                <!-- Create New Lecture -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 animate-fade-in-up" style="animation-delay: 0.1s">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                        <span class="w-10 h-10 bg-green-100 text-green-600 rounded-full flex items-center justify-center mr-3">➕</span>
                        Create New Lecture
                    </h3>

                    @if($subjects->count() > 0)
                    <form action="{{ route('teacher-study-flow.store-lecture') }}" method="POST">
                        @csrf

                        <div class="mb-4">
                            <label for="subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject *</label>
                            <select name="subject_id" id="subject_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                <option value="">-- Select Subject --</option>
                                @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                                @endforeach
                            </select>
                            @error('subject_id')<p class="mt-1 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-4">
                            <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Lecture Title *</label>
                            <input type="text" name="title" id="title" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" placeholder="e.g., Introduction to Calculus">
                            @error('title')<p class="mt-1 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6">
                            <label for="summary" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Summary (Optional)</label>
                            <textarea name="summary" id="summary" rows="3" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white" placeholder="Brief overview of the lecture..."></textarea>
                        </div>

                        <button type="submit" class="w-full inline-flex items-center justify-center px-6 py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-xl font-bold hover:from-green-600 hover:to-emerald-600 transition-all duration-300 transform hover:scale-[1.02] shadow-lg">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Create Lecture & Continue
                        </button>
                    </form>
                    @else
                    <div class="text-center py-8">
                        <p class="text-gray-500 dark:text-gray-400 mb-4">You don't have permission for any subjects yet.</p>
                        <p class="text-sm text-gray-400">Please contact an administrator to grant you access.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
            opacity: 0;
        }
    </style>
</x-app-layout>