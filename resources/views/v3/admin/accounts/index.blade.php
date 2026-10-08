@extends('v3.layout')

@section('title', 'Account Management - StudyFlow V3')

@section('content')
<div class="space-y-6">
    <div class="sm:flex sm:items-center sm:justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">Account Access Management</h2>
            <p class="mt-1 text-sm text-gray-500">Manage V3 role assignments and access statuses with transactional version control.</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <a href="{{ route('v3.admin.workspace') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                Back to Workspace
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md bg-green-50 p-4 border border-green-200">
            <div class="flex">
                <div class="text-sm font-medium text-green-800">
                    {{ session('status') }}
                </div>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md bg-red-50 p-4 border border-red-200">
            <div class="text-sm font-medium text-red-800">
                <ul class="list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="bg-white shadow overflow-hidden sm:rounded-lg border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Version</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Update Access</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($accounts as $account)
                        @php
                            $acc = is_array($account) ? (object) $account : $account;
                        @endphp
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ $acc->id }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $acc->name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $acc->email }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $acc->role === 'admin' ? 'bg-purple-100 text-purple-800' : ($acc->role === 'teacher' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800') }}">
                                    {{ ucfirst($acc->role) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                @if ($acc->v3_is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Active</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Inactive</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $acc->v3_version }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <form action="{{ route('v3.admin.accounts.access.update', $acc->id) }}" method="POST" class="inline-flex items-center space-x-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="expected_version" value="{{ $acc->v3_version }}">
                                    <input type="hidden" name="expected_role" value="{{ $acc->role }}">

                                    <select name="role" class="text-xs rounded border-gray-300 py-1">
                                        <option value="admin" {{ $acc->role === 'admin' ? 'selected' : '' }}>Admin</option>
                                        <option value="teacher" {{ $acc->role === 'teacher' ? 'selected' : '' }}>Teacher</option>
                                        <option value="student" {{ $acc->role === 'student' ? 'selected' : '' }}>Student</option>
                                    </select>

                                    <select name="is_active" class="text-xs rounded border-gray-300 py-1">
                                        <option value="1" {{ $acc->v3_is_active ? 'selected' : '' }}>Active</option>
                                        <option value="0" {{ ! $acc->v3_is_active ? 'selected' : '' }}>Inactive</option>
                                    </select>

                                    <select name="reason_code" class="text-xs rounded border-gray-300 py-1">
                                        <option value="grant_access">Grant Access</option>
                                        <option value="suspend_access">Suspend Access</option>
                                        <option value="restore_access">Restore Access</option>
                                        <option value="change_role">Change Role</option>
                                    </select>

                                    <button type="submit" class="inline-flex items-center px-2.5 py-1 border border-transparent text-xs font-medium rounded text-indigo-700 bg-indigo-100 hover:bg-indigo-200">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                No accounts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($accounts->hasPages())
            <div class="px-6 py-3 border-t border-gray-200">
                {{ $accounts->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
