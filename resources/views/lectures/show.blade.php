<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight animate-fade-in">{{ $lecture->title }}</h2>
            <div class="flex items-center space-x-3">
                @if(session('study_flow'))
                <a href="{{ route('study-flow.exit') }}" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white rounded-lg font-semibold text-xs uppercase tracking-widest hover:bg-gray-600 transition-all duration-200">
                    Exit Flow
                </a>
                @endif
                <a href="{{ route('lectures.index') }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 transition-colors duration-200">
                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Lectures
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

            <!-- Lecture Info Card -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl mb-8 animate-fade-in-up">
                <div class="p-8">
                    <div class="flex items-center mb-6">
                        <div class="w-16 h-16 rounded-xl bg-gradient-to-r from-purple-500 to-pink-500 flex items-center justify-center text-white text-3xl shadow-lg">
                            🎬
                        </div>
                        <div class="ml-6">
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $lecture->title }}</h3>
                            <p class="text-gray-500 dark:text-gray-400">{{ $lecture->subject->name ?? 'N/A' }} • {{ $lecture->sections->count() }} Sections</p>
                        </div>
                        @if(Auth::check() && Auth::user()->role === 'admin')
                        <div class="ml-auto">
                            <a href="{{ route('sections.create', ['lecture_id' => $lecture->id]) }}" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl font-semibold text-sm hover:from-indigo-700 hover:to-purple-700 transition-all duration-300 transform hover:scale-105 shadow-lg">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Add Section
                            </a>
                        </div>
                        @endif
                    </div>
                    <p class="text-gray-600 dark:text-gray-400">{{ $lecture->summary ?? 'No summary available' }}</p>

                    @if($lecture->pdf_path)
                    <div class="mt-4">
                        <a href="{{ Storage::url($lecture->pdf_path) }}" target="_blank" class="inline-flex items-center px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition-colors">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd" />
                            </svg>
                            View PDF
                        </a>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Mind Map Viewer (Konva.js Canvas) -->
            @if($lecture->mindmap_path)
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl mb-8 animate-fade-in-up" style="animation-delay: 0.1s">
                <div class="px-6 py-4 bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-gray-700 dark:to-gray-800 border-b dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <h4 class="text-lg font-semibold text-gray-800 dark:text-gray-200 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                            </svg>
                            Mind Map
                        </h4>
                        <!-- Controls -->
                        <div class="flex items-center gap-2">
                            <button id="zoom-in-btn" class="p-2 bg-emerald-100 dark:bg-emerald-900 text-emerald-700 dark:text-emerald-300 rounded-lg hover:bg-emerald-200 dark:hover:bg-emerald-800 transition-colors" title="Zoom In">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                </svg>
                            </button>
                            <button id="zoom-out-btn" class="p-2 bg-emerald-100 dark:bg-emerald-900 text-emerald-700 dark:text-emerald-300 rounded-lg hover:bg-emerald-200 dark:hover:bg-emerald-800 transition-colors" title="Zoom Out">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7" />
                                </svg>
                            </button>
                            <button id="reset-btn" class="p-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors" title="Reset View">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                            <span id="zoom-level" class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-mono">100%</span>
                            <a href="{{ Storage::url($lecture->mindmap_path) }}" target="_blank" class="p-2 bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 rounded-lg hover:bg-indigo-200 dark:hover:bg-indigo-800 transition-colors" title="Open Full Size">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">🖱️ Scroll to zoom • Drag to pan • Works on mobile with pinch & drag</p>
                </div>
                <div class="p-4">
                    <!-- Konva Canvas Container -->
                    <div id="mindmap-canvas" class="w-full rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700" style="height: 70vh;"></div>
                </div>
            </div>
            @endif

            <!-- Study Flow: Bulk Section Creation -->
            @if(session('study_flow') && Auth::check() && Auth::user()->role === 'admin')
            <div class="bg-gradient-to-r from-pink-50 to-purple-50 dark:from-gray-800 dark:to-gray-700 rounded-2xl shadow-xl p-6 mb-8 animate-fade-in-up" style="animation-delay: 0.15s">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-gray-900 dark:text-gray-100">📑 Bulk Create Sections</h4>
                        <p class="text-gray-600 dark:text-gray-400">Add multiple sections at once</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Number of sections:</label>
                        <input type="number" id="section-count" min="1" max="10" value="1" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <button type="button" onclick="generateSectionForms()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                            Generate
                        </button>
                    </div>
                </div>
                <form action="{{ route('study-flow.bulk-sections', $lecture) }}" method="POST" id="bulk-sections-form">
                    @csrf
                    <div id="section-forms-container" class="space-y-4"></div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" id="submit-sections-btn" class="hidden inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-xl font-bold hover:from-green-600 hover:to-emerald-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Create All Sections
                        </button>
                    </div>
                </form>
            </div>
            @endif

            <!-- Teacher Study Flow: Bulk Section Creation -->
            @if(session('study_flow') && Auth::check() && Auth::user()->role === 'teacher')
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-2xl shadow-xl p-6 mb-8 animate-fade-in-up" style="animation-delay: 0.15s">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h4 class="text-xl font-bold text-gray-900 dark:text-gray-100">📑 Add Sections (Teacher Flow)</h4>
                        <p class="text-gray-600 dark:text-gray-400">Create multiple sections at once</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <a href="{{ route('teacher-study-flow.exit') }}" class="px-4 py-2 bg-gray-500 text-white rounded-lg text-sm hover:bg-gray-600 transition-colors">Exit Flow</a>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Number of sections:</label>
                        <input type="number" id="teacher-section-count" min="1" max="10" value="1" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                        <button type="button" onclick="generateTeacherSectionForms()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                            Generate
                        </button>
                    </div>
                </div>
                <form action="{{ route('teacher-study-flow.bulk-sections', $lecture) }}" method="POST" id="teacher-bulk-sections-form">
                    @csrf
                    <div id="teacher-section-forms-container" class="space-y-4"></div>
                    <div class="mt-6 flex justify-end">
                        <button type="submit" id="teacher-submit-sections-btn" class="hidden inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-xl font-bold hover:from-green-600 hover:to-emerald-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Create All Sections
                        </button>
                    </div>
                </form>
            </div>
            @endif

            <!-- Existing Sections List -->
            @if($lecture->sections->count() > 0)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl overflow-hidden animate-fade-in-up" style="animation-delay: 0.2s">
                <div class="px-6 py-4 bg-gradient-to-r from-purple-50 to-pink-50 dark:from-gray-700 dark:to-gray-800 border-b dark:border-gray-700">
                    <h4 class="text-lg font-semibold text-gray-800 dark:text-gray-200">📑 Sections in this Lecture</h4>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($lecture->sections as $index => $section)
                        <a href="{{ route('sections.show', $section) }}" class="block p-4 bg-gray-50 dark:bg-gray-700 rounded-xl hover:shadow-lg transition-all duration-200 hover:-translate-y-0.5 animate-fade-in-up" style="animation-delay: {{ 0.3 + ($index * 0.05) }}s">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $section->title }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ Str::limit($section->quick_summary, 50) }}</p>
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <p class="text-lg font-medium text-gray-400">No sections yet</p>
                <p class="text-sm text-gray-400 mt-2">Add your first section to get started!</p>
            </div>
            @endif

            @if(Auth::check() && Auth::user()->role !== 'student')
            <div class="flex space-x-4 mt-8 animate-fade-in-up" style="animation-delay: 0.4s">
                <a href="{{ route('lectures.edit', $lecture) }}" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-xl font-semibold hover:from-amber-600 hover:to-orange-600 transition-all duration-300 transform hover:scale-105 shadow-lg">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Edit Lecture
                </a>
                <form action="{{ route('lectures.destroy', $lecture) }}" method="POST" class="inline">
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

        #mindmap-canvas {
            touch-action: none;
        }
    </style>

    <!-- Konva.js Library -->
    <script src="https://unpkg.com/konva@9/konva.min.js"></script>

    <script>
        // Study Flow bulk section forms
        function generateSectionForms() {
            const count = parseInt(document.getElementById('section-count').value) || 1;
            const container = document.getElementById('section-forms-container');
            const submitBtn = document.getElementById('submit-sections-btn');
            container.innerHTML = '';

            for (let i = 0; i < count; i++) {
                container.innerHTML += `
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-600">
                        <div class="flex items-center mb-3">
                            <span class="w-8 h-8 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center font-bold text-sm">${i + 1}</span>
                            <span class="ml-3 font-medium text-gray-700 dark:text-gray-300">Section ${i + 1}</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title *</label>
                                <input type="text" name="sections[${i}][title]" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quick Summary</label>
                                <input type="text" name="sections[${i}][quick_summary]" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Core Concept (Key Takeaway)</label>
                                <textarea name="sections[${i}][core_concept]" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="The primary takeaway..."></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Egyptian Explanation (بالمصري)</label>
                                <textarea name="sections[${i}][egyptian_explain]" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm text-right" dir="rtl" placeholder="شرح مبسط بالعامية المصرية..."></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Real Life Examples (أمثلة من الحياة)</label>
                                <textarea name="sections[${i}][real_life]" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm text-right" dir="rtl" placeholder="أمثلة واقعية للموضوع..."></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dynamic View Link</label>
                                <input type="url" name="sections[${i}][dynamic_view_link]" placeholder="https://" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>
                `;
            }

            submitBtn.classList.remove('hidden');
            submitBtn.classList.add('inline-flex');
        }

        // Teacher Study Flow bulk section forms
        function generateTeacherSectionForms() {
            const count = parseInt(document.getElementById('teacher-section-count').value) || 1;
            const container = document.getElementById('teacher-section-forms-container');
            const submitBtn = document.getElementById('teacher-submit-sections-btn');
            container.innerHTML = '';

            for (let i = 0; i < count; i++) {
                container.innerHTML += `
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-600">
                        <div class="flex items-center mb-3">
                            <span class="w-8 h-8 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center font-bold text-sm">${i + 1}</span>
                            <span class="ml-3 font-medium text-gray-700 dark:text-gray-300">Section ${i + 1}</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title *</label>
                                <input type="text" name="sections[${i}][title]" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quick Summary</label>
                                <input type="text" name="sections[${i}][quick_summary]" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Core Concept</label>
                                <textarea name="sections[${i}][core_concept]" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" placeholder="Key takeaway..."></textarea>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Dynamic View Link</label>
                                <input type="url" name="sections[${i}][dynamic_view_link]" placeholder="https://" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>
                `;
            }

            submitBtn.classList.remove('hidden');
            submitBtn.classList.add('inline-flex');
        }

        // Konva.js Mind Map Viewer
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('mindmap-canvas');
            if (!container) return;

            const containerWidth = container.offsetWidth;
            const containerHeight = container.offsetHeight;

            // Create Konva Stage
            const stage = new Konva.Stage({
                container: 'mindmap-canvas',
                width: containerWidth,
                height: containerHeight,
                draggable: true
            });

            const layer = new Konva.Layer();
            stage.add(layer);

            // Zoom configuration
            const minScale = 0.1;
            const maxScale = 20;
            const scaleBy = 1.15;
            let currentScale = 1;

            // Load image at full resolution
            const imageUrl = "{{ Storage::url($lecture->mindmap_path ?? '') }}";
            const imageObj = new Image();
            imageObj.crossOrigin = 'anonymous';

            imageObj.onload = function() {
                const konvaImage = new Konva.Image({
                    x: 0,
                    y: 0,
                    image: imageObj,
                    width: imageObj.width,
                    height: imageObj.height
                });

                layer.add(konvaImage);

                // Fit image to container initially
                const scaleX = containerWidth / imageObj.width;
                const scaleY = containerHeight / imageObj.height;
                const initialScale = Math.min(scaleX, scaleY) * 0.95;

                currentScale = initialScale;
                stage.scale({
                    x: initialScale,
                    y: initialScale
                });

                // Center the image
                const newWidth = imageObj.width * initialScale;
                const newHeight = imageObj.height * initialScale;
                stage.position({
                    x: (containerWidth - newWidth) / 2,
                    y: (containerHeight - newHeight) / 2
                });

                layer.batchDraw();
                updateZoomDisplay();
            };

            imageObj.src = imageUrl;

            // Mouse wheel zoom (centered on cursor)
            container.addEventListener('wheel', function(e) {
                e.preventDefault();

                const oldScale = stage.scaleX();
                const pointer = stage.getPointerPosition();

                const mousePointTo = {
                    x: (pointer.x - stage.x()) / oldScale,
                    y: (pointer.y - stage.y()) / oldScale
                };

                const direction = e.deltaY > 0 ? -1 : 1;
                let newScale = direction > 0 ? oldScale * scaleBy : oldScale / scaleBy;

                // Clamp scale
                newScale = Math.max(minScale, Math.min(maxScale, newScale));
                currentScale = newScale;

                stage.scale({
                    x: newScale,
                    y: newScale
                });

                const newPos = {
                    x: pointer.x - mousePointTo.x * newScale,
                    y: pointer.y - mousePointTo.y * newScale
                };

                stage.position(newPos);
                layer.batchDraw();
                updateZoomDisplay();
            });

            // Touch pinch zoom
            let lastCenter = null;
            let lastDist = 0;

            stage.on('touchmove', function(e) {
                const touch1 = e.evt.touches[0];
                const touch2 = e.evt.touches[1];

                if (touch1 && touch2) {
                    e.evt.preventDefault();

                    // Calculate distance between touches
                    const dist = Math.sqrt(
                        Math.pow(touch2.clientX - touch1.clientX, 2) +
                        Math.pow(touch2.clientY - touch1.clientY, 2)
                    );

                    // Calculate center point
                    const center = {
                        x: (touch1.clientX + touch2.clientX) / 2,
                        y: (touch1.clientY + touch2.clientY) / 2
                    };

                    if (!lastCenter) {
                        lastCenter = center;
                        lastDist = dist;
                        return;
                    }

                    const oldScale = stage.scaleX();
                    const pointTo = {
                        x: (center.x - stage.x()) / oldScale,
                        y: (center.y - stage.y()) / oldScale
                    };

                    let newScale = oldScale * (dist / lastDist);
                    newScale = Math.max(minScale, Math.min(maxScale, newScale));
                    currentScale = newScale;

                    stage.scale({
                        x: newScale,
                        y: newScale
                    });

                    const dx = center.x - lastCenter.x;
                    const dy = center.y - lastCenter.y;

                    const newPos = {
                        x: center.x - pointTo.x * newScale + dx,
                        y: center.y - pointTo.y * newScale + dy
                    };

                    stage.position(newPos);
                    layer.batchDraw();
                    updateZoomDisplay();

                    lastDist = dist;
                    lastCenter = center;
                }
            });

            stage.on('touchend', function() {
                lastCenter = null;
                lastDist = 0;
            });

            // Control buttons
            document.getElementById('zoom-in-btn').addEventListener('click', function() {
                const oldScale = stage.scaleX();
                let newScale = oldScale * scaleBy;
                newScale = Math.min(maxScale, newScale);
                currentScale = newScale;

                const center = {
                    x: containerWidth / 2,
                    y: containerHeight / 2
                };

                const pointTo = {
                    x: (center.x - stage.x()) / oldScale,
                    y: (center.y - stage.y()) / oldScale
                };

                stage.scale({
                    x: newScale,
                    y: newScale
                });
                stage.position({
                    x: center.x - pointTo.x * newScale,
                    y: center.y - pointTo.y * newScale
                });

                layer.batchDraw();
                updateZoomDisplay();
            });

            document.getElementById('zoom-out-btn').addEventListener('click', function() {
                const oldScale = stage.scaleX();
                let newScale = oldScale / scaleBy;
                newScale = Math.max(minScale, newScale);
                currentScale = newScale;

                const center = {
                    x: containerWidth / 2,
                    y: containerHeight / 2
                };

                const pointTo = {
                    x: (center.x - stage.x()) / oldScale,
                    y: (center.y - stage.y()) / oldScale
                };

                stage.scale({
                    x: newScale,
                    y: newScale
                });
                stage.position({
                    x: center.x - pointTo.x * newScale,
                    y: center.y - pointTo.y * newScale
                });

                layer.batchDraw();
                updateZoomDisplay();
            });

            document.getElementById('reset-btn').addEventListener('click', function() {
                if (!imageObj.width) return;

                const scaleX = containerWidth / imageObj.width;
                const scaleY = containerHeight / imageObj.height;
                const initialScale = Math.min(scaleX, scaleY) * 0.95;

                currentScale = initialScale;
                stage.scale({
                    x: initialScale,
                    y: initialScale
                });

                const newWidth = imageObj.width * initialScale;
                const newHeight = imageObj.height * initialScale;
                stage.position({
                    x: (containerWidth - newWidth) / 2,
                    y: (containerHeight - newHeight) / 2
                });

                layer.batchDraw();
                updateZoomDisplay();
            });

            function updateZoomDisplay() {
                const zoomLevel = document.getElementById('zoom-level');
                if (zoomLevel) {
                    zoomLevel.textContent = Math.round(currentScale * 100) + '%';
                }
            }

            // Handle window resize
            window.addEventListener('resize', function() {
                const newWidth = container.offsetWidth;
                const newHeight = container.offsetHeight;
                stage.width(newWidth);
                stage.height(newHeight);
                layer.batchDraw();
            });
        });
    </script>
</x-app-layout>