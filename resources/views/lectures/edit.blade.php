<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight animate-fade-in">{{ __('Edit Lecture') }}</h2>
            <a href="{{ route('lectures.index') }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 transition-colors duration-200">
                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Lectures
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl animate-fade-in-up">
                <div class="p-8">
                    <form action="{{ route('lectures.update', $lecture) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.1s">
                            <label for="subject_id" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Subject *</label>
                            <select name="subject_id" id="subject_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                                <option value="">-- Select a subject --</option>
                                @foreach($subjects as $subject)
                                <option value="{{ $subject->id }}" {{ old('subject_id', $lecture->subject_id) == $subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>
                                @endforeach
                            </select>
                            @error('subject_id')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.2s">
                            <label for="title" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Title *</label>
                            <input type="text" name="title" id="title" value="{{ old('title', $lecture->title) }}" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                            @error('title')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <!-- PDF Section -->
                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.3s">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">PDF Document</label>
                            @if($lecture->pdf_path)
                            <div class="flex items-center p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-xl mb-4">
                                <svg class="w-8 h-8 text-red-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <span class="text-gray-700 dark:text-gray-300 font-medium">PDF uploaded</span>
                                    <a href="{{ Storage::url($lecture->pdf_path) }}" target="_blank" class="block text-sm text-indigo-600 hover:text-indigo-800">View PDF →</a>
                                </div>
                            </div>
                            @else
                            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl mb-4">
                                <p class="text-amber-600 dark:text-amber-400 text-sm">No PDF uploaded yet</p>
                            </div>
                            @endif
                            <div id="pdf-drop-zone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-xl hover:border-indigo-500 transition-colors duration-200 cursor-pointer">
                                <div class="space-y-1 text-center">
                                    <svg id="pdf-icon" class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                    <div class="flex text-sm text-gray-600 dark:text-gray-400 justify-center">
                                        <label for="pdf_path" class="relative cursor-pointer rounded-md font-medium text-indigo-600 hover:text-indigo-500">
                                            <span>{{ $lecture->pdf_path ? 'Replace PDF' : 'Upload PDF' }}</span>
                                            <input id="pdf_path" name="pdf_path" type="file" accept=".pdf,application/pdf" class="sr-only">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">PDF up to 10MB</p>
                                    <p id="pdf-file-name" class="text-sm font-medium text-green-600 dark:text-green-400 hidden"></p>
                                </div>
                            </div>
                            @error('pdf_path')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        @if(app(\App\Services\LearningAccess::class)->isOwner(auth()->user()))
                        <!-- Mind Map Section -->
                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.35s">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Mind Map Image</label>
                            @if($lecture->mindmap_path)
                            <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-xl">
                                <div class="flex items-center mb-3">
                                    <svg class="w-8 h-8 text-emerald-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                    <span class="text-gray-700 dark:text-gray-300 font-medium">Mind Map uploaded</span>
                                </div>
                                <img src="{{ route('owner-assets.show', ['kind' => 'lecture', 'id' => $lecture->id, 'field' => 'mindmap_path']) }}" alt="Mind Map Preview" class="w-full max-h-40 object-contain rounded-lg">
                            </div>
                            @else
                            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl mb-4">
                                <p class="text-amber-600 dark:text-amber-400 text-sm">No mind map uploaded yet</p>
                            </div>
                            @endif
                            <div id="mindmap-drop-zone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-xl hover:border-emerald-500 transition-colors duration-200 cursor-pointer">
                                <div class="space-y-1 text-center">
                                    <svg id="mindmap-icon" class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                    <div class="flex text-sm text-gray-600 dark:text-gray-400 justify-center">
                                        <label for="mindmap_path" class="relative cursor-pointer rounded-md font-medium text-emerald-600 hover:text-emerald-500">
                                            <span>{{ $lecture->mindmap_path ? 'Replace Mind Map' : 'Upload Mind Map' }}</span>
                                            <input id="mindmap_path" name="mindmap_path" type="file" accept="image/png" class="sr-only">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">PNG up to 15MB</p>
                                    <p id="mindmap-file-name" class="text-sm font-medium text-green-600 dark:text-green-400 hidden"></p>
                                </div>
                            </div>
                            @error('mindmap_path')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        @endif
                        <div class="mb-8 animate-fade-in-up" style="animation-delay: 0.4s">
                            <label for="summary" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Summary</label>
                            <textarea name="summary" id="summary" rows="4" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">{{ old('summary', $lecture->summary) }}</textarea>
                            @error('summary')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="animate-fade-in-up" style="animation-delay: 0.5s">
                            <button type="submit" class="w-full inline-flex items-center justify-center px-6 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-xl font-bold text-lg hover:from-amber-600 hover:to-orange-600 transition-all duration-300 transform hover:scale-[1.02] shadow-xl">
                                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Update Lecture
                            </button>
                        </div>
                    </form>
                </div>
            </div>
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

        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
            opacity: 0;
        }

        .drag-over {
            border-color: #10b981 !important;
            background-color: rgba(16, 185, 129, 0.1);
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setupDropZone('pdf-drop-zone', 'pdf_path', 'pdf-file-name', 'pdf-icon', ['application/pdf'], '.pdf');
            setupDropZone('mindmap-drop-zone', 'mindmap_path', 'mindmap-file-name', 'mindmap-icon', ['image/png'], '.png');

            function setupDropZone(zoneId, inputId, fileNameId, iconId, validTypes, validExt) {
                const dropZone = document.getElementById(zoneId);
                const fileInput = document.getElementById(inputId);
                const fileName = document.getElementById(fileNameId);
                const icon = document.getElementById(iconId);

                if (!dropZone || !fileInput) return;

                dropZone.addEventListener('click', function(e) {
                    if (e.target !== fileInput) fileInput.click();
                });

                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, e => {
                        e.preventDefault();
                        e.stopPropagation();
                    }, false);
                });

                ['dragenter', 'dragover'].forEach(eventName => {
                    dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
                });

                dropZone.addEventListener('drop', function(e) {
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        const file = files[0];
                        if (validTypes.includes(file.type) || file.name.toLowerCase().endsWith(validExt)) {
                            fileInput.files = files;
                            updateFileName(file.name);
                        } else {
                            alert('Please upload a valid ' + validExt.toUpperCase() + ' file.');
                        }
                    }
                });

                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) updateFileName(this.files[0].name);
                });

                function updateFileName(name) {
                    fileName.textContent = '✓ ' + name;
                    fileName.classList.remove('hidden');
                    icon.classList.add('text-green-500');
                    icon.classList.remove('text-gray-400');
                }
            }
        });
    </script>
</x-app-layout>