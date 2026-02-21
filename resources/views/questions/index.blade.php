<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Questions') }}</h2>
            @if(Auth::check() && Auth::user()->role !== 'student')
            <a href="{{ route('questions.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition ease-in-out duration-150">Create Question</a>
            @endif
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
            <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative animate-fade-in">{{ session('success') }}</div>
            @endif

            <!-- Search/Filter Section -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl mb-6 animate-fade-in-up">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Filter Questions
                    </h3>
                    <form id="filterForm" method="GET" action="{{ route('questions.index') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Subject Filter -->
                        <div>
                            <label for="subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                            <select name="subject_id" id="subject_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                                <option value="">-- All Subjects --</option>
                                @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" {{ request('subject_id') == $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Lecture Filter (Dynamic based on Subject) -->
                        <div>
                            <label for="lecture_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Lecture</label>
                            <select name="lecture_id" id="lecture_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200" {{ !request('subject_id') ? 'disabled' : '' }}>
                                <option value="">-- {{ request('subject_id') ? 'All Lectures' : 'Select Subject First' }} --</option>
                                @foreach($lectures as $lecture)
                                <option value="{{ $lecture->id }}" {{ request('lecture_id') == $lecture->id ? 'selected' : '' }}>{{ $lecture->title }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-end gap-2">
                            <button type="submit" class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold hover:from-indigo-700 hover:to-purple-700 transition-all duration-200 shadow-lg">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                </svg>
                                Filter
                            </button>
                            <a href="{{ route('questions.index') }}" class="inline-flex items-center justify-center px-4 py-2.5 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-xl font-semibold hover:bg-gray-300 dark:hover:bg-gray-500 transition-all duration-200">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </a>
                        </div>
                    </form>

                    @if(request()->hasAny(['subject_id', 'lecture_id']))
                    <div class="mt-4 pt-4 border-t dark:border-gray-700">
                        <div class="flex flex-wrap gap-2">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Active filters:</span>
                            @if(request('subject_id'))
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200">
                                Subject: {{ $subjects->find(request('subject_id'))->name ?? 'N/A' }}
                            </span>
                            @endif
                            @if(request('lecture_id'))
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200">
                                Lecture: {{ $lectures->find(request('lecture_id'))->title ?? 'N/A' }}
                            </span>
                            @endif
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Questions Grid -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @forelse($questions as $index => $question)
                        <div class="border dark:border-gray-700 rounded-xl overflow-hidden hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 animate-fade-in-up" style="animation-delay: {{ $index * 0.05 }}s;">
                            @if($question->question_image)
                            <img src="{{ Storage::url($question->question_image) }}" alt="Question" class="w-full h-40 object-cover">
                            @else
                            <div class="w-full h-40 bg-gradient-to-r from-blue-400 to-indigo-500 flex items-center justify-center">
                                <span class="text-white text-4xl">❓</span>
                            </div>
                            @endif
                            <div class="p-5">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-block px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded-full">{{ $question->lecture->subject->name ?? 'N/A' }}</span>
                                        <span class="inline-block px-2 py-1 text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 rounded-full">{{ $question->lecture->title ?? 'N/A' }}</span>
                                    </div>
                                    @auth
                                    <button onclick="toggleLove('question', {{ $question->id }}, this)" class="love-btn p-1 rounded-full hover:bg-red-50 dark:hover:bg-gray-700 transition-all duration-200 {{ auth()->user()->loves($question) ? 'text-red-500' : 'text-gray-400' }}">
                                        <svg class="w-5 h-5" fill="{{ auth()->user()->loves($question) ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                        </svg>
                                    </button>
                                    @endauth
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">{{ Str::limit($question->question_text, 100) }}</p>
                                <div class="flex items-center justify-between">
                                    <a href="{{ route('questions.show', $question) }}" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">View →</a>
                                    @if(Auth::check() && Auth::user()->role !== 'student')
                                    <div class="flex space-x-2">
                                        <a href="{{ route('questions.edit', $question) }}" class="text-yellow-600 hover:text-yellow-800 text-xs">Edit</a>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="col-span-3 text-center py-12">
                            <div class="text-gray-400 dark:text-gray-500 mb-4">
                                <svg class="w-16 h-16 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2z" />
                                </svg>
                            </div>
                            <p class="text-gray-500 dark:text-gray-400 text-lg">No questions found matching your filters.</p>
                            <a href="{{ route('questions.index') }}" class="mt-4 inline-flex items-center text-indigo-600 hover:text-indigo-800">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Clear filters
                            </a>
                        </div>
                        @endforelse
                    </div>
                    <div class="mt-6">{{ $questions->links() }}</div>
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

        .animate-fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const subjectSelect = document.getElementById('subject_id');
            const lectureSelect = document.getElementById('lecture_id');

            subjectSelect.addEventListener('change', function() {
                const subjectId = this.value;

                // Reset and disable lecture select if no subject
                if (!subjectId) {
                    lectureSelect.innerHTML = '<option value="">-- Select Subject First --</option>';
                    lectureSelect.disabled = true;
                    return;
                }

                // Fetch lectures for selected subject
                lectureSelect.innerHTML = '<option value="">Loading...</option>';
                lectureSelect.disabled = true;

                fetch(`/api/subjects/${subjectId}/lectures`)
                    .then(response => response.json())
                    .then(data => {
                        lectureSelect.innerHTML = '<option value="">-- All Lectures --</option>';
                        data.forEach(lecture => {
                            lectureSelect.innerHTML += `<option value="${lecture.id}">${lecture.title}</option>`;
                        });
                        lectureSelect.disabled = false;
                    })
                    .catch(error => {
                        lectureSelect.innerHTML = '<option value="">-- Error loading --</option>';
                        console.error('Error:', error);
                    });
            });
        });
    </script>

    @auth
    <script>
        function toggleLove(type, id, button) {
            fetch('{{ route("love.toggle") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        type: type,
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const svg = button.querySelector('svg');
                        if (data.is_loved) {
                            button.classList.remove('text-gray-400');
                            button.classList.add('text-red-500');
                            svg.setAttribute('fill', 'currentColor');
                        } else {
                            button.classList.remove('text-red-500');
                            button.classList.add('text-gray-400');
                            svg.setAttribute('fill', 'none');
                        }
                    }
                });
        }
    </script>
    @endauth
</x-app-layout>