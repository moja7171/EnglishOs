<?php

namespace Database\Seeders;

use App\Models\Mission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MissionSeeder extends Seeder
{
    /**
     * Seeds M01 with its real content from document/M01/ (Mission01.pdf,
     * BBC Learning English "Real Easy English — Mornings"), matching the
     * phase/step map in EOS-009 §7.
     *
     * Vocabulary content is written fresh, not sourced from the Cambridge
     * textbooks in document/ — see EOS-009 §14 (open question) and
     * Principle 5: the app must not depend on licensed paper resources.
     */
    public function run(): void
    {
        $audioUrl = $this->publishMissionAsset('M01', 'BBC Learning English - Real Easy English Talking about mornings.mp3');

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
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Mission Brief',
                                'hook' => "Imagine a new coworker turns to you and asks: \"So, what's your day usually "
                                    .'like?" Could you answer right now, without stopping to think?',
                                'warm_up_questions' => [
                                    'What time do you usually wake up?',
                                    'What do you usually do in the morning?',
                                    'What do you do after work/class?',
                                    'What do you usually do at weekends?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder',
                                'label' => 'Vocabulary Builder',
                                'hook' => 'Next time someone asks about your morning, will these words be ready — or will you go quiet?',
                                // Original wordlist — not sourced from English Vocabulary in Use.
                                'vocabulary' => [
                                    ['word' => 'routine', 'meaning' => 'the usual things you do, in the usual order'],
                                    ['word' => 'get ready', 'meaning' => 'to prepare yourself to leave the house'],
                                    ['word' => 'commute', 'meaning' => 'to travel to work or school regularly'],
                                    ['word' => 'rush hour', 'meaning' => 'the busiest time of day for traffic'],
                                    ['word' => 'day off', 'meaning' => 'a day when you don\'t work'],
                                    ['word' => 'wind down', 'meaning' => 'to relax before going to sleep'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'hook' => 'Neil and Georgie are chatting about their mornings right now — how much can you catch without reading along?',
                                'source' => 'BBC Learning English — Real Easy English: Mornings (2025)',
                                'audio_url' => $audioUrl,
                                'transcript_ref' => 'document/M01/RealEasyEnglish_mornings__transcript.pdf',
                                'target_phrases' => ['get up', 'sleep in', 'oversleep', 'skip breakfast', 'morning person'],
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Build',
                        'mode' => 'solo',
                        'steps' => [
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar in Context',
                                'hook' => "Every \"I usually...\" you get right here is one less pause when you're speaking for real.",
                                'focus' => 'Present Simple + Adverbs of Frequency',
                                'frequency_starters' => [
                                    'I usually', 'I often', 'I sometimes', 'I rarely', "I don't usually", 'I never',
                                ],
                                'quick_check' => [
                                    ['wrong' => 'She go to work at eight.', 'correct' => 'She goes to work at eight.'],
                                    ['wrong' => "I doesn't exercise every day.", 'correct' => "I don't exercise every day."],
                                    ['wrong' => 'He usually wake up late.', 'correct' => 'He usually wakes up late.'],
                                ],
                            ],
                            [
                                'key' => 'activation',
                                'label' => 'Activation',
                                'hook' => "Say it once here, alone — it'll come out easier when someone's actually listening.",
                                'task' => 'Write 5 personal sentences about your daily life using the new vocabulary, then record 2 minutes of solo speaking without reading.',
                            ],
                        ],
                    ],
                    [
                        'phase' => 'mission',
                        'label' => 'Mission',
                        'mode' => 'ai',
                        'steps' => [
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'AI Conversation #1',
                                'hook' => 'This is the real thing — the AI Instructor is listening, not testing.',
                                // Real interview questions from Mission01.pdf "Speaking Session 01".
                                'interview_questions' => [
                                    'What time do you usually wake up?',
                                    'What do you normally do in the morning?',
                                    'What is the busiest part of your day?',
                                    'What do you usually do after work/class?',
                                    'How often do you exercise?',
                                    'What do you usually do in the evening?',
                                ],
                            ],
                            [
                                'key' => 'ai_feedback_1',
                                'label' => 'AI Feedback #1',
                                'hook' => 'A second pair of ears just heard everything you said — here\'s what stood out.',
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'hook' => 'Putting it on paper often reveals what you actually think about your own day.',
                                'title' => 'A typical day in my life',
                                'prompts' => ['Morning', 'Work / Study', 'Afternoon', 'Evening', 'Free Time', 'Weekend'],
                                'try_to_use' => ['usually', 'normally', 'often', 'sometimes', 'rarely', 'after that', 'then'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'AI Conversation #2 — Final Challenge',
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                // Real rounds + requirements from Mission01.pdf "Speaking Session 02".
                                'rounds' => [
                                    'Describe your typical weekday, with no preparation.',
                                    'Compare your weekday with your weekend.',
                                    'What part of your routine do you enjoy most?',
                                ],
                                'final_prompt' => 'Speak for 3 minutes without stopping about your daily life.',
                                'requirements' => [
                                    'Present Simple',
                                    '5+ vocabulary expressions',
                                    '3+ frequency expressions',
                                    'Reasons / details',
                                    '1+ BBC expression',
                                    'Talks about a weekday',
                                    'Talks about the weekend',
                                ],
                            ],
                            [
                                'key' => 'active_recall',
                                'label' => 'Active Recall',
                                'hook' => 'No peeking. This is exactly how real conversations work — no notes, just what stuck.',
                                'instruction' => 'Without looking at the previous pages.',
                                'sections' => [
                                    ['key' => 'expressions', 'label' => '5 expressions I learned', 'count' => 5],
                                    ['key' => 'listening_facts', 'label' => '3 things I learned from the listening', 'count' => 3],
                                    ['key' => 'present_simple_sentences', 'label' => '3 Present Simple sentences', 'count' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'Error Log',
                                'hook' => 'Mistakes are proof you tried something new. Let\'s fix a few, for good.',
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'hook' => 'You started this mission with a number — let\'s see how far it moved.',
                                // Real structure from Mission01.pdf page 12 "Mission complete".
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => 'What became easier?',
                                    'still_difficult' => 'What is still difficult?',
                                    'expression_to_keep' => 'One expression I want to keep using',
                                    'grammar_to_review' => 'One grammar point I need to review',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Copies a mission's real asset from document/{code}/ (source of truth,
     * kept out of the public disk's git history) into the public storage
     * disk, and returns its public URL. Future missions reuse this by
     * dropping their own document/M0X/ folder — see EOS-009 §7.
     */
    private function publishMissionAsset(string $missionCode, string $filename): string
    {
        $source = base_path("document/{$missionCode}/{$filename}");
        $relative = 'missions/'.strtolower($missionCode).'/'.$filename;

        File::ensureDirectoryExists(dirname(storage_path("app/public/{$relative}")));
        File::copy($source, storage_path("app/public/{$relative}"));

        return Storage::disk('public')->url($relative);
    }
}
