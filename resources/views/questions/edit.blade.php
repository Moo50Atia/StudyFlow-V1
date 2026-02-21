<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight animate-fade-in">{{ __('Edit Question') }}</h2>
            <a href="{{ route('questions.index') }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 transition-colors duration-200">
                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Questions
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl animate-fade-in-up">
                <div class="p-8">
                    <form action="{{ route('questions.update', $question) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.1s">
                            <label for="lecture_id" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Lecture *</label>
                            <select name="lecture_id" id="lecture_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                                <option value="">-- Select a lecture --</option>
                                @foreach($lectures as $lecture)
                                <option value="{{ $lecture->id }}" {{ old('lecture_id', $question->lecture_id) == $lecture->id ? 'selected' : '' }}>{{ $lecture->title }} ({{ $lecture->subject->name ?? 'N/A' }})</option>
                                @endforeach
                            </select>
                            @error('lecture_id')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.15s">
                            <label for="question_text" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Question Text</label>
                            <textarea name="question_text" id="question_text" rows="3" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">{{ old('question_text', $question->question_text) }}</textarea>
                            @error('question_text')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.2s">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Current Question Image</label>
                            @if($question->question_image)
                            <img src="{{ Storage::url($question->question_image) }}" alt="Question" class="w-full max-w-md rounded-xl mb-4 shadow-lg">
                            @else
                            <p class="text-gray-500 dark:text-gray-400 mb-4">No image uploaded</p>
                            @endif
                            <label for="question_image" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Replace Image</label>
                            <input type="file" name="question_image" id="question_image" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                            @error('question_image')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.3s">
                            <label for="idea_text" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Idea</label>
                            <textarea name="idea_text" id="idea_text" rows="3" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">{{ old('idea_text', $question->idea_text) }}</textarea>
                            @error('idea_text')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.35s">
                            <label for="hint" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Hint</label>
                            <textarea name="hint" id="hint" rows="2" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">{{ old('hint', $question->hint) }}</textarea>
                            @error('hint')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.4s">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Solution Images</label>
                            @if($question->solution_images && count($question->solution_images) > 0)
                            <div class="grid grid-cols-4 gap-2 mb-4">
                                @foreach($question->solution_images as $index => $image)
                                <div class="relative group">
                                    <img src="{{ Storage::url($image) }}" alt="Solution {{ $index + 1 }}" class="w-full aspect-square object-cover rounded-lg shadow-sm">
                                    <div class="absolute top-1 left-1 bg-indigo-600 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full">{{ $index + 1 }}</div>
                                </div>
                                @endforeach
                            </div>
                            <p class="text-sm text-amber-600 dark:text-amber-400 mb-4 bg-amber-50 dark:bg-amber-900/20 p-2 rounded-lg">
                                Tip: Uploading new images will replace all existing ones.
                            </p>
                            @else
                            <p class="text-gray-500 dark:text-gray-400 mb-4">No solution images uploaded</p>
                            @endif

                            <div id="solutions-drop-zone" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-xl hover:border-green-500 transition-colors duration-200 cursor-pointer">
                                <div class="space-y-1 text-center">
                                    <svg id="solutions-icon" class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                        <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <div class="flex text-sm text-gray-600 dark:text-gray-400 justify-center">
                                        <label for="solution_images" class="relative cursor-pointer rounded-md font-medium text-green-600 hover:text-green-500">
                                            <span>Upload solutions</span>
                                            <input id="solution_images" name="solution_images[]" type="file" accept="image/*" multiple class="sr-only">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">PNG, JPG up to 5MB each (max 10 images)</p>
                                    <p id="solutions-file-name" class="text-sm font-medium text-green-600 dark:text-green-400 hidden"></p>
                                </div>
                            </div>
                            @error('solution_images')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                            @error('solution_images.*')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.5s">
                            <label for="explanation" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Explanation</label>
                            <textarea name="explanation" id="explanation" rows="4" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">{{ old('explanation', $question->explanation) }}</textarea>
                            @error('explanation')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-8 animate-fade-in-up" style="animation-delay: 0.6s">
                            <label for="dynamic_view_link" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Dynamic View Link</label>
                            <input type="url" name="dynamic_view_link" id="dynamic_view_link" value="{{ old('dynamic_view_link', $question->dynamic_view_link) }}" placeholder="https://" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                            @error('dynamic_view_link')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="animate-fade-in-up" style="animation-delay: 0.7s">
                            <button type="submit" class="w-full inline-flex items-center justify-center px-6 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-xl font-bold text-lg hover:from-amber-600 hover:to-orange-600 transition-all duration-300 transform hover:scale-[1.02] shadow-xl">
                                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Update Question
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setupDragDrop('solutions-drop-zone', 'solution_images', 'solutions-file-name', 'solutions-icon', true);

            function setupDragDrop(dropZoneId, inputId, fileNameId, iconId, multiple) {
                const dropZone = document.getElementById(dropZoneId);
                const fileInput = document.getElementById(inputId);
                const fileName = document.getElementById(fileNameId);
                const icon = document.getElementById(iconId);

                if (!dropZone || !fileInput) return;

                dropZone.addEventListener('click', function(e) {
                    if (e.target !== fileInput) {
                        fileInput.click();
                    }
                });

                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, preventDefaults, false);
                });

                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                ['dragenter', 'dragover'].forEach(eventName => {
                    dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
                });

                dropZone.addEventListener('drop', function(e) {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    if (files.length > 0) {
                        const imageFiles = Array.from(files).filter(f => f.type.startsWith('image/'));
                        if (imageFiles.length > 0) {
                            const dataTransfer = new DataTransfer();
                            imageFiles.forEach(f => dataTransfer.items.add(f));
                            fileInput.files = dataTransfer.files;
                            updateFileName(multiple ? imageFiles.length + ' files selected' : imageFiles[0].name);
                        } else {
                            alert('Please upload image files only.');
                        }
                    }
                });

                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        updateFileName(multiple ? this.files.length + ' files selected' : this.files[0].name);
                    }
                });

                function updateFileName(name) {
                    if (fileName) {
                        fileName.textContent = '✓ ' + name;
                        fileName.classList.remove('hidden');
                    }
                    if (icon) {
                        icon.classList.add('text-green-500');
                        icon.classList.remove('text-gray-400');
                    }
                }
            }
        });
    </script>
    <style>
        .drag-over {
            border-color: #10b981 !important;
            background-color: rgba(16, 185, 129, 0.1);
        }

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
    </style>
</x-app-layout>