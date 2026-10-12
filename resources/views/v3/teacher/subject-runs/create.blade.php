<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Upload Curriculum PDFs — ') }} {{ $subject->name }}
            </h2>
            <a href="{{ route('subjects.show', $subject) }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                &larr; Back to Subject
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8">
                <form action="{{ route('v3.teacher.subject-runs.store', $subject) }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                    <input type="hidden" name="mode" value="export_manual">
                    <input type="hidden" name="qualification_mode" value="real_jev">
                    <p class="rounded-lg bg-indigo-50 p-4 text-sm text-indigo-900">NotebookLM will prepare lesson content. Scene prompts are exported for manual review; no paid scene generation is started.</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">The web uploader accepts 1–20 PDFs per run. A 1,000-page prepared source can produce 32 parts, so larger web batches require a separate upload flow.</p>

                    <!-- PDF Uploads -->
                    <div>
                        <div class="flex justify-between items-center mb-3">
                            <label class="block text-sm font-bold text-gray-900 dark:text-gray-100">Ordered PDFs & Display Titles (Max 20)</label>
                            <button type="button" onclick="addPdfRow()" class="text-xs bg-indigo-50 text-indigo-700 px-3 py-1.5 rounded-lg hover:bg-indigo-100 font-semibold">
                                + Add Another PDF
                            </button>
                        </div>
                        <div id="pdf-rows-container" class="space-y-3">
                            <div class="pdf-row p-4 bg-gray-50 dark:bg-gray-700 rounded-xl grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Display Title *</label>
                                    <input type="text" name="titles[]" required placeholder="e.g. Chapter 1: Kinematics" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">PDF File *</label>
                                    <input type="file" name="files[]" required accept=".pdf" class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 flex justify-end space-x-3">
                        <a href="{{ route('subjects.show', $subject) }}" class="px-5 py-2.5 rounded-xl border border-gray-300 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-50">
                            Cancel
                        </a>
                        <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl text-sm font-bold shadow-lg hover:from-indigo-700 hover:to-purple-700">
                            Start Processing Run
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function addPdfRow() {
            const container = document.getElementById('pdf-rows-container');
            const rowCount = container.getElementsByClassName('pdf-row').length;
            if (rowCount >= 20) {
                alert('Maximum 20 PDFs per batch run.');
                return;
            }
            const div = document.createElement('div');
            div.className = 'pdf-row p-4 bg-gray-50 dark:bg-gray-700 rounded-xl grid grid-cols-1 md:grid-cols-2 gap-4 items-center';
            div.innerHTML = `
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Display Title *</label>
                    <input type="text" name="titles[]" required placeholder="e.g. Chapter ${rowCount + 1}" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">PDF File *</label>
                    <input type="file" name="files[]" required accept=".pdf" class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                </div>
            `;
            container.appendChild(div);
        }
    </script>
</x-app-layout>
