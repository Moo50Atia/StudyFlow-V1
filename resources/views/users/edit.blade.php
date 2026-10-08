<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight animate-fade-in">Edit User</h2>
            <a href="{{ route('users.index') }}" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 transition-colors duration-200">
                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Users
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-xl rounded-2xl animate-fade-in-up">
                <div class="p-8">
                    <form action="{{ route('users.update', $user) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <input type="hidden" name="expected_version" value="{{ $user->v3Access?->version ?? 0 }}">
                        <input type="hidden" name="expected_role" value="{{ $user->role }}">

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.1s">
                            <label for="name" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Name</label>
                            <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                            @error('name')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.2s">
                            <label for="email" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Email</label>
                            @if ($user->v3Access)
                                <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" readonly class="w-full rounded-xl border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 text-gray-500 cursor-not-allowed shadow-sm">
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">Administrative email replacement is prohibited for enrolled V3 accounts.</p>
                            @else
                                <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                            @endif
                            @error('email')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        @if ($user->v3Access)
                            <div class="mb-6 p-4 rounded-xl bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 animate-fade-in-up" style="animation-delay: 0.3s">
                                <span class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Password</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Administrative password replacement is prohibited for enrolled V3 accounts. Account holders must use self-service profile or password reset.</p>
                            </div>
                        @else
                            <div class="mb-6 animate-fade-in-up" style="animation-delay: 0.3s">
                                <label for="password" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Password <span class="text-gray-400">(leave blank to keep current)</span></label>
                                <input type="password" name="password" id="password" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                                @error('password')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                            </div>
                        @endif

                        <div class="mb-8 animate-fade-in-up" style="animation-delay: 0.4s">
                            <label for="role" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Role</label>
                            <select name="role" id="role" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 transition-all duration-200">
                                <option value="student" {{ old('role', $user->role) === 'student' ? 'selected' : '' }}>👨‍🎓 Student</option>
                                <option value="teacher" {{ old('role', $user->role) === 'teacher' ? 'selected' : '' }}>👨‍🏫 Teacher</option>
                                <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>👑 Admin</option>
                            </select>
                            @error('role')<p class="mt-2 text-sm text-red-500">{{ $message }}</p>@enderror
                        </div>

                        <div class="animate-fade-in-up" style="animation-delay: 0.5s">
                            <button type="submit" class="w-full inline-flex items-center justify-center px-6 py-4 bg-gradient-to-r from-amber-500 to-orange-500 text-white rounded-xl font-bold text-lg hover:from-amber-600 hover:to-orange-600 transition-all duration-300 transform hover:scale-[1.02] shadow-xl">
                                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Update User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
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