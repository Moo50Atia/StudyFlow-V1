<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Question Details</h2>
            <div class="flex items-center space-x-4">
                @auth
                <button onclick="toggleLove('question', {{ $question->id }}, this)" class="love-btn inline-flex items-center px-3 py-2 border rounded-md transition-all duration-200 {{ auth()->user()->loves($question) ? 'border-red-300 text-red-500 bg-red-50' : 'border-gray-300 text-gray-400 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-1" fill="{{ auth()->user()->loves($question) ? 'currentColor' : 'none' }}" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                    </svg>
                    <span class="text-sm">{{ auth()->user()->loves($question) ? 'Loved' : 'Love' }}</span>
                </button>
                @endauth
                <a href="{{ route('questions.index') }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">← Back</a>
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
                            <p class="mt-1 text-gray-900 dark:text-gray-100">{{ $question->lecture->title ?? 'N/A' }}</p>
                        </div>
                        <div class="animate-fade-in-up" style="animation-delay: 0.2s;">
                            <h3 class="text-sm font-medium text-gray-500">Subject</h3>
                            <p class="mt-1 text-gray-900 dark:text-gray-100">{{ $question->lecture->subject->name ?? 'N/A' }}</p>
                        </div>
                    </div>

                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.25s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Question Text</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $question->question_text ?? 'No text available.' }}</p>
                    </div>

                    @if($question->question_image)
                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.3s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Question Image</h3>
                        <img src="{{ Storage::url($question->question_image) }}" alt="Question" class="max-w-lg rounded-lg shadow-lg">
                    </div>
                    @else
                    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl animate-fade-in-up" style="animation-delay: 0.3s;">
                        <p class="text-amber-600 dark:text-amber-400 text-sm flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Question Image doesn't exist yet
                        </p>
                    </div>
                    @endif

                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.4s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Idea</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $question->idea_text ?? 'No idea available.' }}</p>
                    </div>

                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.45s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Hint</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $question->hint ?? 'No hint available.' }}</p>
                    </div>

                    @if($question->solution_images && count($question->solution_images) > 0)
                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.5s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Solution Images</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach($question->solution_images as $index => $image)
                            <div class="relative group">
                                <img src="{{ Storage::url($image) }}" alt="Solution {{ $index + 1 }}" class="w-full rounded-lg shadow-lg cursor-pointer hover:shadow-xl transition-shadow duration-200" onclick="openImageModal(this.src)">
                                <div class="absolute top-2 left-2 bg-indigo-600 text-white text-xs font-bold px-2 py-1 rounded-full">{{ $index + 1 }}</div>
                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 rounded-lg transition-all duration-200 flex items-center justify-center">
                                    <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                    </svg>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @else
                    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl animate-fade-in-up" style="animation-delay: 0.5s;">
                        <p class="text-amber-600 dark:text-amber-400 text-sm flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            Solution Images don't exist yet - we will add them soon!
                        </p>
                    </div>
                    @endif

                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.6s;">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Solution Explanation</h3>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $question->solution_explanation ?? 'No explanation available.' }}</p>
                    </div>

                    @if($question->dynamic_view_link)
                    <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.65s;">
                        <a href="{{ $question->dynamic_view_link }}" target="_blank" class="inline-flex items-center p-4 bg-gradient-to-r from-green-50 to-emerald-100 dark:from-gray-700 dark:to-gray-600 rounded-xl hover:shadow-lg transition-all duration-200 group">
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
                        <a href="{{ route('questions.edit', $question) }}" class="inline-flex items-center px-4 py-2 bg-yellow-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-600 transition ease-in-out duration-150">Edit</a>
                        <form action="{{ route('questions.destroy', $question) }}" method="POST" class="inline">
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

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black bg-opacity-80" onclick="closeImageModal()">
        <button class="absolute top-4 right-4 text-white text-4xl font-bold hover:text-gray-300" onclick="closeImageModal()">&times;</button>
        <img id="modalImage" src="" alt="Full size" class="max-w-[90vw] max-h-[90vh] rounded-lg shadow-2xl" onclick="event.stopPropagation()">
    </div>

    <script>
        function openImageModal(src) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = src;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeImageModal();
        });
    </script>
</x-app-layout>