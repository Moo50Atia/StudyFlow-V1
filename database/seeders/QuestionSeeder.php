<?php

namespace Database\Seeders;

use App\Models\Question;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class QuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = database_path('data/hints.json');

        if (!file_exists($jsonPath)) {
            $this->command->error("hints.json file not found at: {$jsonPath}");
            return;
        }

        $jsonContent = file_get_contents($jsonPath);
        $questions = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error("Error decoding JSON: " . json_last_error_msg());
            return;
        }

        foreach ($questions as $data) {
            Question::where('id', $data['id'])->update([
                'hint' => $data['hint'] ?? null,
            ]);
        }

        $this->command->info("Seeded " . count($questions) . " questions successfully.");
    }
}
