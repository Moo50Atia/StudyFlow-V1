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
@endsection
