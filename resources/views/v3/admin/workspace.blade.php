@extends('v3.layout')

@section('title', 'Admin Operations Workspace')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Operations</a>
    <a href="{{ route('v3.admin.cases.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Cases</a>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Admin Operations Workspace</h1>
        <p class="text-sm text-gray-600 mt-1">
            Welcome, {{ $admin->name }}. You monitor operational throughput and manage V3 system access.
        </p>
    </div>

    <section class="bg-white p-6 rounded-lg shadow-sm border border-gray-200" aria-labelledby="notebook-health-title">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 id="notebook-health-title" class="text-lg font-semibold text-gray-900">NotebookLM connection</h2>
                <p class="text-sm text-gray-600 mt-1">Check the saved Google session with a read-only NotebookLM request. This does not generate content.</p>
            </div>
            <button id="notebook-health-check" type="button" class="inline-flex items-center justify-center px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60">
                Check Notebook Health
            </button>
        </div>
        <div id="notebook-health-result" class="mt-4 text-sm" role="status" aria-live="polite"></div>
        <pre id="notebook-health-command" class="hidden mt-3 whitespace-pre-wrap break-all rounded-md bg-gray-900 p-4 text-sm text-gray-100"></pre>
    </section>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Access Management</h3>
            <p class="text-sm text-gray-600 mt-2">Manage enrolled accounts, role assignments, and suspensions.</p>
            <div class="mt-4">
                <a href="{{ route('v3.admin.accounts.index') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                    Manage Accounts
                </a>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 md:col-span-2">
            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Operations Summary</h3>
            <p class="text-sm text-gray-600 mt-2">Inspect processing cases and workflow lifecycle state without educational content exposure.</p>
            <div class="mt-4">
                <a href="{{ route('v3.admin.cases.index') }}" class="inline-flex items-center px-4 py-2 border border-indigo-600 shadow-sm text-sm font-medium rounded-md text-indigo-700 bg-white hover:bg-indigo-50">
                    Monitor Cases Operations &rarr;
                </a>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const button = document.getElementById('notebook-health-check');
    const result = document.getElementById('notebook-health-result');
    const command = document.getElementById('notebook-health-command');
    button.addEventListener('click', async () => {
        button.disabled = true;
        button.textContent = 'Checking…';
        result.textContent = '';
        command.textContent = '';
        command.classList.add('hidden');
        try {
            const response = await fetch(@json(route('v3.admin.notebooklm.health-check')), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const body = await response.json();
            if (body.data?.healthy === true) {
                result.className = 'mt-4 text-sm text-green-700';
                result.textContent = body.data.message || 'All right.';
            } else if (body.data?.state === 'authentication_required') {
                result.className = 'mt-4 text-sm text-amber-800';
                result.textContent = `${body.data.message} ${body.data.after_command || ''}`;
                command.textContent = body.data.terminal_command;
                command.classList.remove('hidden');
            } else {
                result.className = 'mt-4 text-sm text-red-700';
                result.textContent = body.error?.message || 'NotebookLM health could not be verified.';
            }
        } catch (_) {
            result.className = 'mt-4 text-sm text-red-700';
            result.textContent = 'NotebookLM health could not be verified. Check that the web bridge is running and try again.';
        } finally {
            button.disabled = false;
            button.textContent = 'Check Notebook Health';
        }
    });
})();
</script>
@endsection
