@extends('v3.layout')

@section('title', 'Teacher Section Detail')

@section('nav')
    <a href="{{ route('v3.teacher.workspace') }}" class="text-gray-600 hover:text-indigo-600 pb-1">Workspace</a>
    <a href="{{ route('v3.teacher.cases.show', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Case</a>
    <a href="{{ route('v3.teacher.cases.lesson-map', $case->id) }}" class="text-gray-600 hover:text-indigo-600 pb-1">Lesson Map</a>
    <span class="text-indigo-600 border-b-2 border-indigo-600 pb-1">Section Detail</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Section {{ $section['ordinal_in_work'] }}: {{ e($section['fields']['section_identity']) }}</h1>
            <p class="text-sm font-mono text-gray-500 mt-1">Section Ref: {{ $section['section_ref'] }}</p>
        </div>
        <div>
            <a href="{{ route('v3.teacher.cases.lesson-map', $case->id) }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                Back to Map
            </a>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-6">
        <div>
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Section Identity</h3>
            <p class="mt-1 text-base text-gray-900">{{ e($section['fields']['section_identity']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Core Understanding</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['core_understanding']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Visual-First Explanation</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['visual_first_explanation']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Visual Scene Script</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed font-mono bg-gray-50 p-3 rounded">{{ e($section['fields']['visual_scene_script']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Dynamic View Blueprint</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed font-mono bg-gray-50 p-3 rounded">{{ e($section['fields']['dynamic_view_blueprint']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Visual Learning Outcome</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['visual_learning_outcome']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Concept Check</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['concept_check']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Conceptual Summary</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['conceptual_summary']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4" dir="rtl">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">• شرح للجزء بالعامية المصرية</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['colloquial_egyptian_explanation']) }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4" dir="rtl">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">القوانين (إن وُجدت)</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['laws_if_any'] ?: 'لا توجد قوانين مسجلة') }}</p>
        </div>

        <div class="border-t border-gray-100 pt-4" dir="rtl">
            <h3 class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">إسقاط على مثال أو موقف من الحياة اليومية لتثبيت الفهم</h3>
            <p class="mt-1 text-sm text-gray-900 leading-relaxed">{{ e($section['fields']['real_life_example']) }}</p>
        </div>
    </div>
</div>
@endsection
