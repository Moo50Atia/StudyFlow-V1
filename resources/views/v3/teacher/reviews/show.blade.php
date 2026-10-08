@extends('v3.layout')

@section('title', 'Review Scene Candidate')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case</a>
    <a href="{{ route('v3.teacher.cases.reviews.index', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Reviews</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Review Candidate</span>
@endsection

@section('content')
<div class="space-y-6">
    @if($data['review_state'] === 'pending' || $data['review_state'] === 'dormant')
    <section class="rounded-lg border border-gray-200 bg-white p-6 space-y-4"
        data-v3-review-page
        data-case-id="{{ $case->id }}"
        data-review-id="{{ $data['review_ref'] }}"
        data-review-version="{{ $data['review_state_version'] }}"
        data-candidate-ref="{{ $data['candidate_ref'] }}"
        data-candidate-version="{{ $data['candidate_version'] }}">
        <h2 class="text-lg font-semibold">Interactive candidate preview</h2>
        <div id="v3-review-preview"></div>
        <p id="v3-review-message" role="status" aria-live="polite"></p>
        <div class="flex flex-wrap gap-3">
            <button id="v3-review-approve" type="button" disabled class="rounded bg-green-700 px-4 py-2 text-white disabled:opacity-50">Approve after frame 0 is shown</button>
        </div>
        <form id="v3-review-changes" class="space-y-3">
            <label class="block">Request changes
                <select name="feedback_kind" required class="block rounded border p-2">
                    <option value="visual_interaction">Visual or interaction</option>
                    <option value="factual_source">Factual source correction</option>
                    <option value="mixed">Mixed correction</option>
                </select>
            </label>
            <label class="block">Notes
                <textarea name="notes" required maxlength="8192" class="block w-full rounded border p-2" rows="5"></textarea>
            </label>
            <button type="submit" class="rounded bg-indigo-700 px-4 py-2 text-white">Request changes</button>
        </form>
    </section>
    @endif
    @if($data['review_state'] === 'decided' && !empty($data['approval_ref']))
    <section class="rounded-lg border border-emerald-200 bg-emerald-50 p-6 space-y-3">
        <h2 class="text-lg font-semibold">Request a correction to this approved scene</h2>
        <form id="v3-postapproval-correction" data-url="{{ route('v3.teacher.cases.approvals.correct', ['case' => $case->id, 'approval' => $data['approval_ref']]) }}" data-section-version="{{ $data['section_state_version'] }}" class="space-y-3">
            <label class="block">Correction type
                <select name="feedback_kind" required class="block rounded border p-2">
                    <option value="visual_interaction">Visual or interaction</option>
                    <option value="factual_source">Factual source correction</option>
                    <option value="mixed">Mixed correction</option>
                </select>
            </label>
            <label class="block">Notes
                <textarea name="notes" required maxlength="8192" class="block w-full rounded border p-2" rows="5"></textarea>
            </label>
            <button type="submit" class="rounded bg-indigo-700 px-4 py-2 text-white">Submit correction</button>
            <p id="v3-correction-message" role="status" aria-live="polite"></p>
        </form>
    </section>
    @endif

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Review Scene: {{ $data['fields']['section_identity'] }}</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Review Ref: {{ $data['review_ref'] }} | Status: {{ $data['review_state'] }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.cases.reviews.index', $case->id) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Back to Reviews
            </a>
        </div>
    </div>

    <!-- 010 Current Prompt -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">Scene Generation Prompt</h2>
        <div class="bg-gray-50 p-4 rounded-md font-mono text-sm text-gray-800 whitespace-pre-wrap">{{ $data['prompt'] }}</div>
    </div>

    <!-- 009 Eleven Educational Fields in Order -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-6">
        <h2 class="text-lg font-semibold text-gray-900">Section Pedagogical Context</h2>

        <div>
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">1. Section Identity</h3>
            <p class="mt-1 text-base text-gray-900">{{ $data['fields']['section_identity'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">2. Core Understanding</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['core_understanding'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">3. Visual-First Explanation</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['visual_first_explanation'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">4. Visual Scene Script</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed font-mono bg-gray-50 p-3 rounded">{{ $data['fields']['visual_scene_script'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">5. Dynamic View Blueprint</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed font-mono bg-gray-50 p-3 rounded">{{ $data['fields']['dynamic_view_blueprint'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">6. Visual Learning Outcome</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['visual_learning_outcome'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">7. Concept Check</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['concept_check'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">8. Conceptual Summary</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['conceptual_summary'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">9. Colloquial Egyptian Explanation</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['colloquial_egyptian_explanation'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">10. Laws If Any</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['laws_if_any'] }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">11. Real Life Example</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ $data['fields']['real_life_example'] }}</p>
        </div>
    </div>
</div>
@endsection
