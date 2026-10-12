<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-900 dark:text-white">Teaching workspace</h1></x-slot>
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8 space-y-8 text-gray-900 dark:text-gray-100">
        <section class="grid gap-4 md:grid-cols-3" aria-label="Teaching actions">
            <a href="{{ route('subjects.create') }}" class="block rounded-xl bg-indigo-600 p-5 text-white shadow"><strong class="block text-lg">Create Subject</strong><span class="text-sm">You own it and can upload PDFs immediately.</span></a>
            <a href="{{ route('lectures.create') }}" class="block rounded-xl bg-white dark:bg-gray-800 p-5 shadow"><strong class="block text-lg">Add Lecture Manually</strong><span class="text-sm">Creates a lecture without starting NotebookLM.</span></a>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow"><strong class="block text-lg">Upload PDFs</strong><span class="text-sm">Choose a subject below. Each PDF creates its lecture automatically. Up to 20 PDFs per web run; larger prepared batches need separate web support.</span></div>
        </section>
        <section class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow">
            <h2 class="text-lg font-semibold mb-4">Assigned subjects</h2>
            @forelse($subjects as $subject)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 dark:border-gray-700 py-3">
                    <a class="text-indigo-700 dark:text-indigo-300 font-medium" href="{{ route('subjects.show', $subject) }}">{{ $subject->name }}</a>
                    <div class="flex gap-3 text-sm"><a href="{{ route('v3.teacher.subject-runs.create', $subject) }}" class="font-semibold text-indigo-700 dark:text-indigo-300">Upload PDFs</a><a href="{{ route('v3.teacher.subject-runs.index', $subject) }}">Runs</a></div>
                </div>
            @empty <p>No subjects yet. Create one to begin.</p> @endforelse
        </section>
        <section class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow">
            <h2 class="text-lg font-semibold mb-4">PDF runs</h2>
            @forelse($runs as $run)
                <div class="border-t border-gray-200 dark:border-gray-700 py-3">
                    <div class="flex flex-wrap justify-between gap-2"><a class="font-medium text-indigo-700 dark:text-indigo-300" href="{{ route('v3.teacher.subject-runs.show', $run) }}">Run {{ Str::limit($run->id, 8, '') }}</a><span>{{ $run->state }} · {{ $run->mode }}</span></div>
                    <ol class="mt-2 pl-5 list-decimal text-sm space-y-1">@foreach($run->items->sortBy('pdf_ordinal') as $item)<li>{{ $item->display_title ?? $item->title ?? 'PDF '.$item->pdf_ordinal }} · {{ $item->state }} @if($item->lecture_id)<a href="{{ route('lectures.show', $item->lecture_id) }}" class="underline">Lecture</a>@endif</li>@endforeach</ol>
                    <div class="mt-2 flex gap-4 text-sm"><a href="{{ route('v3.teacher.subject-runs.prompt-export', $run) }}" class="underline">Prompt export</a><a href="{{ route('v3.teacher.subject-runs.scenes', $run) }}" class="underline">Manual scene review</a></div>
                </div>
            @empty <p>No PDF runs yet.</p> @endforelse
        </section>
        <section class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow">
            <h2 class="text-lg font-semibold mb-4">Generated lessons</h2>
            @forelse($lessons as $lesson)
                <div class="border-t border-gray-200 dark:border-gray-700 py-3 flex flex-wrap justify-between gap-3">
                    <div><div class="font-medium">{{ $lesson->lesson_title }}</div><div class="text-sm">{{ $lesson->lecture_title }} · {{ $lesson->published_at ? 'Published' : 'Draft' }}</div>@if($lesson->missing_fields)<p class="text-sm text-amber-700">Missing: {{ implode(', ', $lesson->missing_fields) }}</p>@endif</div>
                    <div class="flex items-center gap-3 text-sm">@if($lesson->first_section_id)<a href="{{ route('sections.show', $lesson->first_section_id) }}" class="underline">Read lesson</a>@endif<form action="{{ route('lessons.publication.update', $lesson->work_node_id) }}" method="post">@csrf<input type="hidden" name="published" value="{{ $lesson->published_at ? '0' : '1' }}"><button class="rounded bg-indigo-600 px-3 py-2 text-white" @if($lesson->missing_fields && !$lesson->published_at) disabled @endif>{{ $lesson->published_at ? 'Unpublish' : 'Publish lesson' }}</button></form></div>
                </div>
            @empty <p>Lessons will appear after PDF processing.</p> @endforelse
        </section>
    </main>
</x-app-layout>
