<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight animate-fade-in">{{ $subject->name }}</h2>
            <div class="flex items-center space-x-3">
                @if(session('study_flow'))
                <a href="{{ route('study-flow.exit') }}" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg font-semibold text-xs uppercase tracking-widest hover:bg-gray-600 transition-all duration-200">
                    Exit Flow
                </a>
                @endif
                <a href="{{ route('subjects.index') }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 transition-colors duration-200">
                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Subjects
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
            <div class="mb-6 bg-gradient-to-r from-green-400 to-emerald-500 text-white px-6 py-4 rounded-xl shadow-lg animate-fade-in-down flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ session('success') }}
            </div>
            @endif

            <!-- Subject Info Card -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl mb-8 animate-fade-in-up">
                <div class="p-8">
                    <div class="flex items-center mb-6">
                        <div class="w-16 h-16 rounded-xl bg-gradient-to-r from-blue-500 to-indigo-500 flex items-center justify-center text-white text-3xl shadow-lg">
                            📚
                        </div>
                        <div class="ml-6">
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $subject->name }}</h3>
                            <p class="text-gray-500 dark:text-gray-400">{{ $subject->lectures->count() }} Lectures</p>
                        </div>
                        <div class="ml-auto flex items-center space-x-3">
                            @if(Auth::check() && in_array(Auth::user()->role, ['teacher', 'admin']))
                            <a href="{{ route('v3.teacher.subject-runs.create', $subject) }}" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl font-semibold text-sm hover:from-blue-700 hover:to-indigo-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                Upload Curriculum (PDFs)
                            </a>
                            @endif
                            @if(Auth::check() && Auth::user()->role === 'admin')
                            <a href="{{ route('lectures.create', ['subject_id' => $subject->id]) }}" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold text-sm hover:from-indigo-700 hover:to-purple-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Add Lecture
                            </a>
                            @endif
                        </div>
                    </div>
                    <p class="text-gray-600 dark:text-gray-400">{{ $subject->description ?? 'No description available' }}</p>
                </div>
            </div>

            <!-- Study Flow: Bulk Lecture Creation -->
            @if(session('study_flow') && Auth::check() && Auth::user()->role === 'admin')
            <div class="bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-2xl shadow-xl p-6 mb-8 animate-fade-in-up" style="animation-delay: 0.1s">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-gray-900 dark:text-gray-100">📝 Bulk Create Lectures</h4>
                        <p class="text-gray-600 dark:text-gray-400">Add multiple lectures at once</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Number of lectures:</label>
                        <input type="number" id="lecture-count" min="1" max="10" value="1" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <button type="button" onclick="generateLectureForms()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                            Generate
                        </button>
                    </div>
                </div>
                <form action="{{ route('study-flow.bulk-lectures', $subject) }}" method="POST" id="bulk-lectures-form">
                    @csrf
                    <div id="lecture-forms-container" class="space-y-4">
                        <!-- Dynamic lecture forms will be inserted here -->
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" id="submit-lectures-btn" class="hidden inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-xl font-bold hover:from-green-600 hover:to-emerald-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Create All Lectures
                        </button>
                    </div>
                </form>
            </div>
            @endif

            <!-- Existing Lectures List -->
            @if($subject->lectures->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden animate-fade-in-up" style="animation-delay: 0.2s">
                <div class="px-6 py-4 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-700 dark:to-gray-800 border-b dark:border-gray-700">
                    <h4 class="text-lg font-semibold text-gray-800 dark:text-gray-200">📖 Lectures in this Subject</h4>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach($subject->lectures as $index => $lecture)
                        <a href="{{ route('lectures.show', $lecture) }}" class="block p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:shadow-lg transition-all duration-200 hover:-translate-y-0.5 animate-fade-in-up" style="animation-delay: {{ 0.3 + ($index * 0.05) }}s">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $lecture->title }}</p>
                                        @if($lecture->pipelineLink)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                                            {{ ($lecture->pipelineLink->item?->run?->origin ?? '') === 'historical_import' ? 'Historical' : 'Pipeline' }}
                                        </span>
                                        @endif
                                    </div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $lecture->sections->count() ?? 0 }} sections</p>
                                </div>
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </a>
                        @endforeach
                    </div>
                </div>
            </div>
            @else
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-12 text-center animate-fade-in-up">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <p class="text-lg font-medium text-gray-400">No lectures yet</p>
                <p class="text-sm text-gray-400 mt-2">Add your first lecture to get started!</p>
            </div>
            @endif

            @if(Auth::check() && Auth::user()->role !== 'student')
            <div class="flex space-x-4 mt-8 animate-fade-in-up" style="animation-delay: 0.4s">
                <a href="{{ route('subjects.edit', $subject) }}" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-xl font-semibold hover:from-amber-600 hover:to-orange-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Edit Subject
                </a>
                <form action="{{ route('subjects.destroy', $subject) }}" method="POST" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" onclick="return confirm('Are you sure?')" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-red-500 to-rose-500 text-white rounded-xl font-semibold hover:from-red-600 hover:to-rose-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Delete
                    </button>
                </form>
            </div>
            @endif
        </div>
    </div>

    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

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

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
            opacity: 0;
        }

        .animate-fade-in-down {
            animation: fadeInDown 0.5s ease-out forwards;
        }
    </style>

    <script>
        function generateLectureForms() {
            const count = parseInt(document.getElementById('lecture-count').value) || 1;
            const container = document.getElementById('lecture-forms-container');
            const submitBtn = document.getElementById('submit-lectures-btn');
            container.innerHTML = '';

            for (let i = 0; i < count; i++) {
                container.innerHTML += `
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-600">
                        <div class="flex items-center mb-3">
                            <span class="w-8 h-8 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center font-bold text-sm">${i + 1}</span>
                            <span class="ml-3 font-medium text-gray-700 dark:text-gray-300">Lecture ${i + 1}</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title *</label>
                                <input type="text" name="lectures[${i}][title]" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Summary</label>
                                <input type="text" name="lectures[${i}][summary]" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>
                `;
            }

            submitBtn.classList.remove('hidden');
            submitBtn.classList.add('inline-flex');
        }
    </script>
</x-app-layout>