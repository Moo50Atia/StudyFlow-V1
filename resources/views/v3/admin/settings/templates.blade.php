@extends('v3.layout')

@section('title', 'Approved Templates Catalog')

@section('nav')
    <a href="{{ route('v3.admin.workspace') }}" class="text-gray-500 hover:text-gray-700 pb-1">Operations</a>
    <a href="{{ route('v3.admin.accounts.index') }}" class="text-gray-500 hover:text-gray-700 pb-1">Accounts</a>
    <a href="{{ route('v3.admin.settings.show') }}" class="text-gray-500 hover:text-gray-700 pb-1">Settings</a>
    <a href="{{ route('v3.admin.settings.templates') }}" class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Templates</a>
    <a href="{{ route('v3.admin.settings.history') }}" class="text-gray-500 hover:text-gray-700 pb-1">History</a>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <h1 class="text-2xl font-bold text-gray-900">Approved Templates Catalog</h1>
        <p class="text-sm text-gray-600 mt-1">Available approved instruction templates registered by operator for selection in settings.</p>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Template ID</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Kind</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Registered At</th>
                    <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Checksum (SHA-256)</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($templates as $t)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-gray-900">{{ $t['id'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-700">{{ $t['kind'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600">{{ $t['registered_at'] }}</td>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-xs text-gray-500">{{ $t['checksum'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                            No approved templates registered yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
