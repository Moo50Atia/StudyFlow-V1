<?php

namespace Database\Factories;

use App\Models\Lecture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Section>
 */
class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sectionTitles = [
            'Key Definitions',
            'Core Concepts',
            'Step-by-Step Guide',
            'Examples and Exercises',
            'Summary Points',
            'Quick Review',
            'Important Formulas',
            'Practice Problems',
        ];

        $coreConcepts = [
            'The fundamental principle behind this topic is...',
            'The most important thing to remember here is...',
            'Everything in this section builds on the idea that...',
            'If you only remember one thing, let it be...',
        ];

        $egyptianExplains = [
            'ببساطة كده، الموضوع ده بيتكلم عن... خلينا نفهمها خطوة خطوة.',
            'تعالى نفهم الحكاية دي بالمصري... الفكرة إن احنا عايزين نوصل لحاجة معينة.',
            'فكر فيها كده... لو عندك مشكلة وعايز تحلها، إيه أول حاجة هتعملها؟',
            'الموضوع سهل جداً لو ركزت معايا... هنشرحها بطريقة بسيطة.',
            null,
        ];

        $formulas = [
            "المعادلة الأساسية:\nF = ma\n\nحيث:\n- F = القوة\n- m = الكتلة\n- a = التسارع",
            "القانون المستخدم:\nE = mc²\n\nحيث:\n- E = الطاقة\n- m = الكتلة\n- c = سرعة الضوء",
            "الصيغة الرياضية:\nA = πr²\n\nحيث:\n- A = المساحة\n- r = نصف القطر",
            null,
        ];

        $realLifeExamples = [
            'تخيل إنك بتسوق عربية وفجأة دست على الفرامل - ده بالظبط مثال على القوة والتسارع!',
            'لما بتشحن موبايلك، الطاقة الكهربائية بتتحول لطاقة كيميائية في البطارية.',
            'فكر في لما بتطلب طلب من الإنترنت - المسار من المخزن لحد باب بيتك هو نفس فكرة الـ Routing.',
            'زي لما بتحجز في المطعم - لازم يكون فيه "مكان فاضي" عشان تقدر تقعد.',
            null,
        ];

        return [
            'lecture_id' => Lecture::factory(),
            'title' => fake()->randomElement($sectionTitles),
            'quick_summary' => fake()->paragraph(2),
            'core_concept' => fake()->randomElement($coreConcepts),
            'egyptian_explain' => fake()->randomElement($egyptianExplains),
            'formulas' => fake()->randomElement($formulas),
            'real_life' => fake()->randomElement($realLifeExamples),
            'dynamic_view_link' => fake()->optional(0.7)->passthrough('https://docs.google.com/document/d/' . fake()->uuid() . '/edit'),
        ];
    }
}
