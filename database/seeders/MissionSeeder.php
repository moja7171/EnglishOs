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
                                // Word selection follows every sub-topic of English Vocabulary in Use
                                // Unit 16, "Daily routines" (Sleep / Food / Bathroom routines / Housework
                                // / Spare time — the book's own 5 sections). The story and every meaning
                                // below are written fresh for this app, not copied from it (see EOS-009
                                // §14: content stays original, licensing/piracy risk).
                                'story' => [
                                    [
                                        'heading' => 'Sleep',
                                        'text' => 'During the week, I **wake up** at half past six and '
                                            .'**get up** straight away. I usually **go to bed** around '
                                            .'eleven and **go to sleep** quickly, but sometimes I '
                                            .'**have a late night** if I\'m out with friends. At weekends '
                                            .'I like to **have a sleep** in the afternoon instead.',
                                    ],
                                    [
                                        'heading' => 'Food',
                                        'text' => 'For breakfast I usually have **cereal**, then at work I '
                                            .'**have a light lunch** — just a sandwich and a **snack** in '
                                            .'the afternoon. If I\'m tired, I sometimes **don\'t bother** '
                                            .'cooking and order a **takeaway** instead. Before I leave, I '
                                            .'always **feed** the cat.',
                                    ],
                                    [
                                        'heading' => 'Bathroom routines',
                                        'text' => 'In the morning I **have a shower**, but on busy days I '
                                            .'just **have a wash** instead. I always **clean my teeth** '
                                            .'before breakfast, and in the evening my sister **puts on** '
                                            .'her make-up before going out. On lazy Sundays, I sometimes '
                                            .'**have a bath** instead of a shower.',
                                    ],
                                    [
                                        'heading' => 'Housework',
                                        'text' => '**Fortunately**, we have a **cleaner** who helps with '
                                            .'most of the **housework**. I still do some of the '
                                            .'**ironing** myself, and every Saturday we **do the '
                                            .'shopping** together. Nobody enjoys **doing the washing**, '
                                            .'but somebody has to do it!',
                                    ],
                                    [
                                        'heading' => 'Spare time',
                                        'text' => 'Most weekdays I **stay in** and relax, but at the '
                                            .'weekend I like to **go out** with friends. We often '
                                            .'**eat out** at a new restaurant, and sometimes people '
                                            .'**come round** to my place instead. My best friend calls me '
                                            .'**once a week** just to **chat**.',
                                    ],
                                ],
                                'story_words' => [
                                    // Sleep
                                    ['phrase' => 'wake up', 'meaning' => 'to stop sleeping and become conscious'],
                                    ['phrase' => 'get up', 'meaning' => 'to get out of bed after waking up'],
                                    ['phrase' => 'go to bed', 'meaning' => 'to get into bed to sleep'],
                                    ['phrase' => 'go to sleep', 'meaning' => 'to start sleeping'],
                                    ['phrase' => 'have a late night', 'meaning' => 'to go to bed much later than usual'],
                                    ['phrase' => 'have a sleep', 'meaning' => 'to rest for a short period during the day'],
                                    // Food
                                    ['phrase' => 'cereal', 'meaning' => 'a breakfast food made from grain, eaten with milk'],
                                    ['phrase' => 'have a light lunch', 'meaning' => 'to eat a small meal at midday'],
                                    ['phrase' => 'snack', 'meaning' => 'a small amount of food eaten between meals'],
                                    ['phrase' => "don't bother", 'meaning' => "to not do something because it's too much effort"],
                                    ['phrase' => 'takeaway', 'meaning' => 'a meal bought from a restaurant but eaten at home'],
                                    ['phrase' => 'feed', 'meaning' => 'to give food to a person or animal'],
                                    // Bathroom routines
                                    ['phrase' => 'have a shower', 'meaning' => 'to wash your whole body under running water'],
                                    ['phrase' => 'have a wash', 'meaning' => 'to quickly clean part of your body'],
                                    ['phrase' => 'clean my teeth', 'meaning' => 'to brush your teeth'],
                                    ['phrase' => 'puts on', 'meaning' => 'applies something, like make-up, to the face'],
                                    ['phrase' => 'have a bath', 'meaning' => 'to sit and wash in a bath full of water'],
                                    // Housework
                                    ['phrase' => 'Fortunately', 'meaning' => 'luckily; because of good luck'],
                                    ['phrase' => 'cleaner', 'meaning' => 'a person who is paid to clean a home'],
                                    ['phrase' => 'housework', 'meaning' => 'the work of keeping a home clean and tidy'],
                                    ['phrase' => 'ironing', 'meaning' => 'using an iron to make clothes smooth'],
                                    ['phrase' => 'do the shopping', 'meaning' => 'to buy food and other things you need'],
                                    ['phrase' => 'doing the washing', 'meaning' => 'washing dirty clothes'],
                                    // Spare time
                                    ['phrase' => 'stay in', 'meaning' => 'to spend your evening at home instead of going out'],
                                    ['phrase' => 'go out', 'meaning' => 'to leave home to do something for fun'],
                                    ['phrase' => 'eat out', 'meaning' => 'to have a meal at a restaurant instead of at home'],
                                    ['phrase' => 'come round', 'meaning' => 'to visit someone at their home'],
                                    ['phrase' => 'once a week', 'meaning' => 'happening one time every week'],
                                    ['phrase' => 'chat', 'meaning' => 'to have an informal, friendly conversation'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'hook' => 'Neil and Georgie are chatting about their mornings right now — how much can you catch without reading along?',
                                'source' => 'BBC Learning English — Real Easy English: Mornings (2025)',
                                'audio_url' => $audioUrl,
                                'transcript_ref' => 'document/M01/RealEasyEnglish_mornings__transcript.pdf',
                                // The exact 5 expressions, with the meanings Neil & Georgie themselves
                                // gave in the podcast's own end-of-episode recap (page 5 of the transcript).
                                'target_phrases' => [
                                    ['phrase' => 'get up', 'meaning' => 'to stand up and leave your bed'],
                                    ['phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual'],
                                    ['phrase' => 'oversleep', 'meaning' => 'to sleep longer than you should, by accident'],
                                    ['phrase' => 'skip breakfast', 'meaning' => 'to not eat breakfast, when you usually do'],
                                    ['phrase' => 'morning person', 'meaning' => 'someone who has a lot of energy at the start of the day'],
                                ],
                                // A faithful summary of the real transcript — used to ground the AI check in
                                // what was actually said, so an off-topic answer can be caught as irrelevant.
                                'topic_summary' => 'Neil and Georgie talk about their morning routines: whether '
                                    .'they like to get up early or sleep in, whether they eat breakfast or '
                                    .'sometimes skip it, whether they ever oversleep, whether they exercise in '
                                    .'the morning, and how Neil checks the weather before choosing his clothes.',
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
                                'lesson' => [
                                    'intro' => "We'll cover three things here: how the verb form changes for "
                                        .'he / she / it, how to ask and answer with do / does, and where words '
                                        .'like always, usually, and never go in a sentence.',
                                    'conjugation_examples' => [
                                        ['base' => 'I wake up early.', 'third_person' => 'She wakes up early.'],
                                        ['base' => 'I go to work by bus.', 'third_person' => 'He goes to work by bus.'],
                                        ['base' => 'I have breakfast at eight.', 'third_person' => 'She has breakfast at eight.'],
                                    ],
                                    'question_example' => 'Do you usually wake up early?',
                                    'question_example_does' => 'Does she work on Saturdays?',
                                    'negative_example' => "I don't usually wake up before seven.",
                                    'negative_example_does' => "He doesn't work on Sundays.",
                                    'frequency_scale' => ['always', 'usually', 'often', 'sometimes', 'rarely', 'never'],
                                    'word_order_examples' => [
                                        ['rule' => 'One-word verb → the adverb goes before it', 'example' => 'I always wake up early.', 'adverb' => 'always'],
                                        ['rule' => 'With am/is/are → the adverb goes after it', 'example' => "I'm usually tired in the morning.", 'adverb' => 'usually'],
                                        ['rule' => 'Two-word verb → the adverb goes after the first word', 'example' => "I don't usually work at night.", 'adverb' => 'usually'],
                                    ],
                                    'bridge_note' => "You'll put this straight to use next — in Activation, when you talk about your real daily routine out loud.",
                                ],
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
                        // Split from the original single "Mission" phase (2026-09) —
                        // 7 steps there vs. 3/2 on the two solo days made Day 3 nearly
                        // 3.5x heavier than Day 2. This is the first half of that same
                        // AI Instructor mission: the first conversation, its feedback,
                        // and the writing task — a natural "practice session" cluster.
                        'phase' => 'practice',
                        'label' => 'Practice',
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
                        ],
                    ],
                    [
                        // Second half of the original "Mission" phase: review what
                        // stuck, fix recurring mistakes, THEN the harder final
                        // conversation (error_log deliberately stays before
                        // ai_conversation_2 so corrections get applied in the
                        // Final Challenge, not just logged), then the result.
                        'phase' => 'challenge',
                        'label' => 'Challenge',
                        'mode' => 'ai',
                        'steps' => [
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
