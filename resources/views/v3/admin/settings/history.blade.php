@extends('v3.layout')

@section('title', 'Settings Publication History')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Operations</a>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
    <a href="{{ route('v3.admin.settings.show') }}" class="text-gray-500 hover:text-gray-700 pb-1">Settings</a>
    <a href="{{ route('v3.admin.settings.templates') }}" class="text-gray-500 hover:text-gray-700 pb-1">Templates</a>
    <a href="{{ route('v3.admin.settings.history') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">History</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Settings Publication History</h1>
        <p class="text-sm text-gray-600 mt-1">Audit log of all published operating settings versions and their changed paths.</p>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Version</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Publisher ID</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Published At</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Checksum</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Changed Paths</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($versions as $v)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-gray-900">v{{ $v['version'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600">User #{{ $v['publisher_user_id'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600">{{ $v['published_at'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-gray-500">{{ substr($v['checksum'], 0, 16) }}...</td>
                        <td class="px-6 py-4 text-xs text-gray-600">
                            {{ implode(', ', $v['changed_paths']) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                            No settings versions have been published yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
