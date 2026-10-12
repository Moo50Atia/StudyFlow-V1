<x-app-layout>
    @php
        $displayState = $progress['state'] ?? $run->state;
        $pendingControl = isset($progress['control']) && in_array($progress['control']['state'], ['requested', 'accepted'], true);
        $progressCounts = $progress['counts'] ?? [];
    @endphp
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Subject Run Details') }}
            </h2>
            <div class="flex items-center space-x-3">
                <a href="{{ route('v3.teacher.subject-runs.scenes', $run) }}" class="inline-flex items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow">
                    Scenes &amp; Authoring
                </a>
                <a href="{{ route('v3.teacher.subject-runs.prompt-export', $run) }}" target="_blank" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold shadow">
                    Export Prompts (JSON)
                </a>
                <a href="{{ route('v3.teacher.subject-runs.prompt-export', ['run' => $run, 'format' => 'text']) }}" target="_blank" class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-xs font-bold shadow">
                    Export Prompts (Text)
                </a>
                <a href="{{ route('subjects.show', $run->subject_id) }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                    &larr; Back to Subject
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Run Header Card -->
            <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div>
                        <div class="text-xs font-bold uppercase text-gray-400">Run ID</div>
                        <div class="text-sm font-mono font-semibold text-gray-900 dark:text-gray-100 truncate" title="{{ $run->id }}">{{ $run->id }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase text-gray-400">Status</div>
                        <div class="mt-1">
                            @if($displayState === 'completed')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300">Completed</span>
                            @elseif($displayState === 'prompts_ready')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">Prompts Ready</span>
                            @elseif($displayState === 'partial')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">Partial (Some items failed)</span>
                            @elseif($displayState === 'failed')
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-300">Failed</span>
                            @else
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300">{{ ucwords(str_replace('_', ' ', $displayState)) }}</span>
                            @endif
                            <span class="ml-2 text-xs text-gray-400">v{{ $run->state_version }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase text-gray-400">Mode & Policy</div>
                        <div class="text-sm text-gray-800 dark:text-gray-200">
                            <span class="font-semibold">{{ $run->mode }}</span> / 
                            <span class="text-xs text-gray-500">{{ $run->qualification_mode }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase text-gray-400">Counts</div>
                        <div class="text-sm text-gray-800 dark:text-gray-200">
                            <span>{{ $progressCounts['pdfs_total'] ?? $run->total_pdfs ?? 'Unknown' }} PDFs</span> &bull;
                            <span>{{ $progressCounts['lessons_saved'] ?? $run->total_lessons ?? 'Unknown' }} Lessons saved</span> &bull;
                            <span>{{ $progressCounts['prompts_saved'] ?? $run->total_scenes ?? 'Unknown' }} Prompts saved</span>
                        </div>
                    </div>
                </div>

                <!-- Telemetry & Action Controls Bar -->
                <div class="mt-6 pt-6 border-t border-gray-100 dark:border-gray-700/60 flex flex-wrap justify-between items-center gap-4">
                    <div class="flex items-center space-x-3 text-xs text-gray-500">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold {{ ($progress['projection_source'] ?? 'last_known') === 'last_known' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' }}">
                            {{ ($progress['projection_source'] ?? 'last_known') === 'last_known' ? 'Last Known Projection' : 'Live Projection' }}
                        </span>
                        <span>Updated: {{ $run->last_updated_at ? $run->last_updated_at->diffForHumans() : 'Recently' }}</span>
                        @if(!empty($progress['workflow']['phase']))
                            <span>Phase: {{ ucwords(str_replace('_', ' ', $progress['workflow']['phase'])) }}</span>
                        @endif
                        @if($pendingControl)
                            <span role="status" aria-live="polite">{{ ucfirst($progress['control']['action']) }} requested; waiting for workflow confirmation.</span>
                        @endif
                    </div>

                    <!-- Teacher Run Controls -->
                    <div class="flex items-center space-x-2">
                        @if(!$pendingControl && $displayState === 'running')
                        <form method="POST" action="{{ route('v3.teacher.subject-runs.commands', $run) }}" class="inline">
                            @csrf
                            <input type="hidden" name="command_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="action" value="pause">
                            <input type="hidden" name="expected_version" value="{{ $run->state_version }}">
                            <button type="submit" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-xs font-bold shadow">
                                Pause Run
                            </button>
                        </form>
                        @elseif(!$pendingControl && $displayState === 'paused')
                        <form method="POST" action="{{ route('v3.teacher.subject-runs.commands', $run) }}" class="inline">
                            @csrf
                            <input type="hidden" name="command_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="action" value="resume">
                            <input type="hidden" name="expected_version" value="{{ $run->state_version }}">
                            <button type="submit" class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold shadow">
                                Resume Run
                            </button>
                        </form>
                        @endif

                        @if(!$pendingControl && in_array($displayState, ['running', 'paused', 'staging', 'queued'], true))
                        <form method="POST" action="{{ route('v3.teacher.subject-runs.commands', $run) }}" class="inline">
                            @csrf
                            <input type="hidden" name="command_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="expected_version" value="{{ $run->state_version }}">
                            <button type="submit" onclick="return confirm('Cancel this run?');" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-xs font-bold shadow">
                                Cancel Run
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Items List -->
            <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="font-bold text-gray-900 dark:text-gray-100">Ordered PDF Items</h3>
                    <span class="text-xs text-gray-500">{{ count($run->items) }} items</span>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($run->items as $item)
                    <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-start space-x-4">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-sm shrink-0">
                                {{ $item->pdf_ordinal }}
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 dark:text-gray-100 text-base">{{ $item->display_title }}</h4>
                                <div class="text-xs text-gray-500 mt-0.5">
                                    <span>{{ $item->original_filename }}</span> &bull;
                                    <span>{{ number_format(($item->input_bytes ?? 0) / 1024, 1) }} KB</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="text-right">
                                <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $item->saved_lesson_count }} lessons &bull; {{ $item->prompt_count }} prompts
                                </div>
                                <div class="text-xs mt-1">
                                    @if($item->state === 'completed' || $item->state === 'prompts_ready')
                                        <span class="text-green-600 dark:text-green-400 font-bold">&#x2713; {{ ucfirst($item->state) }}</span>
                                    @elseif($item->state === 'failed')
                                        <span class="text-red-600 dark:text-red-400 font-bold">&#x2717; Failed</span>
                                    @else
                                        <span class="text-gray-500">{{ ucfirst($item->state) }}</span>
                                    @endif
                                </div>
                            </div>
                            @if($item->prompt_count > 0)
                            <a href="{{ route('v3.teacher.subject-runs.prompt-export', ['run' => $run, 'item_id' => $item->id]) }}" target="_blank" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-xs font-semibold">
                                Export Prompts
                            </a>
                            @endif
                        </div>
                    </div>
                    @empty
                    <div class="p-8 text-center text-gray-500">
                        No items found for this run.
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
