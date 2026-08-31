<?php

namespace Database\Seeders;

use App\Models\Mission;
use Illuminate\Database\Seeder;

class MissionSeeder extends Seeder
{
    /**
     * Seeds M01, matching the phase/step map in EOS-009 §7.
     */
    public function run(): void
    {
        Mission::updateOrCreate(
            ['code' => 'M01'],
            [
                'title' => 'My Daily Life',
                'module' => 'Me',
                'outcome' => 'I can talk about my daily routine, habits, work/study and free time.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Foundation',
                        'mode' => 'solo',
                        'steps' => ['mission_brief', 'vocabulary_builder', 'listening'],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Build',
                        'mode' => 'solo',
                        'steps' => ['grammar_in_context', 'activation'],
                    ],
                    [
                        'phase' => 'mission',
                        'label' => 'Mission',
                        'mode' => 'ai',
                        'steps' => [
                            'ai_conversation_1',
                            'ai_feedback_1',
                            'writing',
                            'ai_conversation_2',
                            'active_recall',
                            'error_log',
                            'mission_result',
                        ],
                    ],
                ],
            ]
        );
    }
}
