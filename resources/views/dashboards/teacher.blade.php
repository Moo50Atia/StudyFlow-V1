<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Teacher Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Banner -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl shadow-lg p-6 mb-8 text-white animate-fade-in-up">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-2xl font-bold">Welcome back, {{ auth()->user()->name }}!</h3>
                        <p class="mt-2 text-blue-100">You have access to {{ $permissions->count() }} lecture permissions.</p>
                    </div>
                    <a href="{{ route('teacher-study-flow.start') }}" class="inline-flex items-center px-6 py-3 bg-white text-indigo-600 rounded-xl font-bold hover:bg-indigo-50 transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Start Study Flow
                    </a>
                </div>
            </div>

            <!-- Stats Overview -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center transform hover:scale-105 transition-all duration-300 animate-fade-in-up" style="animation-delay: 0.1s;">
                    <p class="text-3xl font-bold text-blue-600">{{ $subjects->count() }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Subjects</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center transform hover:scale-105 transition-all duration-300 animate-fade-in-up" style="animation-delay: 0.2s;">
                    <p class="text-3xl font-bold text-green-600">{{ $lectures->count() }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Lectures</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center transform hover:scale-105 transition-all duration-300 animate-fade-in-up" style="animation-delay: 0.3s;">
                    <p class="text-3xl font-bold text-purple-600">{{ $questions->count() }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Questions</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-center transform hover:scale-105 transition-all duration-300 animate-fade-in-up" style="animation-delay: 0.4s;">
                    <p class="text-3xl font-bold text-red-600">{{ $lovedLectures->count() + $lovedQuestions->count() }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Favorites</p>
                </div>
            </div>

            <!-- My Lectures -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-8 animate-fade-in-up" style="animation-delay: 0.5s;">
                <div class="px-6 py-4 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-700 dark:to-gray-800 border-b dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">📚 My Lectures</h3>
                </div>
                <div class="p-6">
                    @if($lectures->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($lectures as $index => $lecture)
                        <div class="border dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-all duration-300 hover:-translate-y-1 animate-fade-in-up" style="animation-delay: {{ 0.6 + ($index * 0.1) }}s;">
                            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $lecture->title }}</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $lecture->subject->name ?? 'N/A' }}</p>
                            <div class="mt-3 flex space-x-2">
                                <a href="{{ route('lectures.show', $lecture) }}" class="text-blue-600 hover:text-blue-800 text-sm">View</a>
                                <a href="{{ route('lectures.edit', $lecture) }}" class="text-yellow-600 hover:text-yellow-800 text-sm">Edit</a>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-gray-500 dark:text-gray-400">No lectures assigned yet.</p>
                    @endif
                </div>
            </div>

            <!-- Loved Items Section -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden animate-fade-in-up" style="animation-delay: 0.8s;">
                <div class="px-6 py-4 bg-gradient-to-r from-pink-50 to-red-50 dark:from-gray-700 dark:to-gray-800 border-b dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">❤️ My Favorites</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Loved Lectures -->
                        <div>
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-3">Lectures ({{ $lovedLectures->count() }})</h4>
                            @forelse($lovedLectures as $lecture)
                            <div class="flex items-center space-x-2 mb-2 p-2 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="text-red-500">❤️</span>
                                <a href="{{ route('lectures.show', $lecture) }}" class="text-sm text-gray-900 dark:text-gray-100 hover:text-blue-600">{{ Str::limit($lecture->title, 25) }}</a>
                            </div>
                            @empty
                            <p class="text-sm text-gray-400">No loved lectures yet.</p>
                            @endforelse
                        </div>

                        <!-- Loved Questions -->
                        <div>
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-3">Questions ({{ $lovedQuestions->count() }})</h4>
                            @forelse($lovedQuestions as $question)
                            <div class="flex items-center space-x-2 mb-2 p-2 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="text-red-500">❤️</span>
                                <a href="{{ route('questions.show', $question) }}" class="text-sm text-gray-900 dark:text-gray-100 hover:text-blue-600">{{ Str::limit($question->idea_text, 25) }}</a>
                            </div>
                            @empty
                            <p class="text-sm text-gray-400">No loved questions yet.</p>
                            @endforelse
                        </div>

                        <!-- Loved Exam Questions -->
                        <div>
                            <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-3">Exam Questions ({{ $lovedExamQuestions->count() }})</h4>
                            @forelse($lovedExamQuestions as $examQuestion)
                            <div class="flex items-center space-x-2 mb-2 p-2 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="text-red-500">❤️</span>
                                <a href="{{ route('exam_questions.show', $examQuestion) }}" class="text-sm text-gray-900 dark:text-gray-100 hover:text-blue-600">{{ Str::limit($examQuestion->idea, 25) }}</a>
                            </div>
                            @empty
                            <p class="text-sm text-gray-400">No loved exam questions yet.</p>
                            @endforelse
                        </div>
                    </div>
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
            animation: fadeInUp 0.6s ease-out forwards;
            opacity: 0;
        }
    </style>
</x-app-layout>