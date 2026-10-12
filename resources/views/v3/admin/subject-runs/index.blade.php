<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Admin - Subject Runs Operations') }}
            </h2>
            <a href="{{ route('v3.admin.workspace') }}" class="text-sm text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                &larr; Admin Workspace
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Safe Operational Info Banner -->
            <div class="bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900 rounded-2xl p-4 text-xs text-blue-800 dark:text-blue-300">
                <strong>Operational Boundary:</strong> Admin monitoring is restricted to safe operational telemetry, state versions, and workflow controls. Educational content, teacher titles, private prompt bodies, and provider IDs are excluded.
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="font-bold text-gray-900 dark:text-gray-100">Registered Subject Runs</h3>
                    <span class="text-xs text-gray-500">{{ count($runs) }} runs loaded</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600 dark:text-gray-300">
                        <thead class="bg-gray-100 dark:bg-gray-700 text-xs uppercase text-gray-500 dark:text-gray-400 font-semibold">
                            <tr>
                                <th class="px-6 py-3">Run ID</th>
                                <th class="px-6 py-3">Subject ID</th>
                                <th class="px-6 py-3">Owner</th>
                                <th class="px-6 py-3">State &amp; Version</th>
                                <th class="px-6 py-3">Mode</th>
                                <th class="px-6 py-3">Total PDFs</th>
                                <th class="px-6 py-3">Timestamps</th>
                                <th class="px-6 py-3 text-right">Controls</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($runs as $run)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="px-6 py-4 font-mono text-xs font-semibold text-gray-900 dark:text-gray-100" title="{{ $run['run_id'] }}">
                                    {{ substr($run['run_id'], 0, 8) }}...
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    {{ $run['subject_id'] }}
                                </td>
                                <td class="px-6 py-4 text-xs font-mono">
                                    User #{{ $run['owner_user_id'] }}
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300">
                                        {{ $run['state'] }}
                                    </span>
                                    <span class="ml-1 text-xs text-gray-400">v{{ $run['state_version'] }}</span>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <span class="font-semibold">{{ $run['mode'] }}</span>
                                    <span class="text-gray-400 block text-[11px]">{{ $run['qualification_mode'] }}</span>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    {{ $run['total_pdfs'] }}
                                </td>
                                <td class="px-6 py-4 text-[11px] text-gray-500">
                                    <div>Created: {{ substr($run['created_at'] ?? '', 0, 19) }}</div>
                                    <div>Updated: {{ substr($run['last_updated_at'] ?? '', 0, 19) }}</div>
                                </td>
                                <td class="px-6 py-4 text-right space-x-1">
                                    @if($run['state'] === 'running')
                                    <form method="POST" action="{{ route('v3.admin.subject-runs.commands', $run['run_id']) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="command_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="expected_version" value="{{ $run['state_version'] }}">
                                        <button type="submit" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded text-xs font-semibold">
                                            Pause
                                        </button>
                                    </form>
                                    @elseif($run['state'] === 'paused')
                                    <form method="POST" action="{{ route('v3.admin.subject-runs.commands', $run['run_id']) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="command_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="expected_version" value="{{ $run['state_version'] }}">
                                        <button type="submit" class="px-2.5 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-xs font-semibold">
                                            Resume
                                        </button>
                                    </form>
                                    @endif

                                    @if(in_array($run['state'], ['running', 'paused', 'staging']))
                                    <form method="POST" action="{{ route('v3.admin.subject-runs.commands', $run['run_id']) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="command_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="expected_version" value="{{ $run['state_version'] }}">
                                        <button type="submit" onclick="return confirm('Cancel this active run?');" class="px-2.5 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs font-semibold">
                                            Cancel
                                        </button>
                                    </form>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                                    No subject runs found.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
