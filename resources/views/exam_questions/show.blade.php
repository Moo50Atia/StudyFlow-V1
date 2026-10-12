<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Exam Question Details</h2>
            <div class="flex items-center space-x-4">
                @auth
                <button onclick="toggleLove('exam_question', {{ $examQuestion->id }}, this)" class="love-btn inline-flex items-center px-3 py-2 border rounded-md transition-all duration-200 {{ auth()->user()->loves($examQuestion) ? 'border-red-300 text-red-500 bg-red-50' : 'border-gray-300 text-gray-400 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-1" fill="{{ auth()->user()->loves($examQuestion) ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                    </svg>
                    <span class="text-sm">{{ auth()->user()->loves($examQuestion) ? 'Loved' : 'Love' }}</span>
                </button>
                @endauth
                <a href="{{ route('exam_questions.index') }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">← Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg animate-fade-in-up">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="animate-fade-in-up" style="animation-delay: 0.1s;">
                            <h3 class="text-sm font-medium text-gray-500">Lecture</h3>
                            <p class="mt-1 text-gray-900 dark:text-gray-100">{{ $examQuestion->lecture->title ?? 'N/A' }}</p>
                        </div>
                        <div class="animate-fade-in-up" style="animation-delay: 0.2s;">
                            <h3 class="text-sm font-medium text-gray-500">Subject</h3>
                            <p class="mt-1 text-gray-900 dark:text-gray-100">{{ $examQuestion->lecture->subject->name ?? 'N/A' }}</p>
                        </div>
                    </div>

                    @if($examQuestion->question_image)
                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.3s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Question Image</h3>
                        <img src="{{ route('owner-assets.show', ['kind' => 'exam-question', 'id' => $examQuestion->id, 'field' => 'question_image']) }}" alt="Question" class="max-w-lg rounded-lg shadow-lg">
                    </div>
                    @else
                    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl animate-fade-in-up" style="animation-delay: 0.3s;">
                        <p class="text-amber-600 dark:text-amber-400 text-sm flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Question Image doesn't exist yet - we will add it soon!
                        </p>
                    </div>
                    @endif

                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.4s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Idea</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $examQuestion->idea ?? 'No idea available.' }}</p>
                    </div>

                    @if($examQuestion->solution_image)
                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.5s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Solution Image</h3>
                        <img src="{{ route('owner-assets.show', ['kind' => 'exam-question', 'id' => $examQuestion->id, 'field' => 'solution_image']) }}" alt="Solution" class="max-w-lg rounded-lg shadow-lg">
                    </div>
                    @else
                    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl animate-fade-in-up" style="animation-delay: 0.5s;">
                        <p class="text-amber-600 dark:text-amber-400 text-sm flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Solution Image doesn't exist yet - we will add it soon!
                        </p>
                    </div>
                    @endif

                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.6s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Explanation</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $examQuestion->explanation ?? 'No explanation available.' }}</p>
                    </div>

                    @if($examQuestion->dynamic_view_link)
                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.65s;">
                        <a href="{{ $examQuestion->dynamic_view_link }}" target="_blank" class="inline-flex items-center p-4 bg-gradient-to-r from-green-50 to-emerald-100 dark:from-gray-700 dark:to-gray-600 rounded-xl hover:shadow-lg transition-all duration-200 group">
                            <div class="w-12 h-12 rounded-lg bg-gradient-to-r from-green-500 to-emerald-500 flex items-center justify-center text-white mr-4 group-hover:scale-110 transition-transform duration-200">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-gray-100">Google Dynamic View</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Open interactive explanation in new tab</p>
                            </div>
                        </a>
                    </div>
                    @else
                    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl animate-fade-in-up" style="animation-delay: 0.65s;">
                        <div class="flex items-center">
                            <div class="w-12 h-12 rounded-lg bg-amber-400 flex items-center justify-center text-white mr-4">🔗</div>
                            <div>
                                <p class="font-semibold text-amber-700 dark:text-amber-400">Google Dynamic View</p>
                                <p class="text-sm text-amber-600 dark:text-amber-500">Link doesn't exist yet - we will add it soon!</p>
                            </div>
                        </div>
                    </div>
                    @endif

                    @if(Auth::check() && Auth::user()->role !== 'student')
                    <div class="mt-6 pt-6 border-t dark:border-gray-700 flex space-x-4 animate-fade-in-up" style="animation-delay: 0.7s;">
                        <a href="{{ route('exam_questions.edit', $examQuestion) }}" class="inline-flex items-center px-4 py-2 bg-yellow-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-600 transition ease-in-out duration-150">Edit</a>
                        <form action="{{ route('exam_questions.destroy', $examQuestion) }}" method="POST" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" onclick="return confirm('Are you sure?')" class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 transition ease-in-out duration-150">Delete</button>
                        </form>
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
                        const span = button.querySelector('span');
                        if (data.is_loved) {
                            button.classList.remove('border-gray-300', 'text-gray-400', 'hover:bg-gray-50');
                            button.classList.add('border-red-300', 'text-red-500', 'bg-red-50');
                            svg.setAttribute('fill', 'currentColor');
                            span.textContent = 'Loved';
                        } else {
                            button.classList.remove('border-red-300', 'text-red-500', 'bg-red-50');
                            button.classList.add('border-gray-300', 'text-gray-400', 'hover:bg-gray-50');
                            svg.setAttribute('fill', 'none');
                            span.textContent = 'Love';
                        }
                    }
                });
        }
    </script>
    @endauth
</x-app-layout>