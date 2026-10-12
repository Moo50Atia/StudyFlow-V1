<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ __('Scene Management & Authoring') }}
                </h2>
                <div class="text-xs text-gray-500 mt-1">
                    Run ID: <span class="font-mono">{{ $run->id }}</span>
                </div>
            </div>
            <div class="flex items-center space-x-3">
                <a href="{{ route('v3.teacher.subject-runs.show', $run) }}" class="inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-xs font-semibold">
                    &larr; Back to Run
                </a>
                <a href="{{ route('v3.teacher.subject-runs.prompt-export', $run) }}" target="_blank" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow">
                    Export Prompts (JSON)
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
            <div class="p-4 bg-green-50 dark:bg-green-900/40 border border-green-200 dark:border-green-800 rounded-xl text-sm text-green-800 dark:text-green-300">
                {{ session('status') }}
            </div>
            @endif

            <!-- Generation Mode and Provider Settings -->
            @if(config('services.v3_teacher_paid_scenes_enabled', false))
            <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-6">
                <h3 class="font-bold text-lg text-gray-900 dark:text-gray-100 mb-2">Generation Mode & Qualification</h3>
                <p class="text-xs text-gray-500 mb-6">
                    Export/Manual is the default. Switching to Selected API enables direct provider authoring under strict controls without hidden API calls.
                </p>

                <form method="POST" action="{{ route('v3.teacher.subject-runs.generation-mode', $run) }}" class="space-y-6">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Mode Selection -->
                        <div class="p-4 rounded-xl border {{ $run->mode === 'export_manual' ? 'border-indigo-500 bg-indigo-50/20 dark:bg-indigo-950/20' : 'border-gray-200 dark:border-gray-700' }}">
                            <label class="flex items-start space-x-3 cursor-pointer">
                                <input type="radio" name="mode" value="export_manual" class="mt-1 text-indigo-600 focus:ring-indigo-500" {{ $run->mode === 'export_manual' ? 'checked' : '' }}>
                                <div>
                                    <span class="block font-bold text-sm text-gray-900 dark:text-gray-100">Export / Manual (Default)</span>
                                    <span class="block text-xs text-gray-500 mt-1">
                                        Zero remote provider API calls. Export exact prompt text and import static 3-file scene packages manually.
                                    </span>
                                </div>
                            </label>
                        </div>

                        <div class="p-4 rounded-xl border {{ in_array($run->mode, ['selected_api', 'api_generate'], true) ? 'border-indigo-500 bg-indigo-50/20 dark:bg-indigo-950/20' : 'border-gray-200 dark:border-gray-700' }}">
                            <label class="flex items-start space-x-3 cursor-pointer">
                                <input type="radio" name="mode" value="selected_api" class="mt-1 text-indigo-600 focus:ring-indigo-500" {{ in_array($run->mode, ['selected_api', 'api_generate'], true) ? 'checked' : '' }}>
                                <div>
                                    <span class="block font-bold text-sm text-gray-900 dark:text-gray-100">Selected API Generation</span>
                                    <span class="block text-xs text-gray-500 mt-1">
                                        Use approved model provider for controlled scene code generation with explicit thinking parameters.
                                    </span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- API Provider Options (Visible if selected_api) -->
                    <div class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-xl space-y-4">
                        <div class="text-xs font-bold text-gray-600 dark:text-gray-300 uppercase">Provider Configuration (Selected API Only)</div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Approved Provider</label>
                                <select name="provider_id" class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                    @foreach($catalog as $pId => $pMeta)
                                    <option value="{{ $pId }}" {{ ($currentSelection['provider']['id'] ?? '') === $pId ? 'selected' : '' }}>
                                        {{ strtoupper(str_replace('_api', '', $pId)) }} ({{ $pMeta['default_model'] }})
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Model Override (Optional)</label>
                                <input type="text" name="model" value="{{ $currentSelection['provider']['requested_model'] ?? '' }}" placeholder="Leave empty for default" class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">Thinking Budget (Gemini)</label>
                                <input type="number" name="thinking_budget" value="{{ $currentSelection['provider']['gemini_thinking_budget'] ?? 24576 }}" min="0" max="24576" class="w-full text-xs rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="text-xs text-gray-500">
                            Qualification Policy: <span class="font-semibold">{{ $run->qualification_mode === 'owner_waived_demo' ? 'Owner Waived Demo (Non-production)' : 'Real JEV Qualified' }}</span>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold shadow">
                            Save Generation Settings
                        </button>
                    </div>
                </form>
            </div>
            @endif

            <!-- Scenes List -->
            <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-gray-100">Run Scenes & Candidate Status</h3>
                        <div class="text-xs text-gray-500 mt-0.5">{{ count($scenes) }} total prompt section(s)</div>
                    </div>
                </div>

                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($scenes as $idx => $sc)
                    <div class="p-6 space-y-4">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div class="flex items-start space-x-3">
                                <span class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ $sc['section']['ordinal'] }}
                                </span>
                                <div>
                                    <h4 class="font-bold text-gray-900 dark:text-gray-100 text-sm">
                                        {{ $sc['item']['display_title'] }} &mdash; Section {{ $sc['section']['ordinal'] }}@if(!empty($sc['section']['section_identity'])): {{ $sc['section']['section_identity'] }}@endif
                                    </h4>
                                    <div class="text-xs text-gray-500 font-mono mt-0.5">
                                        Section ID: {{ $sc['section']['id'] }}
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center space-x-2">
                                <!-- Prompt Gate / Qualification Badge -->
                                @if($sc['prompt_gate'])
                                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                        Demo Waived ({{ $sc['prompt_gate']['policy_version'] }})
                                    </span>
                                @elseif($sc['prompt_qualification'] && $sc['prompt_qualification']['passed'])
                                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">
                                        JEV Qualified
                                    </span>
                                @else
                                    <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                        Unqualified / Pending
                                    </span>
                                @endif

                                <!-- Candidate State Badge -->
                                @if($sc['candidate_state'])
                                    @if($sc['candidate_state']['status'] === 'demo_review_ready')
                                        <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                            Demo Review Ready
                                        </span>
                                    @elseif($sc['candidate_state']['status'] === 'review_ready')
                                        <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">
                                            Review Ready
                                        </span>
                                    @elseif($sc['candidate_state']['status'] === 'checking')
                                        <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                                            Checking (Browser)
                                        </span>
                                    @elseif($sc['candidate_state']['status'] === 'repair_pending')
                                        <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300">
                                            Repair Pending
                                        </span>
                                    @else
                                        <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                            {{ ucfirst(str_replace('_', ' ', $sc['candidate_state']['status'])) }}
                                        </span>
                                    @endif
                                @else
                                    <span class="px-2.5 py-1 text-xs text-gray-400">
                                        No Candidate
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Prompt Preview Box -->
                        @if($sc['prompt'])
                        <div class="bg-gray-50 dark:bg-gray-900/50 rounded-xl p-3 border border-gray-200 dark:border-gray-700 text-xs">
                            <div class="flex justify-between items-center text-gray-500 mb-1">
                                <span class="font-semibold uppercase tracking-wider text-[10px]">Prompt Version: <span class="font-mono text-gray-700 dark:text-gray-300">{{ substr($sc['prompt']['id'], 0, 8) }}...</span></span>
                                <span class="font-mono text-[10px]">SHA: {{ substr($sc['prompt']['body_sha256'], 0, 12) }}...</span>
                            </div>
                            <div class="text-gray-800 dark:text-gray-200 line-clamp-2 italic">
                                &ldquo;{{ $sc['prompt']['prompt_text'] }}&rdquo;
                            </div>
                        </div>
                        @endif

                        <!-- Candidate & Checks Information -->
                        @if($sc['candidate'])
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs bg-indigo-50/30 dark:bg-indigo-950/20 p-3 rounded-xl border border-indigo-100 dark:border-indigo-900">
                            <div>
                                <span class="font-bold text-gray-700 dark:text-gray-300">Provenance:</span>
                                @if($sc['candidate']['origin'] === 'manual_import')
                                    <span class="text-indigo-600 dark:text-indigo-400 font-semibold ml-1">Manual Package Import</span>
                                    <div class="text-[10px] text-gray-500 font-mono mt-0.5">Pkg: {{ substr($sc['manual_import']['package_sha256'] ?? '', 0, 12) }}...</div>
                                @else
                                    <span class="text-purple-600 dark:text-purple-400 font-semibold ml-1">API Generation</span>
                                    <div class="text-[10px] text-gray-500 font-mono mt-0.5">Req: {{ substr($sc['authoring_request']['id'] ?? '', 0, 8) }}...</div>
                                @endif
                            </div>
                            <div>
                                <span class="font-bold text-gray-700 dark:text-gray-300">Package:</span>
                                <span class="text-gray-600 dark:text-gray-400 ml-1">{{ $sc['candidate']['file_count'] }} files &bull; {{ number_format($sc['candidate']['total_bytes'] / 1024, 1) }} KB</span>
                                <div class="text-[10px] text-gray-500 font-mono mt-0.5">Manifest: {{ substr($sc['candidate']['manifest_sha256'], 0, 12) }}...</div>
                            </div>
                            <div>
                                <span class="font-bold text-gray-700 dark:text-gray-300">Checks:</span>
                                @if($sc['check_run'])
                                    @if($sc['check_run']['passed'])
                                        <span class="text-green-600 font-semibold ml-1">&#x2713; 7 browser checks passed</span>
                                    @else
                                        <span class="text-red-600 font-semibold ml-1">&#x2717; Checks failed</span>
                                    @endif
                                    <div class="text-[10px] text-gray-500 mt-0.5">Duration: {{ $sc['check_run']['duration_ms'] }}ms</div>
                                @else
                                    <span class="text-gray-400 ml-1">Pending verification</span>
                                @endif
                            </div>
                        </div>
                        @endif

                        <!-- Action Bar for this Section -->
                        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                            <div class="flex items-center space-x-2">
                                <!-- Review Button if review ready -->
                                @if($sc['candidate_state'] && in_array($sc['candidate_state']['status'], ['demo_review_ready', 'review_ready']))
                                    @if($sc['review'])
                                    <a href="{{ route('v3.teacher.cases.reviews.show', ['case' => $sc['section']['case_id'], 'review' => $sc['review']['id']]) }}" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow">
                                        Review Scene &rarr;
                                    </a>
                                    @endif
                                @endif

                                <!-- API Request Button if selected_api mode and no active candidate -->
                                @if(config('services.v3_teacher_paid_scenes_enabled', false) && in_array($run->mode, ['selected_api', 'api_generate'], true) && (! $sc['candidate'] || ($sc['candidate_state'] && $sc['candidate_state']['status'] === 'repair_pending')))
                                <form method="POST" action="{{ route('v3.teacher.subject-runs.scenes.authoring-request', ['run' => $run, 'scene' => $sc['section']['id']]) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow">
                                        {{ $sc['candidate'] ? 'Regenerate Scene (API)' : 'Request Scene (API)' }}
                                    </button>
                                </form>
                                @endif
                            </div>

                            <!-- Manual Scene Import Form -->
                            <details class="text-xs">
                                <summary class="cursor-pointer text-indigo-600 dark:text-indigo-400 hover:underline font-semibold">
                                    Manual Package Upload &hellip;
                                </summary>
                                <div class="mt-3 p-4 bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl space-y-3">
                                    <div class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                        Upload Static Three-File Scene (index.html, styles.css, script.js)
                                    </div>
                                    <form method="POST" action="{{ route('v3.teacher.subject-runs.scenes.imports', ['run' => $run, 'scene' => $sc['section']['id']]) }}" enctype="multipart/form-data" class="space-y-3">
                                        @csrf
                                        <input type="hidden" name="prompt_version_id" value="{{ $sc['prompt']['id'] ?? '' }}">
                                        <div>
                                            <label class="block text-[11px] text-gray-600 dark:text-gray-400 mb-1">Upload Archive / Files</label>
                                            <input type="file" name="files[]" multiple required class="text-xs text-gray-700 dark:text-gray-300">
                                        </div>
                                        <div class="flex justify-end">
                                            <button type="submit" class="px-3 py-1.5 bg-gray-800 hover:bg-gray-900 text-white rounded-lg text-xs font-semibold">
                                                Import Manual Package
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </div>
                    @empty
                    <div class="p-8 text-center text-gray-500">
                        No prompt sections found for this run.
                    </div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
