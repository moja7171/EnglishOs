<?php

namespace Database\Seeders;

use App\Models\Mission;
use App\Services\MediaHost;
use App\Services\PexelsClient;
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
        $shadowingVideoUrl = $this->publishMissionAsset('M01', 'Bob the Canadian - Daily Routine.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M01', 'Bob the Canadian - Daily Routine.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M01'],
            [
                'title' => 'My Daily Life',
                'module' => 'Me',
                'outcome' => 'I can talk about my daily routine, habits, work/study and free time.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        // Pair Practice speaking cards for this day (App\Services\PairPractice):
                        // the asker reads the question, sees the short model answer and ticks
                        // each target word the answerer uses. Grammar only from Day 2 on.
                        'pair_speaking' => [
                            ['question' => 'Are you a morning person?', 'answer' => 'No, I\'m not a morning person. I get up at seven, but I feel tired until ten.', 'words' => ['morning person', 'get up']],
                            ['question' => 'Do you sleep in at the weekend?', 'answer' => 'Yes, I always sleep in on Fridays. I get up at about ten.', 'words' => ['sleep in', 'always']],
                            ['question' => 'What happens when you oversleep?', 'answer' => 'When I oversleep, I usually skip breakfast and run to the bus.', 'words' => ['oversleep', 'usually']],
                            ['question' => 'Do you ever skip breakfast? Why?', 'answer' => 'Sometimes I skip breakfast because I\'m not hungry in the morning.', 'words' => ['skip breakfast', 'sometimes']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => "Imagine a new coworker turns to you and asks: \"So, what's your day usually "
                                    .'like?" Could you answer right now, without stopping to think?',
                                // A wide cover banner, not the small circular portrait style
                                // Reading uses for Aisha specifically — this represents the
                                // mission's theme in general (starting the day), not one person.
                                // Dual-coding, purely decorative, fails soft like every other
                                // PexelsClient call.
                                'image_query' => 'sunrise alarm clock bedroom window',
                                // Silent, looping background clip for the mission's own hero panel
                                // (see ⚡runner.blade.php) — mood-setting only, matches the same
                                // "starting the day" theme as the cover image above. Fails soft
                                // like every other PexelsClient call.
                                'ambient_video_query' => 'quiet morning coffee sunrise',
                                'warm_up_questions' => [
                                    'What time do you usually wake up?',
                                    'What do you usually do in the morning?',
                                    'What do you do after work/class?',
                                    'What do you usually do at weekends?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Next time someone asks about your morning, will these words be ready — or will you go quiet?',
                                // Mission structure redesign, Epic B: Vocabulary Builder now
                                // spreads 5-6 curated words across each mission's 3 days instead
                                // of one 8+-word free-for-all. Day 1 is fixed to exactly the real
                                // Listening episode's own 5 target_phrases (never more than 5) so
                                // every learner meets them here first, then hears them again in
                                // context — see ⚡vocabulary-builder.blade.php and the redesign
                                // notes. Story/meanings are written fresh for this app (EOS-009
                                // §14: content stays original).
                                'story' => "I'm not really a **morning person** — most days I **get up** at half "
                                    .'past six. At the weekend, though, I love to **sleep in**. Some mornings I '
                                    .'**oversleep** by accident and I\'m late! I never **skip breakfast**, even '
                                    .'when I\'m in a hurry.',
                                'words' => [
                                    ['phrase' => 'morning person', 'meaning' => 'someone who has a lot of energy at the start of the day', 'meaning_fa' => 'آدم صبح‌ها (کسی که صبح پرانرژی است)', 'pos' => 'noun phrase', 'synonym' => 'opposite: a night owl', 'example' => 'My sister is a real morning person.', 'difficulty' => 'medium'],
                                    ['phrase' => 'get up', 'meaning' => 'to get out of bed after waking up', 'meaning_fa' => 'از خواب بلند شدن، از تخت بیرون آمدن', 'pos' => 'phrasal verb', 'synonym' => 'rise', 'example' => 'He gets up at six every morning.', 'difficulty' => 'easy'],
                                    ['phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual', 'meaning_fa' => 'دیرتر از معمول خوابیدن (صبح تا دیروقت در رخت‌خواب ماندن)', 'pos' => 'phrasal verb', 'example' => 'We always sleep in on Sundays.', 'difficulty' => 'hard'],
                                    ['phrase' => 'oversleep', 'meaning' => 'to sleep longer than you should, by accident', 'meaning_fa' => 'ناخواسته بیشتر از حد خوابیدن (خواب ماندن)', 'pos' => 'verb', 'example' => 'I overslept and missed the bus.', 'difficulty' => 'medium'],
                                    ['phrase' => 'skip breakfast', 'meaning' => 'to not eat breakfast, when you usually do', 'meaning_fa' => 'صبحانه نخوردن', 'pos' => 'verb phrase', 'example' => "Don't skip breakfast before an exam.", 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                // Was 15, then 18 for the transcript review, then 21 for the "pick
                                // which words join My Words" recap step, then 20 (detail question
                                // moved to an optional Quick Round bonus, -2; a comprehension Quick
                                // Round warm-up added, +1); now 24 — the real BBC audio is 6:44
                                // (ffprobe-verified), and the transcript gate requires 2 real listens
                                // (13:30 alone), which 20 never actually accounted for.
                                'duration_minutes' => 24,
                                'hook' => 'Neil and Georgie are chatting about their mornings right now — how much can you catch without reading along?',
                                'source' => 'BBC Learning English — Real Easy English: Mornings (2025)',
                                // Purely decorative episode cover — dual-coding, same principle
                                // as Mission Brief's own image_query. Doesn't affect duration.
                                'image_query' => 'morning coffee',
                                'audio_url' => $audioUrl,
                                // Real Whisper segments (text + start/end seconds) for the synced,
                                // advancing-with-the-audio text display on the first sub-step — see
                                // missions:cache-shadow-timestamps. [] until that command has been
                                // run for this mission.
                                'listening_segments' => $this->shadowTimestampsFor('M01', 'listening_segments'),
                                // The same chunks split at every change of speaker and tagged with who is
                                // talking, so the panel reads as a chat — see missions:align-listening-speakers.
                                'listening_turns' => $this->shadowTimestampsFor('M01', 'listening_turns'),
                                'transcript_ref' => 'document/M01/RealEasyEnglish_mornings__transcript.pdf',
                                // Full real transcript (BBC Learning English, "Real Easy English:
                                // Mornings", 2025) — shown in-app only after the learner has genuinely
                                // listened twice (see ⚡listening.blade.php), so it's a check, not a
                                // shortcut around the actual listening practice.
                                'transcript' => [
                                    ['speaker' => 'Neil', 'text' => "Hello and welcome to Real Easy English, the podcast where we have real conversations in easy English to help you learn. I'm Neil."],
                                    ['speaker' => 'Georgie', 'text' => "And I'm Georgie. You can read along with this podcast on our website where you can also find a free worksheet."],
                                    ['speaker' => 'Neil', 'text' => 'So, Georgie, how are you today?'],
                                    ['speaker' => 'Georgie', 'text' => "I'm very well, thank you. How are you?"],
                                    ['speaker' => 'Neil', 'text' => "I'm well, thank you very much."],
                                    ['speaker' => 'Georgie', 'text' => 'Good.'],
                                    ['speaker' => 'Neil', 'text' => 'What are we talking about today?'],
                                    ['speaker' => 'Georgie', 'text' => 'Today we are talking about our morning routines. That is what we usually do in the mornings before work.'],
                                    ['speaker' => 'Neil', 'text' => "OK, let's get started."],
                                    ['speaker' => 'Georgie', 'text' => 'So, Neil, do you like to get up early or do you prefer to sleep in?'],
                                    ['speaker' => 'Neil', 'text' => "It depends if I've had enough sleep. If I've had enough sleep, I can get up early. If I haven't, I want to sleep in. How about you?"],
                                    ['speaker' => 'Georgie', 'text' => "Well, I like the mornings sometimes because I feel like it's quieter. Not many people are up. I also feel like my brain works better in the mornings, so that helps. But I do like sleeping in sometimes, especially at the weekends."],
                                    ['speaker' => 'Neil', 'text' => "So, Georgie, you said that your brain works best in the morning. Does that mean that you're a morning person?"],
                                    ['speaker' => 'Georgie', 'text' => "Yes, I think it does. I'm a morning person. That means someone that has a lot of energy at the start of the day."],
                                    ['speaker' => 'Neil', 'text' => 'Georgie, do you eat breakfast?'],
                                    ['speaker' => 'Georgie', 'text' => "That also depends. And I have a different breakfast routine depending if I'm working in the office or at home. So if I'm in the office, and you know this, I usually have a pastry or a croissant in the morning and a banana if I'm feeling good. But if I'm at home, I usually have eggs a bit later, around ten. What about you?"],
                                    ['speaker' => 'Neil', 'text' => 'I always have breakfast before I leave the house.'],
                                    ['speaker' => 'Georgie', 'text' => 'Wow.'],
                                    ['speaker' => 'Neil', 'text' => "Otherwise I'm very grumpy. So, at the moment I am having egg on toast for breakfast and then marmalade on toast as well. But not together."],
                                    ['speaker' => 'Georgie', 'text' => 'So, you said you always have breakfast. You never skip breakfast?'],
                                    ['speaker' => 'Neil', 'text' => "Never. Unless there's a very, very good reason."],
                                    ['speaker' => 'Georgie', 'text' => 'OK.'],
                                    ['speaker' => 'Neil', 'text' => 'Like, I think the last time I skipped breakfast, I needed to get up at 3am to catch a flight.'],
                                    ['speaker' => 'Georgie', 'text' => "Yes. It's very strange to wake up at that time. It changes your routine, doesn't it? Because you don't feel hungry then."],
                                    ['speaker' => 'Neil', 'text' => 'So how about you, Georgie? Do you skip breakfast?'],
                                    ['speaker' => 'Georgie', 'text' => "Sometimes I skip breakfast, because when I wake up, I'm not hungry. So, if I don't eat breakfast straight away, I sometimes forget to eat breakfast, but then I have an early lunch."],
                                    ['speaker' => 'Neil', 'text' => 'Right.'],
                                    ['speaker' => 'Georgie', 'text' => "So, Neil, you said that it's hard to get up when you haven't slept well. Does that mean you oversleep, you sleep too long?"],
                                    ['speaker' => 'Neil', 'text' => 'No. Unfortunately, I never oversleep. In fact, I usually wake up before my alarm. Do you oversleep?'],
                                    ['speaker' => 'Georgie', 'text' => "Well, I usually wake up with my alarm. I never wake up before my alarm. So sometimes if I don't set my alarm, I oversleep. That actually happened this week. But it was OK because I woke up in time for work. I didn't oversleep that much."],
                                    ['speaker' => 'Neil', 'text' => "Right. Have you ever overslept so much that you've missed work or missed the start of the working day?"],
                                    ['speaker' => 'Georgie', 'text' => 'Never work. But yes, to a flight. That was one of the most stressful mornings I\'ve had.'],
                                    ['speaker' => 'Neil', 'text' => 'Is there anything else, Georgie, that you do in the morning regularly?'],
                                    ['speaker' => 'Georgie', 'text' => 'Well, regularly maybe not. But sometimes if I feel motivated, I manage to do some exercise in the mornings, either running, or actually, this morning I did a Pilates workout. What about you, Neil?'],
                                    ['speaker' => 'Neil', 'text' => "No, I can't exercise until later because my body doesn't enjoy that – especially running. All of my joints hurt."],
                                    ['speaker' => 'Georgie', 'text' => 'So what do you do in the mornings?'],
                                    ['speaker' => 'Neil', 'text' => "I like to know what the weather's going to be like. So I check the forecast and I choose my clothes so that I'm wearing the right thing for the type of weather."],
                                    ['speaker' => 'Georgie', 'text' => 'That\'s very sensible.'],
                                    ['speaker' => 'Neil', 'text' => 'Especially in the United Kingdom because the weather can be different every day.'],
                                    ['speaker' => 'Georgie', 'text' => "That's very true. Make sure you've got your umbrella."],
                                    ['speaker' => 'Neil', 'text' => 'Or not.'],
                                    ['speaker' => 'Georgie', 'text' => "OK, let's recap the language we heard during the conversation."],
                                    ['speaker' => 'Neil', 'text' => 'We heard a few ways to talk about what time we sleep until. We heard sleep in, which means staying in bed and sleeping later than we usually do. And we also heard oversleep, which means sleeping longer than you should accidentally.'],
                                    ['speaker' => 'Georgie', 'text' => 'We had get up, which is a phrasal verb we use to say stand up and leave your bed.'],
                                    ['speaker' => 'Neil', 'text' => "And we heard a morning person. Like you, Georgie, you're a morning person. A morning person is someone who likes the mornings, has lots of energy at the start of the day. I'm not really a morning person."],
                                    ['speaker' => 'Georgie', 'text' => 'We had skip, not do something that you usually do or should do. For example, sometimes I forget to eat in the morning, so I skip breakfast and have an early lunch instead.'],
                                    ['speaker' => 'Neil', 'text' => "That's it for this episode of Real Easy English. You can test what you've learned with our worksheet on our website. Find the link in the notes and go there."],
                                    ['speaker' => 'Georgie', 'text' => "And if you want to learn more language to talk about your morning routine, I've made a video all about phrasal verbs for your morning routine. You can also find it at bbclearningenglish.com."],
                                    ['speaker' => 'Neil', 'text' => "And next time we'll talk about our evening routines, what we get up to in the evenings."],
                                    ['speaker' => 'Georgie', 'text' => 'See you next time.'],
                                    ['speaker' => 'Neil', 'text' => 'Goodbye.'],
                                ],
                                // The exact 5 expressions, with the meanings Neil & Georgie themselves
                                // gave in the podcast's own end-of-episode recap (page 5 of the transcript).
                                // gap_before/gap_after are real, single-blank sentences lifted straight
                                // from the transcript — the Third listening gap-fill exercise below.
                                'target_phrases' => [
                                    [
                                        'phrase' => 'get up', 'meaning' => 'to stand up and leave your bed',
                                        'gap_before' => "It depends if I've had enough sleep. If I've had enough sleep, I can ",
                                        'gap_after' => ' early.',
                                    ],
                                    [
                                        'phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual',
                                        'gap_before' => "If I haven't, I want to ", 'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'oversleep', 'meaning' => 'to sleep longer than you should, by accident',
                                        'gap_before' => 'In fact, I usually wake up before my alarm. Do you ', 'gap_after' => '?',
                                    ],
                                    [
                                        'phrase' => 'skip breakfast', 'meaning' => 'to not eat breakfast, when you usually do',
                                        'gap_before' => 'So, you said you always have breakfast. You never ', 'gap_after' => '?',
                                    ],
                                    [
                                        'phrase' => 'morning person', 'meaning' => 'someone who has a lot of energy at the start of the day',
                                        'gap_before' => "Yes, I think it does. I'm a ",
                                        'gap_after' => '. That means someone that has a lot of energy at the start of the day.',
                                    ],
                                ],
                                // A faithful summary of the real transcript — used to ground the AI check in
                                // what was actually said, so an off-topic answer can be caught as irrelevant.
                                'topic_summary' => 'Neil and Georgie talk about their morning routines: whether '
                                    .'they like to get up early or sleep in, whether they eat breakfast or '
                                    .'sometimes skip it, whether they ever oversleep, whether they exercise in '
                                    .'the morning, and how Neil checks the weather before choosing his clothes.',
                                // A quick true/false warm-up right after the first listen — plain
                                // client-side <x-quick-round> cards (see EOS-009 §8), ungraded and
                                // always skippable, just to get the ear engaged before the real
                                // gist/expressions writing starts.
                                // difficulty (Story 4, requirements review, 2026-09-04) drives
                                // <x-quick-round>'s adaptive mode: (1) the episode's whole topic,
                                // stated in the first exchange — easy; (2) a direct contradiction
                                // of a clearly stated fact ("Never. Unless there's a very, very
                                // good reason.") — medium; (3) needs remembering WHO said the
                                // "morning person" line, not just that the phrase was used by
                                // someone — hard.
                                'comprehension_check' => [
                                    ['statement' => 'Neil and Georgie are talking about their morning routines.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Neil says he often skips breakfast.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Georgie describes herself as a morning person.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                // A one-tap <x-quick-round> bonus in the Wrap-up sub-step (not a
                                // required field — never blocks Continue) with one real, checkable
                                // fact from the episode.
                                'detail_question' => [
                                    'question' => 'What time did Neil need to get up to catch his flight, the last time he skipped breakfast?',
                                    'options' => ['7am', '3am', '9am'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only
                                // (synced text + gap-fill); shadowing is Listen Again's job
                                // (daily_listen_2/3, see DailyListenStep) so it never has to
                                // compete with the very first listen's own comprehension goal.
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'What do you do first every morning?', 'answer' => 'I usually have a shower first, and then I clean my teeth.', 'words' => ['have a shower', 'clean my teeth', 'usually']],
                            ['question' => 'What do you usually have for breakfast?', 'answer' => 'I often have cereal with milk, and I never drink coffee in the morning.', 'words' => ['cereal', 'often', 'never']],
                            ['question' => 'How often do you get a takeaway?', 'answer' => 'We get a takeaway about once a week, usually on Friday night.', 'words' => ['takeaway', 'once a week']],
                            ['question' => 'Who does the shopping in your family?', 'answer' => 'My mum usually does the shopping, but I sometimes buy a snack on the way home.', 'words' => ['does the shopping', 'snack']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'A few more everyday words — the kind you actually reach for at home.',
                                'story' => 'For breakfast I usually have **cereal**. In the morning I **have a '
                                    .'shower** and **clean my teeth** before I leave. In the afternoon I have a '
                                    .'**snack** if I\'m hungry. If I\'m tired in the evening, we sometimes order '
                                    .'a **takeaway** instead of cooking. On Saturdays we **do the shopping** '
                                    .'together.',
                                'words' => [
                                    ['phrase' => 'cereal', 'meaning' => 'a breakfast food made from grain, eaten with milk', 'meaning_fa' => 'غلات صبحانه (کورن‌فلکس و مانند آن)', 'pos' => 'noun', 'example' => 'I had a bowl of cereal for breakfast.', 'image_query' => 'bowl of cereal breakfast', 'difficulty' => 'easy'],
                                    ['phrase' => 'have a shower', 'meaning' => 'to wash your whole body under running water', 'meaning_fa' => 'دوش گرفتن', 'pos' => 'verb phrase', 'synonym' => 'shower (verb)', 'example' => 'I have a shower every morning before work.', 'image_query' => 'man showering bathroom', 'difficulty' => 'easy'],
                                    ['phrase' => 'clean my teeth', 'meaning' => 'to brush your teeth', 'meaning_fa' => 'مسواک زدن', 'pos' => 'verb phrase', 'synonym' => 'brush my teeth', 'example' => 'Remember to clean your teeth before bed.', 'image_query' => 'man brushing his teeth toothbrush', 'difficulty' => 'easy'],
                                    ['phrase' => 'snack', 'meaning' => 'a small amount of food eaten between meals', 'meaning_fa' => 'میان‌وعده', 'pos' => 'noun', 'synonym' => 'nibble', 'example' => 'I had a snack around four o\'clock.', 'image_query' => 'healthy snack food', 'difficulty' => 'easy'],
                                    ['phrase' => 'takeaway', 'meaning' => 'a meal bought from a restaurant but eaten at home', 'meaning_fa' => 'غذای بیرون‌بر', 'pos' => 'noun', 'synonym' => 'takeout', 'example' => 'We ordered a takeaway on Friday night.', 'image_query' => 'takeaway food box', 'difficulty' => 'medium'],
                                    ['phrase' => 'do the shopping', 'meaning' => 'to buy food and other things you need', 'meaning_fa' => 'خرید کردن (خرید خانه)', 'pos' => 'verb phrase', 'synonym' => 'go shopping', 'example' => 'I do the shopping every Saturday morning.', 'image_query' => 'grocery shopping', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                // Was 2, then 3 for the recall prompt (+1); now 8 — the audio-ended
                                // gate genuinely requires the full 6:44 (ffprobe-verified) BBC
                                // episode to play through, which 3 never accounted for.
                                'duration_minutes' => 10,
                                'hook' => 'Let your ear warm up to English again — the same real episode, start to finish.',
                                // Purely decorative — a different image than Day 1's own for
                                // visual variety across the 4 listens of the same episode.
                                'image_query' => 'sunrise bedroom window',
                                // This day's own small shadow pool (Epic C) — distinct from
                                // every other daily-listen day's, so no line repeats across
                                // the mission. shadow_timestamps are real Whisper-derived
                                // pause points (see missions:cache-shadow-timestamps) — [] until
                                // that command has been run for this mission.
                                'shadow_lines' => [
                                    'I **always** have **breakfast** before I **leave** the **house**.',
                                    'I **usually** **wake up** **before** my **alarm**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M01', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 14,
                                'hook' => "Every \"I usually...\" you get right here is one less pause when you're speaking for real.",
                                'focus' => 'Present Simple + Adverbs of Frequency',
                                // The lesson is fully data-driven (see grammar-in-context.blade.php)
                                // so this same step can teach any grammar point a mission seeds it
                                // with — a `sections` list of {heading, body, blocks[]}, each block
                                // one of the component's generic shapes: rule / formula / do_dont /
                                // mistake_fix / pairs / examples / chips / rule_examples (see
                                // partials/grammar-lesson-section.blade.php). One idea per section;
                                // the optional `fa` on a `rule` block is a folded-away Persian line.
                                'lesson' => [
                                    'intro' => 'Three small things: the verb with he / she / it, questions with do / does, '
                                        .'and where words like always and never go.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · He / she / it needs an -s',
                                            'body' => 'With <strong>I / we / you / they</strong> nothing changes: <em>I wake up</em>.',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'With <strong>he / she / it</strong>, add <strong>-s</strong> to the verb.',
                                                    'fa' => 'برای he / she / it، به آخر فعل «s» اضافه می‌کنیم.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'The pattern',
                                                    'parts' => [
                                                        ['text' => 'She', 'caption' => 'he / she / it'],
                                                        ['text' => 'wakes', 'caption' => 'verb + s'],
                                                        ['text' => 'up early', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    // Epic D: a short "spot the mistake" moment.
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Leo',
                                                    'wrong' => 'She wake up early every day.',
                                                    'right' => 'She wakes up early every day.',
                                                    'explanation' => 'With he / she / it, the verb needs an -s ending.',
                                                ],
                                                [
                                                    'type' => 'pairs',
                                                    'pairs' => [
                                                        ['left' => 'I wake up early.', 'right' => 'She wakes up early.'],
                                                        ['left' => 'I go to work by bus.', 'right' => 'He goes to work by bus.'],
                                                        ['left' => 'I have breakfast at eight.', 'right' => 'She has breakfast at eight.'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · Questions and “no” use do / does',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Ask with <strong>do / does</strong>. Say “no” with <strong>don\'t / doesn\'t</strong>.',
                                                    'fa' => 'برای سؤال از do / does و برای منفی از don\'t / doesn\'t استفاده می‌کنیم. بعد از آن‌ها، فعل بدون «s» می‌آید.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'A question',
                                                    'parts' => [
                                                        ['text' => 'Does', 'caption' => 'does + he / she / it'],
                                                        ['text' => 'she', 'caption' => 'who'],
                                                        ['text' => 'work', 'caption' => 'plain verb — no s!'],
                                                        ['text' => 'on Saturdays?', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'Does she works on Saturdays?', 'right' => 'Does she work on Saturdays?', 'highlight' => 'work', 'note' => 'After does, the verb has no -s.'],
                                                        ['wrong' => 'He don\'t work on Sundays.', 'right' => 'He doesn\'t work on Sundays.', 'highlight' => 'doesn\'t', 'note' => 'he / she / it → doesn\'t.'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        [
                                                            'label' => 'More examples',
                                                            'items' => [
                                                                'Do you usually wake up early?',
                                                                'I don\'t usually wake up before seven.',
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · Where do always / usually go?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Put the frequency word <strong>before</strong> the main verb.',
                                                    'fa' => 'قید تکرار (مثل always) معمولاً قبل از فعل اصلی می‌آید؛ اما بعد از am / is / are.',
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'From 100% to 0%', 'words' => ['always', 'usually', 'often', 'sometimes', 'rarely', 'never']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'rule_examples',
                                                    'items' => [
                                                        ['rule' => 'One-word verb → the adverb goes before it', 'example' => 'I always wake up early.', 'highlight' => 'always'],
                                                        ['rule' => 'With am / is / are → the adverb goes after it', 'example' => 'I\'m usually tired in the morning.', 'highlight' => 'usually'],
                                                        ['rule' => 'Two-word verb → the adverb goes after the first word', 'example' => 'I don\'t usually work at night.', 'highlight' => 'usually'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this soon — in AI Conversation #1, when you talk about your real daily routine out loud.',
                                ],
                                'frequency_starters' => [
                                    'I usually', 'I often', 'I sometimes', 'I rarely', "I don't usually", 'I never',
                                ],
                                // Feeds SentenceChecker::check()'s judgment/majorCriteria params and
                                // the "continues ___" tail of its context string (see
                                // grammar-in-context.blade.php's sentenceContext()) — kept in seeded
                                // content, not hardcoded, so a different mission's grammar focus gets
                                // its own AI judgment without touching the component.
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence, correctly using the present simple tense.',
                                'grammar_major_criteria' => 'the verb is not in the present simple tense, the '
                                    .'sentence does not actually continue the given starter, or it is not a genuine '
                                    .'personal statement',
                                'grammar_context' => 'continues in the present simple tense',
                                // Rendered as a tap-card <x-quick-round> (see EOS-009 §8) — the
                                // learner picks the correct fix among the real error plus one more
                                // plausible wrong attempt, instead of typing it out. Ungraded and
                                // skippable, like every other Quick Round.
                                // difficulty (Story 4, requirements review, 2026-09-04) drives
                                // <x-quick-round>'s adaptive mode: (1) one distractor is a bare
                                // spelling slip ("gos") — easy to rule out on sight; (2) both
                                // wrong options closely mimic the original do/does + third-person
                                // -s error pattern — medium; (3) the wrong distractor
                                // ("wake ups") is a subtler trap since it's the phrasal verb's
                                // second word that (wrongly) takes the -s, not the verb itself —
                                // easy to miss for a learner still forming the general rule — hard.
                                'quick_check' => [
                                    [
                                        'wrong' => 'She go to work at eight.',
                                        'options' => ['She gos to work at eight.', 'She goes to work at eight.', 'She go to work at eight.'],
                                        'correct' => 1,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => "I doesn't exercise every day.",
                                        'options' => ["I doesn't exercises every day.", 'I not exercise every day.', "I don't exercise every day."],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'He usually wake up late.',
                                        'options' => ['He usually waking up late.', 'He usually wakes up late.', 'He usually wake ups late.'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                // "Build the sentence" round (Epic D) — reuses sentences already
                                // seen in the lesson's own pairs/examples blocks above, so this is
                                // reinforcement, not new vocabulary. `words` is authored already
                                // scrambled; never reshuffled at render time (see
                                // <x-word-order-round>).
                                'word_order' => [
                                    ['words' => ['early.', 'wakes', 'She', 'up'], 'answer' => 'She wakes up early.'],
                                    ['words' => ['Saturdays?', 'work', 'she', 'Does', 'on'], 'answer' => 'Does she work on Saturdays?'],
                                    ['words' => ['early.', 'I', 'up', 'wake', 'always'], 'answer' => 'I always wake up early.'],
                                ],
                            ],
                            [
                                'key' => 'story_sequence',
                                'label' => 'Picture Story',
                                // New (2026-09-04) — wires <x-sequential-picture-story>, built
                                // earlier but never used: its own docblock assumed it needed a
                                // past-narrative mission, but a routine sequence narrates just as
                                // naturally in Present Simple, exactly what Grammar in Context
                                // just taught. Placed right after it on purpose — fresh applied
                                // practice for the tense, before Activation's personal speaking.
                                'duration_minutes' => 10,
                                'hook' => 'You just learned how Present Simple works. Now use it to tell someone '
                                    .'else\'s morning, one picture at a time.',
                                // Captions are ground truth for the AI feedback prompt only —
                                // never rendered to the learner (see the step's own docblock).
                                // 'alt_description' is a neutral scene description for screen
                                // readers — unlike 'caption', it never gives away the sequencing
                                // verb, so it can safely reach the learner (see story-sequence's
                                // own docblock on why 'caption' itself must not).
                                'sequence_images' => [
                                    ['image_query' => 'alarm clock ringing bedroom morning', 'caption' => 'She wakes up', 'alt_description' => 'An alarm clock on a nightstand in a bedroom'],
                                    ['image_query' => 'woman brushing teeth bathroom mirror', 'caption' => 'She has a shower and gets ready', 'alt_description' => 'A woman at a bathroom mirror getting ready'],
                                    ['image_query' => 'woman eating breakfast kitchen table', 'caption' => 'She has breakfast', 'alt_description' => 'A woman at a kitchen table with breakfast'],
                                    ['image_query' => 'woman commuting to work walking', 'caption' => 'She leaves for work', 'alt_description' => 'A woman walking outside on her way to work'],
                                ],
                                'sequencing_words' => ['First', 'Then', 'After that', 'Finally'],
                            ],
                            [
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                // ~7.5-minute video watched once with captions, part of it
                                // again without, plus shadowing.
                                'duration_minutes' => 14,
                                'hook' => 'A real teacher walks you through his whole morning at natural speed — watch it, then make your own voice do the same.',
                                'source' => 'Bob the Canadian — "Learn How To Talk About Your Daily Routine in English by Watching Me Act Out Mine"',
                                // Self-hosted via <x-video-player> — the user sourced this
                                // copy of the video themselves (see EOS-009 §8, video-hosting
                                // note). captions_url is the creator's own human-made English
                                // captions (not YouTube's auto-captions, which have no
                                // punctuation and overlapping cue timings), converted from
                                // .srt to WebVTT with the sound-effect cues removed.
                                'video_url' => $shadowingVideoUrl,
                                'captions_url' => $shadowingCaptionsUrl,
                                // Real caption cues (text + start/end seconds), reused directly
                                // for the synced text panel — see missions:cache-shadow-timestamps.
                                'video_segments' => $this->shadowTimestampsFor('M01', 'video_shadowing_segments'),
                                // Summarized in Claude's own words from the real video's own
                                // audio — never copied verbatim into this app (see EOS-009
                                // §14: content stays original, no licensing/piracy risk), same
                                // principle as the vocabulary story never copying Cambridge
                                // textbook text.
                                'topic_summary' => 'A Canadian teacher takes you through his weekday on camera, '
                                    .'from his alarm clock to the end of the school day: pressing snooze, '
                                    .'getting up, making the bed, cooking eggs and toast, getting dressed, '
                                    .'packing a lunch, driving ten minutes to work and teaching his classes — '
                                    .'and he stops along the way to explain the everyday English he uses '
                                    .'for each part of his routine.',
                                'comprehension_check' => [
                                    ['statement' => 'Bob presses the snooze button on his alarm clock before he gets up.', 'correct' => true],
                                    ['statement' => 'Bob buys his lunch every day.', 'correct' => false],
                                    ['statement' => 'It takes Bob about ten minutes to drive to work.', 'correct' => true],
                                ],
                                // Real phrases the video itself teaches, in Claude's own
                                // words — "wake up" vs "get up" is the video's own explicit
                                // contrast, and "get dressed" / "pack a lunch" are routine
                                // phrases the Day 3 "typical day" task reuses.
                                'target_phrases' => [
                                    ['phrase' => 'wake up', 'meaning' => 'to stop sleeping and become awake'],
                                    ['phrase' => 'get up', 'meaning' => 'to get out of bed'],
                                    ['phrase' => 'get dressed', 'meaning' => 'to put your clothes on'],
                                    ['phrase' => 'grab (something)', 'meaning' => 'to pick something up quickly'],
                                    ['phrase' => 'pack a lunch', 'meaning' => 'to put your lunch in a bag to take with you'],
                                ],
                                // Real verbatim lines from the video's own captions (unlike
                                // topic_summary above, which stays Claude's own words) — shadowing
                                // means mimicking the exact audio just heard, and the auto-pause
                                // timestamp cache (missions:cache-shadow-timestamps) only lands
                                // correctly on words the speaker actually says. Bold marks
                                // naturally-stressed content words, same convention as
                                // Listening's shadow_lines — rendered by <x-stress-marked-line>.
                                'shadow_lines' => [
                                    'The **very first** **thing** I **do** **every day** is I **wake up**.',
                                    'I **usually** **wanna** **sleep** just a **little** bit **more**.',
                                    'I **usually** **take** a **shower** **every day** before I **go** to **work**.',
                                    'So I **usually** **make** a **sandwich** and I **grab** a **banana**.',
                                    '**Every day** at about **7:30** **Monday** through **Friday**, I **go** to **work**.',
                                    'It **takes** me **only** 10 **minutes** to **drive** from my **house** to the **school** that I **work** at.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M01', 'video_shadowing'),
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
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Which housework do you hate?', 'answer' => 'I hate most housework, especially doing the washing.', 'words' => ['housework', 'doing the washing']],
                            ['question' => 'Do you stay in or go out on Friday night?', 'answer' => 'I usually stay in on Fridays, but on Saturdays I go out with friends.', 'words' => ['stay in', 'go out']],
                            ['question' => 'How often do you eat out?', 'answer' => 'We eat out about twice a month, usually at a small pizza place near our house.', 'words' => ['eat out', 'twice a month']],
                            ['question' => 'Who do you chat with every day?', 'answer' => 'I chat with my sister every evening. We never miss a day.', 'words' => ['chat', 'never']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set for this mission — the words for a relaxed weekend at home.',
                                'story' => 'At the weekend I do some **housework** — **doing the washing** is my '
                                    .'least favourite job! Most weekdays I **stay in** and relax, but on Friday '
                                    .'I like to **go out** with friends. We often **eat out** at a new '
                                    .'restaurant, and I always **chat** with my best friend on the phone.',
                                'words' => [
                                    ['phrase' => 'housework', 'meaning' => 'the work of keeping a home clean and tidy', 'meaning_fa' => 'کارهای خانه', 'pos' => 'noun', 'synonym' => 'chores', 'example' => 'We share the housework equally.', 'image_query' => 'cleaning house vacuum', 'difficulty' => 'easy'],
                                    ['phrase' => 'doing the washing', 'meaning' => 'washing dirty clothes', 'meaning_fa' => 'لباس شستن', 'pos' => 'verb phrase', 'synonym' => 'doing the laundry', 'example' => 'I hate doing the washing on weekends.', 'image_query' => 'loading clothes into washing machine', 'difficulty' => 'medium'],
                                    ['phrase' => 'stay in', 'meaning' => 'to spend your evening at home instead of going out', 'meaning_fa' => 'در خانه ماندن (بیرون نرفتن)', 'pos' => 'phrasal verb', 'synonym' => 'opposite: go out', 'example' => 'I stayed in and watched a film last night.', 'difficulty' => 'easy'],
                                    ['phrase' => 'go out', 'meaning' => 'to leave home to do something for fun', 'meaning_fa' => 'بیرون رفتن (برای تفریح)', 'pos' => 'phrasal verb', 'synonym' => 'opposite: stay in', 'example' => 'We usually go out on Friday nights.', 'difficulty' => 'easy'],
                                    ['phrase' => 'eat out', 'meaning' => 'to have a meal at a restaurant instead of at home', 'meaning_fa' => 'بیرون غذا خوردن (در رستوران)', 'pos' => 'phrasal verb', 'synonym' => 'dine out', 'example' => 'We eat out about once a month.', 'difficulty' => 'easy'],
                                    ['phrase' => 'chat', 'meaning' => 'to have an informal, friendly conversation', 'meaning_fa' => 'گپ زدن', 'pos' => 'verb', 'synonym' => 'talk', 'example' => 'We chatted for an hour on the phone.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                // Was 2, then 3 for the recall prompt (+1); now 8 — same real 6:44
                                // (ffprobe-verified) audio-ended gate as daily_listen_2.
                                'duration_minutes' => 10,
                                'hook' => 'Same audio, one more time — familiar is exactly the point.',
                                'image_query' => 'alarm clock wake up bed',
                                'shadow_lines' => [
                                    "**Sometimes** I **skip** **breakfast**, because when I **wake up**, I'm **not hungry**.",
                                    'I **never** **wake up** before my **alarm**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M01', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                // Epic E: merges the old standalone Activation step (solo
                                // recording, now warm_up_task below) and AI Feedback #1 (now
                                // generated automatically at the end, no separate step) into
                                // this one step — 6 (warm-up recording) + 12 (interview) + 3
                                // (feedback) = 21, minus a little shared UI overhead. The
                                // warm-up used to also include writing 5 sentences (budgeted
                                // 12 min); that writing sub-step was removed, so warm-up
                                // dropped to 6.
                                'duration_minutes' => 20,
                                'hook' => 'Practice solo first — it makes the real conversation with the AI Instructor, right after, feel much easier.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your daily life, using the new vocabulary, without reading.',
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
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                // New — a genuine reading-comprehension pillar (Listening's
                                // counterpart), not just the vocabulary story's side effect.
                                // Placed right before Writing on purpose: read a model text
                                // about someone else's day, then write about your own.
                                'duration_minutes' => 12,
                                'hook' => 'Meet Aisha — her morning looks a lot like yours. Can you follow her day in English?',
                                'passage_title' => 'Meet Aisha',
                                // Dual-coding, same principle as Vocabulary Builder's flashcards
                                // (EOS-009 §8) — a face for the passage's subject, purely
                                // decorative/humanizing, never something the reading questions
                                // depend on. Fails soft like every other PexelsClient call.
                                'image_query' => 'young woman morning portrait smiling',
                                // Written fresh for this app (see EOS-009 §14: content stays
                                // original, no licensing/piracy risk) — deliberately reuses
                                // several Vocabulary Builder pool words/phrases (wakes up,
                                // gets up, morning person, skips breakfast, stay in, does
                                // the shopping, sleep in, has a shower, goes to sleep) so
                                // whichever words a learner picked there, they meet again
                                // here in a fresh context, third person instead of first.
                                'passage' => 'Aisha lives in Manchester and works at a hospital. On '
                                    .'weekdays, she wakes up at six and gets up straight away — '
                                    ."she's a real morning person! She never skips breakfast: she "
                                    .'usually has cereal and a cup of tea before her short commute '
                                    .'to work. After a long shift, she comes home exhausted, so '
                                    .'most evenings she likes to stay in and unwind by watching a '
                                    .'film instead of going out. On Saturdays, she does the '
                                    .'shopping with her sister, and on Sundays she likes to sleep '
                                    ."in until nearly ten o'clock. \"It's the only day I don't set "
                                    .'an alarm," she says. In the evening, she always has a shower '
                                    .'before bed, and she goes to sleep by eleven, ready for '
                                    .'another early start.',
                                // Story 3 (requirements review, 2026-09-04): marks exactly which
                                // literal substrings above are the reused Vocabulary Builder
                                // pool words/phrases ("reused", tooltip says so) vs. 3 brand-new
                                // A2+ words added to the passage on purpose ("new", tooltip shows
                                // a short definition) — rendered as highlighted spans by
                                // ⚡reading-comprehension.blade.php's highlightedPassageHtml().
                                // Every phrase here must appear verbatim (exact case) in the
                                // passage text above, or it silently fails to highlight.
                                'highlighted_phrases' => [
                                    ['phrase' => 'wakes up', 'type' => 'reused'],
                                    ['phrase' => 'gets up', 'type' => 'reused'],
                                    ['phrase' => 'morning person', 'type' => 'reused'],
                                    ['phrase' => 'skips breakfast', 'type' => 'reused'],
                                    ['phrase' => 'stay in', 'type' => 'reused'],
                                    ['phrase' => 'does the shopping', 'type' => 'reused'],
                                    ['phrase' => 'sleep in', 'type' => 'reused'],
                                    ['phrase' => 'has a shower', 'type' => 'reused'],
                                    ['phrase' => 'goes to sleep', 'type' => 'reused'],
                                    ['phrase' => 'commute', 'type' => 'new', 'definition' => 'a regular journey to and from work', 'pos' => 'noun', 'example' => 'Her commute takes about thirty minutes by bus.'],
                                    ['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired', 'pos' => 'adjective', 'example' => 'I was exhausted after the long shift.'],
                                    ['phrase' => 'unwind', 'type' => 'new', 'definition' => 'to relax after being busy or stressed', 'pos' => 'verb', 'example' => 'I like to unwind with a film in the evening.'],
                                ],
                                // Grounds the AI check in what the passage actually says, same
                                // pattern as Listening's topic_summary — background for a coarse
                                // topic-relevance check, never a source to fact-check against.
                                'topic_summary' => 'A short profile of Aisha, who works at a '
                                    .'hospital in Manchester: her weekday morning routine (waking '
                                    .'up early, never skipping breakfast), her quiet weekday '
                                    .'evenings at home, and her different weekend routine '
                                    .'(shopping with her sister on Saturday, sleeping in on Sunday).',
                                // An ungraded true/false <x-quick-round> warm-up, same pattern
                                // as Listening's comprehension_check — engages with the text
                                // before the real AI-checked questions below.
                                // difficulty (Story 4, requirements review, 2026-09-04) drives
                                // <x-quick-round>'s adaptive mode — a genuine easy→hard spread,
                                // not alphabetical: (1) copies the passage's own wording almost
                                // verbatim, (2) needs the learner to flip "never skips" into
                                // "usually skips" being false, (3) needs both a paraphrase
                                // ("does the shopping" → "goes shopping") AND recalling which
                                // specific day among several mentioned.
                                'comprehension_check' => [
                                    ['statement' => 'Aisha works at a hospital.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'She usually skips breakfast.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'She goes shopping with her sister on Saturdays.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    "What is Aisha's morning usually like on a weekday?",
                                    "What is different about Aisha's Sunday, compared to a normal weekday?",
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                // Was 12 — bumped for the AI feedback recap (strength/expression/correction).
                                'duration_minutes' => 14,
                                'hook' => 'Putting it on paper often reveals what you actually think about your own day.',
                                'title' => 'A typical day in my life',
                                // image_query on each — small inspirational thumbnails so a blank
                                // page feels less intimidating. Purely decorative, same fail-soft
                                // PexelsClient pattern as Vocabulary Builder/Reading Comprehension
                                // — never blocks writing.
                                'prompts' => [
                                    ['label' => 'Morning', 'image_query' => 'sunrise morning coffee'],
                                    ['label' => 'Work / Study', 'image_query' => 'office desk work laptop'],
                                    ['label' => 'Afternoon', 'image_query' => 'afternoon lunch break'],
                                    ['label' => 'Evening', 'image_query' => 'evening dinner family'],
                                    ['label' => 'Free Time', 'image_query' => 'relaxing hobby leisure'],
                                    ['label' => 'Weekend', 'image_query' => 'weekend park friends'],
                                ],
                                // Grammar connectors only — the mission's own vocabulary_builder
                                // words are already threaded into Writing via <x-vocabulary-pills>
                                // just above this (selectedVocabularyWords(), which also correctly
                                // respects which words the learner actually chose, unlike a
                                // hardcoded list here would). Having both pill rows list the same
                                // words read as pure duplication, not reinforcement.
                                'try_to_use' => ['usually', 'normally', 'often', 'sometimes', 'rarely', 'after that', 'then', 'wake up'],
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
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe a normal weekday for you, from morning to night.', 'answer' => 'I get up at seven, have a shower and go to work. In the evening I usually stay in.', 'words' => ['get up', 'have a shower', 'usually']],
                            ['question' => 'What does your best friend do at the weekend?', 'answer' => 'She sleeps in on Saturday, then she does the shopping with her mum.', 'words' => ['sleeps in', 'does the shopping']],
                            ['question' => 'What is one habit you want to change?', 'answer' => 'I often skip breakfast. I want to eat cereal or fruit every morning.', 'words' => ['often', 'skip breakfast']],
                            ['question' => 'How is your weekend different from your weekdays?', 'answer' => 'On weekdays I never sleep in, but at the weekend I always get up late and go out.', 'words' => ['never', 'sleep in', 'go out']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                // Moved here from Day 2 (2026-10) to balance day load and give
                                // the Challenge day a 4th, AI-graded step now that
                                // partner_speaking_session was cut mission-wide (see that step's
                                // removal note in M02/M03/M04 below) — a real CEFR/IELTS "describe
                                // this picture" task. Everything else in M01 narrates the
                                // learner's OWN routine (Activation, ai_conversation_1/2) — this is
                                // the only step that practices describing someone else's scene
                                // objectively: present continuous for what's happening right now,
                                // "there is/are", prepositions of place. A genuine skill gap, not a
                                // repeat. No sequencing dependency ties it to Day 2.
                                // Was 8 — bumped for the hotspot-driven guiding questions and the
                                // visual overhaul (blur-to-focus, magazine-style result layout).
                                'duration_minutes' => 10,
                                'hook' => 'Forget your own morning for a minute — what\'s happening in this one?',
                                'image_query' => 'family eating breakfast morning kitchen',
                                'guiding_questions' => [
                                    'What is the man doing?',
                                    'What is the woman doing, and where is she standing?',
                                    'Where is the baby, and what is different about her spot at the table?',
                                    'What food can you see on the counter?',
                                ],
                                // Story 5 (requirements review, 2026-09-04) — numbered markers
                                // overlaid on the image at these x/y percentages (from the
                                // top-left), each pointing at the guiding_questions entry it's
                                // about. Hand-verified against the real cached Pexels photo for
                                // this exact query+orientation (landscape) — a dad and mom eating
                                // breakfast standing at a kitchen island on the left/center, a
                                // baby in a yellow high chair on the right, and fruit/food on the
                                // counter in the foreground bottom-left. Changing the query or
                                // orientation invalidates these coordinates.
                                'hotspots' => [
                                    ['x' => 17, 'y' => 32, 'question_index' => 0],
                                    ['x' => 29, 'y' => 32, 'question_index' => 1],
                                    ['x' => 62, 'y' => 58, 'question_index' => 2],
                                    ['x' => 15, 'y' => 85, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                // Was 6 — bumped for the optional AI-generated fill-in-the-blank drills.
                                'duration_minutes' => 7,
                                'hook' => 'Mistakes are proof you tried something new. Let\'s fix a few, for good.',
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                // Was 10 — bumped since an off-topic spoken answer now asks for a retry.
                                'duration_minutes' => 14,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                // Epic E: a bigger pool than one attempt actually asks (see
                                // ⚡ai-conversation2.blade.php's getRoundsProperty()) — real
                                // rounds from Mission01.pdf "Speaking Session 02" plus 3 more in
                                // the same style, so a re-attempt genuinely varies.
                                'round_pool' => [
                                    'Describe your typical weekday, with no preparation.',
                                    'Compare your weekday with your weekend.',
                                    'What part of your routine do you enjoy most?',
                                    'What is the hardest part of your day to get through?',
                                    'How does your day change when you have a day off?',
                                    'What would you change about your daily routine if you could?',
                                ],
                                'role_reversal_topic' => 'daily routines',
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
                                'duration_minutes' => 5,
                                'hook' => 'You started this mission with a number — let\'s see how far it moved.',
                                // Real structure from Mission01.pdf page 12 "Mission complete".
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                // Was free-text ("پرسش و پاسخ" felt like a chore) — now a pick from
                                // real options for this run (see ⚡mission-result.blade.php's
                                // reflectionOptions()), never a blank page to type into.
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->seedM02();

        $this->warmPexelsCache();
    }

    /**
     * Fetches and caches every Pexels image this app will ever need to
     * show a real visitor — run once, right here at the end of seeding.
     * See [[feedback_never_fetch_pexels_live_on_site]]: the host must
     * NEVER connect to Pexels itself, not a real visitor's page view,
     * not even production's own `db:seed --force` during a deploy.
     *
     * The actual acquisition happens HERE, on a dev machine with clean
     * direct Pexels access (production's is filtered/slow — see
     * [[project_ai_relay_for_filtered_host]]) — running this locally
     * after building a mission is what does the real fetching.
     * scripts/deploy-push.sh then force-adds whatever's now cached under
     * storage/app/public/{vocabulary-images,ambient-videos} onto the
     * `deploy` branch (gitignored on every branch otherwise, same as
     * vendor/public-build), so those files already exist on production's
     * disk before Laravel ever boots a request. Calling this again
     * during production's own `db:seed --force` is then a pure no-op
     * safety net, never a real network call: PexelsClient::imageUrlFor()
     * checks the on-disk cache before ever calling out, and a file
     * shipped via the deploy branch is already there.
     *
     * PexelsClient::imageUrlFor() fails soft (network error, no API key,
     * no results all just return null) and is idempotent, so re-running
     * this on a seed that's already fully cached is always a fast no-op
     * — safe to just always run this in full on every seed.
     *
     * Every cache key formula below must stay in exact lockstep with the
     * step component that actually renders it (mission-brief.blade.php,
     * listening.blade.php, etc.) — a mismatched key silently defeats the
     * whole point (warms a key nobody reads, the real page still fetches
     * live). Add a new arm here whenever a new step type gains its own
     * image_query-driven PexelsClient call.
     */
    private function warmPexelsCache(): void
    {
        $client = app(PexelsClient::class);

        foreach (Mission::all() as $mission) {
            $code = $mission->code;

            foreach ($mission->phases as $phase) {
                foreach ($phase['steps'] as $step) {
                    $key = $step['key'];
                    $query = $step['image_query'] ?? null;

                    match (true) {
                        $key === 'mission_brief' && $query => $client->imageUrlFor("{$code}-brief", $query),
                        $key === 'listening' && $query => $client->imageUrlFor("{$code}-listening", $query),
                        in_array($key, ['daily_listen_2', 'daily_listen_3'], true) && $query => $client->imageUrlFor("{$code}-{$key}", $query),
                        $key === 'picture_description' && $query => $client->imageUrlFor("{$code}-picture-description", $query, 'landscape'),
                        $key === 'reading_comprehension' && $query => $client->imageUrlFor("{$code}-reading", $query),
                        $key === 'writing' => collect($step['prompts'] ?? [])
                            ->filter(fn ($prompt) => is_array($prompt) && ($prompt['image_query'] ?? null))
                            ->each(fn ($prompt) => $client->imageUrlFor("{$code}-writing-{$prompt['label']}", $prompt['image_query'])),
                        str_starts_with($key, 'vocabulary_builder_') => collect($step['words'] ?? [])
                            ->filter(fn ($word) => $word['image_query'] ?? null)
                            ->each(fn ($word) => $client->imageUrlFor($word['phrase'], $word['image_query'], null)),
                        $key === 'story_sequence' => collect($step['sequence_images'] ?? [])
                            ->each(fn ($item, $index) => $client->imageUrlFor("{$code}-story-{$index}", $item['image_query'] ?? '')),
                        default => null,
                    };
                }
            }
        }

        $builtCodes = Mission::all()->pluck('code')->all();

        foreach (Mission::roadmapCatalog() as $code => $entry) {
            if (! in_array($code, $builtCodes, true)) {
                $client->imageUrlFor("{$code}-roadmap", $entry['image_query']);
            }
        }
    }

    /**
     * Seeds M02 with its real content from document/M02/ (M02.pdf, BBC
     * Learning English "Real Easy English: Talking about old friends"), matching
     * the phase/step map in EOS-009 §7. Split into its own method (unlike
     * M01's inline run()) purely for readability given its size — no
     * behavioral difference.
     *
     * grammar_in_context was generalized (commit 42608a8) specifically so
     * this mission could reuse it for a different grammar focus — see
     * grammar-in-context.blade.php's lesson.sections shape. (Active Recall,
     * mentioned here historically, was removed entirely in the mission
     * structure redesign's Epic G.)
     */
    private function seedM02(): void
    {
        $audioUrl = $this->publishMissionAsset('M02', 'BBC Learning English - Real Easy English Talking about old friends.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M02', 'Easy English - Friends Best Qualities.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M02', 'Easy English - Friends Best Qualities.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M02'],
            [
                'title' => 'People I Know',
                'module' => 'Relationships',
                'outcome' => 'I can describe people I know, talk about friendships, and explain what makes a good relationship.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'How do you stay in touch with old friends?', 'answer' => 'I usually stay in touch on WhatsApp, and we meet up once a month.', 'words' => ['stay in touch', 'meet up']],
                            ['question' => 'Did you ever lose touch with a friend? What happened?', 'answer' => 'Yes, I lost touch with a school friend when she moved to another city.', 'words' => ['lost touch']],
                            ['question' => 'Is there someone you want to get back in touch with?', 'answer' => 'Yes, I\'d like to get back in touch with my old neighbour. She was really kind.', 'words' => ['get back in touch']],
                            ['question' => 'Can people stay friends after school?', 'answer' => 'Yes. My best friend and I stayed friends after school, and we still meet up every summer.', 'words' => ['stayed friends', 'meet up']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'You already talk about the people in your life every day — this mission just gives you the English to do it properly.',
                                'image_query' => 'two friends laughing coffee shop',
                                'ambient_video_query' => 'friends laughing together outdoors slow motion',
                                'warm_up_questions' => [
                                    'Who are you closest to?',
                                    'What is this person like?',
                                    'How did you become friends?',
                                    'What makes someone a good friend?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 8,
                                'hook' => 'Next time someone asks about the people in your life, will these words be ready — or will you go quiet?',
                                // Mission structure redesign, Epic B — see M01's vocabulary_builder_1
                                // for the full rationale. Day 1 = the real Listening episode's own
                                // 5 target_phrases, so every learner meets them here first.
                                'story' => 'My best friends from school and I **meet up** every few months. We '
                                    .'live in different cities now, so it is hard to **stay in touch**, but we '
                                    .'have a group chat. I **lost touch** with one friend for years, then last '
                                    .'month we **got back in touch** — and now we **stay friends** again, as if '
                                    .'nothing changed.',
                                'words' => [
                                    ['phrase' => 'meet up', 'meaning' => 'to meet someone, usually a friend, for a planned time together', 'meaning_fa' => 'همدیگر را دیدن (قرار ملاقات گذاشتن)', 'pos' => 'phrasal verb', 'synonym' => 'get together', 'example' => 'We meet up for coffee every Saturday.', 'difficulty' => 'easy'],
                                    ['phrase' => 'stay in touch', 'meaning' => 'to keep contact with someone by speaking or writing to them regularly', 'meaning_fa' => 'در تماس ماندن', 'pos' => 'verb phrase', 'synonym' => 'keep in touch', 'example' => 'We stay in touch through a group chat.', 'difficulty' => 'medium'],
                                    ['phrase' => 'lose touch', 'meaning' => 'to stop seeing or speaking to someone, so you no longer have contact', 'meaning_fa' => 'ارتباط را از دست دادن', 'pos' => 'verb phrase', 'synonym' => 'opposite: stay in touch', 'example' => 'I lost touch with my school friends after I moved.', 'difficulty' => 'medium'],
                                    ['phrase' => 'get back in touch', 'meaning' => 'to start speaking to or seeing someone again after a long time', 'meaning_fa' => 'دوباره تماس گرفتن (بعد از مدتی)', 'pos' => 'verb phrase', 'synonym' => 'reconnect', 'example' => 'She got back in touch with an old friend last week.', 'difficulty' => 'hard'],
                                    ['phrase' => 'stay friends', 'meaning' => 'to continue to be friends for a long time', 'meaning_fa' => 'دوست ماندن', 'pos' => 'verb phrase', 'example' => 'We stayed friends even after we left school.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 22,
                                'hook' => 'Phil and Becca are talking about friends they have known for years — how many of your old friends are you still in touch with?',
                                'source' => 'BBC Learning English — Real Easy English: Talking about old friends (2026)',
                                'image_query' => 'two friends talking coffee shop',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M02', 'listening_segments'),
                                // The same chunks split at every change of speaker and tagged with who is
                                // talking, so the panel reads as a chat — see missions:align-listening-speakers.
                                'listening_turns' => $this->shadowTimestampsFor('M02', 'listening_turns'),
                                'transcript_ref' => 'https://www.bbc.co.uk/learningenglish/english/features/real-easy-english/260605',
                                // Full real transcript (BBC Learning English, "Real Easy English:
                                // Talking about old friends", 2026, taken from the episode's own page) —
                                // shown in-app only after the learner has genuinely listened twice (see
                                // ⚡listening.blade.php), so it's a check, not a shortcut. ffprobe-verified
                                // real audio: 351.9s (5:52).
                                'transcript' => [
                                    ['speaker' => 'Becca', 'text' => "Hello and welcome to Real Easy English from BBC Learning English. This is the podcast where we have real conversations in easy English to help you with your learning. I'm Becca."],
                                    ['speaker' => 'Phil', 'text' => "And I'm Phil. And you can watch a video version of this podcast on our website. It's got subtitles to help you learn. That's bbclearningenglish.com."],
                                    ['speaker' => 'Becca', 'text' => 'How are you today, Phil?'],
                                    ['speaker' => 'Phil', 'text' => "I'm good. I'm really good. What about you? Have you got any plans for this week coming up?"],
                                    ['speaker' => 'Becca', 'text' => "Yes. I'm really excited for the weekend because I'm going to meet up with some of my old school friends. I've known them since I was in year eight."],
                                    ['speaker' => 'Phil', 'text' => "Well, Becca, today we're talking about old friends. And when we say old friends, we mean friends that we've had a long time, particularly since we were children."],
                                    ['speaker' => 'Becca', 'text' => 'So, Phil, do you have many old friends?'],
                                    ['speaker' => 'Phil', 'text' => "Yeah, I do have some, but I probably don't see them as much as I should."],
                                    ['speaker' => 'Becca', 'text' => 'Hmm. Why is that?'],
                                    ['speaker' => 'Phil', 'text' => "As you get older, you move away. You have families. You... And I'm just not very good at staying in touch with people, so I don't often see people that are very old friends. What about you? Do you have many old friends that you see often?"],
                                    ['speaker' => 'Becca', 'text' => "Yeah, I do, I do. I mean, luckily for myself, we don't live very far away, so it is easier to stay in touch. And by staying in touch, we mean, kind of, keeping that contact. So, I do have a couple of friendship groups. We have group chats where we, kind of, you know, keep in touch with each other there and we plan meetings. Although we find that when we plan to meet each other, we will then plan to meet each other the next time, months in advance – months before we meet – because, yeah, it is difficult when you are older and you get busy."],
                                    ['speaker' => 'Phil', 'text' => 'How often do you see your old friends? It sounds like you stay in touch quite a lot.'],
                                    ['speaker' => 'Becca', 'text' => 'Yeah, we do. We stay in touch a lot over, like I said, group chats, social media, and we try to meet at least once every month, once every couple of months, you know, if we really try. So yeah, it is easier for us to meet up. So, when was the last time that you saw one of your old friends?'],
                                    ['speaker' => 'Phil', 'text' => "It was probably sometime last year, I think. But yeah, I probably only... Only a couple of times a year, I think, I'd see old friends."],
                                    ['speaker' => 'Becca', 'text' => 'And have you lost touch with any of your old friends? So, to lose touch is to lose that contact that you have with them.'],
                                    ['speaker' => 'Phil', 'text' => "Yes, I think I have. You don't notice it when it happens, do you, really? Because it's not like you have an argument or you say, I'm not talking to that person anymore. You just... I don't know. You just don't see them for a long time. If I saw those people again, then I might get back in touch with them. But yeah, there's people that... there's definitely people that I used to know that I haven't seen for a long time. So yeah, I've definitely lost touch with some people."],
                                    ['speaker' => 'Becca', 'text' => "So, do you think your friendship groups have, kind of, changed as you've grown older then? If you've lost touch with some old friends, you have newer friends now."],
                                    ['speaker' => 'Phil', 'text' => "What has changed a lot is I have a lot more friends who are families, who have children the same age that we have. Because suddenly when you have children, you have so much in common with people in the same situation, and your children do. And if your children get on very well together, you're going to spend a lot of time together."],
                                    ['speaker' => 'Becca', 'text' => "I see. So, that's why we're quite different, because a lot of my friends don't have families. And, you know, I don't either. So, it's quite easy for us to stay friends and to stay in touch with each other. OK. Let's recap some of the vocabulary we learned in this episode, starting with old friend. And that's a friend that you've known for a very long time."],
                                    ['speaker' => 'Phil', 'text' => 'So, we had this idea of being in touch with someone – with a friend. And if you are in touch with someone, it means that you see them often. You speak to them often. So, if you keep in touch with someone, it means you continue to do those things even after a long time.'],
                                    ['speaker' => 'Becca', 'text' => "If you lose touch with someone, that means that you don't see or speak to them anymore. However, you can get back in touch with someone – that is to start seeing them or speaking to them again."],
                                    ['speaker' => 'Phil', 'text' => 'And if you stay friends with someone, it means that you continue to be friends with someone for a long period of time.'],
                                    ['speaker' => 'Becca', 'text' => "For example, it's easy for me to stay friends with some of my old friends because we live very close to each other."],
                                    ['speaker' => 'Phil', 'text' => "That's it for this episode of Real Easy English. Try the worksheet on our website, bbclearningenglish.com, to practise and to help you have your own easy English conversations."],
                                    ['speaker' => 'Becca', 'text' => "And we'll be back next week with another real conversation in easy English. But for now, it's goodbye."],
                                    ['speaker' => 'Phil', 'text' => 'Bye!'],
                                ],
                                // The vocabulary Phil and Becca themselves explain in the episode's recap
                                // (stay in touch, lose touch, get back in touch, stay friends), plus "meet up"
                                // from the opening chat. gap_before/gap_after are real, single-blank
                                // sentences lifted straight from the transcript — the gap-fill sub-step.
                                // "old friend" is deliberately left to Day 2's vocabulary builder.
                                'target_phrases' => [
                                    [
                                        'phrase' => 'meet up', 'meaning' => 'to meet someone, usually a friend, for a planned time together',
                                        'gap_before' => "I'm really excited for the weekend because I'm going to ",
                                        'gap_after' => ' with some of my old school friends.',
                                    ],
                                    [
                                        'phrase' => 'stay in touch', 'meaning' => 'to keep contact with someone by speaking or writing to them regularly',
                                        'gap_before' => "I mean, luckily for myself, we don't live very far away, so it is easier to ",
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'lose touch', 'meaning' => 'to stop seeing or speaking to someone, so you no longer have contact',
                                        'gap_before' => 'So, to ',
                                        'gap_after' => ' is to lose that contact that you have with them.',
                                    ],
                                    [
                                        'phrase' => 'get back in touch', 'meaning' => 'to start speaking to or seeing someone again after a long time',
                                        'gap_before' => 'If I saw those people again, then I might ',
                                        'gap_after' => ' with them.',
                                    ],
                                    [
                                        'phrase' => 'stay friends', 'meaning' => 'to continue to be friends for a long time',
                                        'gap_before' => "For example, it's easy for me to ",
                                        'gap_after' => ' with some of my old friends because we live very close to each other.',
                                    ],
                                ],
                                'topic_summary' => 'Phil and Becca talk about old friends — friends you have known for a '
                                    .'long time, often since childhood. Becca is excited to meet up with her old '
                                    .'school friends at the weekend. Phil says he does not see his old friends '
                                    .'often because people move away, have families and he is not good at staying in '
                                    .'touch; Becca stays in touch through group chats and social media. They talk '
                                    .'about losing touch with people, how having children changes your friendship '
                                    .'groups, and explain the phrases stay in touch, lose touch, get back in touch '
                                    .'and stay friends.',
                                'comprehension_check' => [
                                    ['statement' => 'Phil and Becca are talking about old friends.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Phil says he sees his old friends very often.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Phil has more friends with children now than he used to.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'How does Becca mostly stay in touch with her old friends?',
                                    'options' => ['By phone calls', 'Through group chats and social media', 'By writing letters'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only
                                // (synced text + gap-fill); shadowing is Listen Again's job
                                // (daily_listen_2/3, see DailyListenStep).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Tell me about your best friend. What is he or she doing these days?', 'answer' => 'My best friend is a nurse. These days she\'s working nights, so we don\'t see each other much.', 'words' => ['best friend', 'these days']],
                            ['question' => 'How did you and your oldest friend get to know each other?', 'answer' => 'We got to know each other at primary school. Now he lives in Shiraz, but we talk every week.', 'words' => ['got to know each other', 'every week']],
                            ['question' => 'Who do you get on well with at work or school?', 'answer' => 'I get on well with Reza. He always helps me, and this month we\'re working on a project together.', 'words' => ['get on well with', 'always', 'this month']],
                            ['question' => 'What makes a good friendship?', 'answer' => 'A good friendship needs time. My mate Ali always listens, and he never judges me.', 'words' => ['friendship', 'mate']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'The words you actually use talking about your closest friends.',
                                'story' => 'My **best friend**, Dan, is actually an **old friend** — we met at '
                                    .'university. It took us a while to really **get to know each other**, but '
                                    .'now our **friendship** is really strong. We still **get on well with** each '
                                    .'other, and I often meet up with a **mate** from work too.',
                                'words' => [
                                    ['phrase' => 'best friend', 'meaning' => 'the friend you are closest to and trust the most', 'meaning_fa' => 'بهترین دوست', 'pos' => 'noun phrase', 'synonym' => 'closest friend', 'example' => "She's been my best friend since school.", 'difficulty' => 'easy'],
                                    ['phrase' => 'old friend', 'meaning' => 'a friend you have known for a long time (not necessarily an elderly friend)', 'meaning_fa' => 'دوست قدیمی', 'pos' => 'noun phrase', 'example' => 'I ran into an old friend at the station.', 'difficulty' => 'medium'],
                                    ['phrase' => 'get to know each other', 'meaning' => 'to gradually become familiar with a person', 'meaning_fa' => 'کم‌کم با هم آشنا شدن', 'pos' => 'verb phrase', 'example' => 'It took us months to get to know each other well.', 'difficulty' => 'medium'],
                                    ['phrase' => 'friendship', 'meaning' => 'the relationship between friends', 'meaning_fa' => 'دوستی', 'pos' => 'noun', 'example' => 'Our friendship has lasted over ten years.', 'difficulty' => 'easy'],
                                    ['phrase' => 'get on well with', 'meaning' => 'to have a friendly, easy relationship with someone', 'meaning_fa' => 'با کسی خوب کنار آمدن', 'pos' => 'verb phrase', 'synonym' => 'get along with', 'example' => 'I get on well with most of my colleagues.', 'difficulty' => 'hard'],
                                    ['phrase' => 'mate', 'meaning' => '(British informal) a friend', 'meaning_fa' => 'رفیق (غیررسمی، انگلیسی بریتانیایی)', 'pos' => 'noun (British informal)', 'synonym' => 'friend', 'example' => "I'm meeting a mate for coffee later.", 'image_query' => 'two friends high five outdoors', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 9,
                                'hook' => 'Let your ear warm up to English again — the same real episode, start to finish.',
                                'image_query' => 'two friends chatting park bench',
                                'shadow_lines' => [
                                    "Today we're **talking** about **old** **friends**.",
                                    "I **don't** **often** **see** people that are **very** **old** **friends**.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M02', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 14,
                                'hook' => 'Every "she\'s living" you get right here is one less pause when you\'re talking about someone real.',
                                'focus' => 'Present Simple vs Present Continuous',
                                'lesson' => [
                                    'intro' => 'Three small things: what each tense is for, how to ask in each, and how to pick the right one.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · Always true, or true right now?',
                                            'body' => 'Ask yourself one question: is it <strong>always</strong> true, or true <strong>right now</strong>?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Habit or fact → <strong>Present Simple</strong>.<br>Temporary or now → <strong>Present Continuous</strong>.',
                                                    'fa' => 'عادت و واقعیت همیشگی → Present Simple. موقتی یا در حال انجام (همین روزها) → Present Continuous.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Present Simple — habits & facts',
                                                    'parts' => [
                                                        ['text' => 'She', 'caption' => 'who'],
                                                        ['text' => 'lives', 'caption' => 'verb (+ s)'],
                                                        ['text' => 'near me', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Present Continuous — temporary & now',
                                                    'parts' => [
                                                        ['text' => 'She', 'caption' => 'who'],
                                                        ['text' => 'is living', 'caption' => 'am / is / are + verb-ing'],
                                                        ['text' => 'with a friend', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Nadia',
                                                    'wrong' => 'She lives in Spain at the moment, just for the summer.',
                                                    'right' => 'She\'s living in Spain at the moment, just for the summer.',
                                                    'explanation' => 'A temporary situation right now needs Present Continuous, not Present Simple.',
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · Asking and answering',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Present Simple asks with <strong>do / does</strong>.<br>Present Continuous asks with <strong>am / is / are</strong>.',
                                                    'fa' => 'سؤال در Present Simple با do / does ساخته می‌شود و در Present Continuous با am / is / are.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        [
                                                            'label' => 'Questions',
                                                            'items' => ['Does she get on well with her sister?', 'Is he still working abroad?'],
                                                        ],
                                                        [
                                                            'label' => 'Negatives',
                                                            'items' => ['They don\'t see each other very often.', 'She isn\'t living at home right now.'],
                                                        ],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'Does he working abroad?', 'right' => 'Is he working abroad?', 'highlight' => 'Is', 'note' => 'A verb with -ing needs am / is / are — not do / does.'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · Which tense does the sentence need?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Look for the <strong>time word</strong>. It tells you which tense to use.',
                                                    'fa' => 'به قید زمان نگاه کن؛ مشخص می‌کند کدام زمان لازم است.',
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Present Simple time words', 'words' => ['usually', 'always', 'every week', 'never']],
                                                        ['label' => 'Present Continuous time words', 'words' => ['at the moment', 'these days', 'right now', 'currently']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'rule_examples',
                                                    'items' => [
                                                        ['rule' => 'A general fact about a person → Present Simple', 'example' => 'My best friend lives in Manchester.', 'highlight' => 'lives'],
                                                        ['rule' => 'A temporary situation right now → Present Continuous', 'example' => 'My best friend is living in Manchester for a few months.', 'highlight' => 'is living'],
                                                        ['rule' => 'A habit → Present Simple', 'example' => 'She usually calls me on Sundays.', 'highlight' => 'calls'],
                                                        ['rule' => 'Something happening around now → Present Continuous', 'example' => 'She\'s calling me a lot more since the wedding.', 'highlight' => 'calling'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this soon — in AI Conversation #1, describing someone you\'re close to.',
                                ],
                                'frequency_starters' => [
                                    'My friend usually', 'These days, my friend is', 'He/She often', 'At the moment, he/she is',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence, correctly using either present simple (for '
                                    .'habits/general facts) or present continuous (for temporary/current situations) '
                                    .'as appropriate to the sentence starter given.',
                                'grammar_major_criteria' => 'the verb is not in an appropriate tense for the starter '
                                    .'given (present simple for a habit/general-fact starter, present continuous for '
                                    .'a temporary/current-situation starter), the sentence does not actually continue '
                                    .'the given starter, or it is not a genuine personal statement',
                                'grammar_context' => 'continues appropriately in either the present simple or present '
                                    .'continuous tense, whichever fits the sentence starter given',
                                'quick_check' => [
                                    [
                                        // Was corrected to present perfect ("She's known him...") —
                                        // a tense this mission never teaches. Fixed to present
                                        // simple (Content audit, Epic H): "know" is a stative verb,
                                        // so the real teaching point — never continuous, always
                                        // simple — now stays inside this mission's own scope.
                                        'wrong' => 'She is knowing him very well.',
                                        'options' => ['She is knowing him very well.', 'She know him very well.', 'She knows him very well.'],
                                        'correct' => 2,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'My friend works in London this month, just for a project.',
                                        'options' => ['My friend works in London this month, just for a project.', 'My friend working in London this month.', 'My friend is working in London this month, just for a project.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'She usually is getting on well with her sister.',
                                        'options' => ['She usually is getting on well with her sister.', 'She usually get on well with her sister.', 'She usually gets on well with her sister.'],
                                        'correct' => 2,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['a', "She's", 'lot', 'these', 'working', 'days.'], 'answer' => "She's working a lot these days."],
                                    ['words' => ['well', 'she', 'her', 'with', 'Does', 'sister?', 'on', 'get'], 'answer' => 'Does she get on well with her sister?'],
                                    ['words' => ['on', 'calls', 'usually', 'She', 'Sundays.', 'me'], 'answer' => 'She usually calls me on Sundays.'],
                                ],
                            ],
                            [
                                // Real people, unscripted: Easy English's street interviews with
                                // pairs of friends and family (about 6 minutes cut from the
                                // original 11-minute video — the host intro and the channel's
                                // membership ad removed, the burned-in captions blurred out so
                                // the step's own caption toggle works). Squarely about describing
                                // the people you know, which is this mission's theme.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Real friends, real English — hear how people describe the people they know, then make your own voice do the same.',
                                'source' => 'Easy English — "Asking FRIENDS in BRIGHTON How They Met"',
                                'video_url' => $shadowingVideoUrl,
                                // Cleaned from the video's own burned-in captions (YouTube's
                                // auto-captions for it were unusable): punctuated and
                                // re-timed per sentence.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M02', 'video_shadowing_segments'),
                                'topic_summary' => 'On a seafront in Brighton, a street interviewer asks pairs of '
                                    .'friends, colleagues and family members how they first met and what they '
                                    .'like most about each other. They talk about meeting at work, at school '
                                    .'or through a hobby, and describe each other as reliable, trustworthy, '
                                    .'a good listener, kind, caring, non-judgmental and always there for them.',
                                'comprehension_check' => [
                                    ['statement' => 'All the people in the video met their friends at work.', 'correct' => false],
                                    ['statement' => 'One person says her friend is a very good listener.', 'correct' => true],
                                    ['statement' => 'Honesty is mentioned as an important part of a good friendship.', 'correct' => true],
                                ],
                                // Real words the speakers use to describe each other — useful
                                // for Day 2's grammar and Day 3's "describe a friend" writing.
                                'target_phrases' => [
                                    ['phrase' => 'reliable', 'meaning' => 'someone you can always depend on'],
                                    ['phrase' => 'trustworthy', 'meaning' => 'someone you can trust completely'],
                                    ['phrase' => 'a good listener', 'meaning' => 'someone who pays attention when you talk'],
                                    ['phrase' => 'non-judgmental', 'meaning' => 'not criticising people for what they say or do'],
                                    ['phrase' => 'always there for you', 'meaning' => 'ready to help whenever you need them'],
                                ],
                                // Real verbatim lines from the video's own captions — shadowing
                                // means mimicking the exact audio just heard, and the auto-pause
                                // timestamp cache (missions:cache-shadow-timestamps) only lands
                                // correctly on words the speaker actually says. Bold marks
                                // naturally-stressed content words (rendered by
                                // <x-stress-marked-line>).
                                'shadow_lines' => [
                                    'We **met** at a **clinic** that we were both **working** at in **Brighton**.',
                                    "I would **say** you're **very** **trustworthy** and **reliable**.",
                                    "I **think** you're a **very** **good** **listener**.",
                                    "She's **full** of **life**, she's **non-judgmental** and she's **always** on **time**.",
                                    "I think she's like a **very** **loyal** **friend** and she's **always** **there**, if you **message** her or **need** her.",
                                    'I would **say** that you are **very**, **very** **kind** and **caring**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M02', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Is anyone in your family very outgoing?', 'answer' => 'Yes, my brother is really outgoing. He talks to everyone, and right now he\'s planning a big party.', 'words' => ['outgoing', 'right now']],
                            ['question' => 'Who is in your close family?', 'answer' => 'My close family is my parents and my sister. We have dinner together every Friday.', 'words' => ['close family', 'every Friday']],
                            ['question' => 'How often do you see your relatives?', 'answer' => 'I see my relatives a few times a year. This week my aunt is staying with us.', 'words' => ['relatives', 'this week']],
                            ['question' => 'What is your current job or class, and how is it going?', 'answer' => 'My current job is in a bank. I like it, but I\'m looking for something new at the moment.', 'words' => ['current', 'at the moment']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 8,
                                'hook' => 'The words for talking about family and what people are like.',
                                'story' => "I'm quite an **outgoing** person, so I enjoy seeing my **close family** "
                                    .'and other **relatives** often. My sister\'s **current** boyfriend is '
                                    .'really funny — much better than her **ex-partner**!',
                                'words' => [
                                    ['phrase' => 'outgoing', 'meaning' => 'friendly and enjoys meeting/talking to people', 'meaning_fa' => 'برونگرا، اهل معاشرت', 'pos' => 'adjective', 'synonym' => 'sociable', 'example' => 'My brother is very outgoing and makes friends easily.', 'difficulty' => 'medium'],
                                    ['phrase' => 'close family', 'meaning' => 'your nearest family members (parents, siblings, etc.)', 'meaning_fa' => 'خانواده‌ی نزدیک', 'pos' => 'noun phrase', 'synonym' => 'immediate family', 'example' => 'My close family all live in the same city.', 'image_query' => 'family dinner table together', 'difficulty' => 'easy'],
                                    ['phrase' => 'relatives', 'meaning' => 'members of your family', 'meaning_fa' => 'فامیل، اقوام', 'pos' => 'noun', 'synonym' => 'family members', 'example' => 'We invited all our relatives to the wedding.', 'image_query' => 'extended family reunion', 'difficulty' => 'easy'],
                                    ['phrase' => 'current', 'meaning' => 'happening or existing now (as opposed to before)', 'meaning_fa' => 'کنونی، فعلی', 'pos' => 'adjective', 'synonym' => 'present', 'example' => 'My current job is more stressful than my last one.', 'difficulty' => 'medium'],
                                    ['phrase' => 'ex-partner', 'meaning' => "a person's former boyfriend/girlfriend/husband/wife", 'meaning_fa' => 'شریک سابق (همسر یا دوست‌دختر/پسر سابق)', 'pos' => 'noun', 'synonym' => 'former partner', 'example' => 'I still see my ex-partner at family events sometimes.', 'difficulty' => 'hard'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 9,
                                'hook' => 'Same audio, one more time — familiar is exactly the point.',
                                'image_query' => 'friends walking together talking',
                                'shadow_lines' => [
                                    "It's **not** like you **have** an **argument** or you **say**, I'm **not talking** to that **person** **anymore**.",
                                    "A **lot** of my **friends** **don't** **have** **families**.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M02', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                // Epic E merge — see M01's own comment for the arithmetic.
                                'duration_minutes' => 20,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Choose a friend or someone close to you, then record 2 minutes '
                                    .'of solo speaking describing them, using the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'Tell me about someone in your family — what are they like?',
                                    'Who do you get on well with these days?',
                                    "Is there a friend you've known for a long time? How did you get to know them?",
                                    'What is one of your friends doing at the moment — for work, study, or something else?',
                                    'How do you usually keep in touch with an old friend who lives far away?',
                                    'What personality trait do you like most in the people close to you?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Sara — her friendships probably look a lot like yours. Can you follow her story in English?',
                                'passage_title' => 'Meet Sara',
                                'image_query' => 'young woman smiling portrait friendly',
                                // Written fresh for this app (see EOS-009 §14) — deliberately
                                // reuses several Vocabulary Builder pool words/phrases (close,
                                // best friends, gets on well with, outgoing, old friend,
                                // friendship, lose touch, getting to know, close family,
                                // relatives, times are tough, get through) so whichever words a
                                // learner picked there, they meet again here in a fresh context.
                                'passage' => 'Sara has three very close school friends, and they\'ve stayed best '
                                    .'friends for almost fifteen years. She usually sees them once a month for '
                                    .'dinner, but these days it\'s harder to plan, because two of them are '
                                    .'working abroad for a few months. Sara says she gets on well with all three '
                                    .'of them, even though they\'re very different people — one is quiet and '
                                    .'careful, and another is much more outgoing and always wants to organise '
                                    .'something new. Sara also has an old friend, Mina, who she met at her first '
                                    ."job. They don't talk every week anymore, but Sara is sure they will never "
                                    .'lose touch — she thinks a good friendship can survive a '
                                    .'few quiet months. At the moment, Sara is actually getting to know a new '
                                    .'colleague, Priya, who just joined her team. They\'re having lunch together '
                                    .'most days this week, and Sara already thinks Priya could become a good '
                                    ."friend. Outside of friends, Sara's close family live nearby: her parents, "
                                    .'her brother, and a few relatives who often come round on Sundays. Sara says '
                                    .'that when times are tough, her family are the people who help her get '
                                    .'through it.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'close', 'type' => 'reused'],
                                    ['phrase' => 'best friends', 'type' => 'reused'],
                                    ['phrase' => 'gets on well with', 'type' => 'reused'],
                                    ['phrase' => 'outgoing', 'type' => 'reused'],
                                    ['phrase' => 'old friend', 'type' => 'reused'],
                                    ['phrase' => 'friendship', 'type' => 'reused'],
                                    ['phrase' => 'lose touch', 'type' => 'reused'],
                                    ['phrase' => 'getting to know', 'type' => 'reused'],
                                    ['phrase' => 'close family', 'type' => 'reused'],
                                    ['phrase' => 'relatives', 'type' => 'reused'],
                                    ['phrase' => 'times are tough', 'type' => 'new', 'definition' => 'a period of trouble, unhappiness or financial difficulty in life', 'pos' => 'idiom', 'example' => 'When times are tough, good friends really matter.'],
                                    ['phrase' => 'get through', 'type' => 'new', 'definition' => 'to manage to live through a difficult period of time', 'pos' => 'phrasal verb', 'example' => 'My friends helped me get through a hard year.'],
                                    ['phrase' => 'colleague', 'type' => 'new', 'definition' => 'a person you work with', 'pos' => 'noun', 'example' => 'My colleague helped me finish the report.'],
                                    ['phrase' => 'organise', 'type' => 'new', 'definition' => 'to plan and arrange an event or activity', 'pos' => 'verb', 'example' => 'She loves to organise weekend trips for her friends.'],
                                    ['phrase' => 'survive', 'type' => 'new', 'definition' => 'to continue existing despite a difficult situation', 'pos' => 'verb', 'example' => 'Their friendship survived even after she moved abroad.'],
                                ],
                                'topic_summary' => 'A short profile of Sara and her friendships: her three '
                                    .'long-time school friends (usually seen monthly, though two are currently '
                                    .'working abroad), her old friend Mina from her first job (a friendship she '
                                    .'believes can survive quiet periods), a new colleague, Priya, she\'s getting '
                                    .'to know this week, and her close family and relatives nearby who help her '
                                    .'through hard times.',
                                'comprehension_check' => [
                                    ['statement' => 'Sara has three close friends from school.', 'correct' => true],
                                    ['statement' => 'Sara sees her school friends every week.', 'correct' => false],
                                    ['statement' => 'Sara thinks a friendship can survive a few quiet months without contact.', 'correct' => true],
                                ],
                                'questions' => [
                                    "How does Sara feel about her friendship with Mina, even though they don't talk every week anymore?",
                                    "What is different about Sara's routine with her school friends these days, compared to usual?",
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 14,
                                'hook' => 'Everyone has an opinion about what makes a good friend — now explain yours, with real reasons and real examples.',
                                'title' => 'What makes a good friend?',
                                'structure_note' => "Don't just list qualities. Build each idea as Opinion → Reason → Example.",
                                'prompts' => [
                                    ['label' => 'Trust', 'image_query' => 'two friends trust support'],
                                    ['label' => 'Fun & Laughter', 'image_query' => 'friends laughing together'],
                                    ['label' => 'Honesty', 'image_query' => 'friends honest conversation'],
                                    ['label' => 'Support', 'image_query' => 'friend consoling friend holding hands'],
                                ],
                                // Kept the essay's own connectors (because, for example, in my
                                // opinion — matching structure_note's Opinion → Reason →
                                // Example shape) and added Present Simple / Present Continuous
                                // markers so this mission's own grammar point threads into
                                // Writing too, same as M01/M03/M04 already do — this step was
                                // the one mission missing that (see
                                // feedback_content_authoring_conventions rule 6, memory).
                                // "always"/"usually" fit the essay's general-truths-about-
                                // friendship register (Present Simple); "these days"/"right now"
                                // fit giving one concrete, specific example (Present Continuous).
                                // Grammar connectors only — see M01's try_to_use note above on
                                // why the mission's vocabulary words were dropped from this list
                                // (already covered by <x-vocabulary-pills> just above, which
                                // respects the learner's actual selection; duplicating them here
                                // was pure repetition, not reinforcement).
                                'try_to_use' => ['because', 'for example', 'in my opinion', 'always', 'usually', 'these days', 'right now'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Describe a friend: what is he or she like, and what is he or she doing at the moment?', 'answer' => 'Sara is calm and kind. She usually works in a shop, but at the moment she\'s studying for an exam.', 'words' => ['usually', 'at the moment']],
                            ['question' => 'How do you stay in touch with friends who live far away?', 'answer' => 'We stay in touch with video calls, and we\'re planning to meet up next summer.', 'words' => ['stay in touch', 'meet up']],
                            ['question' => 'Who in your family do you get on well with?', 'answer' => 'I get on well with my grandmother. She\'s teaching me to cook these days.', 'words' => ['get on well with', 'these days']],
                            ['question' => 'Tell me about a friendship that changed.', 'answer' => 'I lost touch with an old friend after university, but this year we\'re talking again.', 'words' => ['lost touch', 'old friend']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                // Moved here from Day 2 (2026-10) to balance day load and give
                                // Day 4 a 4th, AI-graded step now that partner_speaking_session
                                // was cut (see Mission Structure note below for why): its
                                // round_groups duplicated ai_conversation_1/2's questions almost
                                // verbatim, its solo fallback had no AI evaluation at all, and
                                // MissionRun::allLearnerText() never read its output — a dead end
                                // feeding nothing downstream. The lighter <x-practice-with-friend>
                                // bridge on ai_conversation_1/2 already covers the one thing it did
                                // that nothing else does (a real human audience), at zero mandatory
                                // step cost.
                                'duration_minutes' => 10,
                                'hook' => "Forget your own friends for a minute — what's happening in this one?",
                                // Re-queried (2026-09-05, once Pexels access came back) —
                                // the original query returned a 2-person food-plate photo
                                // that didn't match the guiding_questions below (no seated
                                // group, no visible food/drinks). This query returns a real
                                // 5-friend picnic scene: 4 people sitting cross-legged on a
                                // checked blanket plus one sitting separately in a folding
                                // chair, a fruit basket + orange juice bottle + flowers on
                                // the blanket, one person laughing with a plush toy.
                                'image_query' => 'group friends picnic blanket park sitting',
                                'guiding_questions' => [
                                    'What are the friends doing right now?',
                                    'How many people are in the group, and where are they sitting?',
                                    'What food or drinks can you see in the picture?',
                                    'How do the people in the picture seem to feel?',
                                ],
                                // Hand-verified against the real cached photo for this exact
                                // query+orientation (landscape) — see the docblock note on
                                // M01's own picture_description entry for the same convention.
                                // Q1 deliberately points at the one friend sitting in a chair
                                // (not on the blanket like the other four) — a genuine detail
                                // worth noticing for "where are they sitting."
                                'hotspots' => [
                                    ['x' => 45, 'y' => 60, 'question_index' => 0],
                                    ['x' => 80, 'y' => 42, 'question_index' => 1],
                                    ['x' => 42, 'y' => 80, 'question_index' => 2],
                                    ['x' => 42, 'y' => 38, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 14,
                                'hook' => "This one's harder on purpose — describe someone who matters, with no script to hide behind.",
                                'round_pool' => [
                                    'Introduce this person and explain how you know them.',
                                    'Describe what they\'re like as a person, and what you usually do together.',
                                    'Talk about what they\'re doing these days, and why they matter to you.',
                                    'How did you two first meet?',
                                    'What do you admire most about them?',
                                    'How has your relationship with them changed over time?',
                                ],
                                'role_reversal_topic' => 'friends and family',
                                'final_prompt' => 'Speak for 3 minutes without stopping about someone important in your life.',
                                'requirements' => [
                                    'Present Simple',
                                    'Present Continuous',
                                    '5+ vocabulary expressions',
                                    'Examples',
                                    'Reasons',
                                    'Talks about who this person is and how you know them',
                                    'Talks about what they are doing these days',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->seedM04();
    }

    /**
     * Seeds M04 with its real content from document/M04/ (M04.pdf, the
     * Oxford Online English "Talk About Food and Cooking" lesson, and the Smashing English
     * "What I Eat in a Day" vlog), matching M04.pdf's own 3-day/
     * 10-page compact workbook plan (it names 10 pages — this seeds
     * exactly those 10, plus video_shadowing as an 11th, added once its
     * source video arrived — not M01/M02's fuller line-up otherwise).
     */
    private function seedM04(): void
    {
        $audioUrl = $this->publishMissionAsset('M04', 'Oxford Online English - Talk About Food and Cooking.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M04', 'Smashing English - What I Eat in a Day.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M04', 'Smashing English - What I Eat in a Day.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M04'],
            [
                'title' => 'Food & Lifestyle',
                'module' => 'Food',
                'outcome' => 'I can talk about what I eat, my eating habits, my preferences, and the lifestyle choices I make.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'What\'s your favourite dish from your country?', 'answer' => 'My favourite dish is ghormeh sabzi. It has a lot of herbs and some meat.', 'words' => ['dish', 'a lot of', 'some']],
                            ['question' => 'Which cuisine do you like apart from Persian food?', 'answer' => 'I love Italian cuisine, especially pasta with a bit of cheese.', 'words' => ['cuisine', 'a bit of']],
                            ['question' => 'Is there any food you think is bland?', 'answer' => 'Yes, I think plain rice is bland without any sauce or butter.', 'words' => ['bland', 'any']],
                            ['question' => 'Do you take a packed lunch to work or school?', 'answer' => 'Yes, I usually take a packed lunch: some bread, cheese and a few tomatoes.', 'words' => ['packed lunch', 'a few']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Someone asks what you usually eat in a day — could you actually describe it in English, or would you just say "normal food"?',
                                'image_query' => 'healthy meal fresh vegetables table',
                                'ambient_video_query' => 'fresh food preparation kitchen slow motion',
                                // Real questions from M04.pdf page 01 "Before you start".
                                'warm_up_questions' => [
                                    'What do you usually eat in a normal day?',
                                    "What's your favourite meal?",
                                    'Do you think you have a healthy diet?',
                                    'What would you like to change?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five small words that let you say what you really think about a meal.',
                                // Day 1 = the Listening lesson's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'I like **a bit of** everything, but my favourite **cuisine** is Persian. '
                                    .'My mum\'s best **dish** is ghormeh sabzi — it\'s never **bland**! On weekdays '
                                    .'I take a **packed lunch** to work, usually some rice and a little salad.',
                                'words' => [
                                    ['phrase' => 'a bit of', 'meaning' => 'a small amount of something (with uncountable nouns, or "a bit of everything")', 'meaning_fa' => 'کمی، یک مقدار کم', 'pos' => 'quantifier', 'synonym' => 'a little', 'example' => 'Can I have a bit of milk in my tea?', 'difficulty' => 'easy'],
                                    ['phrase' => 'cuisine', 'meaning' => 'the style of cooking of a country or a region', 'meaning_fa' => 'آشپزی (سبک آشپزی یک کشور یا منطقه)', 'pos' => 'noun', 'synonym' => 'cooking, food', 'example' => 'I love Italian cuisine.', 'image_query' => 'italian food table pasta', 'difficulty' => 'medium'],
                                    ['phrase' => 'dish', 'meaning' => 'one kind of food, cooked or prepared in a special way', 'meaning_fa' => 'غذا (یک نوع غذای پخته‌شده)', 'pos' => 'noun', 'synonym' => "not the same as 'meal'", 'example' => 'Ghormeh sabzi is a famous Iranian dish.', 'difficulty' => 'easy'],
                                    ['phrase' => 'bland', 'meaning' => 'with very little or no flavour', 'meaning_fa' => 'بی‌مزه، کم‌طعم', 'pos' => 'adjective', 'synonym' => 'opposite: tasty, spicy', 'example' => 'The soup was a bit bland, so I added some salt.', 'difficulty' => 'hard'],
                                    ['phrase' => 'packed lunch', 'meaning' => 'food you take from home to eat at work or school', 'meaning_fa' => 'ناهار همراه (غذایی که از خانه می‌بری)', 'pos' => 'noun phrase', 'example' => 'I take a packed lunch to work every day.', 'image_query' => 'packed lunch box', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Oli and Marie talk about the food they love — and one meal that went badly wrong. Listen for "a lot of", "a bit of" and "much".',
                                'source' => 'Oxford Online English — "Talk About Food and Cooking in English"',
                                'image_query' => 'home cooking kitchen',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M04', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers.
                                'listening_turns' => $this->shadowTimestampsFor('M04', 'listening_turns'),
                                // The lesson's own human captions for the four parts kept in the cut
                                // (0:01-0:16, 0:52-2:58, 3:47-4:57, 7:46-10:28 of the original; the
                                // website plug and the moussaka recipe dropped), checked against a
                                // Whisper pass of the cut audio. Dialogue speakers as the lesson's
                                // own page labels them; the explanations by voice.
                                'transcript' => [
                                    ['speaker' => 'Oli', 'text' => "Hi, I'm Oli. Welcome to Oxford Online English! In this lesson, you can learn how to talk about food. You'll see how to talk about different cuisines, talk about food you like – or dislike – and talk about cooking and eating habits."],
                                    ['speaker' => 'Marie', 'text' => 'What kind of food do you like?'],
                                    ['speaker' => 'Oli', 'text' => "I like a bit of everything, really. I grew up in the UK, and you can get food from all over the world there. My mum's cooking is a combination of different cuisines: a bit of French, a bit of Italian, a bit of Indian, and so on."],
                                    ['speaker' => 'Marie', 'text' => "What's British cuisine like? I know about fish and chips, but there must be more…"],
                                    ['speaker' => 'Oli', 'text' => "There is, but not that much. There are a few famous dishes like shepherd's pie or Sunday roast, but most people eat a mix of things. What about you? What food do you like?"],
                                    ['speaker' => 'Marie', 'text' => "I'm half Spanish, so when I was young we ate a lot of Mediterranean food at home. Now, I live in Berlin, and it's a pretty cosmopolitan place, so you can get all kinds of food, like the UK, I suppose."],
                                    ['speaker' => 'Oli', 'text' => "I've never really had much Spanish food. Is it similar to Italian?"],
                                    ['speaker' => 'Marie', 'text' => "In some ways, yes. They both use a lot of fresh ingredients, and there's a lot of seafood, salads, and so on. Pasta isn't so common in Spanish cooking, though."],
                                    ['speaker' => 'Oli', 'text' => "What are some typical Spanish dishes? I'd be interested to try some."],
                                    ['speaker' => 'Marie', 'text' => "I guess paella is quite well-known. My personal favourite is a dish called albondigas, which is meatballs in a tomato sauce. It's simple, but so tasty."],
                                    ['speaker' => 'Oli', 'text' => 'Sounds good!'],
                                    ['speaker' => 'Marie', 'text' => "Here's a question: do you know the difference between the words 'cuisine', 'dish' and 'meal'? 'Cuisine' means the kind of food you find in a specific country or culture. For example, you have Chinese cuisine, French cuisine, local cuisine, and so on. 'Cuisine' means something like 'cooking style'. You can use the word 'food' or 'cooking' in the same way. So, you can say 'Chinese cuisine', 'Chinese cooking' or 'Chinese food'. The meaning is very close."],
                                    ['speaker' => 'Oli', 'text' => "A 'dish' means something which is cooked or prepared. Usually, a dish is made from different kinds of food. A 'meal' is food eaten at a specific time. Most people eat three meals a day: breakfast, lunch and dinner. We often hear English learners make mistakes with these three words, so be careful with them!"],
                                    ['speaker' => 'Marie', 'text' => "Next, let's look at how to talk about food you like – or don't!"],
                                    ['speaker' => 'Oli', 'text' => 'So, what do you think?'],
                                    ['speaker' => 'Marie', 'text' => 'Oh no! This is terrible!'],
                                    ['speaker' => 'Oli', 'text' => 'Really?'],
                                    ['speaker' => 'Marie', 'text' => "First, you haven't cooked the meat long enough. It's tough and really chewy. You need to keep cooking it until it's tender."],
                                    ['speaker' => 'Oli', 'text' => 'I cooked it for two hours, just like you said!'],
                                    ['speaker' => 'Marie', 'text' => "Yes, but you also have to check that it's done! Also, these vegetables are awful. They're mushy because you've overcooked them. They should be fresh and crunchy."],
                                    ['speaker' => 'Oli', 'text' => 'Right… What about the sauce?'],
                                    ['speaker' => 'Marie', 'text' => "It's not bad, but it's a little bland. A dish like this should be rich, spicy and a little sour. While you're cooking, don't forget to taste it, and add more spices, or more vinegar, or whatever it needs. Hmm… OK…"],
                                    ['speaker' => 'Oli', 'text' => "I'm a little scared to show you my dessert, now."],
                                    ['speaker' => 'Marie', 'text' => 'Ahh, wow! This is amazing!'],
                                    ['speaker' => 'Oli', 'text' => 'Oh? You mean it?'],
                                    ['speaker' => 'Marie', 'text' => "Yes! It's a perfect tart. It's crumbly, but not dry, which is a difficult balance to get right. The fruit gives it a nice, tangy flavour. Very tasty!"],
                                    ['speaker' => 'Oli', 'text' => "Thank you! Next, let's see how you can talk about cooking habits."],
                                    ['speaker' => 'Marie', 'text' => 'Do you cook much?'],
                                    ['speaker' => 'Oli', 'text' => "Sometimes. I cook maybe twice a week, but I'm too busy to do more than that."],
                                    ['speaker' => 'Marie', 'text' => 'What do you do the rest of the time?'],
                                    ['speaker' => 'Oli', 'text' => "For lunch, I eat in the canteen at work. In the evening, I generally get something from the supermarket, or get a takeaway. I don't like it, because I know it's more expensive and less healthy than cooking for myself, but I just don't have the time. What about you?"],
                                    ['speaker' => 'Marie', 'text' => "I make most of my meals. I don't cook every day, though. I normally do a lot of cooking on Sunday, and then I have food for the week. I take a packed lunch to work, and then eat leftovers in the evening."],
                                    ['speaker' => 'Oli', 'text' => "That's a good system! I wish I could be so organised…"],
                                    ['speaker' => 'Marie', 'text' => "It helps that I have a market very close to my house. They have great fresh produce, fish, meat… everything you need. That means I don't need to spend much time shopping. Plus, I prefer eating home-cooked, fresh food. If I don't have food with me, I end up eating greasy fast food, and then I feel bloated and gross."],
                                    ['speaker' => 'Oli', 'text' => "Yeah, I'm the same way… Maybe I'll start cooking more."],
                                    ['speaker' => 'Marie', 'text' => "Think about the first question you heard in the dialogue: 'Do you cook much?' How would you answer this? You could say something like: 'I cook every day.' 'I don't cook much – maybe once or twice a week.' 'I don't cook. I've never learned how!' If you don't cook, what can you do for food? You heard some phrases in the dialogue. Do you remember?"],
                                    ['speaker' => 'Oli', 'text' => "Look at some sentences that you heard. Imagine you're an English teacher, and you want to explain the meanings of the words in red."],
                                    ['speaker' => 'Marie', 'text' => 'How would you do it?'],
                                    ['speaker' => 'Oli', 'text' => "A canteen is a bit like a restaurant, but it's run by a company for its staff, or by a university for its students. Canteens are usually cheap, or the food might even be free."],
                                    ['speaker' => 'Marie', 'text' => 'A takeaway means you buy or order the food from a restaurant, and then eat it somewhere else, usually at home.'],
                                    ['speaker' => 'Oli', 'text' => 'A packed lunch means you take food from home and eat it at work or school.'],
                                    ['speaker' => 'Marie', 'text' => "If you cook more than you can eat, the extra food is called 'leftovers'. You can eat the leftovers on another day. What about you? Do you cook much? If not, what do you do for food?"],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript.
                                'target_phrases' => [
                                    [
                                        'phrase' => 'a bit of', 'meaning' => 'a small amount of',
                                        'gap_before' => 'I like ',
                                        'gap_after' => ' everything, really.',
                                    ],
                                    [
                                        'phrase' => 'cuisine', 'meaning' => 'style of cooking of a country',
                                        'gap_before' => "What's British ",
                                        'gap_after' => ' like?',
                                    ],
                                    [
                                        'phrase' => 'dish', 'meaning' => 'one kind of cooked food',
                                        'gap_before' => 'My personal favourite is a ',
                                        'gap_after' => ' called albondigas, which is meatballs in a tomato sauce.',
                                    ],
                                    [
                                        'phrase' => 'bland', 'meaning' => 'with little or no flavour',
                                        'gap_before' => "It's not bad, but it's a little ",
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'packed lunch', 'meaning' => 'food you take from home to eat at work',
                                        'gap_before' => 'I take a ',
                                        'gap_after' => ' to work, and then eat leftovers in the evening.',
                                    ],
                                ],
                                'topic_summary' => 'Oli and Marie, two English teachers, talk about food: the cuisines they '
                                    .'grew up with (British and Spanish) and their favourite dishes; the difference between '
                                    .'"cuisine", "dish" and "meal"; a short scene where Marie tastes a meal Oli cooked '
                                    .'(tough meat, mushy vegetables, a bland sauce, but a perfect tart); and their cooking '
                                    .'habits — Oli cooks about twice a week and eats in the canteen or gets a takeaway, while '
                                    .'Marie cooks a lot on Sunday, takes a packed lunch and eats leftovers. Then they explain '
                                    .'canteen, takeaway, packed lunch and leftovers.',
                                'comprehension_check' => [
                                    ['statement' => 'Oli and Marie talk about food, cooking and eating habits.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Marie says the meat Oli cooked was soft and tender.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Marie does a lot of cooking on Sunday, so she has food for the week.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'What does Oli usually eat for lunch?',
                                    'options' => ['A packed lunch from home', 'Food from the canteen at work', 'A takeaway pizza'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Do you eat home-cooked food most days?', 'answer' => 'Yes, I eat home-cooked food most days. My mum doesn\'t buy much frozen food.', 'words' => ['home-cooked', 'much', 'frozen']],
                            ['question' => 'Do you have a balanced diet?', 'answer' => 'I try to have a balanced diet, but I eat a lot of bread and not many vegetables.', 'words' => ['balanced diet', 'a lot of', 'many']],
                            ['question' => 'What ingredients do you always have in your kitchen?', 'answer' => 'I always have some fresh herbs, a few onions and a little oil. They\'re my main ingredients.', 'words' => ['fresh', 'a few', 'a little', 'ingredients']],
                            ['question' => 'What do you do with leftovers?', 'answer' => 'We eat the leftovers for lunch the next day, so we don\'t throw away much food.', 'words' => ['leftovers', 'much']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'The words for talking about what\'s actually in your kitchen.',
                                'story' => 'Most days I eat a **home-cooked** dinner and try to follow a '
                                    .'**balanced diet**. I prefer cooking with **fresh** vegetables, but '
                                    .'**frozen** ones save time too. Whatever fresh **ingredient** I buy too '
                                    .'much of usually ends up as **leftovers** the next day.',
                                'words' => [
                                    ['phrase' => 'home-cooked', 'meaning' => 'made at home, not bought ready-made or from a restaurant', 'meaning_fa' => 'خانگی (پخته‌شده در خانه)', 'pos' => 'adjective', 'example' => "There's nothing better than a home-cooked meal.", 'image_query' => 'home cooked dinner family table', 'difficulty' => 'easy'],
                                    ['phrase' => 'balanced diet', 'meaning' => 'a healthy mix of different kinds of food', 'meaning_fa' => 'رژیم غذایی متعادل', 'pos' => 'noun phrase', 'example' => 'Eating a balanced diet keeps me healthy.', 'difficulty' => 'medium'],
                                    ['phrase' => 'fresh', 'meaning' => 'recently made or picked, not from a can or freezer', 'meaning_fa' => 'تازه', 'pos' => 'adjective', 'synonym' => 'opposite: frozen', 'example' => 'I always buy fresh fruit at the market.', 'image_query' => 'fresh vegetables market', 'difficulty' => 'easy'],
                                    ['phrase' => 'frozen', 'meaning' => 'kept cold to preserve it, not fresh', 'meaning_fa' => 'منجمد، یخ‌زده', 'pos' => 'adjective', 'synonym' => 'opposite: fresh', 'example' => 'We keep frozen vegetables for busy nights.', 'image_query' => 'frozen food in freezer drawer', 'difficulty' => 'easy'],
                                    ['phrase' => 'ingredient', 'meaning' => 'one of the foods used to make a dish', 'meaning_fa' => 'ماده‌ی اولیه (مواد یک غذا)', 'pos' => 'noun', 'example' => 'Garlic is the main ingredient in this sauce.', 'difficulty' => 'medium'],
                                    ['phrase' => 'leftovers', 'meaning' => 'food that was not eaten at a meal, saved for later', 'meaning_fa' => 'غذای باقی‌مانده', 'pos' => 'noun', 'example' => 'I had the leftovers for lunch the next day.', 'image_query' => 'leftovers food container fridge', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same lesson, one more time — copy how Oli and Marie say "a lot of" and "much".',
                                'image_query' => 'healthy breakfast bowl oatmeal',
                                'shadow_lines' => [
                                    'When I was **young** we ate **a lot of** **Mediterranean** food at **home**.',
                                    "I've **never** **really** had **much** **Spanish** food.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M04', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 14,
                                'hook' => "Every \"I don't eat much...\" you get right here is one less pause when you're talking about your own diet.",
                                'focus' => 'Countable and Uncountable Nouns',
                                'lesson' => [
                                    'intro' => 'Three small things: can you count it, which word goes with which noun, and how to talk about your own food.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · Can you count it?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>Countable</strong>: you can count it (1, 2, 3…).<br><strong>Uncountable</strong>: you can\'t — and it has no plural.',
                                                    'fa' => 'قابل‌شمارش: می‌توانی بشماری و جمع دارد (apple → apples). غیرقابل‌شمارش: نمی‌شماری و جمع ندارد (rice, water).',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Countable', 'items' => ['an apple', 'two apples', 'an egg', 'three eggs']],
                                                        ['label' => 'Uncountable', 'items' => ['rice', 'water', 'bread', 'cheese']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Priya',
                                                    'wrong' => 'I don\'t eat many rice.',
                                                    'right' => 'I don\'t eat much rice.',
                                                    'explanation' => 'Rice is uncountable, so it takes “much”, not “many”.',
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · much, many, a few, a little',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>many / a few</strong> + countable.<br><strong>much / a little</strong> + uncountable.',
                                                    'fa' => 'many و a few با اسم قابل‌شمارش (جمع) می‌آیند؛ much و a little با اسم غیرقابل‌شمارش.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Countable',
                                                    'parts' => [
                                                        ['text' => 'many / a few', 'caption' => 'quantity'],
                                                        ['text' => 'eggs', 'caption' => 'plural noun'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Uncountable',
                                                    'parts' => [
                                                        ['text' => 'much / a little', 'caption' => 'quantity'],
                                                        ['text' => 'rice', 'caption' => 'no plural'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'rule_examples',
                                                    'items' => [
                                                        ['rule' => 'many + plural countable noun', 'example' => 'I don\'t eat many vegetables.', 'highlight' => 'many'],
                                                        ['rule' => 'much + uncountable noun', 'example' => 'I don\'t drink much coffee.', 'highlight' => 'much'],
                                                        ['rule' => 'a few + plural countable noun', 'example' => 'I eat a few eggs every week.', 'highlight' => 'a few'],
                                                        ['rule' => 'a little + uncountable noun', 'example' => 'I add a little sugar to my coffee.', 'highlight' => 'a little'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Also useful', 'words' => ['a / an', 'some', 'any', 'a lot of']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about what <strong>you</strong> eat and drink — and use them out loud soon, in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this soon — in AI Conversation #1, talking about your own eating habits.',
                                ],
                                // Real starters from M04.pdf page 04 "Make it personal".
                                'frequency_starters' => [
                                    'I usually eat some', "I don't eat much", 'I eat a lot of',
                                    'I usually have a few', "I don't eat many", 'I need some',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence, using a countable or uncountable food noun '
                                    .'correctly with the quantifier already given in the starter.',
                                'grammar_major_criteria' => 'the noun given after the quantifier is grammatically wrong '
                                    .'for that quantifier (e.g. "much" or "many" with the wrong noun type, a plural '
                                    .'-s added to an uncountable noun), the sentence does not actually continue the '
                                    .'given starter, or it is not a genuine personal statement',
                                'grammar_context' => 'continues with a correct countable or uncountable food noun for '
                                    .'the quantifier already given',
                                'quick_check' => [
                                    [
                                        'wrong' => "I don't eat many rice.",
                                        'options' => ["I don't eat many rice.", "I don't eat many rices.", "I don't eat much rice."],
                                        'correct' => 2,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'She drinks a lot of coffees every morning.',
                                        'options' => ['She drinks a lot of coffees every morning.', 'She drink a lot of coffee every morning.', 'She drinks a lot of coffee every morning.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'I need a few water.',
                                        'options' => ['I need a few water.', 'I need a little water.', 'I need a few waters.'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ["don't", 'many', 'I', 'vegetables.', 'eat'], 'answer' => "I don't eat many vegetables."],
                                    ['words' => ['much', "don't", 'coffee.', 'I', 'drink'], 'answer' => "I don't drink much coffee."],
                                    ['words' => ['a', 'eggs', 'few', 'I', 'every', 'eat', 'week.'], 'answer' => 'I eat a few eggs every week.'],
                                ],
                            ],
                            [
                                // A real vlog, one speaker, natural speed: Smashing English's
                                // "What I Eat in a Day" (about 6 minutes cut from the original
                                // 13-minute video — breakfast and lunch; the creator's burned-in
                                // captions at the top of the frame blurred out so the step's own
                                // caption toggle works, her pink vocabulary cards left in).
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'A real person films everything she eats in a day — hear everyday food English at natural speed, then make your own voice do the same.',
                                'source' => 'Smashing English — "Learn English Vlog - What I Eat In a Day (Real Daily English)"',
                                'video_url' => $shadowingVideoUrl,
                                // The video's own auto-captions, punctuated and corrected
                                // against the creator's burned-in captions, re-timed per
                                // sentence.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M04', 'video_shadowing_segments'),
                                'topic_summary' => 'An English teacher vlogs what she eats in a day and explains the '
                                    .'food English she uses along the way. She makes Greek yogurt with almond '
                                    .'butter, apple and blueberries for breakfast (and spills it at the start), '
                                    .'then a turkey and cranberry bagel for lunch, and talks about why she likes '
                                    .'the same breakfast every day, why yogurt keeps her full, and how she '
                                    .'treats herself even when she tries to eat healthily.',
                                'comprehension_check' => [
                                    ['statement' => 'She eats the same breakfast almost every day.', 'correct' => true],
                                    ['statement' => 'She says Greek yogurt is not filling.', 'correct' => false],
                                    ['statement' => 'On weekdays she usually has a salad for lunch.', 'correct' => true],
                                ],
                                // Real food words she explains on screen — "filling" and
                                // "creature of habit" are her own vocabulary cards; "keeps me
                                // full" is the natural way to say what "filling" means.
                                'target_phrases' => [
                                    ['phrase' => 'filling', 'meaning' => '(of food) making you feel full and satisfied'],
                                    ['phrase' => 'creature of habit', 'meaning' => 'someone who likes to do things the same way every day'],
                                    ['phrase' => 'keep (someone) full', 'meaning' => 'to stop you feeling hungry for a long time'],
                                    ['phrase' => 'treat yourself', 'meaning' => 'to give yourself something nice, like a chocolate bar'],
                                    ['phrase' => 'a chunk of (something)', 'meaning' => 'a piece of food of no exact size'],
                                ],
                                // Real verbatim lines from the video's own captions — shadowing
                                // means mimicking the exact audio just heard, and the auto-pause
                                // timestamp cache (missions:cache-shadow-timestamps) only lands
                                // correctly on words the speaker actually says. Bold marks
                                // naturally-stressed content words (rendered by
                                // <x-stress-marked-line>).
                                'shadow_lines' => [
                                    "So, I'm actually a bit of a **creature** of **habit**.",
                                    'I **really** **like** having **very** **similar** **food** **most** **days**.',
                                    'So, what I **normally** do for **breakfast** is I **normally** get a **bowl**.',
                                    "It's **very** **filling**. It **keeps** me **full** for a **long** **time**.",
                                    'I **cut** the **apple** into **smaller** **pieces**, or I **cut** the apple into **chunks**.',
                                    'And then to **finish** it **off**, I just **top** it with some **blueberries**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M04', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Do you have a sweet tooth?', 'answer' => 'Yes, I have a real sweet tooth. I eat a lot of chocolate and too many biscuits.', 'words' => ['sweet tooth', 'too many']],
                            ['question' => 'Do you like spicy food?', 'answer' => 'I like a little spicy food, but too much chilli hurts my stomach.', 'words' => ['spicy', 'a little', 'too much']],
                            ['question' => 'What food do you want to cut down on?', 'answer' => 'I want to cut down on junk food. I eat too many crisps.', 'words' => ['cut down on', 'junk food']],
                            ['question' => 'Is it easy to eat healthy food where you live?', 'answer' => 'It\'s quite easy. Healthy food like fruit is cheap, but unhealthy food is everywhere too.', 'words' => ['healthy food', 'unhealthy food']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the words for talking about healthy vs. not-so-healthy choices.',
                                'story' => 'I definitely have a **sweet tooth**, but my sister prefers something '
                                    .'**spicy** instead. I\'m not trying to only eat "**healthy food**" and never '
                                    .'touch "**unhealthy food**" again — I\'m just trying to **cut down on** '
                                    .'**junk food**, one small change at a time.',
                                'words' => [
                                    ['phrase' => 'sweet tooth', 'meaning' => 'a strong liking for sweet food', 'meaning_fa' => 'علاقه‌ی زیاد به شیرینی', 'pos' => 'noun phrase (idiom)', 'example' => 'My dad has a real sweet tooth.', 'difficulty' => 'hard'],
                                    ['phrase' => 'spicy', 'meaning' => 'having a strong, hot taste, like chilli', 'meaning_fa' => 'تند، ادویه‌دار', 'pos' => 'adjective', 'synonym' => 'opposite: mild', 'example' => 'This curry is a bit too spicy for me.', 'image_query' => 'spicy food chilli', 'difficulty' => 'easy'],
                                    ['phrase' => 'healthy food', 'meaning' => 'food that is good for your body', 'meaning_fa' => 'غذای سالم', 'pos' => 'noun phrase', 'synonym' => 'opposite: junk food', 'example' => 'She only keeps healthy food in the house.', 'image_query' => 'healthy food bowl vegetables', 'difficulty' => 'easy'],
                                    ['phrase' => 'unhealthy food', 'meaning' => 'food that is bad for your body if eaten often', 'meaning_fa' => 'غذای ناسالم', 'pos' => 'noun phrase', 'example' => 'Too much unhealthy food makes me feel tired.', 'difficulty' => 'easy'],
                                    ['phrase' => 'cut down on', 'meaning' => 'to reduce how much of something you eat or do', 'meaning_fa' => 'کم کردن (مصرف یا انجام چیزی)', 'pos' => 'phrasal verb', 'synonym' => 'reduce', 'example' => "I'm trying to cut down on sugar.", 'difficulty' => 'hard'],
                                    ['phrase' => 'junk food', 'meaning' => 'cheap food that is quick to eat but bad for your health', 'meaning_fa' => 'فست‌فود، غذای ناسالم آماده', 'pos' => 'noun phrase', 'synonym' => 'opposite: healthy food', 'example' => "It's easy to eat junk food when you're busy.", 'image_query' => 'junk food fast food', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Last listen — notice the little words before the nouns: a bit of, a little, much.',
                                'image_query' => 'fresh food kitchen preparation',
                                'shadow_lines' => [
                                    "It's **not bad**, but it's **a little** **bland**.",
                                    "That **means** I **don't** need to spend **much** **time** **shopping**.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M04', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                // Epic E merge — see M01's own comment for the arithmetic.
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                // Real questions from M04.pdf page 05 "Food in my life".
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your eating habits — '
                                    .'what you usually eat, and what you\'d like to eat more or less of — using '
                                    .'the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'What do you usually eat for breakfast?',
                                    'How often do you cook at home?',
                                    "What's the healthiest meal you eat regularly?",
                                    'Do you prefer eating alone or with other people?',
                                    "What do you eat when you're busy or stressed?",
                                    'How has your diet changed in the last few years?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Marco — his eating habits change with his shift. Can you follow his day in English?',
                                'passage_title' => 'Meet Marco',
                                'image_query' => 'young man portrait smiling kitchen',
                                // Written fresh for this app (see EOS-009 §14) — reuses several
                                // Vocabulary Builder pool words/phrases (skips breakfast from
                                // M01, home-cooked, fresh, sweet tooth, cut down on, junk
                                // food) so whichever words a learner picked there,
                                // they meet again here in a fresh context, third person
                                // instead of first.
                                'passage' => 'Marco works long hours as a nurse in Rome, so his eating habits change '
                                    .'a lot depending on his shift. On a normal day, he never skips breakfast — he '
                                    .'always has a home-cooked bowl of oats before leaving the house, because he '
                                    ."knows a quick snack won't last through a busy morning. At work, he brings "
                                    .'fresh fruit and nuts instead of relying on the vending machine, though he '
                                    .'admits he still has a sweet tooth and sometimes gives in to a chocolate bar '
                                    .'around 3pm. For dinner, he tries to cook something simple with fresh '
                                    .'ingredients, but on his busiest nights he can\'t resist ordering takeaway '
                                    .'instead. Marco says he is slowly trying to cut down on processed food and '
                                    .'junk food, not by banning them completely, but by choosing them a little '
                                    .'less often. His only rule: never eat a heavy meal right before a night '
                                    .'shift — it leaves him feeling sluggish for hours.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'skips breakfast', 'type' => 'reused'],
                                    ['phrase' => 'home-cooked', 'type' => 'reused'],
                                    ['phrase' => 'fresh', 'type' => 'reused'],
                                    ['phrase' => 'sweet tooth', 'type' => 'reused'],
                                    ['phrase' => 'cut down on', 'type' => 'reused'],
                                    ['phrase' => 'processed food', 'type' => 'new', 'definition' => 'food that has been changed a lot in a factory before it is sold', 'pos' => 'noun phrase', 'example' => 'Try to eat less processed food.'],
                                    ['phrase' => 'junk food', 'type' => 'reused'],
                                    ['phrase' => 'vending machine', 'type' => 'new', 'definition' => 'a machine that sells snacks or drinks when you put money in', 'pos' => 'noun phrase', 'example' => 'I bought a bottle of water from the vending machine.'],
                                    ['phrase' => 'sluggish', 'type' => 'new', 'definition' => 'slow-moving and lacking energy', 'pos' => 'adjective', 'example' => 'I always feel sluggish after eating too much.'],
                                ],
                                'topic_summary' => 'A short profile of Marco, a nurse in Rome, whose eating habits '
                                    .'change with his shift: he never skips breakfast, brings fresh fruit and nuts '
                                    .'to work despite his sweet tooth, cooks simple dinners but sometimes orders '
                                    .'takeaway on busy nights, and is slowly trying to cut down on processed and '
                                    .'junk food rather than banning them completely.',
                                'comprehension_check' => [
                                    ['statement' => 'Marco works as a nurse.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Marco never eats junk food.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Marco tries not to eat a heavy meal before a night shift.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'What does Marco usually eat for breakfast, and why?',
                                    "What is Marco's one rule about eating before a night shift?",
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Putting it on paper often reveals what you actually think about your own eating habits.',
                                'title' => 'My eating habits',
                                // Real "include" list from M04.pdf page 07.
                                'prompts' => [
                                    ['label' => 'What you normally eat', 'image_query' => 'healthy meal plate'],
                                    ['label' => 'Your favourite food', 'image_query' => 'favourite comfort food'],
                                    ['label' => 'What you eat at home', 'image_query' => 'home cooked dinner table'],
                                    ['label' => 'What you eat outside', 'image_query' => 'restaurant meal eating out'],
                                    ['label' => 'Healthy / unhealthy habits', 'image_query' => 'junk food and healthy food side by side'],
                                    ['label' => 'One thing you want to change', 'image_query' => 'fresh salad vegetables'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note above.
                                'try_to_use' => ['usually', 'normally', 'often', 'some', 'a lot of', 'a little', 'not many', 'not much'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'What did you eat yesterday? Was it healthy?', 'answer' => 'I had some eggs for breakfast and a lot of rice for lunch, but I didn\'t eat any vegetables.', 'words' => ['some', 'a lot of', 'any']],
                            ['question' => 'Describe a dish you can cook. What do you need?', 'answer' => 'I can make an omelette. The ingredients are simple: a few eggs, a little butter and some cheese.', 'words' => ['ingredients', 'a few', 'a little']],
                            ['question' => 'What is one change you want to make to your diet?', 'answer' => 'I want to cut down on sugar and eat more home-cooked meals.', 'words' => ['cut down on', 'home-cooked']],
                            ['question' => 'What food from your country should a tourist try?', 'answer' => 'They should try kebab. It isn\'t spicy, and it comes with a lot of fresh bread.', 'words' => ['spicy', 'fresh', 'a lot of']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                // Moved here from Day 2 (2026-10) — see M02's Day 4 note on why
                                // partner_speaking_session was cut mission-wide and
                                // picture_description fills its slot instead.
                                'duration_minutes' => 10,
                                'hook' => "Forget your own plate for a minute — what's happening at this one?",
                                'image_query' => 'family sharing dinner table home',
                                'guiding_questions' => [
                                    'What are the people doing together?',
                                    'What is the older man on the left wearing, and what is he doing?',
                                    'What different dishes of food can you see on the table?',
                                    'What does the room in the background look like?',
                                ],
                                // Hand-verified against the real cached Pexels photo for this
                                // exact query+orientation (landscape) — a large family seated
                                // around a table full of dishes, the older man in glasses on
                                // the left, food spread across the middle/bottom, a decorated
                                // living room (wall clock, candelabra, sofa) in the background.
                                // Changing the query or orientation invalidates these coordinates.
                                'hotspots' => [
                                    ['x' => 50, 'y' => 50, 'question_index' => 0],
                                    ['x' => 30, 'y' => 45, 'question_index' => 1],
                                    ['x' => 30, 'y' => 85, 'question_index' => 2],
                                    ['x' => 18, 'y' => 18, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Describe what you usually eat in a normal day, with no preparation.',
                                    'Talk about your favourite food and why you like it.',
                                    'Explain one eating habit you would like to change, and why.',
                                    'What food did you eat a lot of as a child?',
                                    'Do you prefer cooking at home or eating out? Why?',
                                    'What is a food from another country that you really enjoy?',
                                ],
                                'role_reversal_topic' => 'food and eating habits',
                                // Real prompt + requirements checklist from M04.pdf page 08
                                // "3-Minute Speaking".
                                'final_prompt' => 'Speak for 3 minutes without stopping about your food and lifestyle.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'Countable nouns',
                                    'Uncountable nouns',
                                    'Examples',
                                    'One opinion',
                                    'One reason',
                                    'Talks about one personal goal',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->seedM03();
    }

    /**
     * Seeds M03 with its real content from document/M03/ (M03.pdf, the
     * Oxford Online English "Talking About Your Job" lesson, and the Easy English
     * "Jobs and Work" street interviews). Matches M02's full
     * 4-phase/~18-step template exactly, per the standing rule that every
     * mission does — NOT M03.pdf's own compact 3-day plan, which only
     * outlines 10 of these (the rest — daily_listen_2/3, ai_conversation_1,
     * picture_description, reading_comprehension, error_log — are
     * extrapolated in the same style/quality, grounded in this mission's
     * real Work & Study topic, same as M04 was corrected to do).
     */
    private function seedM03(): void
    {
        $audioUrl = $this->publishMissionAsset('M03', 'Oxford Online English - Talking About Your Job.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M03', 'Easy English - Jobs and Work.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M03', 'Easy English - Jobs and Work.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M03'],
            [
                'title' => 'Work & Study',
                'module' => 'Work',
                'outcome' => 'I can talk about my work or studies, describe my responsibilities, and explain what I am working on.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Would you like to run your own business? Why?', 'answer' => 'Yes, I\'d love to run my own business, but you have to work very long hours.', 'words' => ['run my own business', 'have to']],
                            ['question' => 'What is good and bad about being self-employed?', 'answer' => 'When you\'re self-employed you can choose your hours, but you don\'t get paid holidays.', 'words' => ['self-employed', 'can']],
                            ['question' => 'Who do you look after in your family?', 'answer' => 'I look after my little brother after school, so I can\'t go out until six.', 'words' => ['look after', 'can\'t']],
                            ['question' => 'What is challenging about your job or your studies?', 'answer' => 'Maths is challenging for me. I have to study every night.', 'words' => ['challenging', 'have to']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Someone asks what you do for work or study — could you actually explain it in English, with real details, not just the job title?',
                                'image_query' => 'people working office study',
                                'ambient_video_query' => 'office desk typing slow motion',
                                // Real questions from M03.pdf page 01 "Before you start".
                                'warm_up_questions' => [
                                    'What do you do?',
                                    'What does a normal day at work/study look like?',
                                    'What do you like about what you do?',
                                    'What is difficult about it?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases that turn "I\'m a nurse" into a real answer about your work.',
                                // Day 1 = the Listening lesson's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'My aunt is **self-employed** — she **runs her own business**, a small '
                                    .'bakery. It\'s **challenging** work, but she loves it. Last year my cousin was '
                                    .'**between jobs**, so he helped her and **looked after** the shop on Saturdays.',
                                'words' => [
                                    ['phrase' => 'run your own business', 'meaning' => 'to own a company and be the person in charge of it', 'meaning_fa' => 'کسب‌وکار خود را داشتن و اداره کردن', 'pos' => 'verb phrase', 'example' => 'She runs her own business from home.', 'difficulty' => 'medium'],
                                    ['phrase' => 'self-employed', 'meaning' => 'working for yourself, not for a company or a boss', 'meaning_fa' => 'خویش‌فرما، دارای کار آزاد', 'pos' => 'adjective', 'synonym' => 'freelance', 'example' => "My dad is self-employed — he's a plumber.", 'difficulty' => 'medium'],
                                    ['phrase' => 'between jobs', 'meaning' => 'not working right now, after leaving one job and before finding the next', 'meaning_fa' => 'بین دو کار، (فعلاً) بی‌کار', 'pos' => 'phrase', 'synonym' => 'a polite way to say: unemployed', 'example' => "I'm between jobs at the moment, so I have more free time.", 'difficulty' => 'hard'],
                                    ['phrase' => 'look after', 'meaning' => 'to take care of someone or something', 'meaning_fa' => 'مراقبت کردن از، رسیدگی کردن به', 'pos' => 'phrasal verb', 'synonym' => 'take care of', 'example' => 'Nurses have to look after a lot of patients.', 'image_query' => 'nurse caring for patient hospital', 'difficulty' => 'easy'],
                                    ['phrase' => 'challenging', 'meaning' => 'difficult, but in an interesting way that makes you think and learn', 'meaning_fa' => 'چالش‌برانگیز (سخت ولی جذاب)', 'pos' => 'adjective', 'synonym' => 'opposite: easy, boring', 'example' => 'Teaching is challenging, but I love it.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Mike shows how people really describe their work — listen for what comes after "I have to".',
                                'source' => 'Oxford Online English — "Talking About Your Job in English"',
                                'image_query' => 'pharmacist working pharmacy',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M03', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. One speaker here.
                                'listening_turns' => $this->shadowTimestampsFor('M03', 'listening_turns'),
                                // The lesson's own human captions for the five parts kept in the cut
                                // (0:02-1:54, 3:08-4:09, 4:23-5:49, 8:14-9:53, 10:13-10:24 of the
                                // original; parts 2 and 4 dropped), checked against a Whisper pass of
                                // the cut audio. On-screen blanks are written as "…".
                                'transcript' => [
                                    ['speaker' => 'Mike', 'text' => "Hi, I'm Mike and welcome to Oxford Online English! In this lesson, you can learn how to talk about your job in English. Where do you work? What do you have to do in your job? What kind of company do you work for? In this class, you can learn how to answer questions like this in clear, detailed English. Ready? Let's start!"],
                                    ['speaker' => 'Mike', 'text' => 'Part one: introducing your job. "I\'m a…", "I work in…", "I work for…". How would you complete these sentences? You could say something like: "I\'m a writer. I work in online education. I work for a publishing company." \'I work in\' is used with a field, meaning a type of work. So, you can say things like: "I work in finance." "I work in digital marketing." You can also use \'work in\' with a place or a department of a company. For example: "I work in the HR department of a financial firm."'],
                                    ['speaker' => 'Mike', 'text' => '"I work in a school, teaching modern languages." Confused? You can see that if you use \'work in\' to talk about the type of work you do, you don\'t use an article like \'a\' or \'the\'. If you use an article \'a\' or \'the\' after \'work in\', it means you\'re talking about the place or department where you work. \'I work for\' is used with a company. So, you could say: "I\'m a salesman. I work for a mobile phone company." "I work for a law firm based in Frankfurt."'],
                                    ['speaker' => 'Mike', 'text' => 'Or even: "I run my own business, so I work for myself." What if you don\'t work for a company? Here are some things you could say: "I\'m a freelancer." Meaning: I work independently, for different people and companies. "I\'m self-employed." Meaning: I work for myself, either freelance or I have my own business. "I\'m a business owner." If you don\'t work, and people ask you what you do, what can you say? Here are some useful phrases: "I\'m between jobs at the moment." Meaning: I\'ve left one job, and haven\'t found another yet.'],
                                    ['speaker' => 'Mike', 'text' => 'This sounds nicer than saying, "I\'m unemployed." "I\'m taking some time out to travel, spend time with my kids, write a book, recover from an illness, etc." Meaning: I\'m not working at the moment because I want to focus on something else. "I\'m retired."'],
                                    ['speaker' => 'Mike', 'text' => 'Part three: how to describe your job in more detail. So what do you actually do all day? Let\'s see how you can describe your job in more detail. Look at these sentences: "I have to…", "I\'m responsible for…", "Most of my time is spent…". Let\'s do some examples together. We\'ll start with a simple example. "I\'m a nurse. I have to look after patients, give them medicine and make sure they\'re comfortable. I\'m responsible for about 20 to 30 patients.'],
                                    ['speaker' => 'Mike', 'text' => 'Most of my time is spent talking to patients and checking that everything is okay." Next, let\'s do a more detailed example. "I have to design websites to the client\'s specifications. I\'m responsible for the whole design process, so I have to take the client\'s ideas and turn them into a finished product. Most of my time is spent experimenting with different designs and ideas and seeing what looks good, because attention to detail is important in this kind of work." Can you say something like this about your job and what you do?'],
                                    ['speaker' => 'Mike', 'text' => 'Try to add details if you can.'],
                                    ['speaker' => 'Mike', 'text' => 'Part five: how to make a longer answer. At this point, you should be able to introduce your work, say where you work, give details about what you do and say how you feel about your work. Let\'s make some longer sample answers together. First one: "I\'m a pharmacist. I started my own small pharmacy, so I\'m also a business owner. I have to work as a pharmacist, of course, giving advice to patients and making sure they have the right medicine.'],
                                    ['speaker' => 'Mike', 'text' => 'However, I\'m also responsible for the pharmacy, so I have to manage my staff, do the accounts, and so on. It\'s stimulating work because I have to do many different things every day, so I never get bored." Clear? Could you make an answer like this? Let\'s do one more: "I\'m a writer. I work in online education. I work for a big publishing company, which produces different educational materials that are used all around the world. I have to write lesson plans and materials for teachers to use in the classroom.'],
                                    ['speaker' => 'Mike', 'text' => 'I spend most of my time thinking about how I can make different things fit together into a lesson. It\'s very challenging work and it can be exhausting, but it\'s also very creative and satisfying, because I know people all around the world are using lessons which I wrote." That\'s the end of the lesson. I hope you learned some new ways to talk about your job and what you do. Thanks very much for watching. See you next time!'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript (run my own business as Mike says it).
                                'target_phrases' => [
                                    [
                                        'phrase' => 'run my own business', 'meaning' => 'own and manage a company yourself',
                                        'gap_before' => 'Or even: "I ',
                                        'gap_after' => ', so I work for myself."',
                                    ],
                                    [
                                        'phrase' => 'self-employed', 'meaning' => 'working for yourself, not for a company',
                                        'gap_before' => '"I\'m ',
                                        'gap_after' => '." Meaning: I work for myself, either freelance or I have my own business.',
                                    ],
                                    [
                                        'phrase' => 'between jobs', 'meaning' => 'not working now, between one job and the next',
                                        'gap_before' => 'Here are some useful phrases: "I\'m ',
                                        'gap_after' => ' at the moment."',
                                    ],
                                    [
                                        'phrase' => 'look after', 'meaning' => 'take care of',
                                        'gap_before' => '"I\'m a nurse. I have to ',
                                        'gap_after' => " patients, give them medicine and make sure they're comfortable.",
                                    ],
                                    [
                                        'phrase' => 'challenging', 'meaning' => 'difficult in an interesting way',
                                        'gap_before' => "It's very ",
                                        'gap_after' => ' work and it can be exhausting, but it\'s also very creative and satisfying, because I know people all around the world are using lessons which I wrote."',
                                    ],
                                ],
                                'topic_summary' => 'Mike, an English teacher, shows how to talk about your job: "I work in" '
                                    .'(a field or a department) versus "I work for" (a company); what to say if you are a '
                                    .'freelancer, self-employed, between jobs or retired; how to describe your duties with '
                                    .'"I have to…", "I\'m responsible for…" and "Most of my time is spent…" (a nurse and a '
                                    .'web designer); and two longer model answers from a pharmacist and a writer.',
                                'comprehension_check' => [
                                    ['statement' => 'The lesson is about how to talk about your job.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Mike says "I\'m unemployed" sounds nicer than "I\'m between jobs".', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'The pharmacist never gets bored because there are many different things to do every day.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'In the nurse example, how many patients is the nurse responsible for?',
                                    'options' => ['About 5', 'About 20 to 30', 'About 100'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Do you work or study full-time or part-time?', 'answer' => 'I work part-time in a café, so I have to study in the evenings.', 'words' => ['part-time', 'have to']],
                            ['question' => 'What rules are there in your workplace or school?', 'answer' => 'In my workplace we must wear a uniform, and we can\'t use our phones at the desk.', 'words' => ['workplace', 'must', 'can\'t']],
                            ['question' => 'Tell me about a colleague or classmate you like.', 'answer' => 'My colleague Neda is great. She can speak three languages, and she always helps me.', 'words' => ['colleague', 'can']],
                            ['question' => 'What career would you like in five years?', 'answer' => 'I\'d like a career in design. For that I need to be able to use new software.', 'words' => ['career', 'be able to']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'The words for talking about your career and where you work.',
                                'story' => "I've had a long **career** in this field. I've always worked "
                                    .'**full-time** — I don\'t think I\'d enjoy **part-time** work. My '
                                    .'**workplace** is small, so I know all my **colleagues** well.',
                                'words' => [
                                    ['phrase' => 'career', 'meaning' => 'the jobs someone has over a long period, usually in one field', 'meaning_fa' => 'مسیر شغلی، حرفه', 'pos' => 'noun', 'example' => 'He has had a long career in teaching.', 'difficulty' => 'medium'],
                                    ['phrase' => 'full-time', 'meaning' => 'working the standard number of hours in a week', 'meaning_fa' => 'تمام‌وقت', 'pos' => 'adjective', 'synonym' => 'opposite: part-time', 'example' => 'She works full-time at a hospital.', 'difficulty' => 'easy'],
                                    ['phrase' => 'part-time', 'meaning' => 'working fewer than the standard number of hours', 'meaning_fa' => 'پاره‌وقت', 'pos' => 'adjective', 'synonym' => 'opposite: full-time', 'example' => 'I have a part-time job at weekends.', 'difficulty' => 'easy'],
                                    ['phrase' => 'workplace', 'meaning' => 'the place where someone works', 'meaning_fa' => 'محل کار', 'pos' => 'noun', 'example' => 'Our workplace is very friendly.', 'image_query' => 'colleagues working in open office', 'difficulty' => 'easy'],
                                    ['phrase' => 'colleague', 'meaning' => 'a person you work with', 'meaning_fa' => 'همکار', 'pos' => 'noun', 'synonym' => 'co-worker', 'example' => 'I had lunch with a colleague today.', 'image_query' => 'colleagues working together office', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 9,
                                'hook' => 'Same lesson, one more time — copy how Mike says "have to": it sounds like "hafta".',
                                'image_query' => 'people working office study',
                                'shadow_lines' => [
                                    "I **have to** **look after** patients, give them **medicine** and make sure they're **comfortable**.",
                                    'I **run** my **own** **business**, so I **work** for **myself**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M03', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 14,
                                // Grammar point changed 2026-09-10 from "Present Simple vs
                                // Present Continuous" (a straight repeat of M02's own focus,
                                // with no real content difference beyond the title) to Modals
                                // of Obligation & Ability — genuine variety across the roadmap,
                                // and a very natural fit for Work & Study (describing job
                                // duties/rules and what you can/can't do). See
                                // feedback_content_authoring_conventions rule 7 (memory).
                                'hook' => "Every \"I have to...\" you get right here is one less pause when you're talking about your own work.",
                                'focus' => 'Modals of Obligation & Ability (Work & Study)',
                                'lesson' => [
                                    'intro' => 'Three small things: required or optional, the plain verb after a modal, and can / can\'t.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · Required, or not required?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>have to / need to / must</strong> = required.<br><strong>don\'t have to</strong> = not required (it\'s your choice).',
                                                    'fa' => 'have to / need to / must یعنی اجبار. don\'t have to یعنی اجباری نیست؛ انتخاب با خودت است.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Required', 'items' => ['I have to finish this report by Friday.', 'You need to attend the training.', 'We must follow the safety rules.']],
                                                        ['label' => 'Not required', 'items' => ['I don\'t have to work on Saturdays.', 'You don\'t need to bring your own laptop.']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Tomás',
                                                    'wrong' => 'I have to finishing this report today.',
                                                    'right' => 'I have to finish this report today.',
                                                    'explanation' => 'After a modal or “have to”, the next verb stays plain — never the -ing form.',
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · After a modal: the plain verb',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'After <strong>can</strong>, <strong>must</strong> or <strong>have to</strong>, use the plain verb — no -s, no -ing.',
                                                    'fa' => 'بعد از can، must و have to، فعل ساده می‌آید: بدون «s» و بدون «ing».',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'The pattern',
                                                    'parts' => [
                                                        ['text' => 'I', 'caption' => 'who'],
                                                        ['text' => 'have to', 'caption' => 'modal'],
                                                        ['text' => 'finish', 'caption' => 'plain verb'],
                                                        ['text' => 'the report', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'She can to speak three languages.', 'right' => 'She can speak three languages.', 'highlight' => 'speak', 'note' => 'After can: no “to”.'],
                                                        ['wrong' => 'Do you have to worked on weekends?', 'right' => 'Do you have to work on weekends?', 'highlight' => 'work', 'note' => 'After do … have to: the plain verb.'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Questions', 'items' => ['Do you have to work weekends?', 'Can you help me with this?']],
                                                        ['label' => 'Negatives', 'items' => ['I don\'t have to attend every meeting.', 'I can\'t finish this today.']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · can / can\'t, then make it personal',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>can</strong> = able or allowed.<br><strong>can\'t</strong> = not able or not allowed.',
                                                    'fa' => 'can یعنی توانایی یا اجازه. can\'t یعنی ناتوانی یا ممنوعیت.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Ability & permission', 'items' => ['I can speak two languages.', 'You can leave early today.', 'I can\'t work overtime this week.']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Your starters — finish them about your own work or studies', 'words' => ['I have to', 'I need to', 'I must', 'I don\'t have to', 'I can', 'I can\'t']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this soon — in AI Conversation #1, talking about your own work or studies.',
                                ],
                                'frequency_starters' => [
                                    'I have to', 'I need to', "I don't have to",
                                    'I can', "I can't", 'I must',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about their work or studies, correctly '
                                    .'using the modal (obligation or ability/permission) that the starter itself '
                                    .'calls for, with the following verb in its bare form.',
                                'grammar_major_criteria' => 'the modal is used incorrectly (wrong verb form after '
                                    .'it, e.g. "to work" or "working" instead of "work"), the sentence does not '
                                    .'actually continue the given starter, or it is not a genuine personal '
                                    .'statement',
                                'grammar_context' => 'continues appropriately using a modal of obligation (have to/'
                                    .'need to/must) or ability/permission (can/can\'t), matching the starter given',
                                'quick_check' => [
                                    [
                                        'wrong' => 'I have to finishing this report today.',
                                        'options' => ['I have to finishing this report today.', 'I has to finish this report today.', 'I have to finish this report today.'],
                                        'correct' => 2,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'She can to speak three languages.',
                                        'options' => ['She can to speak three languages.', 'She cans speak three languages.', 'She can speak three languages.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'Do you have to worked on weekends?',
                                        'options' => ['Do you have to worked on weekends?', 'Do you have to work on weekends?', 'Have you to work on weekends?'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['this', 'have', 'I', 'report', 'to', 'today.', 'finish'], 'answer' => 'I have to finish this report today.'],
                                    ['words' => ['work', 'have', 'Do', 'you', 'weekends?', 'to'], 'answer' => 'Do you have to work weekends?'],
                                    ['words' => ['two', 'speak', 'I', 'languages.', 'can'], 'answer' => 'I can speak two languages.'],
                                ],
                            ],
                            [
                                // Real people, unscripted: Easy English's street interviews in
                                // Brighton about work (about 6 minutes cut from the original
                                // 11-minute video — the host intro and the channel's membership
                                // ad removed, the burned-in captions blurred out so the step's
                                // own caption toggle works).
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Real people, real jobs — hear how they talk about their work, then make your own voice do the same.',
                                'source' => 'Easy English — "English Conversations about JOBS and WORK"',
                                'video_url' => $shadowingVideoUrl,
                                // Cleaned from the video's own burned-in captions (YouTube's
                                // auto-captions for it were unreliable): punctuated and
                                // re-timed per sentence.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M03', 'video_shadowing_segments'),
                                'topic_summary' => 'On the streets of Brighton, an interviewer asks people what they '
                                    .'do for work: a music teacher, a postie, an airport coordinator, a '
                                    .'caterer, a healthcare assistant and a tattoo artist. Then they explain '
                                    .'how they got into their jobs — a childhood dream, a career change, a '
                                    .'part-time job that became permanent — and what they like and dislike '
                                    .'about them.',
                                'comprehension_check' => [
                                    ['statement' => 'One person says she works part-time, four days a week.', 'correct' => true],
                                    ['statement' => 'The tattoo artist says he only started drawing as an adult.', 'correct' => false],
                                    ['statement' => 'The caterer says it is a good job while she is a student, but not forever.', 'correct' => true],
                                ],
                                // Real phrases the speakers use to talk about their work —
                                // reused by Day 3's "my work / study life" task.
                                'target_phrases' => [
                                    ['phrase' => 'used to (do something)', 'meaning' => 'did something in the past that you do not do now'],
                                    ['phrase' => 'work part-time', 'meaning' => 'to work only some days or hours each week'],
                                    ['phrase' => 'fall into (a line of work)', 'meaning' => 'to start a job without planning it'],
                                    ['phrase' => 'career change', 'meaning' => 'moving to a completely different type of job'],
                                    ['phrase' => 'long hours', 'meaning' => 'working for many hours in a day'],
                                ],
                                // Real verbatim lines from the video's own captions — shadowing
                                // means mimicking the exact audio just heard, and the auto-pause
                                // timestamp cache (missions:cache-shadow-timestamps) only lands
                                // correctly on words the speaker actually says. Bold marks
                                // naturally-stressed content words (rendered by
                                // <x-stress-marked-line>).
                                'shadow_lines' => [
                                    "**Currently**, I'm a **music** **teacher** in **Barcelona**.",
                                    'I **used** to be a **teacher**, but I **retired** in **July**.',
                                    'So, I **work** **part-time**, **doing** that **four** **days** a **week**.',
                                    "I've **always** **wanted** to be a **tattoo** **artist**, really, since I was a **kid**.",
                                    'I **really** **like** to **work** with **people**, but also **help** **people**.',
                                    'I work like, quite **long** **hours** and stuff.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M03', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'What are your responsibilities at work or at home?', 'answer' => 'One responsibility I have is the shopping. I\'m also in charge of paying the bills.', 'words' => ['responsibility', 'in charge of']],
                            ['question' => 'What are your working hours?', 'answer' => 'My working hours are nine to five, but sometimes I have to stay late.', 'words' => ['working hours', 'have to']],
                            ['question' => 'Do you ever do overtime?', 'answer' => 'Yes, I do overtime at the end of the month. I don\'t have to, but the money is good.', 'words' => ['overtime', 'don\'t have to']],
                            ['question' => 'Is salary important to you in a job?', 'answer' => 'Salary is important, but I must enjoy my work too.', 'words' => ['salary', 'must']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => "Last set — the words for what you're responsible for, and how you're paid for it.",
                                'story' => 'One of my main **responsibilities** is training new staff, and I\'m '
                                    .'also **in charge of** the weekly schedule. My **working hours** are pretty '
                                    .'standard, though I sometimes do **overtime**. The **salary** isn\'t amazing, '
                                    .'but it\'s fair.',
                                'words' => [
                                    ['phrase' => 'responsibility', 'meaning' => 'a duty or task that is part of your job', 'meaning_fa' => 'مسئولیت', 'pos' => 'noun', 'example' => 'Training new staff is my responsibility.', 'difficulty' => 'medium'],
                                    ['phrase' => 'in charge of', 'meaning' => 'responsible for managing something', 'meaning_fa' => 'مسئول (چیزی)', 'pos' => 'adjective phrase', 'synonym' => 'responsible for', 'example' => "She's in charge of the whole department.", 'difficulty' => 'hard'],
                                    ['phrase' => 'working hours', 'meaning' => 'the times of day someone is at work', 'meaning_fa' => 'ساعت کاری', 'pos' => 'noun phrase', 'example' => 'My working hours are nine to five.', 'difficulty' => 'medium'],
                                    ['phrase' => 'overtime', 'meaning' => 'extra hours worked beyond the usual schedule', 'meaning_fa' => 'اضافه‌کاری', 'pos' => 'noun', 'example' => 'I did two hours of overtime yesterday.', 'difficulty' => 'medium'],
                                    ['phrase' => 'salary', 'meaning' => 'the fixed amount of money someone is paid for their job', 'meaning_fa' => 'حقوق (ماهانه)', 'pos' => 'noun', 'example' => 'The salary for this job is quite good.', 'image_query' => 'salary paycheck money', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 9,
                                'hook' => 'Same audio, one more time — notice how much easier it sounds now.',
                                'image_query' => 'colleagues office coffee break',
                                'shadow_lines' => [
                                    "I'm **also** **responsible** for the pharmacy, so I **have to** **manage** my **staff**.",
                                    "It's **very** **challenging** work and it **can** be **exhausting**.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M03', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                // Epic E merge — see M01's own comment for the arithmetic.
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                // Real questions from M03.pdf page 05 "Work / Study".
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your work or studies — '
                                    .'what you normally do, what you\'re working on these days, and what you '
                                    .'enjoy — using the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'What do you do for work or study?',
                                    'What does a typical day look like for you?',
                                    'What is the most challenging part of your work/study?',
                                    'How do you usually get to work or school?',
                                    'Do you prefer working alone or in a team?',
                                    'What are you working on at the moment?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Sara — her days depend on her timetable. Can you follow her routine in English?',
                                'passage_title' => 'Meet Sara',
                                'image_query' => 'young woman student portrait smiling',
                                // Written fresh for this app (see EOS-009 §14) — reuses several
                                // Vocabulary Builder pool words/phrases (responsibility,
                                // part-time, working hours, in charge of) so
                                // whichever words a learner picked there, they meet again here
                                // in a fresh context, third person instead of first.
                                'passage' => 'Sara is a second-year student at a university in Leeds. Her days '
                                    .'depend a lot on her timetable — some days she has lectures from nine until '
                                    .'four, while on others she spends most of the day in the library instead. '
                                    .'She\'s currently working with her project team on a group assignment, which '
                                    .'means she often meets her classmates online in the evening to divide up the '
                                    .'responsibilities. Sara says the most difficult part of her course is having '
                                    .'to deal with several assignments due in the same week, but what she enjoys '
                                    .'most is finally understanding something she found confusing only a few days '
                                    .'earlier. Outside class, she has a part-time job at a cafe, where her working '
                                    .'hours are usually two evenings a week. She\'s in charge of closing the till '
                                    .'at the end of her shift, which she says has taught her more about '
                                    .'responsibility than any lecture has.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'responsibilities', 'type' => 'reused'],
                                    ['phrase' => 'deal with', 'type' => 'new', 'definition' => 'to handle a task or a problem', 'pos' => 'phrasal verb', 'example' => 'I have to deal with a lot of emails every morning.'],
                                    ['phrase' => 'part-time', 'type' => 'reused'],
                                    ['phrase' => 'working hours', 'type' => 'reused'],
                                    ['phrase' => 'in charge of', 'type' => 'reused'],
                                    ['phrase' => 'responsibility', 'type' => 'reused'],
                                    ['phrase' => 'timetable', 'type' => 'new', 'definition' => 'a plan showing when classes or events happen', 'pos' => 'noun', 'example' => 'My timetable is different every day this week.'],
                                    ['phrase' => 'assignment', 'type' => 'new', 'definition' => 'a piece of work given as part of a course', 'pos' => 'noun', 'example' => 'I have to finish my assignment by Friday.'],
                                ],
                                'topic_summary' => 'A short profile of Sara, a university student in Leeds, whose '
                                    .'days depend on her timetable — some full of lectures, others spent mostly in '
                                    .'the library. She\'s working with her project team on a group assignment, '
                                    .'finds overlapping deadlines the hardest part but loves finally understanding '
                                    .'something confusing, and also has a part-time cafe job where she\'s in '
                                    .'charge of closing the till.',
                                'comprehension_check' => [
                                    ['statement' => 'Sara is a university student.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => "Sara's timetable is exactly the same every day.", 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Sara has a part-time job at a cafe.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'What does Sara find most difficult about her course, and what does she enjoy most?',
                                    'What is Sara in charge of at her part-time job?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Putting it on paper often reveals what you actually think about your own work or studies.',
                                'title' => 'My work / study life',
                                // Real "include" list from M03.pdf page 07.
                                'prompts' => [
                                    ['label' => 'What you do', 'image_query' => 'person working laptop desk'],
                                    ['label' => 'Your normal day', 'image_query' => 'daily routine schedule'],
                                    ['label' => 'Your responsibilities', 'image_query' => 'checklist tasks notebook'],
                                    ['label' => 'What you enjoy', 'image_query' => 'happy at work colleagues'],
                                    ['label' => 'What is difficult', 'image_query' => 'stressed deadline work'],
                                    ['label' => 'What you are working on these days', 'image_query' => 'project planning whiteboard'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note above.
                                'try_to_use' => ['have to', 'need to', "don't have to", 'can', "can't", 'must'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe your job or studies: what do you have to do every day?', 'answer' => 'I\'m a student. I have to go to classes in the morning, and I must finish my homework every night.', 'words' => ['have to', 'must']],
                            ['question' => 'What can you do now that you couldn\'t do five years ago?', 'answer' => 'Now I can drive, but five years ago I couldn\'t. I can also speak more English.', 'words' => ['can', 'couldn\'t']],
                            ['question' => 'Would you like a full-time job or to be self-employed? Why?', 'answer' => 'I\'d like to be self-employed, because then I don\'t have to ask anyone for a day off.', 'words' => ['self-employed', 'don\'t have to']],
                            ['question' => 'What do you need to do to get your dream job?', 'answer' => 'I need to improve my English, and I have to be able to work in a team.', 'words' => ['have to', 'be able to']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                // Moved here from Day 2 (2026-10) — see M02's Day 4 note on why
                                // partner_speaking_session was cut mission-wide and
                                // picture_description fills its slot instead.
                                'duration_minutes' => 10,
                                'hook' => "Forget your own desk for a minute — what's happening at this one?",
                                'image_query' => 'team meeting office discussion',
                                'guiding_questions' => [
                                    'What are the people doing together?',
                                    'What is the man in the center wearing, and what is he doing with his hands?',
                                    'What can you see on the table?',
                                    'What does the room in the background look like?',
                                ],
                                // Hand-verified against the real cached Pexels photo for this
                                // exact query+orientation (landscape) — four colleagues around
                                // a wooden table in a bright loft-style office/kitchen space,
                                // one man in a patterned beanie gesturing with his hands in the
                                // center, papers and folders spread across the table, kitchen
                                // cabinets visible in the upper-right background. Changing the
                                // query or orientation invalidates these coordinates.
                                'hotspots' => [
                                    ['x' => 50, 'y' => 55, 'question_index' => 0],
                                    ['x' => 48, 'y' => 35, 'question_index' => 1],
                                    ['x' => 42, 'y' => 78, 'question_index' => 2],
                                    ['x' => 80, 'y' => 15, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Describe what you do for work or study, with no preparation.',
                                    'Talk about something you are currently working on.',
                                    'Explain one thing you find difficult, and one thing you enjoy.',
                                    'What does a normal week look like for you?',
                                    'What skill would you like to improve at work or school?',
                                    'How do you usually relax after a busy day of work or study?',
                                ],
                                'role_reversal_topic' => 'work and study',
                                // Real prompt + requirements checklist from M03.pdf page 08
                                // "3-Minute Speaking".
                                'final_prompt' => 'Speak for 3 minutes without stopping about your work or studies.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'Modals of obligation (have to/need to/must)',
                                    'Modals of ability (can/can\'t)',
                                    'Talk about something current',
                                    'Give examples',
                                    'Explain one difficulty',
                                    'Explain one thing you enjoy',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->seedM05();
        $this->seedM06();
        $this->seedM07();
        $this->seedM08();
        $this->seedM09();
        $this->seedM10();
        $this->seedM11();
    }

    /**
     * Seeds M05 "Hobbies & Free Time" — the first mission authored without
     * a source PDF (see EOS-009 §6/§7): the 4-day, 16-step layout of
     * M02-M04 (no story_sequence — this lesson has no picture narration),
     * the grammar point Mission::roadmapCatalog() assigns it (gerunds vs
     * infinitives), and media the user picked: Oxford Online English's
     * "How to Talk About Your Free Time and Hobbies" (cut from 9 to about
     * 6 minutes: the like/love/enjoy + -ing part, how to say when and why
     * you started, and one model answer) and Pensando Inglés' "My Favorite
     * Hobbies" vlog (about 6 minutes cut from 23: guitar, learning
     * languages, reading and martial arts, sponsor segment removed, the
     * creator's burned-in captions blurred out). Vocabulary days 2-3 follow
     * Vocabulary in Use units 41B (Leisure activities) and 68 (Likes,
     * dislikes, attitudes and preferences), rewritten in our own sentences.
     */
    private function seedM05(): void
    {
        $audioUrl = $this->publishMissionAsset('M05', 'Oxford Online English - Free Time and Hobbies.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M05', 'Pensando Ingles - My Favorite Hobbies.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M05', 'Pensando Ingles - My Favorite Hobbies.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M05'],
            [
                'title' => 'Hobbies & Free Time',
                'module' => 'Free Time',
                'outcome' => 'I can talk about my hobbies and free time — what I enjoy doing, how often, how I got into it, and what I want to try next.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Where do you like to hang out with your friends?', 'answer' => 'We like hanging out at a café near the park. We go there once or twice a week.', 'words' => ['hanging out', 'once or twice']],
                            ['question' => 'Who got you into your favourite hobby?', 'answer' => 'My uncle got me into chess. He enjoys playing it every Friday.', 'words' => ['got me into', 'enjoys playing']],
                            ['question' => 'Who can you beat at a game?', 'answer' => 'I can beat my brother at football, but never at chess. I hate losing!', 'words' => ['beat', 'losing']],
                            ['question' => 'What would you like to try if you get the chance?', 'answer' => 'If I get the chance, I\'d love to try skiing.', 'words' => ['get the chance', 'to try']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Someone asks "So what do you do for fun?" — could you answer with more than "nothing special"?',
                                'image_query' => 'hobby painting guitar leisure',
                                'ambient_video_query' => 'person playing acoustic guitar relaxing at home',
                                'warm_up_questions' => [
                                    'What do you like doing in your free time?',
                                    'How often do you do it?',
                                    'How did you get into your favourite hobby?',
                                    'Is there a hobby you would like to try one day?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five small phrases that turn "I like football" into a real answer about your free time.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'On Saturdays I usually **hang out** with my friends at the park. I play '
                                    .'tennis **once or twice** a week — my older brother **got me into** it last '
                                    .'summer, and now I always try to **beat** him. I would love to try painting '
                                    .'too, but I never **get the chance**.',
                                'words' => [
                                    ['phrase' => 'hang out', 'meaning' => 'to spend time relaxing somewhere or with someone', 'meaning_fa' => 'وقت گذراندن (با دوستان)، دور هم بودن', 'pos' => 'phrasal verb', 'example' => 'We usually hang out at a café near the university.', 'image_query' => 'friends hanging out cafe', 'difficulty' => 'easy'],
                                    ['phrase' => 'once or twice', 'meaning' => 'one or two times; not very often', 'meaning_fa' => 'یکی دو بار', 'pos' => 'phrase', 'example' => 'I go swimming once or twice a month.', 'difficulty' => 'easy'],
                                    ['phrase' => 'get (someone) into (something)', 'meaning' => 'to make someone start liking an activity', 'meaning_fa' => 'کسی را به چیزی علاقه‌مند کردن', 'pos' => 'phrasal verb', 'example' => 'My uncle got me into chess when I was eight.', 'difficulty' => 'hard'],
                                    ['phrase' => 'beat', 'meaning' => 'to win against someone in a game or sport', 'meaning_fa' => 'شکست دادن (در بازی)', 'pos' => 'verb', 'synonym' => 'opposite: lose to', 'example' => 'My sister always beats me at table tennis.', 'difficulty' => 'medium'],
                                    ['phrase' => 'get the chance', 'meaning' => 'to have the time or opportunity to do something', 'meaning_fa' => 'فرصت (انجام کاری) را پیدا کردن', 'pos' => 'verb phrase', 'example' => "I love hiking, but I don't often get the chance.", 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Oli shows how people really talk about their free time — listen for the little word that comes after "I enjoy".',
                                'source' => 'Oxford Online English — "How to Talk About Your Free Time and Hobbies in English"',
                                'image_query' => 'friends playing basketball park',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M05', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. One speaker here.
                                'listening_turns' => $this->shadowTimestampsFor('M05', 'listening_turns'),
                                // The lesson's own human captions for the three parts kept in
                                // the cut (0:00-4:21, 5:16-6:23, 7:23-7:52 of the original),
                                // checked against a Whisper pass of the cut audio.
                                'transcript' => [
                                    ['speaker' => 'Oli', 'text' => "Hello, I'm Oli. Welcome to Oxford Online English! In this lesson, you can learn about how to talk about your free time and hobbies in English. What do you like doing in your free time? Do you have any interesting hobbies? Talking about your free time is a great way to start a conversation in English, or to find things in common when you're speaking to somebody. By the end of this lesson, you'll be able to speak clearly and naturally about your free time and your hobbies in English."],
                                    ['speaker' => 'Oli', 'text' => 'Part one: free time and hobbies vocabulary. What words do you know for free time activities in English? Some people prefer things you can do at home, like watching TV, reading, or cooking. Other people like sports, like playing football, playing basketball, playing tennis, going swimming, going jogging, or going climbing, or maybe doing boxing, doing judo, or doing yoga.'],
                                    ['speaker' => 'Oli', 'text' => 'Of course, many people prefer more social activities, for example, hanging out with your friends, relaxing with your family, or going shopping. Or maybe you like to do something more creative, like taking photos, painting, or writing stories or writing a blog. What about you? What kind of free time activities do you prefer? Let\'s look at how you can talk about free time activities and hobbies which you like.'],
                                    ['speaker' => 'Oli', 'text' => 'Part two: what do you like doing? In my free time, I like... What could you say here? "In my free time, I like playing basketball." "In my free time, I like relaxing with my family." "In my free time, I like reading books." Can you see a pattern here? In every sentence, after \'like\', we use a verb plus -ing. Can you make a sentence? What do you like doing in your free time?'],
                                    ['speaker' => 'Oli', 'text' => "But just using 'like' can be repetitive and boring. So what other verbs can you use? Well, you could use verbs like 'love', 'enjoy', or 'hate'. For example: \"I love going swimming.\" \"I enjoy watching TV.\" \"I hate going shopping.\" Can you see the same pattern? After all of these verbs, you need to use a verb with -ing."],
                                    ['speaker' => 'Oli', 'text' => "You can also add words like 'really', 'sometimes', or 'absolutely', to make your meaning stronger or weaker. For example: \"I really like taking photos.\" \"Sometimes I enjoy going jogging.\" \"I absolutely hate playing football.\" Now it's your turn. What can you say? Can you make your own sentences?"],
                                    ['speaker' => 'Oli', 'text' => 'OK, so now you can say something about what you like or don\'t like doing. Let\'s look at how you can add more details to your ideas. First, let\'s say how often you do your free time activity. For example: "I like playing sport. I play football every Tuesday evening." "I absolutely hate going shopping. I only go shopping once or twice a year." "I really like taking photos, but I don\'t get the chance to do it often."'],
                                    ['speaker' => 'Oli', 'text' => "What about you? How often do you do your hobbies? You can use these phrases to help you talk. 'Every...' For example: 'every weekend', 'every Saturday afternoon', 'every day'. 'Once or twice a...', for example: 'once a week', 'twice a month', 'three times a year', and so on. Can you make some sentences like this about your free time and hobbies? How often do you do your hobbies?"],
                                    ['speaker' => 'Oli', 'text' => 'OK, next, let\'s look at giving background information, especially if you have an interesting or an unusual hobby. You can talk about when and why you started doing it. For example, "I started playing tennis when I was 10." "I\'ve been learning to paint for six months now." "I decided to do yoga because I wanted to be healthy." "My French friend got me into climbing."'],
                                    ['speaker' => 'Oli', 'text' => 'In the last sentence, the phrase "My friend got me into something" means that your friend introduced this activity to you, and now you like it. What can you say about your hobby? Can you use some of these phrases to talk about yourself? For example, I started climbing when I was living in Russia. I\'ve been climbing for about eight years. I also recently decided to do yoga because I wanted to be more flexible. When I was younger, my dad got me into photography.'],
                                    ['speaker' => 'Oli', 'text' => 'Let\'s try using everything we\'ve looked at to make a longer answer. For example, "I really like playing tennis. I play every weekend at the park near my house. Sometimes I play with my brother, or sometimes with a friend. My brother got me into it because he loves sports, and he needed someone to play with. I didn\'t like it at first because it was hard, and I lost all the time. But now, I enjoy it, especially when I beat my brother."'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript (beat / got me into as the
                                // inflected forms Oli actually says).
                                'target_phrases' => [
                                    [
                                        'phrase' => 'hanging out', 'meaning' => 'spending time relaxing with someone',
                                        'gap_before' => 'Of course, many people prefer more social activities, for example, ',
                                        'gap_after' => ' with your friends, relaxing with your family, or going shopping.',
                                    ],
                                    [
                                        'phrase' => 'once or twice', 'meaning' => 'one or two times',
                                        'gap_before' => 'I absolutely hate going shopping. I only go shopping ',
                                        'gap_after' => ' a year.',
                                    ],
                                    [
                                        'phrase' => 'get the chance', 'meaning' => 'have the time or opportunity',
                                        'gap_before' => "I really like taking photos, but I don't ",
                                        'gap_after' => ' to do it often.',
                                    ],
                                    [
                                        'phrase' => 'got me into', 'meaning' => 'made me start liking (an activity)',
                                        'gap_before' => 'My French friend ',
                                        'gap_after' => ' climbing.',
                                    ],
                                    [
                                        'phrase' => 'beat', 'meaning' => 'win against',
                                        'gap_before' => 'But now, I enjoy it, especially when I ',
                                        'gap_after' => ' my brother.',
                                    ],
                                ],
                                'topic_summary' => 'Oli, an English teacher, shows how to talk about free time: words '
                                    .'for common hobbies, the pattern "like / love / enjoy / hate + verb-ing", how to '
                                    .'add how often and where you do something, how to explain when and why you '
                                    .'started ("I decided to…", "my friend got me into…"), and finally one longer '
                                    .'model answer about playing tennis with a brother.',
                                'comprehension_check' => [
                                    ['statement' => "Oli says that after 'like', 'love' and 'enjoy' we use a verb with -ing.", 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Oli says short answers with no details sound the most natural.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => "In the model answer, the speaker didn't enjoy tennis at first.", 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'Who got Oli into photography?',
                                    'options' => ['His French friend', 'His dad', 'His brother'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Do you like going camping?', 'answer' => 'Yes, I enjoy going camping in the mountains. I want to go again this summer.', 'words' => ['going camping', 'enjoy', 'want to']],
                            ['question' => 'Would you try rock climbing?', 'answer' => 'I\'d love to try rock climbing, but I\'m afraid of falling.', 'words' => ['rock climbing', 'love to try']],
                            ['question' => 'What do you do to keep fit?', 'answer' => 'I work out three times a week. Going to the gym keeps me fit.', 'words' => ['work out', 'keeps me fit']],
                            ['question' => 'What do you do just for fun?', 'answer' => 'Just for fun, I do a bit of drawing. I like drawing people on the bus.', 'words' => ['for fun', 'a bit of']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'The words for what people really do at the weekend — and how much of it they do.',
                                // Vocabulary in Use unit 41B (Leisure activities), our own sentences.
                                'story' => 'Every summer my family **goes camping** by a lake. My brother **does a bit of** '
                                    .'**rock climbing**, and my mum **works out** at the gym twice a week — she says it '
                                    .'**keeps** her **fit**. I just swim **for fun**.',
                                'words' => [
                                    ['phrase' => 'go camping', 'meaning' => 'to sleep in a tent for a holiday or a weekend', 'meaning_fa' => 'به کمپینگ رفتن، چادر زدن', 'pos' => 'verb phrase', 'example' => 'We often go camping in the mountains in summer.', 'image_query' => 'tent camping lake', 'difficulty' => 'easy'],
                                    ['phrase' => 'rock climbing', 'meaning' => 'climbing up rocks or a climbing wall as a sport', 'meaning_fa' => 'صخره‌نوردی', 'pos' => 'noun', 'example' => "Rock climbing looks scary, but it's great fun.", 'image_query' => 'rock climbing outdoor', 'difficulty' => 'medium'],
                                    ['phrase' => 'do a bit of', 'meaning' => 'to do some of an activity, but not a lot', 'meaning_fa' => 'کمی (کاری را) انجام دادن', 'pos' => 'phrase (+ noun or -ing)', 'synonym' => 'opposite: do a lot of', 'example' => 'I did a bit of sailing on holiday.', 'difficulty' => 'medium'],
                                    ['phrase' => 'work out', 'meaning' => 'to do exercise to make your body stronger', 'meaning_fa' => 'ورزش کردن (معمولاً در باشگاه)', 'pos' => 'phrasal verb', 'example' => 'She works out in the gym three times a week.', 'image_query' => 'woman working out gym', 'difficulty' => 'easy'],
                                    ['phrase' => 'keep (someone) fit', 'meaning' => 'to help your body stay healthy and strong', 'meaning_fa' => '(کسی را) سرحال و تندرست نگه داشتن', 'pos' => 'verb phrase', 'example' => 'Jogging keeps my dad fit.', 'difficulty' => 'medium'],
                                    ['phrase' => 'for fun', 'meaning' => 'because you enjoy it, not for work or money', 'meaning_fa' => 'برای سرگرمی، برای تفریح', 'pos' => 'phrase', 'synonym' => 'for pleasure', 'example' => "I don't play to win — I just play for fun.", 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same lesson, one more time — this time copy how Oli says the -ing words.',
                                'image_query' => 'family relaxing together at home weekend',
                                'shadow_lines' => [
                                    'In my **free** **time**, I **like** **relaxing** with my **family**.',
                                    "I **really** **like** **taking** **photos**, but I **don't** get the **chance** to **do** it **often**.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M05', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 14,
                                'hook' => 'One small choice — "playing" or "to play"? — decides whether your hobby sentence sounds natural.',
                                'focus' => 'Gerunds vs Infinitives',
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use's verb + -ing / verb + to units.
                                'lesson' => [
                                    'intro' => 'Three small things: verbs that take -ing, verbs that take to, and -ing after little words like at and in.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · enjoy + -ing',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'After <strong>enjoy, don\'t mind, finish, give up</strong> and <strong>keep</strong>, the next verb takes <strong>-ing</strong>.<br>With <strong>like, love, hate</strong>, -ing is the natural choice for hobbies.',
                                                    'fa' => 'بعد از enjoy، don\'t mind، finish، give up و keep فعل بعدی همیشه ing می‌گیرد. برای حرف زدن از سرگرمی‌ها بعد از like، love و hate هم ing طبیعی‌ترین انتخاب است.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Verb + -ing',
                                                    'parts' => [
                                                        ['text' => 'I', 'caption' => 'subject'],
                                                        ['text' => 'enjoy', 'caption' => 'verb'],
                                                        ['text' => 'playing', 'caption' => 'verb + -ing'],
                                                        ['text' => 'chess', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Leo',
                                                    'wrong' => 'I enjoy to cook for my friends.',
                                                    'right' => 'I enjoy cooking for my friends.',
                                                    'explanation' => 'Enjoy is always followed by -ing, never by to.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'I love going swimming on Sunday mornings.',
                                                            "I don't mind getting up early for a match.",
                                                            'She gave up playing the piano last year.',
                                                            'He keeps losing at chess, but he loves it.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · want + to',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'After <strong>want, would like, decide, plan, hope</strong> and <strong>learn</strong>, use <strong>to + verb</strong>.',
                                                    'fa' => 'بعد از want، would like، decide، plan، hope و learn فعل بعدی با to می‌آید (to + شکل ساده‌ی فعل). این فعل‌ها معمولاً درباره‌ی برنامه و خواسته‌ی آینده‌اند.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Verb + to',
                                                    'parts' => [
                                                        ['text' => 'I', 'caption' => 'subject'],
                                                        ['text' => 'want', 'caption' => 'verb'],
                                                        ['text' => 'to learn', 'caption' => 'to + verb'],
                                                        ['text' => 'the guitar', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I decided joining a dance class.', 'right' => 'I decided to join a dance class.', 'highlight' => 'to join'],
                                                        ['wrong' => "I'd like trying surfing one day.", 'right' => "I'd like to try surfing one day.", 'highlight' => 'to try'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'rule_examples',
                                                    'items' => [
                                                        ['rule' => 'want + to + verb', 'example' => 'I want to learn the guitar.', 'highlight' => 'to learn'],
                                                        ['rule' => 'decide + to + verb', 'example' => 'She decided to take up yoga.', 'highlight' => 'to take up'],
                                                        ['rule' => 'would like + to + verb', 'example' => "I'd like to try painting.", 'highlight' => 'to try'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · good at + -ing',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'After a little word like <strong>at, in, of, about</strong>, a verb always takes <strong>-ing</strong>.',
                                                    'fa' => 'بعد از حرف اضافه (at، in، of، about، on …) فعل همیشه ing می‌گیرد، نه to: good at drawing، interested in learning.',
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Mina',
                                                    'wrong' => "I'm interested in learn Spanish.",
                                                    'right' => "I'm interested in learning Spanish.",
                                                    'explanation' => '"in" is a preposition, so the verb after it takes -ing.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            "I'm good at drawing faces.",
                                                            "He's thinking about joining a running club.",
                                                            "We're tired of watching TV every evening.",
                                                        ]],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Also useful: go + -ing', 'words' => ['go swimming', 'go jogging', 'go climbing', 'go fishing', 'go shopping']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about <strong>your</strong> free time — and use them out loud soon, in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this soon — in AI Conversation #1, talking about your own hobbies.',
                                ],
                                'frequency_starters' => [
                                    'In my free time, I enjoy', "I'm really good at", 'Next year I want',
                                    "I'd love to learn how", 'A few years ago I gave up', "I'm interested in",
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about their free time, using the correct verb '
                                    .'form after the starter: -ing after enjoy / give up / a preposition (at, in, of, '
                                    .'about), and to + verb after want / would like / decide / learn how.',
                                'grammar_major_criteria' => 'the verb right after the starter has the wrong form (e.g. '
                                    .'"enjoy to swim", "want playing", "good at play", a bare verb after "in"), the '
                                    .'sentence does not actually continue the given starter, or it is not a genuine '
                                    .'personal statement',
                                'grammar_context' => 'continues with the correct verb form (verb + -ing or to + verb) '
                                    .'for the starter already given',
                                'quick_check' => [
                                    [
                                        'wrong' => 'I enjoy to swim in the sea.',
                                        'options' => ['I enjoy to swim in the sea.', 'I enjoy swimming in the sea.', 'I enjoy swim in the sea.'],
                                        'correct' => 1,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'She decided joining a dance class.',
                                        'options' => ['She decided joining a dance class.', 'She decided join a dance class.', 'She decided to join a dance class.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => "I'm interested in learn Spanish.",
                                        'options' => ["I'm interested in learning Spanish.", "I'm interested in learn Spanish.", "I'm interested to learning Spanish."],
                                        'correct' => 0,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['playing', 'enjoy', 'I', 'chess.'], 'answer' => 'I enjoy playing chess.'],
                                    ['words' => ['to', 'want', 'guitar.', 'I', 'the', 'learn'], 'answer' => 'I want to learn the guitar.'],
                                    ['words' => ['at', 'drawing.', "She's", 'good'], 'answer' => "She's good at drawing."],
                                ],
                            ],
                            [
                                // A real vlog, one speaker, natural speed: Pensando Inglés'
                                // "My Favorite Hobbies" (about 6 minutes cut from the original
                                // 23: guitar, learning languages, reading, martial arts and why
                                // he stopped; the sponsor segment and the guitar playing left
                                // out; the creator's burned-in captions blurred out so the
                                // step's own caption toggle works).
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Kyle shows you his real hobbies, guitar in hand — hear "I enjoy making" and "I like to play" at natural speed, then say them yourself.',
                                'source' => 'Pensando Inglés — "Learn Real-Life English: My Favorite Hobbies"',
                                'video_url' => $shadowingVideoUrl,
                                // The video's own auto-captions, checked against the creator's
                                // burned-in captions, re-timed per sentence on Whisper's
                                // word timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M05', 'video_shadowing_segments'),
                                'topic_summary' => 'Kyle, an American English teacher, talks about his hobbies. He has '
                                    .'played the guitar on and off for about 20 years, and explains why we "play" an '
                                    .'instrument. Learning languages changed his life — Spanish helped him meet his '
                                    .'wife. He loves reading non-fiction because he likes to keep on learning. He '
                                    .'trained in martial arts for almost four years, but stopped because it was '
                                    .'expensive and he wanted to spend his time and money on his channel instead.',
                                'comprehension_check' => [
                                    ['statement' => 'Kyle has played the guitar on and off for many years.', 'correct' => true],
                                    ['statement' => 'Kyle prefers reading fantasy stories.', 'correct' => false],
                                    ['statement' => 'One reason Kyle stopped martial arts was the cost.', 'correct' => true],
                                ],
                                // Real phrases Kyle uses and explains on camera.
                                'target_phrases' => [
                                    ['phrase' => 'pastime', 'meaning' => 'something you do for fun in your free time; a hobby'],
                                    ['phrase' => 'on and off', 'meaning' => 'sometimes doing something, then stopping, then starting again'],
                                    ['phrase' => 'be passionate about', 'meaning' => 'to love something and care about it a lot'],
                                    ['phrase' => 'keep on (doing)', 'meaning' => 'to continue doing something'],
                                    ['phrase' => 'in good shape', 'meaning' => 'healthy and physically fit'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words the
                                // speaker actually says. Chosen for this mission's grammar:
                                // enjoy + -ing, like / love / prefer / want + to, -ing as a
                                // subject and stop + -ing. Bold marks naturally-stressed
                                // content words (rendered by <x-stress-marked-line>).
                                'shadow_lines' => [
                                    "I do **enjoy** **making** **music**, and I **think** it's **fun**.",
                                    '**Not** very **good**, but I **do** **like** to **play** **guitar**.',
                                    '**Learning** **languages** has **absolutely** **changed** my **life** in so many **ways**.',
                                    'I **love** to **read** because I **always** love to **keep** on **learning**.',
                                    'I just **want** to **know** how to **protect** **myself** and my **family**.',
                                    'I **decided** that I **wanted** to **stop** **giving** my **time** and **money** to that.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M05', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'What are you really into at the moment?', 'answer' => 'I\'m really into cooking at the moment. I\'m keen on trying new recipes.', 'words' => ['into', 'keen on']],
                            ['question' => 'Which jobs at home don\'t you mind doing?', 'answer' => 'I don\'t mind washing the dishes, but I can\'t stand ironing.', 'words' => ['don\'t mind', 'can\'t stand']],
                            ['question' => 'What kind of music do you quite like?', 'answer' => 'I quite like old Persian pop. I enjoy listening to it in the car.', 'words' => ['quite like', 'enjoy listening']],
                            ['question' => 'Do you have a lot in common with your best friend?', 'answer' => 'Yes, we have a lot in common. We both love watching football and playing video games.', 'words' => ['have a lot in common', 'love watching']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — ways to say how much you like something, beyond just "I like".',
                                // Vocabulary in Use unit 68 (Likes, dislikes, attitudes and
                                // preferences), our own sentences.
                                'story' => "I'm really **into** board games, and I **quite like** chess too. I **don't mind** "
                                    ."losing, but I **can't stand** waiting for slow players! My best friend and I **have a "
                                    ."lot in common**, but she's **not** very **keen on** games.",
                                'words' => [
                                    ['phrase' => 'into', 'meaning' => '(informal) liking something very much', 'meaning_fa' => 'خیلی علاقه‌مند به (محاوره‌ای)', 'pos' => 'preposition (be into)', 'example' => "I'm really into dance music at the moment.", 'difficulty' => 'medium'],
                                    ['phrase' => 'quite like', 'meaning' => 'to like something a little, but not very much', 'meaning_fa' => 'تا حدی دوست داشتن', 'pos' => 'verb phrase', 'example' => 'I quite like jazz, but I prefer rock.', 'difficulty' => 'easy'],
                                    ['phrase' => "don't mind", 'meaning' => "to be OK with something; it doesn't bother you", 'meaning_fa' => 'برایم فرقی نمی‌کند، مشکلی ندارم با', 'pos' => 'verb phrase (+ -ing)', 'example' => "I don't mind getting up early.", 'difficulty' => 'medium'],
                                    ['phrase' => "can't stand", 'meaning' => '(informal) to dislike something very much', 'meaning_fa' => 'اصلاً تحمل نکردن، بیزار بودن از', 'pos' => 'verb phrase (+ -ing)', 'synonym' => 'hate', 'example' => "I can't stand waiting in queues.", 'difficulty' => 'medium'],
                                    ['phrase' => 'keen on', 'meaning' => 'liking something or interested in it (often "not very keen on")', 'meaning_fa' => 'علاقه‌مند به (بیشتر به شکل منفی: not very keen on)', 'pos' => 'adjective phrase', 'synonym' => 'into', 'example' => "I'm not very keen on folk music.", 'difficulty' => 'hard'],
                                    ['phrase' => 'have a lot in common', 'meaning' => 'to have many of the same interests', 'meaning_fa' => 'وجه مشترک زیادی داشتن', 'pos' => 'verb phrase', 'synonym' => 'opposite: have nothing in common', 'example' => 'My sister and I have a lot in common — we both love films.', 'difficulty' => 'hard'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same audio, one more time — now listen for "decided to" and "wanted to".',
                                'image_query' => 'woman doing yoga outdoors',
                                'shadow_lines' => [
                                    'I **decided** to **do** **yoga** because I **wanted** to be **healthy**.',
                                    "I **didn't** **like** it at **first** because it was **hard**, and I **lost** all the **time**.",
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M05', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your hobbies — what you '
                                    .'enjoy doing, how often, and one thing you want to try — using the new '
                                    .'vocabulary, without reading.',
                                'interview_questions' => [
                                    'What do you enjoy doing in your free time?',
                                    'How often do you do it, and where?',
                                    'How did you get into it?',
                                    "Is there a hobby you've given up? Why?",
                                    'What new hobby would you like to try, and why?',
                                    'What are you good at that most people don\'t know about?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Sara — a busy nurse who found a hobby in the strangest place. Can you follow her story?',
                                'passage_title' => 'Meet Sara',
                                'image_query' => 'woman indoor climbing wall',
                                // Written fresh for this app (EOS-009 §14) — reuses the
                                // Vocabulary Builder words (got the chance, for fun, work out,
                                // can't stand, got her into, rock climbing, keen on, once or
                                // twice, don't mind, a lot in common, keeps her fit, go camping,
                                // do a bit of) in a fresh context, third person instead of
                                // first, and is full of the mission's -ing / to patterns.
                                'passage' => 'Sara is a nurse in Shiraz, and for years she almost never got the chance to '
                                    .'do anything for fun. She tried to work out at a gym near the hospital, but she '
                                    .'couldn\'t stand running on a machine and looking at a wall. Last year a friend from '
                                    .'work got her into rock climbing. At first Sara wasn\'t keen on it: her arms hurt, and '
                                    .'she nearly gave up after two lessons. But she decided to join a small evening group, '
                                    .'and now she goes once or twice a week. She doesn\'t mind falling, because there is '
                                    .'always a rope, and she enjoys being with people who have a lot in common with her. '
                                    .'She says climbing keeps her fit and helps her forget a long shift. This summer she '
                                    .'hopes to go camping in the mountains and do a bit of climbing outdoors, and she is '
                                    .'already looking forward to choosing her first real climbing shoes.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'got the chance', 'type' => 'reused'],
                                    ['phrase' => 'for fun', 'type' => 'reused'],
                                    ['phrase' => 'work out', 'type' => 'reused'],
                                    ['phrase' => 'couldn\'t stand', 'type' => 'reused'],
                                    ['phrase' => 'got her into', 'type' => 'reused'],
                                    ['phrase' => 'rock climbing', 'type' => 'reused'],
                                    ['phrase' => 'keen on', 'type' => 'reused'],
                                    ['phrase' => 'once or twice', 'type' => 'reused'],
                                    ['phrase' => 'doesn\'t mind', 'type' => 'reused'],
                                    ['phrase' => 'a lot in common', 'type' => 'reused'],
                                    ['phrase' => 'keeps her fit', 'type' => 'reused'],
                                    ['phrase' => 'go camping', 'type' => 'reused'],
                                    ['phrase' => 'do a bit of', 'type' => 'reused'],
                                    ['phrase' => 'shift', 'type' => 'new', 'definition' => 'the hours someone works, for example in a hospital', 'pos' => 'noun', 'example' => 'She works the night shift this week.'],
                                    ['phrase' => 'looking forward to', 'type' => 'new', 'definition' => 'feeling happy about something that is going to happen (followed by -ing)', 'pos' => 'phrase', 'example' => "I'm looking forward to seeing my friends this weekend."],
                                ],
                                'topic_summary' => 'A short profile of Sara, a nurse who hated the gym until a friend got '
                                    .'her into rock climbing. She nearly gave up at first, but joined an evening group, now '
                                    .'climbs once or twice a week to keep fit, and hopes to go camping and climb outdoors '
                                    .'this summer.',
                                'comprehension_check' => [
                                    ['statement' => 'Sara works as a nurse.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Sara loved rock climbing from the very first lesson.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Sara hopes to go camping this summer.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'How did Sara get into rock climbing?',
                                    'Why does Sara like climbing more than the gym?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Writing about your free time often shows you which hobby really matters to you.',
                                'title' => 'My free time',
                                'prompts' => [
                                    ['label' => 'What you enjoy doing', 'image_query' => 'person reading book cozy'],
                                    ['label' => 'How often and where you do it', 'image_query' => 'people jogging park morning'],
                                    ['label' => 'How you got into it', 'image_query' => 'father teaching child to ride bike'],
                                    ['label' => 'Something you gave up', 'image_query' => 'old piano keys dusty'],
                                    ['label' => 'A hobby you want to try', 'image_query' => 'rock climbing indoor wall'],
                                    ['label' => 'Why it matters to you', 'image_query' => 'happy person painting canvas'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['enjoy + -ing', 'love + -ing', 'want to', 'decided to', "I'd like to", 'good at + -ing', 'interested in + -ing', 'gave up + -ing'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe your perfect free day.', 'answer' => 'I\'d get up late and hang out with friends in the park. In the evening I\'d enjoy watching a film.', 'words' => ['hang out', 'enjoy watching']],
                            ['question' => 'What hobby did you give up, and why?', 'answer' => 'I gave up playing the guitar because I didn\'t have time to practise.', 'words' => ['gave up playing', 'to practise']],
                            ['question' => 'Is there a hobby you can\'t stand?', 'answer' => 'I can\'t stand fishing. Waiting for hours is so boring.', 'words' => ['can\'t stand', 'waiting']],
                            ['question' => 'What new hobby would you like to learn?', 'answer' => 'I\'d like to learn to swim. I\'m keen on doing more sport this year.', 'words' => ['like to learn', 'keen on']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'Forget your own hobbies for a minute — how is this family spending its evening?',
                                'image_query' => 'family playing board game living room',
                                'guiding_questions' => [
                                    'What game are the woman and the two girls playing?',
                                    'What is the woman in the orange jumper doing?',
                                    'What are the two older women in the background doing?',
                                    'What can you see on the shelves behind them?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): a woman in an orange
                                // jumper and two girls playing chess at a small table in front,
                                // two older women sitting at a dining table in the background
                                // (one centre, one on the right), kitchen shelves with jars and
                                // a vase top-left. Changing the query or orientation
                                // invalidates these coordinates.
                                'hotspots' => [
                                    ['x' => 40, 'y' => 82, 'question_index' => 0],
                                    ['x' => 36, 'y' => 45, 'question_index' => 1],
                                    ['x' => 72, 'y' => 32, 'question_index' => 2],
                                    ['x' => 18, 'y' => 15, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Describe your favourite hobby in detail, with no preparation.',
                                    'Talk about a hobby you gave up, and why you stopped.',
                                    'Explain a new hobby you would like to take up, and why.',
                                    'What did you enjoy doing in your free time as a child?',
                                    'Do you prefer hobbies you do alone or with other people? Why?',
                                    'Is it important to have a hobby? Why or why not?',
                                ],
                                'role_reversal_topic' => 'hobbies and free time',
                                'final_prompt' => 'Speak for 3 minutes without stopping about your hobbies and free time.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'Verb + -ing (enjoy, love, give up…)',
                                    'Verb + to (want, decide, would like…)',
                                    'How often you do it',
                                    'How you got into it',
                                    'One reason',
                                    'One hobby you want to try',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Seeds M06 "Learning English" — the roadmap's Past Simple, and the
     * first checkpoint mission (ProgramPlanner::CHECKPOINT_MISSIONS; the
     * voice checkpoint itself is offered by the planner after the run, so
     * nothing here changes for it). M01's 17-step layout: story_sequence
     * is back, right after Grammar in Context, because a finished story is
     * exactly what Past Simple narrates. Vocabulary days 2-3 follow
     * Vocabulary in Use unit 33 (Studying English and taking exams), the
     * lesson follows Grammar in Use unit 5, both rewritten in our own words.
     * Media the user picked: Speak English With Vanessa's "My Language
     * Success Story" (cut from 7:43 to about 6 minutes: intro, website
     * plugs and the closing recap out) and POC English's "How I Went From
     * Zero English to C2" (the first 6:40 of an 18-minute talk, cut to
     * about 6 minutes, burned-in captions blurred out).
     */
    private function seedM06(): void
    {
        $audioUrl = $this->publishMissionAsset('M06', 'Speak English With Vanessa - My Language Success Story.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M06', 'POC English - Zero English to C2.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M06', 'POC English - Zero English to C2.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M06'],
            [
                'title' => 'Learning English',
                'module' => 'Learning',
                'outcome' => 'I can tell the story of how I learned English — when I started, what I did, what didn\'t work and what finally helped.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'When did you start learning English?', 'answer' => 'I started at school when I was ten. I didn\'t like it at first.', 'words' => ['started', 'didn\'t']],
                            ['question' => 'Did you ever quit a class? Why?', 'answer' => 'Yes, I quit a gym class last year. It wasn\'t a good fit for me.', 'words' => ['quit', 'a good fit']],
                            ['question' => 'Do you like one-on-one lessons or group classes?', 'answer' => 'I like one-on-one lessons. Last year a private teacher helped me gain confidence.', 'words' => ['one-on-one', 'gain confidence']],
                            ['question' => 'Tell me about an experience that opened your eyes.', 'answer' => 'A trip to Turkey opened my eyes. I spoke English with tourists for the first time.', 'words' => ['opened my eyes', 'spoke']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Someone asks "How did you learn your English?" — could you tell them the real story?',
                                'image_query' => 'open notebook studying language',
                                'ambient_video_query' => 'student writing notes in notebook',
                                'warm_up_questions' => [
                                    'When did you start learning English?',
                                    'Where did you learn it — at school, in a class, or on your own?',
                                    'What did you find difficult at first?',
                                    'What helped you most?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases Vanessa uses to tell her own language story — you\'ll need them for yours.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'My first English class at university was boring, so I **quit** after a month — it '
                                    .'just wasn\'t **a good fit** for me. Then I found a teacher, and we met **one-on-one** '
                                    .'every Monday. She **opened my eyes**: English could be fun! Slowly I **gained confidence**.',
                                'words' => [
                                    ['phrase' => 'quit', 'meaning' => 'to stop doing something, for example a class or a job', 'meaning_fa' => 'ول کردن، کنار گذاشتن (کلاس یا کار)', 'pos' => 'verb (quit – quit – quit)', 'example' => 'He quit his job and went back to university.', 'difficulty' => 'easy'],
                                    ['phrase' => 'a good fit', 'meaning' => 'right or suitable for someone', 'meaning_fa' => 'مناسب (کسی)، جور با (کسی)', 'pos' => 'noun phrase', 'example' => "That course wasn't a good fit for me — it was too slow.", 'difficulty' => 'medium'],
                                    ['phrase' => 'one-on-one', 'meaning' => 'with only two people, for example one teacher and one student', 'meaning_fa' => 'دونفره، خصوصی (یک معلم و یک شاگرد)', 'pos' => 'adjective / adverb', 'example' => 'I take one-on-one lessons with a teacher online.', 'image_query' => 'tutor teaching one student', 'difficulty' => 'easy'],
                                    ['phrase' => 'gain confidence', 'meaning' => 'to start to feel sure about yourself and what you can do', 'meaning_fa' => 'اعتماد به نفس پیدا کردن', 'pos' => 'verb phrase', 'example' => 'She gained confidence after her first presentation.', 'difficulty' => 'medium'],
                                    ['phrase' => 'open (someone\'s) eyes', 'meaning' => 'to make someone see or understand something new', 'meaning_fa' => 'چشم کسی را باز کردن، دید تازه دادن', 'pos' => 'idiom', 'example' => 'Living abroad opened my eyes to other cultures.', 'difficulty' => 'hard'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Vanessa quit two language classes before French finally clicked — listen for what she did differently.',
                                'source' => 'Speak English With Vanessa — "My Language Success Story"',
                                'image_query' => 'eiffel tower paris street',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M06', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. One speaker here.
                                'listening_turns' => $this->shadowTimestampsFor('M06', 'listening_turns'),
                                // The four parts kept in the cut (0:27-2:49, 2:52-4:18,
                                // 4:25-6:16 and 6:49-7:09 of the original), written from
                                // YouTube's captions and checked against a Whisper pass
                                // of the cut audio.
                                'transcript' => [
                                    ['speaker' => 'Vanessa', 'text' => 'As I mentioned in my Spanish video, I decided to quit my Spanish class because I felt like it was boring. It wasn\'t interesting. It wasn\'t what I wanted to do. And I thought that maybe there was a problem with Spanish and my brain. Maybe there was a problem with the language and me. In reality, that\'s not the case. There was nothing wrong with Spanish, but there was a huge problem with the method that I was learning Spanish.'],
                                    ['speaker' => 'Vanessa', 'text' => 'So, I decided to change to French instead. I took a French class that was very basic. But after this basic level, I decided to go on to a more advanced class. And in this advanced class, the other students didn\'t really care about learning French. I think they were only there because it was a requirement, but for me it wasn\'t a requirement. I was just interested in learning French, trying another language. So, this class wasn\'t a really good fit for me and I decided to quit again.'],
                                    ['speaker' => 'Vanessa', 'text' => 'And this is the best thing that I could have done because I asked the professor, "Can I meet with you in your office once, twice, maybe three times a week just to speak one-on-one? I\'m really interested in trying to speak, but the classroom isn\'t really helping me with that." She said, "Yes." I was very lucky. And I met with her two or three times a week for several months until I graduated.'],
                                    ['speaker' => 'Vanessa', 'text' => 'This helped in so many different ways. First of all, she helped answer all my questions. She also helped to give me more expressions that I needed to use in my conversation. And the most important thing, I feel like, is she helped me gain confidence. I wasn\'t scared to try to speak even if I had no idea what to say. I just tried to say something and she was so patient. So I learned a lot in that situation.'],
                                    ['speaker' => 'Vanessa', 'text' => 'But after I finished my studies, then I had to do something different. I decided to go to a local meetup group. You can see these kind of meetup groups in your city. But I went to a French meetup in my city. And every Wednesday evening, there were a group of people who spoke French. Most of them also spoke English because we were in the US, but the goal was to speak only French together.'],
                                    ['speaker' => 'Vanessa', 'text' => 'And these people opened my eyes because it wasn\'t one-on-one anymore. It was a group of people all speaking together. I didn\'t really understand very much and I mostly listened. I tried to speak, but I mostly listened in that situation. But it helped me to realize that this is a real language. This is a thing that isn\'t just in the classroom, that isn\'t just with my professor, but this is something that I can use with a lot of people. And it showed me that I need some motivation to be able to get to that level.'],
                                    ['speaker' => 'Vanessa', 'text' => 'So, I decided to change my path. I decided to move to France and live with a French family as their au pair. Au pair is like a nanny or a babysitter who lives with the family. So, I moved to Paris and lived with the French family. Of course, this was really useful to me to speak French with the family, but I think there were two other things that were really useful while I was living in Paris that I could have done other places, too.'],
                                    ['speaker' => 'Vanessa', 'text' => 'The first one is that I spoke French with my friends who were not native French speakers. A lot of my friends were other au pairs from Austria, Germany, Sweden, a lot of other places. And none of us were native French speakers, but we spoke French together anyway. And I think in these conversations, it really helped me because I felt confident expressing myself. I knew that they wouldn\'t feel frustrated if I didn\'t know exactly what to say, because they understood. They were in the same situation. But we learned from each other. This was so beneficial. Non-native French speakers speaking together was wonderful.'],
                                    ['speaker' => 'Vanessa', 'text' => 'The second thing that helped me was when I went to my daily French class. This was required for my visa, so I had to go to a French class every morning. When I went to that French class, I had a great time. I\'ve never had a good language class before, but this teacher showed me that it was possible. And in my own life, I tried to imitate him and imitate his style and his care, because each class passed so quickly. We learned a lot. He shared so much fun information with us and he cared about each student.'],
                                    ['speaker' => 'Vanessa', 'text' => 'So this was something that was really positive for me: having someone who was entertaining, interesting, and also attentive to each student. This helped me to learn French a lot. So, speaking with my friends, speaking with the meetup group, speaking with my professor, speaking with the teacher in Paris, these were all so useful.'],
                                    ['speaker' => 'Vanessa', 'text' => 'And I want to ask you a question now. Tell me, what is your English learning history? Your English learning background: when did you start to learn English? What did you do to reach today? What did you do throughout your life to learn English? Maybe you had a similar experience to me.'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript (opened my eyes as the form
                                // Vanessa actually says).
                                'target_phrases' => [
                                    [
                                        'phrase' => 'quit', 'meaning' => 'stop going to (a class)',
                                        'gap_before' => 'As I mentioned in my Spanish video, I decided to ',
                                        'gap_after' => ' my Spanish class because I felt like it was boring.',
                                    ],
                                    [
                                        'phrase' => 'good fit', 'meaning' => 'right or suitable for someone',
                                        'gap_before' => 'So, this class wasn\'t a really ',
                                        'gap_after' => ' for me and I decided to quit again.',
                                    ],
                                    [
                                        'phrase' => 'one-on-one', 'meaning' => 'just two people, teacher and student',
                                        'gap_before' => 'maybe three times a week just to speak ',
                                        'gap_after' => '?',
                                    ],
                                    [
                                        'phrase' => 'gain confidence', 'meaning' => 'start to feel sure of yourself',
                                        'gap_before' => 'And the most important thing, I feel like, is she helped me ',
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'opened my eyes', 'meaning' => 'made me see something new',
                                        'gap_before' => 'And these people ',
                                        'gap_after' => ' because it wasn\'t one-on-one anymore.',
                                    ],
                                ],
                                'topic_summary' => 'Vanessa, an American English teacher, tells how she learned French. '
                                    .'She quit a boring Spanish class, then quit a French class too. What worked was '
                                    .'meeting her professor one-on-one, going to a French meetup where she mostly '
                                    .'listened, and finally moving to Paris as an au pair, where she spoke French with '
                                    .'other learners and had a great teacher. She ends by asking how you learned English.',
                                'comprehension_check' => [
                                    ['statement' => 'Vanessa quit her Spanish class because she found it boring.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'At the French meetup, Vanessa did most of the talking.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'In Paris, speaking with friends who were not native speakers helped Vanessa feel confident.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'How often did Vanessa meet her professor?',
                                    'options' => ['Once a month', 'Two or three times a week', 'Every morning'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Is anyone in your family fluent in English?', 'answer' => 'My cousin is fluent. She lived in Canada for five years.', 'words' => ['fluent', 'lived']],
                            ['question' => 'Which English accent do you like?', 'answer' => 'I like the British accent. I picked up some words from British TV shows.', 'words' => ['accent', 'picked up']],
                            ['question' => 'Tell me about a time you made yourself understood in English.', 'answer' => 'Last summer I got lost in Istanbul, but I made myself understood and found my hotel.', 'words' => ['made myself understood', 'found']],
                            ['question' => 'When did you feel you made progress in English?', 'answer' => 'I made progress when I started watching films without subtitles.', 'words' => ['made progress', 'started']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'The words teachers use about your English — fluent, accurate, and the rest.',
                                // Vocabulary in Use unit 33A ("How are they getting on?"), our own sentences.
                                'story' => 'At school my English wasn\'t **fluent**, and I had a strong **accent**. I didn\'t '
                                    .'always speak **accurately**, but I could **make myself understood**. Then I watched a lot '
                                    .'of films, **picked up** new phrases, and **made** real **progress** in one year.',
                                'words' => [
                                    ['phrase' => 'fluent', 'meaning' => 'able to speak a language naturally, without stopping', 'meaning_fa' => 'روان (در صحبت کردن)', 'pos' => 'adjective', 'example' => "She's fluent in English and Turkish.", 'difficulty' => 'easy'],
                                    ['phrase' => 'accent', 'meaning' => 'the way someone pronounces words', 'meaning_fa' => 'لهجه', 'pos' => 'noun', 'example' => 'He has a strong Scottish accent.', 'difficulty' => 'easy'],
                                    ['phrase' => 'accurately', 'meaning' => 'correctly, without mistakes', 'meaning_fa' => 'دقیق، بدون غلط', 'pos' => 'adverb', 'synonym' => 'correctly', 'example' => 'Try to write accurately, not just fast.', 'difficulty' => 'medium'],
                                    ['phrase' => 'make yourself understood', 'meaning' => 'to say things in a way people can understand', 'meaning_fa' => 'منظور خود را رساندن', 'pos' => 'verb phrase', 'example' => 'My Spanish is basic, but I can make myself understood.', 'difficulty' => 'hard'],
                                    ['phrase' => 'pick up', 'meaning' => 'to learn something easily, without really studying it', 'meaning_fa' => 'خودبه‌خود یاد گرفتن (زبان، کلمه)', 'pos' => 'phrasal verb', 'example' => 'Children pick up languages very quickly.', 'difficulty' => 'medium'],
                                    ['phrase' => 'make progress', 'meaning' => 'to get better at something', 'meaning_fa' => 'پیشرفت کردن', 'pos' => 'verb phrase', 'example' => 'I made a lot of progress last year.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same story, one more time — this time copy how Vanessa says "decided", "felt" and "took".',
                                'image_query' => 'bored student classroom desk',
                                'shadow_lines' => [
                                    'I **decided** to **quit** my **Spanish** **class** because I **felt** like it was **boring**.',
                                    'I **took** a **French** **class** that was **very** **basic**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M06', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 15,
                                'hook' => 'Your English story already happened — so every verb in it needs its past form.',
                                'focus' => 'Past Simple',
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use unit 5: -ed verbs, irregular verbs,
                                // did / didn't + base verb, then was / were.
                                'lesson' => [
                                    'intro' => 'Four small things: -ed verbs, verbs with their own past form, didn\'t and did, and was / were.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · Most verbs add -ed',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'For something that happened and finished, most verbs add <strong>-ed</strong>: <strong>work → worked, start → started</strong>.<br>Watch the spelling: <strong>study → studied, stop → stopped, live → lived</strong>.',
                                                    'fa' => 'برای کاری که در گذشته انجام شد و تمام شد، بیشتر فعل‌ها ed می‌گیرند (start → started). به املا دقت کن: study → studied، stop → stopped، live → lived.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Past Simple',
                                                    'parts' => [
                                                        ['text' => 'I', 'caption' => 'subject'],
                                                        ['text' => 'started', 'caption' => 'verb + -ed'],
                                                        ['text' => 'English', 'caption' => 'the rest'],
                                                        ['text' => 'when I was eight', 'caption' => 'when'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'I studied English at a language school.',
                                                            'We watched English cartoons every Friday.',
                                                            'She stopped going to class after a month.',
                                                        ]],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => '-ed sounds like /t/', 'words' => ['worked', 'watched', 'stopped', 'practised']],
                                                        ['label' => '-ed sounds like /d/', 'words' => ['lived', 'played', 'listened', 'tried']],
                                                        ['label' => '-ed sounds like /ɪd/', 'words' => ['started', 'wanted', 'decided', 'needed']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · Some verbs have their own past',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Many common verbs don\'t take -ed. They have their own past form — learn them in pairs: <strong>go → went, have → had, take → took</strong>.',
                                                    'fa' => 'خیلی از فعل‌های پرکاربرد ed نمی‌گیرند و شکل گذشته‌ی خودشان را دارند (فعل بی‌قاعده). این‌ها را جفتی حفظ کن: go → went، have → had، take → took.',
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Leo',
                                                    'wrong' => 'I goed to a language school for four years.',
                                                    'right' => 'I went to a language school for four years.',
                                                    'explanation' => 'Go has its own past form: went.',
                                                ],
                                                [
                                                    'type' => 'pairs',
                                                    'pairs' => [
                                                        ['left' => 'go', 'right' => 'went'],
                                                        ['left' => 'have', 'right' => 'had'],
                                                        ['left' => 'take', 'right' => 'took'],
                                                        ['left' => 'speak', 'right' => 'spoke'],
                                                        ['left' => 'teach', 'right' => 'taught'],
                                                        ['left' => 'feel', 'right' => 'felt'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'I took a French class at university.',
                                                            'Our teacher spoke very fast.',
                                                            'We had English twice a week.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · didn\'t and Did …? + base verb',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'In negatives and questions, <strong>did / didn\'t</strong> carries the past — the main verb goes back to its base form: <strong>I didn\'t go</strong>, <strong>Did you study?</strong>',
                                                    'fa' => 'در جمله‌ی منفی و سؤالی، did یا didn\'t نشانه‌ی گذشته است و فعل اصلی به شکل ساده برمی‌گردد: I didn\'t go (نه went)، Did you study? (نه studied).',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Question',
                                                    'parts' => [
                                                        ['text' => 'Did', 'caption' => 'did', 'tone' => 'accent'],
                                                        ['text' => 'you', 'caption' => 'subject'],
                                                        ['text' => 'study', 'caption' => 'base verb'],
                                                        ['text' => 'English at school?', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => "I didn't went to class.", 'right' => "I didn't go to class.", 'highlight' => 'go'],
                                                        ['wrong' => 'Did you studied English?', 'right' => 'Did you study English?', 'highlight' => 'study'],
                                                        ['wrong' => 'What did you at school?', 'right' => 'What did you do at school?', 'highlight' => 'do'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · was / were',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'The past of <strong>am / is / are</strong> is <strong>was / were</strong>, with no did: <strong>I was nervous</strong>, <strong>I wasn\'t ready</strong>, <strong>Were you tired?</strong>',
                                                    'fa' => 'گذشته‌ی am/is/are می‌شود was/were و برای منفی و سؤالی did نمی‌خواهد: I wasn\'t ready، Were you tired?',
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => "I didn't be nervous.", 'right' => "I wasn't nervous.", 'highlight' => "wasn't"],
                                                        ['wrong' => 'Did the classes be big?', 'right' => 'Were the classes big?', 'highlight' => 'Were'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Finished-time words', 'words' => ['yesterday', 'last year', 'two years ago', 'when I was ten', 'in 2019']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'E · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about <strong>your</strong> English story — then tell a picture story in the past, and soon say it out loud in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this right away — in the Picture Story next, and in AI Conversation #1.',
                                ],
                                'frequency_starters' => [
                                    'I started learning English when', 'My first English teacher', 'At school we',
                                    "At first I didn't", 'Last year I', 'The first time I spoke English with a real person,',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about how they learned English, using the past '
                                    .'simple correctly: -ed or the right irregular past form for finished events, did / '
                                    .'didn\'t + base verb in negatives and questions, and was / were for the past of be.',
                                'grammar_major_criteria' => 'a wrong past form ("goed", "teached"), a past form after '
                                    .'didn\'t / did ("didn\'t went", "did you studied"), a present verb for a finished past '
                                    .'event, "didn\'t be" instead of "wasn\'t", the sentence does not actually continue the '
                                    .'given starter, or it is not a genuine personal statement',
                                'grammar_context' => 'continues in the past simple (verb + -ed, an irregular past form, '
                                    .'didn\'t + base verb, or was / were) for the starter already given',
                                'quick_check' => [
                                    [
                                        'wrong' => 'I goed to a language school for two years.',
                                        'options' => ['I goed to a language school for two years.', 'I went to a language school for two years.', 'I gone to a language school for two years.'],
                                        'correct' => 1,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => "I didn't understood the teacher.",
                                        'options' => ["I didn't understood the teacher.", "I didn't understand the teacher.", 'I not understood the teacher.'],
                                        'correct' => 1,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'Did you studied English at school?',
                                        'options' => ['Did you study English at school?', 'Did you studied English at school?', 'You did study English at school?'],
                                        'correct' => 0,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => "I didn't be nervous in the exam.",
                                        'options' => ["I didn't be nervous in the exam.", "I weren't nervous in the exam.", "I wasn't nervous in the exam."],
                                        'correct' => 2,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['English', 'started', 'I', 'when', 'eight.', 'I', 'was'], 'answer' => 'I started English when I was eight.'],
                                    ['words' => ['the', 'understand', 'teacher.', "didn't", 'I'], 'answer' => "I didn't understand the teacher."],
                                    ['words' => ['study', 'you', 'English', 'Did', 'school?', 'at'], 'answer' => 'Did you study English at school?'],
                                ],
                            ],
                            [
                                'key' => 'story_sequence',
                                'label' => 'Picture Story',
                                // Back for M06 (see M01's note): a finished story is what
                                // Past Simple narrates, so the pictures come right after the
                                // lesson as fresh applied practice for the tense.
                                'duration_minutes' => 10,
                                'hook' => 'You just learned the past simple. Now tell how Mina finally learned English, one picture at a time.',
                                // Captions are ground truth for the AI feedback prompt only —
                                // never rendered to the learner (see the step's own docblock).
                                // Each query was checked against its real first Pexels result
                                // (square), so the picture matches its caption.
                                'sequence_images' => [
                                    ['image_query' => 'tired student studying books', 'caption' => 'She was tired of her grammar books', 'alt_description' => 'A young woman lying on the floor among open books'],
                                    ['image_query' => 'woman with headphones', 'caption' => 'She listened to English songs and podcasts every day', 'alt_description' => 'A smiling woman wearing white headphones'],
                                    ['image_query' => 'women talking coffee shop', 'caption' => 'She practised speaking with a conversation partner at a café', 'alt_description' => 'Two women talking at a café table'],
                                    ['image_query' => 'woman graduation diploma', 'caption' => 'She passed her exam and got her certificate', 'alt_description' => 'A smiling graduate holding up a certificate'],
                                ],
                                // Vocabulary in Use unit 92 (Time and sequence), D and E.
                                'sequencing_words' => ['First', 'Then', 'After a while', 'In the end'],
                                'narration_tense' => 'Past Simple',
                                'narration_kind' => 'a story that already happened',
                                'narration_example' => 'First, she was tired of her books...',
                                'narration_not' => 'present tense',
                            ],
                            [
                                // A real talk, one speaker, natural speed: POC English's "How
                                // I Went From Zero English to C2 (My Real Story)" — the first
                                // 6:40 of the original 18 minutes, cut to about 6 (a few asides
                                // and repeats left out), the creator's burned-in captions
                                // blurred out so the step's own caption toggle works.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Maddy skipped English class for four years to play video games — then one summer changed everything. Hear his story, then tell it with him.',
                                'source' => 'POC English — "How I Went From Zero English to C2 (My Real Story)"',
                                'video_url' => $shadowingVideoUrl,
                                // The video's own auto-captions, checked against the creator's
                                // burned-in captions, re-timed per sentence on Whisper's
                                // word timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M06', 'video_shadowing_segments'),
                                'topic_summary' => 'Maddy, the teacher behind POC English, tells how he learned English, '
                                    .'his third language. When he was eight, his parents sent him to a language school, '
                                    .'but for four years he skipped most classes to play games across the street. One '
                                    .'summer he stopped going and started watching Nicolas Cage films and listening to '
                                    .'Eminem — just for fun. He wrote down a whole film line by line and copied the songs. '
                                    .'When he went back to school, he was the best student in his class.',
                                'comprehension_check' => [
                                    ['statement' => 'Maddy started learning English when he was eight.', 'correct' => true],
                                    ['statement' => 'Maddy went to almost every class at the language school.', 'correct' => false],
                                    ['statement' => 'After that summer, Maddy was the best student in his class.', 'correct' => true],
                                ],
                                // Real phrases Maddy uses on camera.
                                'target_phrases' => [
                                    ['phrase' => 'back then', 'meaning' => 'at that time in the past'],
                                    ['phrase' => 'sign up', 'meaning' => 'to put your name on a list to join a class or course'],
                                    ['phrase' => 'turning point', 'meaning' => 'the moment when things start to change'],
                                    ['phrase' => 'role model', 'meaning' => 'a person you admire and want to be like'],
                                    ['phrase' => 'pay attention', 'meaning' => 'to listen or watch carefully'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words the
                                // speaker actually says. Chosen for this mission's grammar: a
                                // Did-question, did not / didn't + base verb, irregular went,
                                // regular started, and was. Bold marks naturally-stressed
                                // content words (rendered by <x-stress-marked-line>).
                                'shadow_lines' => [
                                    '**Did** you **know** that this **creature** is called a **fish** in **English**?',
                                    'I **did** **not** **know** the **alphabet** of **English**.',
                                    'For **four** **years**, I **went** to a **language** **school** following **traditional** **books**.',
                                    'That was **when** I **didn\'t** **learn** **English**.',
                                    'And I **started** **watching** Nicolas **Cage** **movies** and **listening** to **Eminem\'s** **songs**.',
                                    'This **time**, I was the **best** **student** in that **class**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M06', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Tell me about the last exam you took.', 'answer' => 'I took an exam in June. I revised for it for two weeks, and I passed.', 'words' => ['took an exam', 'revised for', 'passed']],
                            ['question' => 'Did you ever fail an exam? How did you feel?', 'answer' => 'Yes, I failed my driving test the first time. I felt terrible, but I passed the second time.', 'words' => ['failed', 'passed']],
                            ['question' => 'How did you revise for big exams at school?', 'answer' => 'I revised for exams at night. I made notes and asked my sister to test me.', 'words' => ['revised for', 'made']],
                            ['question' => 'When did you get a good grade and feel proud?', 'answer' => 'I got a good grade in maths last year. I did my best, and it worked.', 'words' => ['got a good grade', 'did my best']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the words for exams, the part of every English story nobody forgets.',
                                // Vocabulary in Use unit 33B (Examinations), our own sentences.
                                'story' => 'Last June I **took** the Cambridge **exam**. I **revised for** it every evening '
                                    .'for two months and **did my best**. I was sure I would **fail** — but I **passed**, and I '
                                    .'even **got a good grade**!',
                                'words' => [
                                    ['phrase' => 'take an exam', 'meaning' => 'to do an exam (not "make an exam")', 'meaning_fa' => 'امتحان دادن', 'pos' => 'verb phrase', 'synonym' => 'do an exam', 'example' => 'I took my driving exam last week.', 'image_query' => 'students taking exam hall', 'difficulty' => 'easy'],
                                    ['phrase' => 'pass', 'meaning' => 'to be successful in an exam', 'meaning_fa' => 'قبول شدن (در امتحان)', 'pos' => 'verb', 'synonym' => 'opposite: fail', 'example' => 'She passed her English exam with no problems.', 'difficulty' => 'easy'],
                                    ['phrase' => 'fail', 'meaning' => 'not to be successful in an exam', 'meaning_fa' => 'رد شدن، مردود شدن', 'pos' => 'verb', 'example' => 'He failed the test, but he can take it again in June.', 'difficulty' => 'easy'],
                                    ['phrase' => 'revise for', 'meaning' => 'to study again to get ready for an exam (British English)', 'meaning_fa' => 'برای امتحان مرور کردن', 'pos' => 'verb phrase', 'synonym' => 'study for', 'example' => 'I revised for the exam every evening.', 'difficulty' => 'medium'],
                                    ['phrase' => 'do your best', 'meaning' => 'to try as hard as you can', 'meaning_fa' => 'نهایت تلاشت را کردن', 'pos' => 'verb phrase', 'synonym' => 'try your best', 'example' => "Don't worry about the result — just do your best.", 'difficulty' => 'medium'],
                                    ['phrase' => 'get a good grade', 'meaning' => 'to get a high mark in an exam', 'meaning_fa' => 'نمره‌ی خوب گرفتن', 'pos' => 'verb phrase', 'synonym' => 'get a good mark', 'example' => 'He got a good grade in his final exam.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same story, one more time — now listen for "didn\'t", "moved" and "lived".',
                                'image_query' => 'paris apartment window view',
                                'shadow_lines' => [
                                    "I **didn't** **really** **understand** very **much** and I **mostly** **listened**.",
                                    'So, I **moved** to **Paris** and **lived** with the **French** **family**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M06', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about how you learned English — when '
                                    .'you started, one thing that didn\'t work and one thing that helped — using the new '
                                    .'vocabulary, without reading.',
                                'interview_questions' => [
                                    'When did you start learning English?',
                                    'Where did you learn it — at school, in a class, or on your own?',
                                    'What was your first English teacher like?',
                                    "What didn't work for you? Did you ever quit a class?",
                                    'What really helped you make progress?',
                                    'When did you first speak English with a real person? How did it go?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Reza — he quit English at sixteen. What made him try again?',
                                'passage_title' => 'Reza\'s second try',
                                'image_query' => 'young man studying laptop headphones',
                                // Written fresh for this app (EOS-009 §14) — reuses the Vocabulary
                                // Builder words in a new context, third person instead of first,
                                // and is told almost entirely in the past simple.
                                'passage' => 'Reza started learning English at school in Tabriz when he was twelve, but he '
                                    .'didn\'t enjoy it. The classes were big, the teacher only talked about grammar, and the '
                                    .'course just wasn\'t a good fit for him. At sixteen he quit. Two years later, a cousin '
                                    .'from Canada visited the family, and that week opened his eyes: Reza understood almost '
                                    .'nothing, and he felt embarrassed. So he decided to try again in a different way. He '
                                    .'found a teacher online and met her one-on-one twice a week. He watched cartoons with '
                                    .'English subtitles and picked up a lot of everyday words. Slowly he gained confidence. '
                                    .'He still had a strong accent, but he made a lot of progress in one year. Last spring he '
                                    .'took the IELTS exam. He revised for it every evening and did his best — and he passed. '
                                    .'He isn\'t fluent yet, but now he isn\'t afraid to speak.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'a good fit', 'type' => 'reused'],
                                    ['phrase' => 'quit', 'type' => 'reused'],
                                    ['phrase' => 'opened his eyes', 'type' => 'reused'],
                                    ['phrase' => 'one-on-one', 'type' => 'reused'],
                                    ['phrase' => 'picked up', 'type' => 'reused'],
                                    ['phrase' => 'gained confidence', 'type' => 'reused'],
                                    ['phrase' => 'accent', 'type' => 'reused'],
                                    ['phrase' => 'made a lot of progress', 'type' => 'reused'],
                                    ['phrase' => 'revised for', 'type' => 'reused'],
                                    ['phrase' => 'did his best', 'type' => 'reused'],
                                    ['phrase' => 'passed', 'type' => 'reused'],
                                    ['phrase' => 'fluent', 'type' => 'reused'],
                                    ['phrase' => 'embarrassed', 'type' => 'new', 'definition' => 'feeling silly or uncomfortable in front of other people', 'pos' => 'adjective', 'example' => 'I was embarrassed when I forgot my teacher\'s name.'],
                                    ['phrase' => 'subtitles', 'type' => 'new', 'definition' => 'the words of a film written at the bottom of the screen', 'pos' => 'noun (plural)', 'example' => 'I watch series with English subtitles.'],
                                ],
                                'topic_summary' => 'A short story about Reza, who did not enjoy English at school and quit at '
                                    .'sixteen. A visit from his cousin showed him how little he understood, so he tried '
                                    .'again: one-on-one lessons online, cartoons with subtitles, and lots of practice. He '
                                    .'made a lot of progress and passed the IELTS exam last spring.',
                                'comprehension_check' => [
                                    ['statement' => 'Reza enjoyed his English classes at school.', 'correct' => false, 'difficulty' => 'easy'],
                                    ['statement' => 'Reza\'s cousin\'s visit made him want to try English again.', 'correct' => true, 'difficulty' => 'medium'],
                                    ['statement' => 'Reza thinks he is fluent now.', 'correct' => false, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'Why did Reza quit English at sixteen?',
                                    'What did Reza do differently the second time?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Writing your English story often shows you how far you have already come.',
                                'title' => 'My English story',
                                'prompts' => [
                                    ['label' => 'When and where you started', 'image_query' => 'children classroom school desks'],
                                    ['label' => 'Your first teacher or class', 'image_query' => 'teacher writing on blackboard'],
                                    ['label' => "Something that didn't work", 'image_query' => 'tired student books desk'],
                                    ['label' => 'What really helped you', 'image_query' => 'woman watching movie headphones'],
                                    ['label' => 'A moment you felt proud', 'image_query' => 'happy student certificate'],
                                    ['label' => 'What you want to do next', 'image_query' => 'person studying laptop notebook'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['verb + -ed', 'went / took / had', "didn't + verb", 'was / were', '… ago', 'when I was…', 'First / Then / In the end'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe your first English class.', 'answer' => 'My first class was in a small room. The teacher was kind, but I didn\'t understand much.', 'words' => ['was', 'didn\'t understand']],
                            ['question' => 'What helped you most with your English?', 'answer' => 'Watching films helped me most. I picked up a lot of everyday phrases.', 'words' => ['helped', 'picked up']],
                            ['question' => 'Tell me about a time English was hard for you.', 'answer' => 'Once I tried to order food in English, but the waiter didn\'t understand my accent.', 'words' => ['tried', 'accent']],
                            ['question' => 'What did you do last week to practise English?', 'answer' => 'I listened to a podcast every day, and I made progress with my listening.', 'words' => ['listened', 'made progress']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'Forget your own story for a minute — what is happening in this English class?',
                                'image_query' => 'students learning language classroom',
                                'guiding_questions' => [
                                    'What is the teacher holding up, and why?',
                                    'How does the woman on the right feel? How do you know?',
                                    'What can you see on the table?',
                                    'Which flags can you see? What do they tell you about this class?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): a teacher in a blue
                                // shirt at the centre holding up a picture card, a smiling
                                // woman on the right, two girls with their backs to the camera
                                // on the left, books and papers on the table, and small US and
                                // UK flags in the bottom right. Changing the query or
                                // orientation invalidates these coordinates.
                                'hotspots' => [
                                    ['x' => 54, 'y' => 40, 'question_index' => 0],
                                    ['x' => 85, 'y' => 28, 'question_index' => 1],
                                    ['x' => 50, 'y' => 72, 'question_index' => 2],
                                    ['x' => 77, 'y' => 82, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Tell the story of how you learned English, from the very beginning.',
                                    'Talk about an English class or teacher you remember well.',
                                    'Describe a time you used English in real life. What happened?',
                                    'Talk about something you tried that didn\'t help your English.',
                                    'Describe an exam you took. How did you prepare, and how did it go?',
                                    'What was the hardest part of English for you at first, and what did you do about it?',
                                ],
                                'role_reversal_topic' => 'how people learned English',
                                'final_prompt' => 'Speak for 3 minutes without stopping about how you learned English.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'Regular past verbs (-ed)',
                                    'Irregular past verbs (went, took, had…)',
                                    "didn't + verb",
                                    'was / were',
                                    'When you started',
                                    'One thing that helped you',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Seeds M07 "Family" — the roadmap's 'used to' + Past Continuous.
     * M01/M06's 17-step layout: story_sequence stays, right after Grammar
     * in Context, because "what was everyone doing?" is exactly what the
     * Past Continuous narrates in a picture. The lesson follows Grammar in
     * Use units 6 (Past continuous) then 18 (used to); vocabulary days 2-3
     * follow Vocabulary in Use units 13A (Relatives) and 14C (Growing up),
     * all rewritten in our own words. Media the user picked, both from My
     * English Coach Dave: his 95-year-old Nanar on growing up in Leicester
     * (cut from 32 to about 6 minutes: birth, house and brothers, a school
     * story, a normal day and food back then) and his 69-year-old mum on
     * meeting his dad, him as a baby, and her own childhood (cut from 31
     * to about 6 minutes, the burned-in captions blurred out, and the top
     * definition boxes blurred while they are on screen).
     */
    private function seedM07(): void
    {
        $audioUrl = $this->publishMissionAsset('M07', 'My English Coach Dave - Life Stories with Nanar.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M07', 'My English Coach Dave - Life Stories with Mum.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M07', 'My English Coach Dave - Life Stories with Mum.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M07'],
            [
                'title' => 'Family',
                'module' => 'Family',
                'outcome' => 'I can talk about my family\'s past — what we used to do when I was a child, and what was happening at a moment I still remember.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'What was life like for your parents back in the day?', 'answer' => 'Back in the day my parents didn\'t have much money. They couldn\'t afford a car.', 'words' => ['back in the day', 'afford']],
                            ['question' => 'Who were you thick as thieves with as a child?', 'answer' => 'My cousin and I were thick as thieves. We did everything together.', 'words' => ['thick as thieves']],
                            ['question' => 'When did your parents tell you off?', 'answer' => 'My mum told me off when I came home late or forgot my homework.', 'words' => ['told me off']],
                            ['question' => 'Is it hard to pay rent where you live?', 'answer' => 'Yes, very hard. Many young people can\'t afford to pay rent in the city centre.', 'words' => ['pay rent', 'afford']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'What did your family use to do when you were small — and what was everyone doing in your favourite family photo?',
                                'image_query' => 'family together home smiling',
                                'ambient_video_query' => 'family looking at old photographs',
                                'warm_up_questions' => [
                                    'Who is in your family?',
                                    'Where did you grow up?',
                                    'What did your family use to do at the weekend?',
                                    'Who were you closest to when you were a child?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases Nanar uses about her childhood — you\'ll need them to tell your family\'s story.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => '**Back in the day**, my grandparents couldn\'t **afford** a house, so they **paid rent** '
                                    .'for two small rooms. My dad and his brother were **thick as thieves** — always laughing, '
                                    .'always in trouble — and Grandpa often **told them off**.',
                                'words' => [
                                    ['phrase' => 'back in the day', 'meaning' => 'in the past, a long time ago', 'meaning_fa' => 'قدیم‌ها، آن زمان‌ها', 'pos' => 'phrase (informal)', 'example' => 'Back in the day, nobody had a phone at home.', 'difficulty' => 'easy'],
                                    ['phrase' => 'afford', 'meaning' => 'to have enough money to buy or pay for something', 'meaning_fa' => 'از پس هزینه‌ی چیزی برآمدن، توان خرید داشتن', 'pos' => 'verb', 'example' => "We couldn't afford a car when I was a child.", 'difficulty' => 'medium'],
                                    ['phrase' => 'pay rent', 'meaning' => 'to pay money every month to live in a house that is not yours', 'meaning_fa' => 'اجاره دادن (اجاره‌ی خانه پرداختن)', 'pos' => 'verb phrase', 'example' => 'My parents paid rent for ten years before they bought a flat.', 'image_query' => 'apartment for rent sign', 'difficulty' => 'easy'],
                                    ['phrase' => 'thick as thieves', 'meaning' => 'very close friends who share secrets', 'meaning_fa' => 'خیلی صمیمی، جیک‌توجیک', 'pos' => 'idiom', 'example' => 'My sister and my cousin were thick as thieves at school.', 'difficulty' => 'hard'],
                                    ['phrase' => 'tell (someone) off', 'meaning' => 'to speak angrily to someone because they did something wrong', 'meaning_fa' => 'دعوا کردن، سرزنش کردن (کسی)', 'pos' => 'phrasal verb', 'example' => 'My mum told me off for coming home late.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Nanar is 95. Listen to what life used to be like in her house — and what she was doing when her teacher got angry.',
                                'source' => 'My English Coach Dave — "Learn English with a 95-Year-Old British Woman!"',
                                'image_query' => 'old family photographs vintage',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M07', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. Two speakers: Dave
                                // asks, his grandmother Nanar answers.
                                'listening_turns' => $this->shadowTimestampsFor('M07', 'listening_turns'),
                                // The five parts kept in the cut (2:26-2:49, 3:33-5:37,
                                // 7:18-8:00, 9:08-10:34 and 10:51-12:09 of the original),
                                // written from the video's own English captions and checked
                                // against a Whisper pass of the cut audio. Nanar's Leicester
                                // English ("it were", "everybody were") is kept as she says it.
                                'transcript' => [
                                    ['speaker' => 'Dave', 'text' => 'Um, so where were you born, and when were you born?'],
                                    ['speaker' => 'Nanar', 'text' => 'I was born in Bond Street Nursing Home on Easter Sunday, 1930!'],
                                    ['speaker' => 'Dave', 'text' => '1930! So how old does that make you?'],
                                    ['speaker' => 'Nanar', 'text' => 'What, now? 95, isn\'t it?'],
                                    ['speaker' => 'Dave', 'text' => '95, everyone. So, where did you grow up?'],
                                    ['speaker' => 'Nanar', 'text' => 'Down Bath Lane. It was called Bath Lane.'],
                                    ['speaker' => 'Dave', 'text' => 'Bath Lane. And which city is that?'],
                                    ['speaker' => 'Nanar', 'text' => 'Leicester City.'],
                                    ['speaker' => 'Dave', 'text' => 'Leicester. Okay, Bath Lane. Interesting.'],
                                    ['speaker' => 'Nanar', 'text' => 'It was called Bath Lane because there was a pub halfway up the road, and it was called the New Bath. And it was a pub, it used to be open till 10, 11 o\'clock at night.'],
                                    ['speaker' => 'Dave', 'text' => 'Oh, wow. And when you were young, Nanar, what was your house like when you were a child? What do you remember about the house when you were very young?'],
                                    ['speaker' => 'Nanar', 'text' => 'Well, we didn\'t… my mum hadn\'t got a house. She rented this house from this man who was ill. He slept in the front room all the time.'],
                                    ['speaker' => 'Dave', 'text' => 'What?'],
                                    ['speaker' => 'Nanar', 'text' => 'He slept in… the man who owned the house…'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah.'],
                                    ['speaker' => 'Nanar', 'text' => '…slept in the house. My mum used to pay rent, and we lived in the back two back rooms.'],
                                    ['speaker' => 'Dave', 'text' => 'Oh, okay. So it was a shared house.'],
                                    ['speaker' => 'Nanar', 'text' => 'Yes, I suppose so.'],
                                    ['speaker' => 'Dave', 'text' => 'Wow. And what do you remember about the house? Was it big, dark, cold?'],
                                    ['speaker' => 'Nanar', 'text' => 'No, it was a… well, I used to think it were a poor house, really. Although people lived like that then.'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah, yeah. Did you have any brothers or sisters?'],
                                    ['speaker' => 'Nanar', 'text' => 'I had a brother who died…'],
                                    ['speaker' => 'Dave', 'text' => 'When you were young?'],
                                    ['speaker' => 'Nanar', 'text' => 'He died, yeah. He wasn\'t very old. And I got a brother who was… what was Albert? I think Albert was about four or five years older than me. And then there was Ray. There was about 18 months between me and Ray.'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah. And you two were thick as thieves.'],
                                    ['speaker' => 'Nanar', 'text' => 'Yeah, as they say.'],
                                    ['speaker' => 'Dave', 'text' => 'As they say, yeah. Like two peas in a pod.'],
                                    ['speaker' => 'Nanar', 'text' => 'Yeah. \'Cause when… he used to smoke, and my dad used to tell him off. He used to hit him \'cause he used to smoke.'],
                                    ['speaker' => 'Dave', 'text' => 'What did you think of school when you were young?'],
                                    ['speaker' => 'Nanar', 'text' => 'Oh dear. Don\'t tell me about school.'],
                                    ['speaker' => 'Dave', 'text' => 'Why?'],
                                    ['speaker' => 'Nanar', 'text' => '\'Cause I was in this class and I was doing something, and the teacher made me… because he had a blackboard that swung round…'],
                                    ['speaker' => 'Dave', 'text' => 'Oh, yeah.'],
                                    ['speaker' => 'Nanar', 'text' => 'And the teacher made me stand behind the blackboard. But the thing was, I kept peeping round, and the class was in uproar.'],
                                    ['speaker' => 'Dave', 'text' => '\'Cause you were, like, laughing? You were, like, peeping like that.'],
                                    ['speaker' => 'Nanar', 'text' => 'Yeah. Everybody were laughing. And the teacher hit me. So my mum went up to school and hit the teacher.'],
                                    ['speaker' => 'Dave', 'text' => 'Really? How old were you, roughly?'],
                                    ['speaker' => 'Nanar', 'text' => 'Eight, nine.'],
                                    ['speaker' => 'Dave', 'text' => 'So, Nana, what was a normal day like when you were young?'],
                                    ['speaker' => 'Nanar', 'text' => 'We used to go to school, used to play in the street, play hopscotch.'],
                                    ['speaker' => 'Dave', 'text' => 'Do you know what hopscotch is?'],
                                    ['speaker' => 'Nanar', 'text' => 'Don\'t you?'],
                                    ['speaker' => 'Dave', 'text' => 'I do. Can you describe it?'],
                                    ['speaker' => 'Nanar', 'text' => 'Yeah. You got the slabs on the floor, and you do it: one, two, three, four, five, six, seven, eight, nine, ten. And you had a stone, and you had to throw the stone, and wherever the stone went, you had to hop on one leg to it.'],
                                    ['speaker' => 'Dave', 'text' => 'And, you know, back in the day, what did you have for breakfast? What was a normal breakfast?'],
                                    ['speaker' => 'Nanar', 'text' => 'Porridge.'],
                                    ['speaker' => 'Dave', 'text' => 'Porridge? Every day?'],
                                    ['speaker' => 'Nanar', 'text' => 'Yeah. My mum used to make it with milk.'],
                                    ['speaker' => 'Dave', 'text' => 'Oh, right. Porridge. And how did you cook food? Was there an oven?'],
                                    ['speaker' => 'Nanar', 'text' => 'Yes. Like… what do you call them? Stoves.'],
                                    ['speaker' => 'Dave', 'text' => 'A gas oven?'],
                                    ['speaker' => 'Nanar', 'text' => 'Gas oven, yeah.'],
                                    ['speaker' => 'Dave', 'text' => 'So you had an oven in the house. You didn\'t use coal?'],
                                    ['speaker' => 'Nanar', 'text' => 'We did have coal fires, but I don\'t think she used to, no. She used to use the oven.'],
                                    ['speaker' => 'Dave', 'text' => 'And this sounds silly, but when you were young, did you have electricity in the house?'],
                                    ['speaker' => 'Nanar', 'text' => 'No, it was all gas.'],
                                    ['speaker' => 'Dave', 'text' => 'No electricity?'],
                                    ['speaker' => 'Nanar', 'text' => 'No. Gas.'],
                                    ['speaker' => 'Dave', 'text' => 'Where did people buy food when you were young? Were there big supermarkets like Tesco\'s and Sainsbury\'s?'],
                                    ['speaker' => 'Nanar', 'text' => 'No, no. We used to go to Worthington\'s quite a lot. It was sort of a shop on the corner or something like that.'],
                                    ['speaker' => 'Dave', 'text' => 'Okay, so it was small, local shops.'],
                                    ['speaker' => 'Nanar', 'text' => 'Yes, yeah.'],
                                    ['speaker' => 'Dave', 'text' => 'Okay. But were there supermarkets? I know you didn\'t go to supermarkets.'],
                                    ['speaker' => 'Nanar', 'text' => 'No, no. We had open market.'],
                                    ['speaker' => 'Dave', 'text' => 'Oh, like a real market?'],
                                    ['speaker' => 'Nanar', 'text' => 'A real market, yes.'],
                                    ['speaker' => 'Dave', 'text' => 'Wow. Here\'s a question for you, Nanar. What was your favourite meal as a child?'],
                                    ['speaker' => 'Nanar', 'text' => 'Difficult. It was difficult, because we didn\'t have the money to buy expensive food or nice food. You sort of had to buy what you could afford. But I mean, we used to get chips in the little bag.'],
                                    ['speaker' => 'Dave', 'text' => 'How much for?'],
                                    ['speaker' => 'Nanar', 'text' => 'Well, I…'],
                                    ['speaker' => 'Dave', 'text' => 'I don\'t know the old money, everyone.'],
                                    ['speaker' => 'Nanar', 'text' => 'Tuppence.'],
                                    ['speaker' => 'Dave', 'text' => 'Tuppence. That means nothing to me.'],
                                    ['speaker' => 'Nanar', 'text' => 'Bag of chips for tuppence, and they put salt and vinegar on for you as well.'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript (tell him off and could afford as
                                // the forms Nanar actually says).
                                'target_phrases' => [
                                    [
                                        'phrase' => 'pay rent', 'meaning' => 'pay money every month to live in a house',
                                        'gap_before' => '…slept in the house. My mum used to ',
                                        'gap_after' => ', and we lived in the back two back rooms.',
                                    ],
                                    [
                                        'phrase' => 'thick as thieves', 'meaning' => 'very close friends',
                                        'gap_before' => 'Yeah. And you two were ',
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'tell him off', 'meaning' => 'speak angrily to him for doing something wrong',
                                        'gap_before' => 'he used to smoke, and my dad used to ',
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'back in the day', 'meaning' => 'in the past, long ago',
                                        'gap_before' => 'And, you know, ',
                                        'gap_after' => ', what did you have for breakfast?',
                                    ],
                                    [
                                        'phrase' => 'afford', 'meaning' => 'have enough money for',
                                        'gap_before' => 'You sort of had to buy what you could ',
                                        'gap_after' => '.',
                                    ],
                                ],
                                'topic_summary' => 'Dave talks with his grandmother Nanar, who was born in 1930 and grew up '
                                    .'on Bath Lane in Leicester. Her mum used to pay rent for two rooms in a shared house, '
                                    .'and her dad used to tell off her brother Ray for smoking. She tells a school story — '
                                    .'she was doing something silly, the teacher hit her, and her mum hit the teacher. Then '
                                    .'she describes a normal day back then: playing hopscotch in the street, porridge for '
                                    .'breakfast, a gas oven, no electricity, small local shops, and chips for tuppence.',
                                'comprehension_check' => [
                                    ['statement' => 'Nanar was born in 1930.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'When Nanar was a child, her family had their own house.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'After the teacher hit Nanar, her mum went to the school and hit the teacher.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'What did Nanar usually have for breakfast?',
                                    'options' => ['Bacon and eggs', 'Porridge', 'Chips'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'What did you use to do with your relatives when you were little?', 'answer' => 'We used to visit my relatives every Nowruz. We played in the garden all day.', 'words' => ['used to', 'relatives']],
                            ['question' => 'Are you an only child? What was that like?', 'answer' => 'No, I\'m not an only child. My elder brother used to take me to school.', 'words' => ['only child', 'elder', 'used to']],
                            ['question' => 'What were you doing at eight o\'clock last night?', 'answer' => 'At eight o\'clock I was having dinner with my close family.', 'words' => ['was having', 'close family']],
                            ['question' => 'Did you get on well with your cousins as a child?', 'answer' => 'I got on well with my cousins, the twins. We used to play football every Friday.', 'words' => ['got on well with', 'twins', 'used to']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'The words for the people in your family tree — and how well you get on with them.',
                                // Vocabulary in Use unit 13A ("Relatives"), our own sentences.
                                'story' => 'I come from a **close family**. My mum is an **only child**, but my dad has lots of '
                                    .'**relatives**. I have an **elder** brother and two little sisters who are **twins**. I '
                                    .'**get on well with** all of them — most of the time!',
                                'words' => [
                                    ['phrase' => 'relatives', 'meaning' => 'the people in your family, like aunts, uncles and cousins', 'meaning_fa' => 'اقوام، خویشاوندان', 'pos' => 'noun (plural)', 'synonym' => 'relations', 'example' => 'All our relatives come to our house for the New Year.', 'difficulty' => 'easy'],
                                    ['phrase' => 'close family', 'meaning' => 'a family who like each other and spend a lot of time together', 'meaning_fa' => 'خانواده‌ی صمیمی و نزدیک به هم', 'pos' => 'noun phrase', 'example' => "We're a close family — we have dinner together every Friday.", 'image_query' => 'happy family hug', 'difficulty' => 'easy'],
                                    ['phrase' => 'only child', 'meaning' => 'a person who has no brothers or sisters', 'meaning_fa' => 'تک‌فرزند', 'pos' => 'noun phrase', 'example' => "I'm an only child, so my cousins are like my sisters.", 'difficulty' => 'easy'],
                                    ['phrase' => 'elder', 'meaning' => 'older (used about brothers, sisters and children in a family)', 'meaning_fa' => 'بزرگ‌تر (برادر یا خواهر)', 'pos' => 'adjective', 'synonym' => 'older', 'example' => 'My elder sister lives in Shiraz.', 'difficulty' => 'medium'],
                                    ['phrase' => 'twins', 'meaning' => 'two children born to the same mother at the same time', 'meaning_fa' => 'دوقلوها', 'pos' => 'noun (plural)', 'example' => 'The twins look the same, but they are very different.', 'image_query' => 'twin sisters', 'difficulty' => 'easy'],
                                    ['phrase' => 'get on well with', 'meaning' => 'to have a friendly relationship with someone', 'meaning_fa' => 'با کسی خوب کنار آمدن، رابطه‌ی خوب داشتن', 'pos' => 'phrasal verb', 'example' => 'I get on well with my uncle — we like the same football team.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — this time copy how Nanar says "used to" and "was doing".',
                                'image_query' => 'old school classroom blackboard',
                                'shadow_lines' => [
                                    'My **mum** **used** to **pay** **rent**.',
                                    'I was in this **class** and I was **doing** **something**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M07', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 15,
                                'hook' => 'Two ways to go back in time: what was happening at one moment, and what you did again and again.',
                                'focus' => "Past Continuous + 'used to'",
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use units 6 and 18: was / were + -ing,
                                // past continuous vs past simple with when / while, then
                                // used to, and its negative and question.
                                'lesson' => [
                                    'intro' => 'Two past forms: was / were + -ing for what was happening, and used to for what you often did but don\'t do now.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · was / were + -ing',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Use <strong>was / were + -ing</strong> for something in the middle of happening at a time in the past: <strong>At 8 o\'clock I was having dinner.</strong>',
                                                    'fa' => 'برای کاری که در یک لحظه از گذشته در حال انجام بود (هنوز تمام نشده بود) از was / were + ing استفاده کن: At 8 o\'clock I was having dinner.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Past Continuous',
                                                    'parts' => [
                                                        ['text' => 'My mum', 'caption' => 'subject'],
                                                        ['text' => 'was', 'caption' => 'was / were', 'tone' => 'accent'],
                                                        ['text' => 'cooking', 'caption' => 'verb + -ing'],
                                                        ['text' => 'at 7 o\'clock', 'caption' => 'when'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'We was playing in the street.', 'right' => 'We were playing in the street.', 'highlight' => 'were'],
                                                        ['wrong' => 'I was play with my cousins.', 'right' => 'I was playing with my cousins.', 'highlight' => 'playing'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'At 10 last night, my dad was watching the news.',
                                                            'The children were sleeping when we got home.',
                                                            'What were you doing at this time yesterday?',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · was doing or did? when / while',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'The longer action is <strong>was / were + -ing</strong>; the short one that happens in the middle is past simple: <strong>I was cooking when the phone rang.</strong>',
                                                    'fa' => 'کار طولانی‌تر که در جریان بود را با was / were + ing بگو و کار کوتاهی که وسط آن اتفاق افتاد را با گذشته‌ی ساده: I was cooking when the phone rang. معمولاً while قبل از کار طولانی و when قبل از کار کوتاه می‌آید.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Something happened in the middle',
                                                    'parts' => [
                                                        ['text' => 'While we were eating,', 'caption' => 'was / were + -ing', 'tone' => 'accent'],
                                                        ['text' => 'my uncle', 'caption' => 'subject'],
                                                        ['text' => 'arrived', 'caption' => 'past simple', 'tone' => 'success'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Sara',
                                                    'wrong' => 'I watched TV when my brother was calling me.',
                                                    'right' => 'I was watching TV when my brother called me.',
                                                    'explanation' => 'Watching TV was the long action, so it takes was + -ing. The call was short — past simple.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'My grandma was cooking when we arrived.',
                                                            'I met my best friend while I was living in Tabriz.',
                                                            'It was raining when we left the house.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · used to + verb',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>used to + verb</strong> = you did it often in the past, or it was true then, but not now: <strong>We used to live near the sea.</strong>',
                                                    'fa' => 'used to + فعل ساده یعنی کاری را در گذشته بارها انجام می‌دادی یا چیزی در گذشته درست بود، ولی الان دیگر نه: We used to live near the sea. دقت کن: use to برای حال وجود ندارد؛ برای حال از Present Simple استفاده کن.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'used to',
                                                    'parts' => [
                                                        ['text' => 'My grandpa', 'caption' => 'subject'],
                                                        ['text' => 'used to', 'caption' => 'used to', 'tone' => 'accent'],
                                                        ['text' => 'tell', 'caption' => 'base verb'],
                                                        ['text' => 'us stories', 'caption' => 'the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I use to visit my grandma every summer.', 'right' => 'I used to visit my grandma every summer.', 'highlight' => 'used to'],
                                                        ['wrong' => 'We used to playing in the street.', 'right' => 'We used to play in the street.', 'highlight' => 'play'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'pairs',
                                                    'pairs' => [
                                                        ['left' => 'then: we used to live in a village', 'right' => 'now: we live in a city'],
                                                        ['left' => 'then: there used to be a shop on the corner', 'right' => 'now: there is a supermarket'],
                                                        ['left' => 'then: I used to hate vegetables', 'right' => 'now: I love them'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · didn\'t use to and Did … use to?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'With <strong>did / didn\'t</strong>, write <strong>use to</strong> (no -d): <strong>I didn\'t use to like fish.</strong> <strong>Did you use to walk to school?</strong>',
                                                    'fa' => 'در جمله‌ی منفی و سؤالی، did نشانه‌ی گذشته است، پس used به use برمی‌گردد: I didn\'t use to، Did you use to …? (مثل didn\'t go، نه didn\'t went).',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Question',
                                                    'parts' => [
                                                        ['text' => 'Did', 'caption' => 'did', 'tone' => 'accent'],
                                                        ['text' => 'you', 'caption' => 'subject'],
                                                        ['text' => 'use to', 'caption' => 'use to (no -d)'],
                                                        ['text' => 'play outside?', 'caption' => 'base verb + the rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'Did you used to have a pet?', 'right' => 'Did you use to have a pet?', 'highlight' => 'use to'],
                                                        ['wrong' => "We didn't used to have a car.", 'right' => "We didn't use to have a car.", 'highlight' => 'use to'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'was / were + -ing: one moment', 'words' => ['at 8 o\'clock', 'when the phone rang', 'while we were eating']],
                                                        ['label' => 'used to: again and again', 'words' => ['every summer', 'on Sundays', 'when I was a child', 'back in the day']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'E · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about <strong>your</strong> family — then tell a picture story about one busy Sunday, and soon say it out loud in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this right away — in the Picture Story next, and in AI Conversation #1.',
                                ],
                                'frequency_starters' => [
                                    'When I was a child, my family used to', 'My grandparents used to', "I didn't use to",
                                    'At 8 o\'clock last night, I was', 'When my parents met, they were', 'While my mum was cooking,',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about their family\'s past, using the past '
                                    .'continuous (was / were + -ing) for something in progress at a past moment, the past '
                                    .'simple for a short action in the middle of it, and used to + base verb (didn\'t use to '
                                    .'/ did … use to in negatives and questions) for past habits and states that are not true now.',
                                'grammar_major_criteria' => 'a wrong form of be ("we was playing"), a missing -ing after '
                                    .'was / were ("I was play"), "use to" for a past habit in an affirmative sentence, "used to" '
                                    .'after did / didn\'t, used to + -ing ("used to playing"), the past continuous for the short '
                                    .'action that interrupts ("when my brother was calling"), the sentence does not actually '
                                    .'continue the given starter, or it is not a genuine personal statement',
                                'grammar_context' => 'continues with the past continuous (was / were + -ing), used to + '
                                    .'base verb, or didn\'t use to + base verb, as the starter already given needs',
                                'quick_check' => [
                                    [
                                        'wrong' => 'I was play football when it started to rain.',
                                        'options' => ['I was play football when it started to rain.', 'I was playing football when it started to rain.', 'I were playing football when it started to rain.'],
                                        'correct' => 1,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'I use to visit my grandparents every summer.',
                                        'options' => ['I used to visit my grandparents every summer.', 'I use to visit my grandparents every summer.', 'I was used to visit my grandparents every summer.'],
                                        'correct' => 0,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'My dad cooked when my uncle was arriving.',
                                        'options' => ['My dad cooked when my uncle was arriving.', 'My dad was cooking when my uncle was arriving.', 'My dad was cooking when my uncle arrived.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'Did you used to walk to school?',
                                        'options' => ['Did you used to walk to school?', 'Did you use to walk to school?', 'Were you use to walk to school?'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['when', 'watching', 'I', 'called.', 'TV', 'you', 'was'], 'answer' => 'I was watching TV when you called.'],
                                    ['words' => ['the', 'used', 'We', 'sea.', 'live', 'to', 'near'], 'answer' => 'We used to live near the sea.'],
                                    ['words' => ['use', 'outside?', 'you', 'Did', 'play', 'to'], 'answer' => 'Did you use to play outside?'],
                                ],
                            ],
                            [
                                'key' => 'story_sequence',
                                'label' => 'Picture Story',
                                // Kept for M07 (see M01's and M06's notes): "what was everyone
                                // doing?" is what the Past Continuous describes, so the pictures
                                // come right after the lesson as fresh applied practice.
                                'duration_minutes' => 10,
                                'hook' => 'You just learned was / were + -ing. Now say what everyone was doing at Grandma\'s house last Sunday, one picture at a time.',
                                // Captions are ground truth for the AI feedback prompt only —
                                // never rendered to the learner (see the step's own docblock).
                                // Each query was checked against its real first Pexels result
                                // (square), so the picture matches its caption.
                                'sequence_images' => [
                                    ['image_query' => 'grandmother baking in kitchen', 'caption' => 'Grandma was making bread in the kitchen', 'alt_description' => 'Old hands kneading dough on a table'],
                                    ['image_query' => 'grandfather reading newspaper', 'caption' => 'Grandpa was reading his newspaper by the window', 'alt_description' => 'An old man reading a newspaper by a window with the sea outside'],
                                    ['image_query' => 'kids playing in backyard', 'caption' => 'Little Leo was playing catch outside', 'alt_description' => 'A small boy playing catch with a ball in front of a house'],
                                    ['image_query' => 'family lunch table grandparents', 'caption' => 'In the end, the whole family was having lunch together outside', 'alt_description' => 'Grandparents and grandchildren having a meal at a table outside'],
                                ],
                                // Vocabulary in Use unit 92 (Time and sequence), D and E.
                                'sequencing_words' => ['First', 'Meanwhile', 'At the same time', 'In the end'],
                                'narration_tense' => 'Past Continuous',
                                'narration_kind' => 'what everyone was doing at one moment in the past',
                                'narration_example' => 'First, Grandma was making bread...',
                                'narration_not' => 'present or past simple',
                            ],
                            [
                                // A real talk between two real people, natural speed: My English
                                // Coach Dave's "Learn English with My 69-Year-Old Mum" — four
                                // parts of the 31-minute walk, cut to about 6 minutes, the
                                // creator's burned-in captions blurred out (and his top word
                                // boxes while they show) so the step's own caption toggle works.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Dave\'s dad was trying to impress his mum with a very hot curry. Hear her story — and what she used to do as a child — then say it with them.',
                                'source' => 'My English Coach Dave — "Learn English with My 69-Year-Old Mum"',
                                'video_url' => $shadowingVideoUrl,
                                // The video's auto-captions, checked against the creator's
                                // burned-in captions, re-timed per sentence on Whisper's word
                                // timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M07', 'video_shadowing_segments'),
                                'topic_summary' => 'Dave walks in the countryside with his mum and asks about the family. '
                                    .'She met his dad at a tennis club; on their first date he ate a very hot curry — he was '
                                    .'trying to impress her. As a baby, Dave made up his own language. Then she remembers her '
                                    .'own childhood: playing in the street, losing touch with her best friend after the 11-plus '
                                    .'exam, the meal she used to have most Saturdays, feeling like the poor kid at a posh school, '
                                    .'and the tennis lessons she and her friends used to skip.',
                                'comprehension_check' => [
                                    ['statement' => 'Dave\'s mum and dad met at a tennis club.', 'correct' => true],
                                    ['statement' => 'Dave\'s mum went to the same secondary school as her best friend Anne.', 'correct' => false],
                                    ['statement' => 'As a child, Dave\'s mum loved fish fingers, peas and mashed potatoes.', 'correct' => true],
                                ],
                                // Real phrases Dave and his mum use on camera.
                                'target_phrases' => [
                                    ['phrase' => 'get together', 'meaning' => 'to start a romantic relationship'],
                                    ['phrase' => 'the rest is history', 'meaning' => 'everybody knows what happened after that'],
                                    ['phrase' => 'show off', 'meaning' => 'to do something to make people notice and admire you'],
                                    ['phrase' => 'make up', 'meaning' => 'to invent something, like a story or a language'],
                                    ['phrase' => 'lose touch', 'meaning' => 'to stop seeing or talking to someone over time'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words the
                                // speakers actually say. Chosen for this mission's grammar: a
                                // past continuous question and answer, were saying, then used
                                // to for an old habit three times. Bold marks naturally-stressed
                                // content words (rendered by <x-stress-marked-line>).
                                'shadow_lines' => [
                                    'So, was he **trying** to **show** **off**?',
                                    'I **think** he was **trying** to **impress** me.',
                                    '**Only** **I** could **understand** what you were **saying**.',
                                    'It was an **exam** that we **used** to **have**.',
                                    'But I **used** to like to make a **volcano** of my **mashed** **potatoes**.',
                                    'We **used** to just **knock** the **ball** out of **court**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M07', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Where did you grow up?', 'answer' => 'I grew up in a small town near Tabriz. I was brought up by my grandparents.', 'words' => ['grew up', 'was brought up']],
                            ['question' => 'Were your parents strict?', 'answer' => 'Yes, they were quite strict. I wasn\'t allowed to stay out late.', 'words' => ['strict', 'wasn\'t allowed to', 'stay out late']],
                            ['question' => 'What did your parents let you do as a teenager?', 'answer' => 'They used to let me go to the cinema with friends, but not travel alone.', 'words' => ['used to', 'let me']],
                            ['question' => 'What were you doing when you heard some big family news?', 'answer' => 'I was cooking when my sister called. She was getting married!', 'words' => ['was cooking', 'was getting']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the words for growing up, and for what your parents let you do.',
                                // Vocabulary in Use unit 14C ("Past and present"), our own sentences.
                                'story' => 'My mum **grew up** in a small town. She **was brought up** by her grandparents, '
                                    .'and they were very **strict**: she **wasn\'t allowed to** go to parties. Years later, my '
                                    .'parents **let** me **stay out late** — very different!',
                                'words' => [
                                    ['phrase' => 'grow up', 'meaning' => 'to change from a child into an adult; to spend your childhood somewhere', 'meaning_fa' => 'بزرگ شدن', 'pos' => 'phrasal verb (grow – grew – grown)', 'example' => 'I grew up in Isfahan, near my grandparents.', 'difficulty' => 'easy'],
                                    ['phrase' => 'be brought up', 'meaning' => 'to be looked after by someone until you are an adult', 'meaning_fa' => 'تربیت شدن، بزرگ شدن (زیر دست کسی)', 'pos' => 'verb phrase (passive)', 'example' => 'He was brought up by his aunt after his parents died.', 'difficulty' => 'hard'],
                                    ['phrase' => 'strict', 'meaning' => 'making people follow rules and behave well', 'meaning_fa' => 'سخت‌گیر', 'pos' => 'adjective', 'example' => 'My dad was strict about homework.', 'image_query' => 'strict father pointing finger', 'difficulty' => 'easy'],
                                    ['phrase' => 'be allowed to', 'meaning' => 'to have permission to do something', 'meaning_fa' => 'اجازه داشتن', 'pos' => 'verb phrase', 'example' => "I wasn't allowed to watch TV on school nights.", 'difficulty' => 'medium'],
                                    ['phrase' => 'let (someone) do', 'meaning' => 'to allow someone to do something (more informal than allow)', 'meaning_fa' => 'اجازه دادن (به کسی برای کاری)', 'pos' => 'verb (let – let – let)', 'synonym' => 'allow (someone) to do', 'example' => 'My parents let me drive their car when I was eighteen.', 'difficulty' => 'medium'],
                                    ['phrase' => 'stay out late', 'meaning' => 'not to come home until late at night', 'meaning_fa' => 'تا دیروقت بیرون ماندن', 'pos' => 'verb phrase', 'example' => 'Teenagers often want to stay out late with their friends.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — now listen for "used to make" and "used to get".',
                                'image_query' => 'bowl of porridge breakfast',
                                'shadow_lines' => [
                                    'My **mum** **used** to **make** it with **milk**.',
                                    'We **used** to **get** **chips** in the **little** **bag**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M07', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your family when you were a child — '
                                    .'where you grew up, two things your family used to do, and one moment you remember (what '
                                    .'was everyone doing?) — using the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'Where did you grow up? What was your house like?',
                                    'Do you come from a big family or a small family?',
                                    'What did your family use to do at the weekend?',
                                    'Who did you get on well with when you were a child?',
                                    'Were your parents strict? What weren\'t you allowed to do?',
                                    'Think of a family photo you like. What was everyone doing in it?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Leila — her grandmother\'s house used to be the centre of the whole family. What happened to it?',
                                'passage_title' => 'Leila\'s Friday house',
                                'image_query' => 'grandmother and granddaughter garden',
                                // Written fresh for this app (EOS-009 §14) — reuses the Vocabulary
                                // Builder words in a new context, third person instead of first,
                                // and is told mostly in used to and the past continuous.
                                'passage' => 'Leila grew up in Shiraz in a close family. She was an only child, but she never '
                                    .'felt alone, because every Friday all her relatives used to come to her grandmother\'s '
                                    .'house. Back in the day, her grandparents couldn\'t afford a big house — they paid rent '
                                    .'for a small one with a garden — but there was always room for everyone. Her grandmother '
                                    .'was quite strict: the children weren\'t allowed to eat before the adults. Leila and her '
                                    .'elder cousin Sima were thick as thieves, and Grandpa often told them off for climbing the '
                                    .'orange tree. Leila remembers one Friday very well. Her uncles were talking in the garden, '
                                    .'her grandmother was cooking rice, and the twins were sleeping on the sofa, when suddenly '
                                    .'the lights went out. Nobody was worried. Grandpa lit some candles and told stories until '
                                    .'midnight, and the parents let the children stay out late in the garden. Today the house '
                                    .'belongs to someone else, but Leila still gets on well with all her cousins.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'grew up', 'type' => 'reused'],
                                    ['phrase' => 'close family', 'type' => 'reused'],
                                    ['phrase' => 'only child', 'type' => 'reused'],
                                    ['phrase' => 'relatives', 'type' => 'reused'],
                                    ['phrase' => 'Back in the day', 'type' => 'reused'],
                                    ['phrase' => 'afford', 'type' => 'reused'],
                                    ['phrase' => 'paid rent', 'type' => 'reused'],
                                    ['phrase' => 'strict', 'type' => 'reused'],
                                    ['phrase' => 'weren\'t allowed to', 'type' => 'reused'],
                                    ['phrase' => 'elder', 'type' => 'reused'],
                                    ['phrase' => 'thick as thieves', 'type' => 'reused'],
                                    ['phrase' => 'told them off', 'type' => 'reused'],
                                    ['phrase' => 'twins', 'type' => 'reused'],
                                    ['phrase' => 'stay out late', 'type' => 'reused'],
                                    ['phrase' => 'gets on well with', 'type' => 'reused'],
                                    ['phrase' => 'lit', 'type' => 'new', 'definition' => 'started a fire or a light burning (past of light)', 'pos' => 'verb (light – lit – lit)', 'example' => 'We lit a candle when the power went off.'],
                                    ['phrase' => 'belongs to', 'type' => 'new', 'definition' => 'is the property of someone', 'pos' => 'verb phrase', 'example' => 'This old watch belongs to my grandfather.'],
                                ],
                                'topic_summary' => 'A short story about Leila, an only child from a close family in Shiraz. '
                                    .'Every Friday her relatives used to meet at her grandmother\'s small rented house. She '
                                    .'remembers one evening when everyone was busy and the lights suddenly went out, and the '
                                    .'night turned into stories by candlelight.',
                                'comprehension_check' => [
                                    ['statement' => 'Leila has two brothers.', 'correct' => false, 'difficulty' => 'easy'],
                                    ['statement' => 'Leila\'s grandparents did not own their house.', 'correct' => true, 'difficulty' => 'medium'],
                                    ['statement' => 'When the lights went out, the family was scared and went home.', 'correct' => false, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'What did Leila\'s relatives use to do every Friday?',
                                    'What were the people in the house doing when the lights went out?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Writing about your family\'s past is a way to keep it — and a story your own children may read one day.',
                                'title' => 'My family, then and now',
                                'prompts' => [
                                    ['label' => 'Where you grew up', 'image_query' => 'old neighborhood street houses'],
                                    ['label' => 'Who was in your family', 'image_query' => 'family portrait vintage'],
                                    ['label' => 'Something your family used to do', 'image_query' => 'family picnic park'],
                                    ['label' => 'Something you weren\'t allowed to do', 'image_query' => 'child looking out window'],
                                    ['label' => 'One moment you remember: what was everyone doing?', 'image_query' => 'family dinner table evening'],
                                    ['label' => 'How your family is different now', 'image_query' => 'family video call laptop'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['used to + verb', "didn't use to", 'was / were + -ing', 'when / while', 'back in the day', 'when I was a child', 'but now'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe a family photo from your childhood.', 'answer' => 'In the photo we were sitting in the garden, and my grandfather was holding me.', 'words' => ['were sitting', 'was holding']],
                            ['question' => 'What did your family use to do at the weekend?', 'answer' => 'We used to go to the mountains on Fridays and have a picnic.', 'words' => ['used to']],
                            ['question' => 'How is family life different now from when you were a child?', 'answer' => 'When I was a child, we used to eat together every night. Now everyone is busy.', 'words' => ['used to']],
                            ['question' => 'Tell me about a time someone told you off.', 'answer' => 'I was playing football in the house when I broke a window. My dad told me off.', 'words' => ['was playing', 'told me off']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'Imagine this photo was taken last night when you walked in. What was everyone doing?',
                                'image_query' => 'big family dinner table grandparents',
                                'guiding_questions' => [
                                    'What was the grandfather doing when you walked in?',
                                    'What was the grandmother holding?',
                                    'What food was on the table?',
                                    'Who was the young father looking at? What do you think he was saying?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): a family of three
                                // generations at a wooden kitchen table — a grey-haired grandfather
                                // on the left, the grandmother next to him raising a glass, a
                                // little boy in orange in the middle, a young father turning to a
                                // curly-haired girl on the right, and plates, fruit and a roast
                                // on the table. Changing the query or orientation invalidates
                                // these coordinates.
                                'hotspots' => [
                                    ['x' => 22, 'y' => 42, 'question_index' => 0],
                                    ['x' => 34, 'y' => 62, 'question_index' => 1],
                                    ['x' => 52, 'y' => 82, 'question_index' => 2],
                                    ['x' => 76, 'y' => 44, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Tell me about your family when you were a child. Where did you live, and what did you use to do together?',
                                    'Describe a family member you used to spend a lot of time with.',
                                    'Talk about a family celebration you remember. What was everyone doing?',
                                    'What were the rules in your house when you were a child? Were your parents strict?',
                                    'How is your family life different now from when you were small?',
                                    'Tell a funny or surprising story from your family\'s past.',
                                ],
                                'role_reversal_topic' => 'family life in the past',
                                'final_prompt' => 'Speak for 3 minutes without stopping about your family when you were a child.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'used to + verb',
                                    "didn't use to",
                                    'was / were + -ing',
                                    'when / while',
                                    'Where you grew up',
                                    'One family moment you remember',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Seeds M08 "Friends" — the roadmap's Comparatives & Superlatives.
     * M05's 16-step layout (no story_sequence: comparing is not a story
     * told in order, so the comparisons are practised in Picture
     * Description and the two AI conversations instead). The lesson follows
     * Grammar in Use units 105-108 in order: -er / more, much / a bit +
     * comparative, as … as, then the -est / the most. Vocabulary days 2-3
     * follow Vocabulary in Use unit 11 (Describing character: A-B, then C
     * "Describing a friend"), all rewritten in our own words — unit 13B was
     * already taught in M02. Media the user picked, both from My English
     * Coach Dave: Dave and his best friend Elliot on how they met, stayed
     * close and whether making friends gets harder (cut from 46 to about 6
     * minutes) and Dave's dad Ian with his friend of nearly 60 years, Tony,
     * playing "Who is the most…?" (cut from 46 to about 6 minutes, the
     * burned-in captions blurred out, and the top definition boxes blurred
     * while they are on screen).
     */
    private function seedM08(): void
    {
        $audioUrl = $this->publishMissionAsset('M08', 'My English Coach Dave - Friendship with Elliot.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M08', 'My English Coach Dave - Best Friends for 60 Years.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M08', 'My English Coach Dave - Best Friends for 60 Years.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M08'],
            [
                'title' => 'Friends',
                'module' => 'Friends',
                'outcome' => 'I can describe my friends and compare us — who is funnier, who is more patient, who is the most reliable — and say how a friendship has changed.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Do you have a lifelong friend?', 'answer' => 'Yes, Amir is a lifelong friend. We met when we were six.', 'words' => ['a lifelong friend']],
                            ['question' => 'Why do friends sometimes drift apart?', 'answer' => 'Friends drift apart when they move away or get busy with work.', 'words' => ['drift apart']],
                            ['question' => 'How do you stay close to friends who are busy?', 'answer' => 'We stay close because we both put the effort in. We call every Sunday.', 'words' => ['stay close', 'put the effort in']],
                            ['question' => 'Are you introverted or outgoing?', 'answer' => 'I\'m a bit introverted. I like one or two good friends, not a big group.', 'words' => ['introverted']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Who is your oldest friend — and which of you is funnier? Could you explain why in English?',
                                'image_query' => 'friends group laughing outdoors',
                                'ambient_video_query' => 'friends walking together park',
                                'warm_up_questions' => [
                                    'Who is your best friend?',
                                    'How did you meet?',
                                    'What is your friend like?',
                                    'Who is funnier — you or your best friend?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases Dave and Elliot use about their friendship — you\'ll need them to talk about yours.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'Sam and I met at school, and now we\'re **lifelong friends**. When he moved to Canada, '
                                    .'we **stayed close**, because we both **put the effort in** and called every week. I '
                                    .'**drifted apart** from my other school friends. Sam has become more **introverted**, '
                                    .'but with me he still talks for hours.',
                                'words' => [
                                    ['phrase' => 'stay close', 'meaning' => 'to stay good friends, even when life changes or you live far apart', 'meaning_fa' => 'صمیمی ماندن، رابطه‌ی نزدیک را حفظ کردن', 'pos' => 'verb phrase', 'example' => 'My cousin and I stayed close after she moved to Mashhad.', 'difficulty' => 'easy'],
                                    ['phrase' => 'drift apart', 'meaning' => 'to slowly become less close, without a fight, until you are not really friends', 'meaning_fa' => 'کم‌کم از هم دور شدن (بدون دعوا)', 'pos' => 'phrasal verb', 'example' => 'We drifted apart after university because we were both so busy.', 'difficulty' => 'medium'],
                                    ['phrase' => 'put the effort in', 'meaning' => 'to give time and energy to something so that it works', 'meaning_fa' => 'وقت و انرژی گذاشتن، تلاش کردن', 'pos' => 'verb phrase (informal)', 'example' => 'A friendship lasts when both people put the effort in.', 'difficulty' => 'medium'],
                                    ['phrase' => 'a lifelong friend', 'meaning' => 'a friend you have for all of your life, or for most of it', 'meaning_fa' => 'دوست همیشگی، دوستِ یک عمر', 'pos' => 'noun phrase', 'example' => 'My grandmother met her lifelong friend at primary school.', 'image_query' => 'two old women friends laughing', 'difficulty' => 'easy'],
                                    ['phrase' => 'introverted', 'meaning' => 'quiet, and happier alone or with a few people than in a big group (opposite: an extrovert)', 'meaning_fa' => 'درون‌گرا', 'pos' => 'adjective', 'example' => "I'm quite introverted — I prefer one friend to a big party.", 'difficulty' => 'hard'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Dave and Elliot have been best friends since they were eight. How did they stay close when Dave lived in Korea — and is it harder to make friends as an adult?',
                                'source' => 'My English Coach Dave — "Can You Follow This Real British English Conversation?"',
                                'image_query' => 'two friends talking park bench',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M08', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. Two speakers: Dave
                                // asks, his best friend Elliot answers.
                                'listening_turns' => $this->shadowTimestampsFor('M08', 'listening_turns'),
                                // The five parts kept in the cut (2:40-3:59, 5:43-6:57,
                                // 7:36-8:43, 18:28-18:43 and 20:47-22:48 of the original),
                                // written from the video's captions and checked against a
                                // Whisper pass of the cut audio.
                                'transcript' => [
                                    ['speaker' => 'Dave', 'text' => 'So the next section is about friendship, and like I said, we have known each other for quite a long time, since Year 4. That\'s age eight to nine in the UK. Um, it\'s a long time.'],
                                    ['speaker' => 'Elliot', 'text' => 'Yeah. Nearly 30 years.'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah, yeah. So my question is, do you remember the first time we actually spoke in Year 4?'],
                                    ['speaker' => 'Elliot', 'text' => 'Um, I don\'t. But you weren\'t my best friend then.'],
                                    ['speaker' => 'Dave', 'text' => 'No. So, no, I do remember. That\'s really upsetting, because I do remember the first time.'],
                                    ['speaker' => 'Elliot', 'text' => 'What was it?'],
                                    ['speaker' => 'Dave', 'text' => 'I remember being in the playground, and it was… I\'m not going to use last names, but it was you, Dan, Katie and Ramsay, and you were playing Batman. And you all chose characters, and I didn\'t have a character. And I remember, I think it was either you or Dan or someone said, "Oh, you can just be Penguin." And I was so upset. But at the same time, I thought to myself, "Well, at least I\'m playing with someone in the playground," you know. But that\'s my first memory of you.'],
                                    ['speaker' => 'Elliot', 'text' => 'Okay. Well, I have memories of that time. I\'m sorry. You\'re better than Penguin.'],
                                    ['speaker' => 'Dave', 'text' => 'Oh, thank you. Thank you.'],
                                    ['speaker' => 'Dave', 'text' => 'Why do you think we stayed close even when I moved abroad? And I\'m not talking about when I moved to France, \'cause I moved to France when I was 18, but in 2012, I moved to South Korea to teach English. So, why do you think we stayed close?'],
                                    ['speaker' => 'Elliot', 'text' => 'Um, well, first of all, when you did move away, that was devastating. My best friend was leaving me to go halfway around the world. But I think we stayed close because we stayed in touch, you know. We would video call fairly regularly, and every time you came back to the UK, which was only once a year, I would absolutely take every opportunity within the time that you were here to see you.'],
                                    ['speaker' => 'Dave', 'text' => 'I felt very privileged.'],
                                    ['speaker' => 'Elliot', 'text' => 'But I think, moreover, the reason we stayed close is because of our connection, you know. I think it\'s quite rare that, certainly, you know, adults stay as close and connected when you live halfway across the world for a prolonged period of time. I think that\'s quite rare.'],
                                    ['speaker' => 'Dave', 'text' => 'Okay, next question is, were you ever surprised that we didn\'t drift apart? \'Cause I\'m sure, you know, I\'ve drifted apart with some friends, and I\'m sure you have, too.'],
                                    ['speaker' => 'Elliot', 'text' => 'Well, that\'s what, you know, going back to what I just said, I think it\'s rare that people don\'t.'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah.'],
                                    ['speaker' => 'Elliot', 'text' => 'You know, I have friends that I was very close with in school that I\'m not friends with anymore. And that\'s not for any personal reasons. It\'s just, you know, you grow up, you move apart.'],
                                    ['speaker' => 'Dave', 'text' => 'Right. It\'s strange. They say, um, you need seven years of friendship with someone, and then that\'s a lifelong friend.'],
                                    ['speaker' => 'Elliot', 'text' => 'Wow. I don\'t know if that\'s true. I don\'t know if that\'s true. But what I would say is, sometimes I look back and think, and kind of can\'t believe that we have stayed as close as we are, because we could have so easily not. You know, it would have been no problem at all to just not contact you when you lived in Korea, especially because of the time difference.'],
                                    ['speaker' => 'Dave', 'text' => 'I think we both put the effort in. I think that\'s what\'s important.'],
                                    ['speaker' => 'Elliot', 'text' => 'Can I just say, Dave is on a hill. We\'re the same height, but he looks…'],
                                    ['speaker' => 'Dave', 'text' => 'I\'ll stand here. Look, I am a bit taller than you.'],
                                    ['speaker' => 'Elliot', 'text' => 'No, you\'re not.'],
                                    ['speaker' => 'Dave', 'text' => 'I am. Look.'],
                                    ['speaker' => 'Elliot', 'text' => 'Yeah, yeah. Now look. Look, I\'ll stand where Dave was. Now I look taller than you are.'],
                                    ['speaker' => 'Dave', 'text' => 'Um, do you feel like it\'s harder or easier to make new friends now as an adult?'],
                                    ['speaker' => 'Elliot', 'text' => 'Harder.'],
                                    ['speaker' => 'Dave', 'text' => 'Even for you? \'Cause Elliot\'s quite, um, an extrovert, I would say. You\'re quite talkative and good with people.'],
                                    ['speaker' => 'Elliot', 'text' => 'No. I think I\'ve become more introverted in later life.'],
                                    ['speaker' => 'Dave', 'text' => 'Interesting.'],
                                    ['speaker' => 'Elliot', 'text' => 'You know, definitely… I think that… well, I don\'t know. I\'ve heard that the older men get, the harder they find it to make friends. I mean, by the age of 30, I would say, certainly 40 — not that we are nearly 40 — but you\'ve kind of made all the friends. You\'ve been through all these sort of transformative periods in your life. You\'ve been to school, you might have gone to higher education, university, for example. You\'ve been through your first, second, third job. You\'ve made friends and hopefully maintained friendships throughout all of that. It gets to a point where maybe it gets a bit weird to keep making new friends. That\'s how I feel. I\'m quite self-conscious about that sort of thing.'],
                                    ['speaker' => 'Dave', 'text' => 'What, about making new friends?'],
                                    ['speaker' => 'Elliot', 'text' => 'Well, I wouldn\'t want anyone thinking, "Why is this weirdo talking to me? Oh, why is he, like, walking around? Will you be friends with me?"'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah, I don\'t say that.'],
                                    ['speaker' => 'Elliot', 'text' => 'Um, that is weird. But yeah, I definitely think it\'s harder, also because when you\'re an adult, you are in situations where you meet new people less frequently, less regularly, you know.'],
                                    ['speaker' => 'Dave', 'text' => 'Yeah.'],
                                    ['speaker' => 'Elliot', 'text' => 'And you have to choose who you spend time with. Less free time. So when I went to university, as an example, I was suddenly around hundreds of new people, you know, on my course, who I lived with in the flat. Um, you know, that doesn\'t happen anymore.'],
                                    ['speaker' => 'Dave', 'text' => 'Right.'],
                                    ['speaker' => 'Elliot', 'text' => 'I have my own house, and I go and I see the same work colleagues every day. You meet less people. Um, sorry, you meet new people less often.'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript (stayed close and drift apart
                                // as the forms Dave and Elliot actually say).
                                'target_phrases' => [
                                    [
                                        'phrase' => 'stayed close', 'meaning' => 'stayed good friends',
                                        'gap_before' => 'But I think we ',
                                        'gap_after' => ' because we stayed in touch, you know.',
                                    ],
                                    [
                                        'phrase' => 'drift apart', 'meaning' => 'slowly become less close',
                                        'gap_before' => 'Okay, next question is, were you ever surprised that we didn\'t ',
                                        'gap_after' => '?',
                                    ],
                                    [
                                        'phrase' => 'a lifelong friend', 'meaning' => 'a friend for all of your life',
                                        'gap_before' => 'you need seven years of friendship with someone, and then that\'s ',
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'put the effort in', 'meaning' => 'gave time and energy to it',
                                        'gap_before' => 'I think we both ',
                                        'gap_after' => '. I think that\'s what\'s important.',
                                    ],
                                    [
                                        'phrase' => 'introverted', 'meaning' => 'quiet, happier with fewer people',
                                        'gap_before' => 'I think I\'ve become more ',
                                        'gap_after' => ' in later life.',
                                    ],
                                ],
                                'topic_summary' => 'Dave and his best friend Elliot have known each other since Year 4, when '
                                    .'they were eight or nine. Dave remembers the first time they played together — he had '
                                    .'to be Penguin in a game of Batman. When Dave moved to South Korea in 2012, they stayed '
                                    .'close: they video called, and Elliot saw him every time he came home. Many school '
                                    .'friends drift apart, but they both put the effort in. They argue about who is taller, '
                                    .'and then Elliot explains why making new friends is harder as an adult.',
                                'comprehension_check' => [
                                    ['statement' => 'Dave and Elliot first met at school when they were about eight or nine.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Elliot thinks it is easier to make new friends as an adult.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'When Dave lived in Korea, Elliot tried to see him every time he came back to the UK.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'What game were the children playing the first time Dave remembers Elliot?',
                                    'options' => ['Football', 'Batman', 'Hide and seek'],
                                    'correct' => 1,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Who is the most generous person you know?', 'answer' => 'My aunt is the most generous person I know. She always buys gifts for everyone.', 'words' => ['the most generous']],
                            ['question' => 'Are you more confident than your best friend?', 'answer' => 'No, I\'m shyer than my best friend. She\'s much more confident at parties.', 'words' => ['shyer than', 'more confident']],
                            ['question' => 'Who is the most hard-working person in your family?', 'answer' => 'My dad is the most hard-working. He works harder than anyone I know.', 'words' => ['the most hard-working', 'harder than']],
                            ['question' => 'Who is more patient, you or your mum?', 'answer' => 'My mum is far more patient than me. I get angry more quickly.', 'words' => ['more patient than']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Six words for what someone is like — soon you\'ll say who is more patient, and who is the most generous.',
                                // Vocabulary in Use unit 11A-B ("What are you like?", "Opposites"),
                                // our own sentences.
                                'story' => 'My friend Nima is very **confident** — he talks to everyone — but his brother is '
                                    .'quite **shy**. Nima is **hard-working** and **generous**: he always pays for the coffee! '
                                    .'He\'s also very **honest**, but he isn\'t very **patient** in traffic.',
                                'words' => [
                                    ['phrase' => 'confident', 'meaning' => 'sure about yourself and what you can do (opposite: shy)', 'meaning_fa' => 'با اعتمادبه‌نفس', 'pos' => 'adjective', 'example' => "She's confident enough to speak in front of the whole class.", 'difficulty' => 'easy'],
                                    ['phrase' => 'shy', 'meaning' => 'nervous about meeting or talking to new people', 'meaning_fa' => 'خجالتی', 'pos' => 'adjective', 'example' => 'My son is shy at first, but then he never stops talking.', 'image_query' => 'shy child hiding behind mother', 'difficulty' => 'easy'],
                                    ['phrase' => 'hard-working', 'meaning' => 'putting a lot of time and effort into your work (opposite: lazy)', 'meaning_fa' => 'سخت‌کوش، پرتلاش', 'pos' => 'adjective', 'example' => 'Ali is the most hard-working person in our office.', 'difficulty' => 'easy'],
                                    ['phrase' => 'patient', 'meaning' => 'able to stay calm and not get angry when you have to wait (opposite: impatient)', 'meaning_fa' => 'صبور', 'pos' => 'adjective', 'example' => 'My dad is very patient — he taught me to drive.', 'difficulty' => 'medium'],
                                    ['phrase' => 'generous', 'meaning' => 'happy to give people money, time or help (opposite: mean)', 'meaning_fa' => 'دست‌ودل‌باز، سخاوتمند', 'pos' => 'adjective', 'example' => 'My aunt is so generous — she always brings presents.', 'image_query' => 'friend giving gift', 'difficulty' => 'medium'],
                                    ['phrase' => 'honest', 'meaning' => 'always telling the truth (opposite: dishonest)', 'meaning_fa' => 'صادق، روراست', 'pos' => 'adjective', 'example' => 'Be honest — do you like my new haircut?', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — this time copy how Elliot compares: "better than" and "as close as".',
                                'image_query' => 'school playground children playing',
                                'shadow_lines' => [
                                    'You\'re **better** than **Penguin**.',
                                    'We have **stayed** as **close** as we **are**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M08', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 15,
                                'hook' => 'Friends get described by comparison: "he\'s funnier than me", "she\'s the most patient person I know".',
                                'focus' => 'Comparatives & Superlatives',
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use units 105-108: -er / more + than,
                                // much / a bit + comparative, as … as, then the -est /
                                // the most.
                                'lesson' => [
                                    'intro' => 'Four ways to compare people: -er / more, much / a bit, as … as, and the -est / the most.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · -er or more … than',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Short adjectives take <strong>-er</strong>, longer ones take <strong>more</strong>, then <strong>than</strong>: <strong>Dave is taller than Elliot. Tony is more serious than Ian.</strong>',
                                                    'fa' => 'برای مقایسه‌ی دو نفر: صفت کوتاه + er و صفت بلند more + صفت، و بعد than. صفت دوهجایی که به y ختم می‌شود: funny → funnier. بی‌قاعده‌ها: good → better، bad → worse، far → further.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Comparative',
                                                    'parts' => [
                                                        ['text' => 'My brother', 'caption' => 'subject'],
                                                        ['text' => 'is', 'caption' => 'verb'],
                                                        ['text' => 'funnier', 'caption' => '-er / more + adjective', 'tone' => 'accent'],
                                                        ['text' => 'than me', 'caption' => 'than + person'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'She is more tall than me.', 'right' => 'She is taller than me.', 'highlight' => 'taller'],
                                                        ['wrong' => 'He is more gooder at maths.', 'right' => 'He is better at maths.', 'highlight' => 'better'],
                                                        ['wrong' => 'Tony is more serious that Ian.', 'right' => 'Tony is more serious than Ian.', 'highlight' => 'than'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => '-er: short words', 'words' => ['taller', 'older', 'kinder', 'nicer', 'bigger']],
                                                        ['label' => '-y → -ier', 'words' => ['funnier', 'easier', 'happier', 'lazier']],
                                                        ['label' => 'more: longer words', 'words' => ['more patient', 'more generous', 'more confident', 'more serious']],
                                                        ['label' => 'irregular', 'words' => ['good → better', 'bad → worse', 'far → further']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · much / a bit + comparative',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Say how big the difference is: <strong>much / a lot / far</strong> (big) or <strong>a bit / a little / slightly</strong> (small) before the comparative: <strong>Ian\'s much more adventurous.</strong>',
                                                    'fa' => 'برای اینکه بگویی فرق زیاد است یا کم، much / a lot / far (خیلی) یا a bit / a little / slightly (کمی) را قبل از صفت مقایسه‌ای بگذار. very قبل از صفت مقایسه‌ای نمی‌آید: much taller، نه very taller.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'How much?',
                                                    'parts' => [
                                                        ['text' => 'I am', 'caption' => 'subject + verb'],
                                                        ['text' => 'a bit', 'caption' => 'a bit / much', 'tone' => 'accent'],
                                                        ['text' => 'taller', 'caption' => 'comparative'],
                                                        ['text' => 'than you', 'caption' => 'than + person'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'My sister is very older than me.', 'right' => 'My sister is much older than me.', 'highlight' => 'much'],
                                                        ['wrong' => 'This game is a bit more easier.', 'right' => 'This game is a bit easier.', 'highlight' => 'a bit easier'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'I am a bit taller than you.',
                                                            'Ian\'s much more solitary than Tony.',
                                                            'Making friends is a lot harder for adults.',
                                                        ]],
                                                        ['label' => 'Two more patterns', 'items' => [
                                                            'It\'s getting harder and harder to meet new people.',
                                                            'The older you get, the harder it is to make friends.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · as … as and not as … as',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>as + adjective + as</strong> = the same. <strong>not as … as</strong> = less: <strong>I\'m not as resourceful as Ian.</strong>',
                                                    'fa' => 'as + صفت + as یعنی «به همان اندازه». not as … as یعنی «به اندازه‌ی … نیست». در گفتار روزمره بعد از than و as ضمیر مفعولی می‌آید: than me، as him (یا than I am).',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'not as … as',
                                                    'parts' => [
                                                        ['text' => 'Tony', 'caption' => 'subject'],
                                                        ['text' => 'isn\'t as', 'caption' => 'not as', 'tone' => 'accent'],
                                                        ['text' => 'resourceful', 'caption' => 'adjective'],
                                                        ['text' => 'as Ian', 'caption' => 'as + person'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Reza',
                                                    'wrong' => 'My brother is as tall like me.',
                                                    'right' => 'My brother is as tall as me.',
                                                    'explanation' => 'After as + adjective, say as again — not like and not than.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'We\'re the same height — he\'s as tall as me.',
                                                            'My new friends aren\'t as close as my school friends.',
                                                            'He\'ll be the same as he was last time.',
                                                            'She\'s older than me. = She\'s older than I am.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · the -est and the most',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'To compare one person with the whole group, use <strong>the + -est</strong> or <strong>the most</strong>: <strong>Who is the funniest? Who is the most stubborn?</strong>',
                                                    'fa' => 'برای مقایسه‌ی یک نفر با کل گروه: the + صفت + est (صفت کوتاه) یا the most + صفت (صفت بلند). good → the best و bad → the worst. برای مکان و گروه in می‌آید (the tallest in my class) و برای تجربه ever + Present Perfect (the nicest thing anybody\'s ever said).',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'Superlative',
                                                    'parts' => [
                                                        ['text' => 'Ian', 'caption' => 'subject'],
                                                        ['text' => 'is', 'caption' => 'verb'],
                                                        ['text' => 'the most', 'caption' => 'the -est / the most', 'tone' => 'accent'],
                                                        ['text' => 'adventurous', 'caption' => 'adjective'],
                                                        ['text' => 'in our group', 'caption' => 'in + group / place'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'He is most funny person in my class.', 'right' => 'He is the funniest person in my class.', 'highlight' => 'the funniest'],
                                                        ['wrong' => 'She is the tallest of my family.', 'right' => 'She is the tallest in my family.', 'highlight' => 'in'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the video', 'items' => [
                                                            'Who was the most mischievous when you were younger?',
                                                            'It\'s the nicest thing anybody\'s ever said about me.',
                                                            'I\'m probably the most boring person you\'ve ever met.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'E · Make it personal',
                                            'body' => 'Next you\'ll finish sentences comparing <strong>your</strong> friends — then watch two friends of 60 years play "Who is the most…?", and soon say it out loud in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this right away — in Video Shadowing next, and in AI Conversation #1.',
                                ],
                                'frequency_starters' => [
                                    'My best friend is more', 'I\'m a bit', 'I\'m not as',
                                    'The funniest person I know is', 'The most generous friend I have', 'Making new friends as an adult is much',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence comparing people they know, using a correct '
                                    .'comparative (-er / more + than, with much / a bit / a lot before it when the difference '
                                    .'is big or small), as … as / not as … as for the same or less, or a correct superlative '
                                    .'(the -est / the most, often with in + group or ever + present perfect).',
                                'grammar_major_criteria' => 'a double comparative ("more taller", "more better"), more + a '
                                    .'short adjective ("more tall"), -er on a long adjective ("patienter"), "that" or "then" '
                                    .'instead of than, very before a comparative ("very older"), as … like or as … than instead '
                                    .'of as … as, a superlative without the ("most funny person"), the wrong irregular form '
                                    .'("gooder", "the baddest"), the sentence does not actually continue the given starter, or '
                                    .'it is not a genuine personal statement',
                                'grammar_context' => 'continues with a comparative + than, much / a bit + comparative, as … '
                                    .'as / not as … as, or the -est / the most, as the starter already given needs',
                                'quick_check' => [
                                    [
                                        'wrong' => 'My brother is more tall than me.',
                                        'options' => ['My brother is taller than me.', 'My brother is more tall than me.', 'My brother is tallest than me.'],
                                        'correct' => 0,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'Sara is the most funny person in our group.',
                                        'options' => ['Sara is the most funny person in our group.', 'Sara is funnier person in our group.', 'Sara is the funniest person in our group.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'I\'m not as patient than my sister.',
                                        'options' => ['I\'m not as patient than my sister.', 'I\'m not as patient as my sister.', 'I\'m not patient as my sister.'],
                                        'correct' => 1,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'Making friends is very harder for adults.',
                                        'options' => ['Making friends is very harder for adults.', 'Making friends is much harder for adults.', 'Making friends is more harder for adults.'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['than', 'is', 'My', 'me.', 'friend', 'funnier', 'best'], 'answer' => 'My best friend is funnier than me.'],
                                    ['words' => ['as', 'not', 'I\'m', 'him.', 'as', 'confident'], 'answer' => 'I\'m not as confident as him.'],
                                    ['words' => ['person', 'Who', 'the', 'is', 'patient', 'most', 'you', 'know?'], 'answer' => 'Who is the most patient person you know?'],
                                ],
                            ],
                            [
                                // A real talk between three real people, natural speed: My
                                // English Coach Dave's "Best Friends for 60 Years" — the
                                // "Who is the most…?" game and the question before it, cut to
                                // about 6 minutes, the creator's burned-in captions blurred
                                // out (and his top word boxes while they show) so the step's
                                // own caption toggle works.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Dave\'s dad Ian and his friend Tony have been friends for nearly 60 years. Who was the most mischievous? Who is the most stubborn? Watch them point — then say it with them.',
                                'source' => 'My English Coach Dave — "Best Friends for 60 Years – Can You Understand Their English?"',
                                'video_url' => $shadowingVideoUrl,
                                // The creator's own English captions, re-timed per sentence on
                                // Whisper's word timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M08', 'video_shadowing_segments'),
                                'topic_summary' => 'Dave sits down with his dad, Ian, and Ian\'s best friend Tony — friends '
                                    .'for nearly 60 years. First Ian says what he admires about Tony: he is reliable, loyal and '
                                    .'consistent. Then Dave plays a game: he asks "Who is the most…?", counts three, two, one, '
                                    .'and they point. Who was the most mischievous? Who was the better student? Who is the '
                                    .'funniest, the most adventurous, the most stubborn — and who would survive the longest on '
                                    .'a desert island? After each point, they explain why.',
                                'comprehension_check' => [
                                    ['statement' => 'Ian says Tony is reliable, loyal and consistent.', 'correct' => true],
                                    ['statement' => 'They both agree that Tony is the most adventurous.', 'correct' => false],
                                    ['statement' => 'Tony thinks Ian would survive longer on a desert island.', 'correct' => true],
                                ],
                                // Real phrases Ian, Tony and Dave use on camera.
                                'target_phrases' => [
                                    ['phrase' => 'balance out', 'meaning' => 'to be different in a way that makes a good mix together'],
                                    ['phrase' => 'risk-averse', 'meaning' => 'not wanting to take risks'],
                                    ['phrase' => 'put yourself forward', 'meaning' => 'to offer to do something, without being asked'],
                                    ['phrase' => 'dig your heels in', 'meaning' => 'to refuse to change your mind'],
                                    ['phrase' => 'resourceful', 'meaning' => 'good at finding ways to solve problems'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words the
                                // speakers actually say. Chosen for this mission's grammar: two
                                // superlatives with ever, a comparative question and answer,
                                // not as … as, and much more twice. Bold marks naturally-stressed
                                // content words (rendered by <x-stress-marked-line>).
                                'shadow_lines' => [
                                    'It\'s the **nicest** thing **anybody\'s** ever **said** about me.',
                                    'I\'m **probably** the most **boring** person you\'ve ever **met**.',
                                    'Who was the **better** **student**?',
                                    'So **Tony** was the more **academic** person.',
                                    'I don\'t think I\'m as **resourceful** as **Ian** is.',
                                    '**Ian\'s** much more **solitary**, but much more **self-sufficient**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M08', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Who has the best sense of humour among your friends?', 'answer' => 'Reza has the best sense of humour. He\'s funnier than any comedian.', 'words' => ['the best', 'sense of humour', 'funnier than']],
                            ['question' => 'Who is the most creative person you know?', 'answer' => 'My sister is the most creative person I know. She\'s a really talented painter.', 'words' => ['the most creative', 'talented']],
                            ['question' => 'Are you more sensible now than at school?', 'answer' => 'Yes, I\'m more sensible now. At school I was much sillier than my friends.', 'words' => ['more sensible', 'sillier than']],
                            ['question' => 'Who do you trust the most?', 'answer' => 'I trust my brother the most. He\'s the most honest person in my life.', 'words' => ['trust', 'the most']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the words you need to describe a friend you really trust.',
                                // Vocabulary in Use unit 11C ("Describing a friend") plus sense
                                // of humour from 11A, our own sentences.
                                'story' => 'My best friend Mina is very **sensible** — she never does anything **silly**, so I '
                                    .'can always **trust** her. She\'s also **creative** and a really **talented** painter. '
                                    .'And she has a great **sense of humour**: she makes everyone laugh.',
                                'words' => [
                                    ['phrase' => 'sensible', 'meaning' => 'practical, and making good decisions (not doing stupid things)', 'meaning_fa' => 'عاقل، منطقی', 'pos' => 'adjective', 'example' => "It's sensible to save some money every month.", 'difficulty' => 'medium'],
                                    ['phrase' => 'silly', 'meaning' => 'not sensible; a bit stupid, often in a funny way', 'meaning_fa' => 'بچگانه، بی‌فکر، مسخره', 'pos' => 'adjective', 'example' => 'We were silly kids — we once painted the cat blue.', 'difficulty' => 'easy'],
                                    ['phrase' => 'trust', 'meaning' => 'to be sure that someone is honest and will not hurt you', 'meaning_fa' => 'اعتماد کردن (به کسی)', 'pos' => 'verb', 'example' => 'I trust my sister with all my secrets.', 'difficulty' => 'easy'],
                                    ['phrase' => 'creative', 'meaning' => 'good at thinking of new ideas and using your imagination', 'meaning_fa' => 'خلاق', 'pos' => 'adjective', 'example' => 'Reza is the most creative person in our team.', 'image_query' => 'artist painting canvas', 'difficulty' => 'easy'],
                                    ['phrase' => 'talented', 'meaning' => 'having a natural ability to do something well', 'meaning_fa' => 'بااستعداد', 'pos' => 'adjective', 'example' => 'She is a talented singer — much better than me!', 'difficulty' => 'medium'],
                                    ['phrase' => 'sense of humour', 'meaning' => 'the ability to laugh and to see when something is funny', 'meaning_fa' => 'شوخ‌طبعی', 'pos' => 'noun phrase', 'example' => 'My best friend has a great sense of humour.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — now listen for "more introverted" and "less often".',
                                'image_query' => 'friends meeting in cafe',
                                'shadow_lines' => [
                                    'I think I\'ve become **more** **introverted** in **later** **life**.',
                                    'You **meet** new **people** less **often**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M08', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your best friend — how you met, '
                                    .'what they are like, and three ways you are different (who is taller, funnier, more '
                                    .'patient?) — using the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'Who is your oldest friend? How did you meet?',
                                    'What is your best friend like? Is he or she more confident than you?',
                                    'Who is the funniest person in your group of friends?',
                                    'Have you ever drifted apart from a good friend? What happened?',
                                    'Is it harder or easier to make friends now than when you were at school?',
                                    'What do you think makes a friendship last?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Arash and Kian — two friends who are different in almost every way. So why are they still so close?',
                                'passage_title' => 'Two very different friends',
                                'image_query' => 'two young men friends laughing',
                                // Written fresh for this app (EOS-009 §14) — reuses the Vocabulary
                                // Builder words in a new context, third person instead of first,
                                // and compares the two friends all the way through.
                                'passage' => 'Arash and Kian have been friends since primary school. They are lifelong friends '
                                    .'now, but they are very different. Arash is taller and much more confident: he can talk to '
                                    .'anyone at a party. Kian is shy and quieter, but he is the most hard-working person in '
                                    .'their class and far more patient than Arash. Arash has a great sense of humour, but he '
                                    .'can be a bit silly. Kian is more sensible, so when Arash has a problem, Kian is the first '
                                    .'person he calls — he knows he can trust him. People say opposites attract, and these two '
                                    .'are the proof. Three years ago, Kian moved to Germany for work, and many people thought '
                                    .'the two friends would drift apart. They didn\'t. They both put the effort in: they talk '
                                    .'every Sunday, and because Arash isn\'t as busy as Kian, he usually flies to Berlin. '
                                    .'"Kian isn\'t as funny as me," Arash says, "but he\'s the most honest and generous friend '
                                    .'I have."',
                                'highlighted_phrases' => [
                                    ['phrase' => 'lifelong friends', 'type' => 'reused'],
                                    ['phrase' => 'confident', 'type' => 'reused'],
                                    ['phrase' => 'shy', 'type' => 'reused'],
                                    ['phrase' => 'hard-working', 'type' => 'reused'],
                                    ['phrase' => 'patient', 'type' => 'reused'],
                                    ['phrase' => 'sense of humour', 'type' => 'reused'],
                                    ['phrase' => 'silly', 'type' => 'reused'],
                                    ['phrase' => 'sensible', 'type' => 'reused'],
                                    ['phrase' => 'trust', 'type' => 'reused'],
                                    ['phrase' => 'drift apart', 'type' => 'reused'],
                                    ['phrase' => 'put the effort in', 'type' => 'reused'],
                                    ['phrase' => 'honest', 'type' => 'reused'],
                                    ['phrase' => 'generous', 'type' => 'reused'],
                                    ['phrase' => 'opposites attract', 'type' => 'new', 'definition' => 'people who are very different often like each other', 'pos' => 'saying', 'example' => 'She loves parties and he loves books — opposites attract!'],
                                    ['phrase' => 'proof', 'type' => 'new', 'definition' => 'something that shows that a thing is true', 'pos' => 'noun', 'example' => 'This photo is proof that we were friends at school.'],
                                ],
                                'topic_summary' => 'A short text about Arash and Kian, friends since primary school. Arash is '
                                    .'taller, more confident and funnier; Kian is shyer, more sensible and more patient. When '
                                    .'Kian moved to Germany, they did not drift apart, because they both put the effort in.',
                                'comprehension_check' => [
                                    ['statement' => 'Arash is taller than Kian.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Kian is more confident than Arash.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'After Kian moved to Germany, the two friends stopped talking.', 'correct' => false, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'In what ways is Kian different from Arash?',
                                    'How do the two friends stay close now that Kian lives in Germany?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Writing down why a friend matters is a small gift — you could even send it to them.',
                                'title' => 'My best friend and me',
                                'prompts' => [
                                    ['label' => 'How and when you met', 'image_query' => 'two kids school friends'],
                                    ['label' => 'What your friend is like', 'image_query' => 'smiling young woman portrait'],
                                    ['label' => 'Three ways you are different', 'image_query' => 'two friends different styles'],
                                    ['label' => 'Something you have in common', 'image_query' => 'friends playing football together'],
                                    ['label' => 'The best thing about your friend', 'image_query' => 'friends hugging'],
                                    ['label' => 'How you stay close', 'image_query' => 'video call friends laptop'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['-er / more … than', 'much / a bit + comparative', 'as … as', 'not as … as', 'the -est / the most', 'stay close', 'put the effort in'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Compare your two closest friends.', 'answer' => 'Ali is funnier than Sam, but Sam is more patient and a better listener.', 'words' => ['funnier than', 'more patient', 'better']],
                            ['question' => 'What is the best thing about having friends?', 'answer' => 'The best thing is that you\'re never alone. Life is easier with good friends.', 'words' => ['the best', 'easier']],
                            ['question' => 'Who was your best friend at school, and what was he or she like?', 'answer' => 'Mina was my best friend. She was the most confident girl in our class.', 'words' => ['best friend', 'the most confident']],
                            ['question' => 'Is it harder to make friends as an adult?', 'answer' => 'Yes, it\'s harder. Adults are busier, so you have to put the effort in.', 'words' => ['harder', 'busier', 'put the effort in']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'Four friends, one street, four very different styles. Who is the tallest — and who looks the happiest?',
                                'image_query' => 'four friends walking street',
                                'guiding_questions' => [
                                    'Who is the tallest of the four friends? Who is the shortest?',
                                    'Who looks the most energetic? What is he doing?',
                                    'Compare the two friends in the middle. Who looks happier? Whose clothes are darker?',
                                    'Whose shoes are the most colourful? Which shoes do you like best?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): four young men on a
                                // street, arms round each other — on the left one in a white
                                // hoodie kicking up a leg, then one in black with red-striped
                                // trousers laughing, one in a grey hoodie and ripped teal jeans,
                                // and on the right the tallest, in a black tracksuit, beanie and
                                // yellow boots. Changing the query or orientation invalidates
                                // these coordinates.
                                'hotspots' => [
                                    ['x' => 72, 'y' => 14, 'question_index' => 0],
                                    ['x' => 27, 'y' => 55, 'question_index' => 1],
                                    ['x' => 50, 'y' => 30, 'question_index' => 2],
                                    ['x' => 70, 'y' => 91, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Describe your best friend. How are you similar, and how are you different?',
                                    'Who is the funniest, the kindest and the most reliable person you know? Why?',
                                    'Compare your school friends with the friends you have now.',
                                    'Is it harder to make friends as an adult than as a child? Why?',
                                    'Tell me about a friend you drifted apart from. What happened?',
                                    'What makes a friendship last for 60 years?',
                                ],
                                'role_reversal_topic' => 'friends and friendship',
                                'final_prompt' => 'Speak for 3 minutes without stopping about your closest friends and how they are different from you.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    '-er / more … than',
                                    'much / a bit + comparative',
                                    'as … as / not as … as',
                                    'the -est / the most',
                                    'How you met your best friend',
                                    'Who is the most … in your group',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Seeds M09 "Personality" — the roadmap's Defining Relative Clauses.
     * M05's 16-step layout (no story_sequence: a relative clause says which
     * person or what kind of person, it is not a story told in order, so it
     * is practised in Picture Description — "the man who…", "the woman
     * whose…" — and the two AI conversations instead). The lesson follows
     * Grammar in Use units 92-94 in order: who / that for people, that /
     * which for things, leaving the pronoun out, then whose / where (units
     * 95-96, extra-information clauses, are left for later). Vocabulary
     * days 2-3 follow Vocabulary in Use units 12A (Feelings) and 72B-C
     * (-able, -ful / -less adjectives), all rewritten in our own words —
     * unit 11 was already taught in M08. Media the user picked: Evie's
     * podcast episode on introverts and extroverts (cut from 17 to about 6
     * minutes: the two types, the energy test and why she is an ambivert,
     * and why confident learners speak sooner) and Bob the Canadian's
     * "Describing People" lesson (cut from 27 to about 6 minutes: warm,
     * impatient, calm, loyal, courageous, timid, sympathetic, generous and
     * frugal, each defined with "someone who…").
     */
    private function seedM09(): void
    {
        $audioUrl = $this->publishMissionAsset('M09', 'English with Evie - Introverts and Extroverts.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M09', 'Bob the Canadian - Describing People.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M09', 'Bob the Canadian - Describing People.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M09'],
            [
                'title' => 'Personality',
                'module' => 'Personality',
                'outcome' => 'I can say what kind of person I am and describe the people in my life — someone who…, a friend whose…, a place where I recharge.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Are you outgoing or reserved?', 'answer' => 'I\'m quite reserved with new people, but I\'m outgoing with my family.', 'words' => ['reserved', 'outgoing']],
                            ['question' => 'What drains your energy?', 'answer' => 'Long meetings drain me. After work I need a quiet hour.', 'words' => ['drain']],
                            ['question' => 'How do you recharge after a busy day?', 'answer' => 'I recharge by walking alone in the park with some music.', 'words' => ['recharge']],
                            ['question' => 'When did you last feel on top of the world?', 'answer' => 'I felt on top of the world when I got my first job.', 'words' => ['on top of the world']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Think of someone you love. Could you say in one English sentence what kind of person they are?',
                                'image_query' => 'thoughtful portrait person',
                                'ambient_video_query' => 'people talking in cafe',
                                'warm_up_questions' => [
                                    'Are you a quiet person or a talkative person?',
                                    'What is one word that describes you?',
                                    'Do big parties give you energy or make you tired?',
                                    'What kind of person is your best friend?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases Evie uses for introverts and extroverts — you\'ll need them to describe yourself.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'My sister Leila is very **outgoing**: she talks to everyone at a party. I\'m more '
                                    .'**reserved**. Big parties **drain** me, so the next day I stay at home to **recharge**. '
                                    .'But after a quiet evening with one good friend, I feel **on top of the world**.',
                                'words' => [
                                    ['phrase' => 'outgoing', 'meaning' => 'friendly and happy to meet and talk to new people', 'meaning_fa' => 'اجتماعی، خوش‌برخورد', 'pos' => 'adjective', 'example' => 'Mina is so outgoing — she made five new friends on her first day.', 'image_query' => 'friendly woman laughing with group', 'difficulty' => 'easy'],
                                    ['phrase' => 'reserved', 'meaning' => 'quiet, and not showing your feelings or talking much, especially with new people', 'meaning_fa' => 'کم‌حرف، تودار', 'pos' => 'adjective', 'example' => 'My grandfather is a reserved man, but he is very kind.', 'difficulty' => 'medium'],
                                    ['phrase' => 'drain', 'meaning' => 'to make someone feel very tired, with no energy left', 'meaning_fa' => 'خسته کردن، انرژی کسی را گرفتن', 'pos' => 'verb', 'example' => 'Long meetings really drain me.', 'difficulty' => 'medium'],
                                    ['phrase' => 'recharge', 'meaning' => 'to rest so that you get your energy back', 'meaning_fa' => 'دوباره انرژی گرفتن، شارژ شدن', 'pos' => 'verb', 'example' => 'After work I go for a walk alone to recharge.', 'image_query' => 'woman relaxing alone with book', 'difficulty' => 'easy'],
                                    ['phrase' => 'on top of the world', 'meaning' => 'extremely happy', 'meaning_fa' => 'در اوج خوشحالی', 'pos' => 'idiom', 'example' => 'When I passed my driving test, I felt on top of the world.', 'difficulty' => 'hard'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Evie talks for a living — so why does she say she is an introvert? Listen to the test she uses to tell the two types apart.',
                                'source' => 'English with Evie — "Do Extroverts Speak Better English?" (The English Podcast)',
                                'image_query' => 'woman podcast microphone coffee',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M09', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. One speaker: Evie.
                                'listening_turns' => $this->shadowTimestampsFor('M09', 'listening_turns'),
                                // The three parts kept in the cut (1:16-3:02, 4:21-6:57
                                // and 10:38-12:24 of the original), written from the
                                // video's captions and checked against a Whisper pass of
                                // the cut audio.
                                'transcript' => [
                                    ['speaker' => 'Evie', 'text' => 'So, introverts and extroverts. This might be a term that you are familiar with already. I know some people are interested in, like, personality tests and things like this. But just in case you do not know what is an introvert or an extrovert, you haven\'t heard these terms before, it\'s basically a way of describing how outgoing somebody is, how much they like to be around people.'],
                                    ['speaker' => 'Evie', 'text' => 'So, if you are extroverted, it means that you are somebody who loves to be surrounded by people, hanging out with people. It gives you energy. It makes you super happy. Extroverts are usually very confident, and they have lots of friends, and they love being at, like, a house party. If they could walk into a room full of people that they don\'t know, for them, that\'s like the dream.'],
                                    ['speaker' => 'Evie', 'text' => 'Meanwhile, for introverts, the association is that they are a little bit more shy, I suppose. Maybe more reserved, we would say. They maybe prefer to be with people who they know very, very well. And often introverts really like to be by themselves. If they are under stress, or if an introvert feels like everything in the world is too much, they\'re maybe more likely to spend some time alone, as opposed to seeking out lots of people, trying to find lots of people to hang out with.'],
                                    ['speaker' => 'Evie', 'text' => 'The other way that people say you can tell whether you are more introverted or extroverted as a person is if you go and hang out with, like, a big group of people. You know, maybe some of them are your friends, but some of them are new people, and you\'re socialising with loads of people during an evening. Does that experience… does it give you energy, or does it take energy away?'],
                                    ['speaker' => 'Evie', 'text' => 'If you are an introvert, if it drains you, if you feel like, "Okay, that social interaction was, like, really fun, but now I need to recharge," then probably you are more of an introvert. But if you go to that event and you literally leave it feeling on top of the world, if you feel like you have so much energy, you feel great, that was the best thing, it refreshed you, then you are an extrovert. So, the idea that if socialising gives you energy, then you\'re an extrovert, and if it takes energy away, then you are an introvert.'],
                                    ['speaker' => 'Evie', 'text' => 'I am an introvert. I know that… maybe that\'s surprising. I think whenever I tell people I\'m an introvert, they\'re like, "Girl, no way." But I actually think I probably am. I did do the Myers-Briggs personality type thing, the 16 personality types test, and I was 53% introverted. So I think I\'m half and half, you know? Half introverted, half extroverted, I think.'],
                                    ['speaker' => 'Evie', 'text' => 'I know there are some people who call this, like, at the borderline, we would say. At the borderline, 50%, around this sort of area. If you\'re on the borderline with these things, then you are an ambivert. So you\'re not an introvert, you\'re not an extrovert, you\'re an ambivert. And I think I can be extroverted. Like, I really enjoy speaking with people. I enjoy, like, spending time with people. Like, I love it. I love talking. Obviously, this is, like, my job: just talk.'],
                                    ['speaker' => 'Evie', 'text' => 'But apparently, students who are more self-confident, who feel less anxious in social situations, they learn languages better. So this technically means that extroverts can learn how to speak in a language faster than somebody who\'s super shy or super introverted. And I think that makes sense, because so much of language learning is just doing the damn thing.'],
                                    ['speaker' => 'Evie', 'text' => 'Like, if you want to sit down and write… I don\'t mean this. I hate studying grammar, but some people actually love studying grammar, and they really, genuinely learn languages by sitting down and, like, studying a textbook. I have a friend like this. She speaks, like, four languages, and she loves to read a textbook and study grammar. She\'s insane, but she is a really great linguist. So I\'m not going to say that doing that doesn\'t work, but she is also very confident.'],
                                    ['speaker' => 'Evie', 'text' => 'Like, even though she might not have the best linguistic abilities, if she meets somebody who speaks French, for example, you know 100% she is going to speak French. And she\'s so extroverted. She doesn\'t care that she\'s making mistakes. She\'ll just… "I\'m going to speak French to you," and she goes. And I think it\'s one of the things that makes her so amazing at learning a language, because she goes, "I can do this," and she just does it. And that means that she gets so many more opportunities. She gets so many more opportunities to practise and improve and build confidence.'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript (drains as the form Evie
                                // actually says).
                                'target_phrases' => [
                                    [
                                        'phrase' => 'outgoing', 'meaning' => 'friendly with new people',
                                        'gap_before' => 'it\'s basically a way of describing how ',
                                        'gap_after' => ' somebody is, how much they like to be around people.',
                                    ],
                                    [
                                        'phrase' => 'reserved', 'meaning' => 'quiet, not showing much',
                                        'gap_before' => 'Maybe more ',
                                        'gap_after' => ', we would say.',
                                    ],
                                    [
                                        'phrase' => 'drains', 'meaning' => 'makes you very tired',
                                        'gap_before' => 'If you are an introvert, if it ',
                                        'gap_after' => ' you, if you feel like,',
                                    ],
                                    [
                                        'phrase' => 'recharge', 'meaning' => 'get your energy back',
                                        'gap_before' => 'really fun, but now I need to ',
                                        'gap_after' => ',"',
                                    ],
                                    [
                                        'phrase' => 'on top of the world', 'meaning' => 'extremely happy',
                                        'gap_before' => 'you literally leave it feeling ',
                                        'gap_after' => ', if you feel like you have so much energy',
                                    ],
                                ],
                                'topic_summary' => 'Evie explains two personality types. Extroverts are people who love '
                                    .'being around people: a party gives them energy. Introverts are more reserved and '
                                    .'prefer people who they know very well. Her test: if socialising drains you and you '
                                    .'need to recharge, you are an introvert; if you leave feeling on top of the world, you '
                                    .'are an extrovert. She was 53% introverted, so she calls herself an ambivert. Then she '
                                    .'says confident students often learn languages faster — like her friend who speaks '
                                    .'four languages and always speaks French when she meets somebody who speaks it.',
                                'comprehension_check' => [
                                    ['statement' => 'Evie says extroverts usually get energy from being with lots of people.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Evie\'s personality test said she was 80% introverted.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Evie\'s friend speaks French with new people even when she makes mistakes.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'What does Evie call a person who is half introverted and half extroverted?',
                                    'options' => ['An ambivert', 'A linguist', 'A borderline'],
                                    'correct' => 0,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Tell me about something you are proud of.', 'answer' => 'I\'m proud of the cake that I made for my mum\'s birthday.', 'words' => ['proud of', 'that']],
                            ['question' => 'What is something that makes you anxious?', 'answer' => 'Exams are something that makes me really anxious.', 'words' => ['anxious', 'that makes']],
                            ['question' => 'Describe a person who is always curious.', 'answer' => 'My nephew is someone who is always curious. He asks a hundred questions a day.', 'words' => ['someone who', 'curious']],
                            ['question' => 'Tell me about a time you were disappointed.', 'answer' => 'I was disappointed when the concert that I wanted to see was cancelled.', 'words' => ['disappointed', 'that I wanted']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Six words for how people feel — soon you\'ll describe someone who gets anxious, or a friend who was proud of you.',
                                // Vocabulary in Use unit 12A ("How do you feel?"), our own
                                // sentences.
                                'story' => 'My brother Amir always gets **anxious** before exams. Last month he passed his '
                                    .'English test, and my parents were very **proud** of him. He was a bit **disappointed** '
                                    .'with his speaking score, though. His friend failed, so he was **upset** — and maybe a '
                                    .'little **jealous**. Now Amir is **curious** about the next level.',
                                'words' => [
                                    ['phrase' => 'proud', 'meaning' => 'happy because you, or someone close to you, did something well', 'meaning_fa' => 'مفتخر، سربلند', 'pos' => 'adjective', 'example' => 'I\'m so proud of my daughter — she won the race!', 'image_query' => 'proud parents graduation', 'difficulty' => 'easy'],
                                    ['phrase' => 'disappointed', 'meaning' => 'sad because something was not as good as you hoped', 'meaning_fa' => 'ناامید، دلسرد', 'pos' => 'adjective', 'example' => 'We were disappointed that the concert was cancelled.', 'difficulty' => 'medium'],
                                    ['phrase' => 'curious', 'meaning' => 'wanting to know or learn about something', 'meaning_fa' => 'کنجکاو', 'pos' => 'adjective', 'example' => 'Children are curious about everything.', 'image_query' => 'curious child looking magnifying glass', 'difficulty' => 'easy'],
                                    ['phrase' => 'anxious', 'meaning' => 'worried and nervous about something that might happen', 'meaning_fa' => 'نگران، مضطرب', 'pos' => 'adjective', 'example' => 'I always feel anxious before I speak in public.', 'difficulty' => 'medium'],
                                    ['phrase' => 'jealous', 'meaning' => 'unhappy because someone has something you want, or because someone you love likes another person', 'meaning_fa' => 'حسود، دچار حسادت', 'pos' => 'adjective', 'example' => 'He was jealous of his friend\'s new car.', 'difficulty' => 'medium'],
                                    ['phrase' => 'upset', 'meaning' => 'unhappy because something bad has happened', 'meaning_fa' => 'ناراحت، دلخور', 'pos' => 'adjective', 'example' => 'She was upset when her cat got lost.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — this time copy how Evie describes people: "somebody who…" and "people who…".',
                                'image_query' => 'people talking at house party',
                                'shadow_lines' => [
                                    'You are **somebody** who **loves** to be **surrounded** by **people**.',
                                    'They maybe **prefer** to be with people who they **know** very, very **well**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M09', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 15,
                                'hook' => 'A personality is a "someone who…": a friend who always listens, a boss who never gets angry.',
                                'focus' => 'Defining Relative Clauses',
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use units 92-94: who / that for people,
                                // that / which for things, leaving the pronoun out, then
                                // whose / where.
                                'lesson' => [
                                    'intro' => 'A relative clause tells you which person or thing you mean: someone who…, a test that…, the friend I…, a place where….',
                                    'sections' => [
                                        [
                                            'heading' => 'A · who / that for people',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'After a person, use <strong>who</strong> (or <strong>that</strong>) to say which person or what kind of person: <strong>A warm person is someone who makes others feel comfortable.</strong>',
                                                    'fa' => 'برای اینکه بگویی منظورت کدام آدم یا چه جور آدمی است، بعد از اسمِ شخص who (یا that) بیاور. who برای آدم رایج‌تر است. فعل بعد از who با اسمِ قبلش جور می‌شود: someone who gets و people who get.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'who for people',
                                                    'parts' => [
                                                        ['text' => 'An introvert', 'caption' => 'person'],
                                                        ['text' => 'is someone', 'caption' => 'verb + someone'],
                                                        ['text' => 'who', 'caption' => 'who / that', 'tone' => 'accent'],
                                                        ['text' => 'needs time alone', 'caption' => 'verb + rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I have a friend which speaks four languages.', 'right' => 'I have a friend who speaks four languages.', 'highlight' => 'who'],
                                                        ['wrong' => 'She is someone who love parties.', 'right' => 'She is someone who loves parties.', 'highlight' => 'loves'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'someone who…', 'words' => ['gets annoyed easily', 'stays calm', 'likes to give', 'tells the truth']],
                                                        ['label' => 'people who…', 'words' => ['love parties', 'need time alone', 'never give up']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · that / which for things',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'After a thing, use <strong>that</strong> or <strong>which</strong> — never who: <strong>It\'s a test that tells you your personality type.</strong> Don\'t add <strong>it / he / she</strong> again.',
                                                    'fa' => 'بعد از اشیا that یا which بیاور (نه who). ضمیر را تکرار نکن: who / that / which خودش جای he / she / it نشسته است. what یعنی the thing that: What I need is a quiet evening.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'that / which for things',
                                                    'parts' => [
                                                        ['text' => 'It\'s a test', 'caption' => 'thing'],
                                                        ['text' => 'that', 'caption' => 'that / which', 'tone' => 'accent'],
                                                        ['text' => 'tells you your type', 'caption' => 'verb + rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I have a friend who she speaks French.', 'right' => 'I have a friend who speaks French.', 'highlight' => 'who speaks'],
                                                        ['wrong' => 'It\'s a job who makes me happy.', 'right' => 'It\'s a job that makes me happy.', 'highlight' => 'that'],
                                                        ['wrong' => 'The thing what I like about him is his humour.', 'right' => 'The thing that I like about him is his humour.', 'highlight' => 'that'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the listening', 'items' => [
                                                            'It\'s one of the things that makes her so amazing at learning a language.',
                                                            'They love walking into a room full of people that they don\'t know.',
                                                        ]],
                                                        ['label' => 'what = the thing that', 'items' => [
                                                            'What I need after a party is a quiet evening.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · Leaving out who / that',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'When another subject comes after <strong>who / that / which</strong>, you can leave it out: <strong>people (who) they know very well</strong>. A preposition goes at the end: <strong>the friend I talk to most</strong>.',
                                                    'fa' => 'اگر بعد از who / that / which یک فاعل دیگر بیاید، می‌توانی آن را حذف کنی: the people (that) I met. ولی اگر خودش فاعلِ فعل بعدی است، حذف نمی‌شود: the man who lives next door. در گفتار، حرف اضافه به آخر جمله می‌رود: the person I was talking to.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'No who / that',
                                                    'parts' => [
                                                        ['text' => 'the friend', 'caption' => 'person'],
                                                        ['text' => '(that)', 'caption' => 'can leave out', 'tone' => 'accent'],
                                                        ['text' => 'I', 'caption' => 'new subject'],
                                                        ['text' => 'talk to most', 'caption' => 'verb + preposition'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Sara',
                                                    'wrong' => 'The man lives next door is very friendly.',
                                                    'right' => 'The man who lives next door is very friendly.',
                                                    'explanation' => 'Here who is the subject of lives, so it has to stay. Leave it out only when another subject follows: the man (who) I met.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'They prefer to be with people they know very, very well.',
                                                            'Jen is the person I can always trust.',
                                                            'Is this the podcast you were talking about?',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · whose and where',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>whose</strong> = his / her / their: <strong>a friend whose sister speaks four languages</strong>. <strong>where</strong> = in or at that place: <strong>a party where I didn\'t know anyone</strong>.',
                                                    'fa' => 'whose به جای his / her / their می‌آید و بعدش یک اسم می‌آید: a woman whose son is a firefighter. where برای مکان است: the town where I grew up. بعد از where دیگر there یا in را تکرار نکن.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'whose',
                                                    'parts' => [
                                                        ['text' => 'I have a friend', 'caption' => 'person'],
                                                        ['text' => 'whose', 'caption' => 'whose', 'tone' => 'accent'],
                                                        ['text' => 'sister', 'caption' => 'noun'],
                                                        ['text' => 'speaks four languages', 'caption' => 'verb + rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I know a man who his son is a doctor.', 'right' => 'I know a man whose son is a doctor.', 'highlight' => 'whose'],
                                                        ['wrong' => 'That\'s the café where we met there.', 'right' => 'That\'s the café where we met.', 'highlight' => 'where we met'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'He\'s someone whose friends call him when they have a problem.',
                                                            'A house party is a place where extroverts feel at home.',
                                                            'My room is the place where I recharge.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'E · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about <strong>your</strong> people — the friend who…, the place where… — then watch Bob define personality words with "someone who…", and soon say them out loud in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this right away — in Video Shadowing next, and in AI Conversation #1.',
                                ],
                                'frequency_starters' => [
                                    'My best friend is someone who', 'I don\'t like people who', 'The person I talk to most',
                                    'I have a friend whose', 'The place where I feel most relaxed', 'I\'m the kind of person who',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about people, things or places in their life, using '
                                    .'a correct defining relative clause: who / that after a person, that / which after a '
                                    .'thing, the pronoun left out only when another subject follows it ("the person I talk '
                                    .'to most"), whose + noun for possession, or where for a place.',
                                'grammar_major_criteria' => 'which or what after a person ("a friend which"), who after a '
                                    .'thing ("a job who"), an extra pronoun inside the clause ("a friend who she speaks", '
                                    .'"the café where we met there"), who his / who her instead of whose, the subject pronoun '
                                    .'left out ("the man lives next door is…"), a verb that does not agree with the noun before '
                                    .'who ("someone who love"), what instead of that after a noun ("the thing what"), the '
                                    .'sentence does not actually continue the given starter, or it is not a genuine personal '
                                    .'statement',
                                'grammar_context' => 'continues with a defining relative clause (who / that / which, no '
                                    .'pronoun when another subject follows, whose or where) and a main verb, as the starter '
                                    .'already given needs',
                                'quick_check' => [
                                    [
                                        'wrong' => 'I have a friend which speaks four languages.',
                                        'options' => ['I have a friend who speaks four languages.', 'I have a friend which speaks four languages.', 'I have a friend what speaks four languages.'],
                                        'correct' => 0,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'It\'s a test who tells you your personality type.',
                                        'options' => ['It\'s a test who tells you your personality type.', 'It\'s a test it tells you your personality type.', 'It\'s a test that tells you your personality type.'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'I know a woman who her son is a firefighter.',
                                        'options' => ['I know a woman who her son is a firefighter.', 'I know a woman whose son is a firefighter.', 'I know a woman where her son is a firefighter.'],
                                        'correct' => 1,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'The man lives next door is very calm.',
                                        'options' => ['The man lives next door is very calm.', 'The man who lives next door is very calm.', 'The man he lives next door is very calm.'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['who', 'is', 'someone', 'An', 'loves', 'parties.', 'extrovert'], 'answer' => 'An extrovert is someone who loves parties.'],
                                    ['words' => ['I', 'the', 'is', 'trust', 'person', 'most.', 'She'], 'answer' => 'She is the person I trust most.'],
                                    ['words' => ['a', 'is', 'This', 'café', 'recharge.', 'where', 'I'], 'answer' => 'This is a café where I recharge.'],
                                ],
                            ],
                            [
                                // A real teacher at natural speed: Bob the Canadian's
                                // "Describing People" lesson — each word shown on a card with
                                // its "someone who…" definition, then Bob's own stories —
                                // cut to about 6 minutes (nine words kept; aggressive, vulgar,
                                // violent, cruel and the side stories left out). The creator
                                // burns no captions in, so the step's own caption toggle works.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Bob explains nine personality words the way a dictionary would — "someone who…" — and then tells you which ones are true about him. Watch, then say the definitions with him.',
                                'source' => 'Learn English with Bob the Canadian — "Let\'s Learn English! Topic: Describing People!"',
                                'video_url' => $shadowingVideoUrl,
                                // The creator's own English captions, re-timed per sentence on
                                // Whisper's word timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M09', 'video_shadowing_segments'),
                                'topic_summary' => 'Bob describes people with one pattern: a word, then "someone who…". '
                                    .'A warm person is someone who makes others feel comfortable, like his uncle. He '
                                    .'admits he is impatient when Jen and the kids are not ready to leave, and he wishes he '
                                    .'were calm, like Jen. He is loyal to the mechanic he has used since he was 16, '
                                    .'firefighters are courageous, and a timid person is shy and easily scared. Last come '
                                    .'sympathetic, generous — with money, time or food — and frugal: someone who is careful '
                                    .'with money and avoids wasting it.',
                                'comprehension_check' => [
                                    ['statement' => 'Bob says he is sometimes impatient, for example when his family is not ready to leave.', 'correct' => true],
                                    ['statement' => 'Bob says Jen gets upset quickly when they disagree.', 'correct' => false],
                                    ['statement' => 'Bob has taken his car to the same mechanic since he was 16.', 'correct' => true],
                                ],
                                // Real phrases Bob uses on camera.
                                'target_phrases' => [
                                    ['phrase' => 'get annoyed', 'meaning' => 'to become a bit angry'],
                                    ['phrase' => 'take too long', 'meaning' => 'to need more time than you want'],
                                    ['phrase' => 'stay faithful', 'meaning' => 'to keep supporting someone and not leave them'],
                                    ['phrase' => 'face danger', 'meaning' => 'to be brave when something could hurt you'],
                                    ['phrase' => 'be generous with your time', 'meaning' => 'to give people your time happily'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words Bob
                                // actually says. Chosen for this mission's grammar: five
                                // "someone who…" definitions and one "relatives … who are".
                                // Bold marks naturally-stressed content words (rendered by
                                // <x-stress-marked-line>).
                                'shadow_lines' => [
                                    'I have some **relatives** in my family who are very **warm** people.',
                                    'Someone who gets **annoyed** **easily** when things take too **long**.',
                                    'Someone who stays **relaxed** and does not get **upset** easily.',
                                    'Someone who is **brave** and not **afraid** to face **danger** or **difficulty**.',
                                    'I\'m someone who **likes** to **give** and **share** with others.',
                                    'Someone who is **careful** with **money** and avoids **wasting** it.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M09', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe a friend who is very reliable.', 'answer' => 'Sara is a friend who is always on time. She\'s the most reliable person I know.', 'words' => ['who', 'reliable']],
                            ['question' => 'Who is the most helpful person at your work or school?', 'answer' => 'Our teacher is someone who is always helpful. She stays after class to answer questions.', 'words' => ['someone who', 'helpful']],
                            ['question' => 'Are you careful or careless with money?', 'answer' => 'I\'m careful with money. I don\'t buy things that I don\'t need.', 'words' => ['careful', 'things that']],
                            ['question' => 'What kind of person is good to travel with?', 'answer' => 'Someone sociable and reasonable. I don\'t like people who complain all the time.', 'words' => ['sociable', 'reasonable', 'people who']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the words you need to describe someone you can count on (and someone you can\'t).',
                                // Vocabulary in Use unit 72B-C (-able, then -ful / -less),
                                // our own sentences.
                                'story' => 'My colleague Darya is someone you can always count on — she\'s very **reliable**. '
                                    .'She\'s **sociable** too, and she\'s always **helpful** when someone has a problem. She\'s '
                                    .'**careful** with her work, and she\'s **reasonable** when we disagree. Her brother? He\'s '
                                    .'a bit **careless** — he loses his keys every week!',
                                'words' => [
                                    ['phrase' => 'reliable', 'meaning' => 'someone you can trust to do what they say (opposite: unreliable)', 'meaning_fa' => 'قابل‌اعتماد', 'pos' => 'adjective', 'example' => 'Arman is reliable — if he says he\'ll come at eight, he comes at eight.', 'difficulty' => 'medium'],
                                    ['phrase' => 'sociable', 'meaning' => 'friendly and enjoying time with other people (opposite: unsociable)', 'meaning_fa' => 'معاشرتی، اهل رفت‌وآمد', 'pos' => 'adjective', 'example' => 'My parents are very sociable — they have guests every weekend.', 'image_query' => 'friends dinner party table', 'difficulty' => 'medium'],
                                    ['phrase' => 'reasonable', 'meaning' => 'fair and sensible; (a price) not too expensive', 'meaning_fa' => 'منطقی، معقول', 'pos' => 'adjective', 'example' => 'My boss is reasonable — she listens before she decides.', 'difficulty' => 'medium'],
                                    ['phrase' => 'helpful', 'meaning' => 'happy to help people; useful', 'meaning_fa' => 'کمک‌کننده، مفید', 'pos' => 'adjective', 'example' => 'The woman at the station was very helpful.', 'image_query' => 'person helping elderly woman', 'difficulty' => 'easy'],
                                    ['phrase' => 'careful', 'meaning' => 'thinking about what you do, so that you don\'t make mistakes or have accidents', 'meaning_fa' => 'محتاط، بادقت', 'pos' => 'adjective', 'example' => 'Be careful — the floor is wet.', 'difficulty' => 'easy'],
                                    ['phrase' => 'careless', 'meaning' => 'not thinking enough about what you do, so you make mistakes (opposite: careful)', 'meaning_fa' => 'بی‌دقت، سهل‌انگار', 'pos' => 'adjective', 'example' => 'I made some careless mistakes in my test.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — now listen for "students who…" and "the things that…".',
                                'image_query' => 'student speaking confidently in class',
                                'shadow_lines' => [
                                    'Students who are **more** **self-confident**, who feel **less** **anxious** in social situations',
                                    'It\'s one of the **things** that **makes** her so **amazing** at learning a language.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M09', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about what kind of person you are — '
                                    .'introvert, extrovert or ambivert? — and describe two people you know with "someone '
                                    .'who…" and "a friend whose…", using the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'Are you an introvert, an extrovert or an ambivert? Why do you think so?',
                                    'What kind of person drains you, and what kind of person gives you energy?',
                                    'Describe a friend who is very reliable.',
                                    'Is there someone in your family whose personality is very different from yours?',
                                    'Where do you go when you need to recharge?',
                                    'What is the most important quality in a friend?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Nadia needs a flatmate. Three people answer her advert — which one is the person she is looking for?',
                                'passage_title' => 'Looking for the right flatmate',
                                'image_query' => 'two women sharing apartment kitchen',
                                // Written fresh for this app (EOS-009 §14) — reuses the Vocabulary
                                // Builder words in a new context, third person instead of first,
                                // with a relative clause in almost every sentence.
                                'passage' => 'Nadia is looking for a flatmate, so she put an advert online: "I\'m a reserved, '
                                    .'careful person who works from home. I\'m looking for someone who is reliable and '
                                    .'reasonable." Three people answered. The first was a man whose messages were full of '
                                    .'mistakes — a bit careless, Nadia thought. The second was Tara, a sociable student who '
                                    .'loves parties. "Big parties drain me," Nadia said. "I need a flat where I can recharge." '
                                    .'The third was Bahar, a nurse who works at night, so she is at home only in the '
                                    .'afternoons. She was polite and helpful, and she asked lots of curious questions about '
                                    .'the neighbourhood. Nadia was anxious before they met, but Bahar was exactly the kind of '
                                    .'person she wanted. Six months later, they are good friends. "She\'s someone I can '
                                    .'trust," Nadia says. "And she\'s very proud of our clean kitchen!"',
                                'highlighted_phrases' => [
                                    ['phrase' => 'reserved', 'type' => 'reused'],
                                    ['phrase' => 'careful', 'type' => 'reused'],
                                    ['phrase' => 'reliable', 'type' => 'reused'],
                                    ['phrase' => 'reasonable', 'type' => 'reused'],
                                    ['phrase' => 'careless', 'type' => 'reused'],
                                    ['phrase' => 'sociable', 'type' => 'reused'],
                                    ['phrase' => 'drain me', 'type' => 'reused'],
                                    ['phrase' => 'recharge', 'type' => 'reused'],
                                    ['phrase' => 'helpful', 'type' => 'reused'],
                                    ['phrase' => 'curious', 'type' => 'reused'],
                                    ['phrase' => 'anxious', 'type' => 'reused'],
                                    ['phrase' => 'proud', 'type' => 'reused'],
                                    ['phrase' => 'flatmate', 'type' => 'new', 'definition' => 'a person you share a flat with', 'pos' => 'noun', 'example' => 'My flatmate always forgets to buy milk.'],
                                    ['phrase' => 'advert', 'type' => 'new', 'definition' => 'a notice or short text that tells people about something (also: ad)', 'pos' => 'noun', 'example' => 'I found my job through an advert online.'],
                                ],
                                'topic_summary' => 'Nadia, a reserved and careful person, wants a flatmate who is reliable and '
                                    .'reasonable. The first man is careless, and Tara is a sociable student who loves parties. '
                                    .'Bahar, a nurse who works at night, is helpful and curious — and six months later they '
                                    .'are good friends.',
                                'comprehension_check' => [
                                    ['statement' => 'Nadia wanted a flatmate who was reliable and reasonable.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Nadia chose Tara because she loves parties.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Bahar is usually at home in the afternoons because she works at night.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'What kind of person was Nadia looking for?',
                                    'Why didn\'t Nadia choose the first two people?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Describing yourself in English is harder than it sounds — and it is the first thing a new friend or boss will ask.',
                                'title' => 'What kind of person am I?',
                                'prompts' => [
                                    ['label' => 'Introvert, extrovert or ambivert?', 'image_query' => 'woman reading alone cafe'],
                                    ['label' => 'Three words that describe you', 'image_query' => 'smiling young man portrait'],
                                    ['label' => 'A person who knows you well', 'image_query' => 'two friends talking bench'],
                                    ['label' => 'Something that drains you', 'image_query' => 'tired man desk evening'],
                                    ['label' => 'A place where you recharge', 'image_query' => 'quiet room window plants'],
                                    ['label' => 'A quality you want to have', 'image_query' => 'calm woman meditation'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['who / that (people)', 'that / which (things)', 'no who / that before a new subject', 'whose', 'where', 'reliable', 'recharge'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Describe someone whose personality you admire.', 'answer' => 'My grandmother is a woman whose patience is amazing. She never gets angry.', 'words' => ['whose']],
                            ['question' => 'What kind of person are you?', 'answer' => 'I\'m the kind of person who needs time alone to recharge.', 'words' => ['the kind of person who', 'recharge']],
                            ['question' => 'Describe a place where you feel relaxed.', 'answer' => 'My room is the place where I recharge. It\'s quiet and full of books.', 'words' => ['the place where', 'recharge']],
                            ['question' => 'What do you look for in a friend?', 'answer' => 'I want a friend who is honest and reliable, someone I can trust.', 'words' => ['a friend who', 'reliable']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'A room full of people — who is the life of the party, and who would rather be at home?',
                                'image_query' => 'friends party living room',
                                'guiding_questions' => [
                                    'Find the person who looks the most outgoing. What is he or she doing?',
                                    'Is there someone who looks quieter than the others? Describe them.',
                                    'Describe a person whose clothes or hair you like.',
                                    'What kind of place is this — a place where you would feel on top of the world, or a place that would drain you?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): four friends on a
                                // sofa in a basement room — on the left a young man in a white
                                // sleeveless jumper, then a woman with short white hair in pink,
                                // a man in a yellow shirt and glasses laughing loudly, and on
                                // the right, in a beanbag, a calmer woman with long dark hair
                                // holding a bowl of popcorn; snacks and a radio in front.
                                // Changing the query or orientation invalidates these
                                // coordinates.
                                'hotspots' => [
                                    ['x' => 61, 'y' => 45, 'question_index' => 0],
                                    ['x' => 82, 'y' => 52, 'question_index' => 1],
                                    ['x' => 50, 'y' => 38, 'question_index' => 2],
                                    ['x' => 60, 'y' => 88, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'What kind of person are you? Give examples from your life.',
                                    'Describe someone who gives you energy, and someone who drains you.',
                                    'Tell me about a friend whose personality is very different from yours.',
                                    'What is a place where you can really relax? Why?',
                                    'Do you think introverts or extroverts learn languages faster? Why?',
                                    'What kind of person would be the perfect flatmate or colleague for you?',
                                ],
                                'role_reversal_topic' => 'personality and the people in your life',
                                'final_prompt' => 'Speak for 3 minutes without stopping about what kind of person you are and the people who matter most to you.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'who / that for people',
                                    'that / which for things',
                                    'a clause without who / that',
                                    'whose or where',
                                    'What kind of person you are',
                                    'Someone who gives you energy',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Seeds M10 "Relationships" — the roadmap's Present Perfect with for /
     * since. M05's 16-step layout (no story_sequence: the present perfect
     * links the past to now — how long, ever, never, yet — it is not a
     * story told in order, so it is practised in Picture Description — "how
     * long have they been married?" — and the two AI conversations instead).
     * The lesson follows Grammar in Use units 7-8 (have / has + past
     * participle; ever / never, just / already / yet) and 11-12 (how long…?,
     * for / since, when…? vs how long…?); units 9-10 (continuous) and 13-14
     * (present perfect vs past) are left for later. Vocabulary days 2-3
     * follow Vocabulary in Use units 15 (Romance, marriage and divorce) and
     * 60C (approximate time: for ages, recently, lately…), all rewritten in
     * our own words. Pair speaking cards ask about other people's
     * relationships — parents, grandparents, couples in films — never the
     * learner's own love life. Media the user picked: Vanessa and her
     * husband Dan's live lesson on relationships (cut from 50 to about 6
     * minutes: how long they have been married and known each other, don't
     * rush, have fun together, take a breath before you speak) and Bob the
     * Canadian and Jen's Q&A about marriage (cut from 26 to about 6
     * minutes: getting engaged, rings, getting more mature, the most
     * unforgettable place, putting the other first, the empty nest).
     */
    private function seedM10(): void
    {
        $audioUrl = $this->publishMissionAsset('M10', 'Speak English with Vanessa - Relationships with Dan.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M10', 'Bob the Canadian - Marriage with Jen.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M10', 'Bob the Canadian - Marriage with Jen.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M10'],
            [
                'title' => 'Relationships',
                'module' => 'Relationships',
                'outcome' => 'I can talk about the relationships around me and how long they have lasted — they\'ve been married for…, we\'ve known each other since…, I\'ve never….',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Why should people take advice about love with a grain of salt?', 'answer' => 'Advice is just one person\'s opinion, so I take it with a grain of salt.', 'words' => ['take it with a grain of salt']],
                            ['question' => 'Why is it a bad idea to rush into marriage?', 'answer' => 'If you rush into marriage, you don\'t really know the other person yet.', 'words' => ['rush into']],
                            ['question' => 'How do the happy couples in films maintain a good relationship?', 'answer' => 'They maintain it by talking a lot and having fun together.', 'words' => ['maintain']],
                            ['question' => 'What happens to a film character who has a temper?', 'answer' => 'He has a temper, so small problems build up and one day he explodes.', 'words' => ['temper', 'build up']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'Think of the oldest couple you know. Could you say in English how long they have been together?',
                                'image_query' => 'couple holding hands walking',
                                'ambient_video_query' => 'old couple walking park',
                                'warm_up_questions' => [
                                    'Who is the oldest couple in your family?',
                                    'How long have you known your best friend?',
                                    'What makes a relationship last a long time?',
                                    'Do you like films about love? Which one?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases Vanessa and Dan use about their marriage — you\'ll need them to talk about any couple.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'My aunt Shirin has a lot of advice about love, but I **take it with a grain of salt**. '
                                    .'She says, "Don\'t **rush into** marriage, and talk about small problems before they '
                                    .'**build up**." My uncle **has a temper**, so she knows! Still, they have been married for '
                                    .'thirty years, and they **maintain** a happy home.',
                                'words' => [
                                    ['phrase' => 'maintain', 'meaning' => 'to keep something in good condition, so that it continues', 'meaning_fa' => 'حفظ کردن، نگه داشتن', 'pos' => 'verb', 'example' => 'It takes work to maintain a strong friendship.', 'difficulty' => 'medium'],
                                    ['phrase' => 'take it with a grain of salt', 'meaning' => 'to not believe all of something, because it may not be completely true for you', 'meaning_fa' => 'با احتیاط پذیرفتن، صددرصد باور نکردن', 'pos' => 'idiom', 'example' => 'It\'s only my opinion, so take it with a grain of salt.', 'difficulty' => 'hard'],
                                    ['phrase' => 'rush into', 'meaning' => 'to start something important too quickly, without thinking enough', 'meaning_fa' => 'عجولانه وارد کاری شدن', 'pos' => 'phrasal verb', 'example' => 'Don\'t rush into buying a house.', 'image_query' => 'man running late hurry', 'difficulty' => 'medium'],
                                    ['phrase' => 'have a temper', 'meaning' => 'to get angry quickly and easily', 'meaning_fa' => 'زود عصبانی شدن، تندخو بودن', 'pos' => 'phrase', 'example' => 'My brother has a temper, but he calms down fast.', 'image_query' => 'angry man shouting', 'difficulty' => 'medium'],
                                    ['phrase' => 'build up', 'meaning' => 'to slowly grow bigger or stronger', 'meaning_fa' => 'کم‌کم جمع شدن، انباشته شدن', 'pos' => 'phrasal verb', 'example' => 'If you never talk about problems, they build up.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Vanessa gives her husband a little test: how long have we been married? Listen to find out if he passes — and what keeps them together.',
                                'source' => 'Speak English with Vanessa — "LIVE: English Conversation Lesson: Relationships!" (with her husband Dan)',
                                'image_query' => 'happy couple talking at home',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M10', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. Two speakers: Vanessa
                                // and Dan.
                                'listening_turns' => $this->shadowTimestampsFor('M10', 'listening_turns'),
                                // The five parts kept in the cut (1:35-4:28, 6:47-7:11,
                                // 12:15-13:24, 14:55-15:30 and 20:32-21:31 of the
                                // original), written from the video's human captions and
                                // checked against a Whisper pass of the cut audio.
                                'transcript' => [
                                    ['speaker' => 'Vanessa', 'text' => 'Let\'s start with a couple pieces of factual information. How long have we… This is a kind of test.'],
                                    ['speaker' => 'Dan', 'text' => 'Oh, it\'s a test for me.'],
                                    ['speaker' => 'Vanessa', 'text' => 'How long have we been married?'],
                                    ['speaker' => 'Dan', 'text' => 'We\'ve been married eight years.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Oh, he passed the test. This year in August it will be nine years. So we\'ve been married eight and a half years or so.'],
                                    ['speaker' => 'Dan', 'text' => 'Which is a long time for the average American of our age, because we\'re only 31. So we\'ve been married a little while.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes. And when did we meet each other?'],
                                    ['speaker' => 'Dan', 'text' => 'We met each other the very first day of college, which is university in other countries.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes, so I was 17 years old, but I was almost 18. The next week was my birthday, so I was pretty much 18 years old, and you were 18 too, right?'],
                                    ['speaker' => 'Dan', 'text' => 'Yeah.'],
                                    ['speaker' => 'Vanessa', 'text' => 'So we\'ve known each other for quite a long time, from 18 to 31. What is that? 13 years? A long time. And a lot has happened during that time. I think knowing someone, being in a relationship with someone for 13 years, is normal for maybe our parents\' generation, but for our generation it\'s something that\'s a little bit surprising. When people meet us, they\'re surprised that we are 31 and we\'ve been married for eight years.'],
                                    ['speaker' => 'Dan', 'text' => 'Yeah, and that we\'ve only dated each other for a really long time.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes.'],
                                    ['speaker' => 'Dan', 'text' => 'That\'s not very usual, I don\'t think.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yeah, and that we still like each other. I think that there\'s a lot of…'],
                                    ['speaker' => 'Dan', 'text' => 'Do we?'],
                                    ['speaker' => 'Vanessa', 'text' => 'You\'ll find out today.'],
                                    ['speaker' => 'Dan', 'text' => 'We do.'],
                                    ['speaker' => 'Vanessa', 'text' => 'So there\'s a lot of things that we do in our relationship, or principles that we have, that have really helped us to maintain a healthy, strong relationship. And those are the two words that I want to focus on today: having a healthy relationship, not just how to find a boyfriend. I can\'t give you advice on that… or find a girlfriend. But having a healthy relationship and having a strong relationship, it means that you feel confident in your relationship. You feel confident in yourself…'],
                                    ['speaker' => 'Dan', 'text' => 'Yes.'],
                                    ['speaker' => 'Vanessa', 'text' => '…when you\'re part of that relationship.'],
                                    ['speaker' => 'Dan', 'text' => 'And it means it will last a long time. And if you want to have children, it will be a good relationship to have children. Because really, if you\'re going to get married, it\'s probably mostly to have children, in my personal opinion.'],
                                    ['speaker' => 'Vanessa', 'text' => 'So today, before we get started, I\'d like to give a couple disclaimers. First of all, we have a unique situation, that we met each other when we were young. All of these are personal tips, but that\'s all we can do, is share from our personal lives. We have not been married for 50 years. I know there are plenty of people who have been together much longer than us, so take it with a grain of salt.'],
                                    ['speaker' => 'Dan', 'text' => 'Yes. But apparently it\'s working.'],
                                    ['speaker' => 'Dan', 'text' => 'Connected to that, I would say don\'t rush. So don\'t rush into a relationship. For example, for Vanessa and I, we knew each other for six months before we even dated, and after dating, we didn\'t live together for four years.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes, and of course this is a little bit unusual.'],
                                    ['speaker' => 'Dan', 'text' => 'And we were young.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Because we were so young.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Well, I wanted to share some things that have personally really helped me in our relationship, beyond the general principles, and this is to have fun together. I think that this can apply before you are married, but also during your marriage. I know that we have some friends who have been together for a long time, and then, even though they both like each other, they feel like, "Eh, there\'s nothing special any more. Maybe we shouldn\'t be together." Of course, everyone has their own situation, but for us, it\'s been really helpful to have some common activities that we really like to do together.'],
                                    ['speaker' => 'Vanessa', 'text' => 'There\'s actually a bunch of studies that show… people who are 100 years old, or pretty old, and they\'ve been in a relationship for a while, something that has helped them is to have fun together. Because you\'re not always going to be a honeymoon couple who just met each other. You\'re going to be just normal people.'],
                                    ['speaker' => 'Vanessa', 'text' => 'And something that the marriage counselor who I was watching… something that she mentioned is that sometimes, after you\'ve been with someone for a long time, your relationship tends to get more serious. Not just serious as in you\'re going to stay together, but serious as in your demeanor. Demeanor means your face, your attitude. Your attitude becomes really serious, because you\'re talking about daily life, your job. Are you doing the dishes? Who\'s cooking dinner? Where\'s our baby? Just factual things.'],
                                    ['speaker' => 'Dan', 'text' => 'Yes.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Not really fun things.'],
                                    ['speaker' => 'Dan', 'text' => 'And I think maybe an extra bit of advice — you said take a deep breath. This is a good idea. Take a deep breath. If you\'re feeling a little angry at your partner, before you say something, just breathe. Because, I mean, I know we look very happy all the time, but we get angry with each other, too.'],
                                    ['speaker' => 'Vanessa', 'text' => 'It\'s true.'],
                                    ['speaker' => 'Dan', 'text' => 'I promise you it happens. I\'ve had to learn, especially me. Sometimes I get a little bit… I have a temper. I can get angry.'],
                                    ['speaker' => 'Vanessa', 'text' => 'He can get upset at times.'],
                                    ['speaker' => 'Dan', 'text' => 'I can get angry sometimes.'],
                                    ['speaker' => 'Vanessa', 'text' => 'I think everybody can get upset sometimes.'],
                                    ['speaker' => 'Dan', 'text' => 'So I\'ve had to learn to stop and take a little breath before I say something. Because when you\'re angry, you might say something really mean. And if you say something really bad, your partner is going to remember that.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes.'],
                                    ['speaker' => 'Dan', 'text' => 'So you don\'t want to let a lot of those bad words build up over time.'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript.
                                'target_phrases' => [
                                    [
                                        'phrase' => 'maintain', 'meaning' => 'keep something good going',
                                        'gap_before' => 'that have really helped us to ',
                                        'gap_after' => ' a healthy, strong relationship.',
                                    ],
                                    [
                                        'phrase' => 'take it with a grain of salt', 'meaning' => 'don\'t believe all of it',
                                        'gap_before' => 'who have been together much longer than us, so ',
                                        'gap_after' => '.',
                                    ],
                                    [
                                        'phrase' => 'rush into', 'meaning' => 'start too quickly',
                                        'gap_before' => 'So don\'t ',
                                        'gap_after' => ' a relationship.',
                                    ],
                                    [
                                        'phrase' => 'have a temper', 'meaning' => 'get angry quickly',
                                        'gap_before' => 'Sometimes I get a little bit… I ',
                                        'gap_after' => '. I can get angry.',
                                    ],
                                    [
                                        'phrase' => 'build up', 'meaning' => 'slowly grow bigger',
                                        'gap_before' => 'let a lot of those bad words ',
                                        'gap_after' => ' over time.',
                                    ],
                                ],
                                'topic_summary' => 'Vanessa and her husband Dan are 31. They have been married for about '
                                    .'eight and a half years, and they have known each other since the first day of '
                                    .'college — 13 years. People are often surprised, so they say: take our tips with a '
                                    .'grain of salt. Dan\'s tip is don\'t rush into a relationship: they knew each other '
                                    .'for six months before they dated. Vanessa\'s tip is to have fun together, because '
                                    .'after you\'ve been with someone for a long time, life gets serious. And Dan has had '
                                    .'to learn to take a breath before he speaks when he is angry, so bad words don\'t '
                                    .'build up.',
                                'comprehension_check' => [
                                    ['statement' => 'Vanessa and Dan have been married for about eight and a half years.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Vanessa and Dan lived together for four years before they started dating.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Dan says he has had to learn to take a breath before he speaks when he is angry.', 'correct' => true, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'How long did Vanessa and Dan know each other before they started dating?',
                                    'options' => ['Six months', 'Four years', '13 years'],
                                    'correct' => 0,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'How long have your parents been married?', 'answer' => 'My parents have been married for thirty years. They got married in 1995.', 'words' => ['have been married', 'for', 'got married']],
                            ['question' => 'Where do couples in your country usually go on their honeymoon?', 'answer' => 'A lot of couples go to Kish or Turkey on their honeymoon.', 'words' => ['honeymoon']],
                            ['question' => 'Why do couples in films often break up?', 'answer' => 'They break up because they have never really talked about their problems.', 'words' => ['break up', 'have never']],
                            ['question' => 'How do your grandparents celebrate their anniversary?', 'answer' => 'They have had a family dinner on every anniversary since they got married.', 'words' => ['anniversary', 'since']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Six words for a love story from the first date to the anniversary — and, sometimes, the end.',
                                // Vocabulary in Use unit 15 (Romance, marriage and divorce),
                                // our own sentences.
                                'story' => 'My cousin Neda **went out with** Kian for two years. They **broke up** once, but '
                                    .'after a month they were back together. Last spring they **got engaged**, and they **got '
                                    .'married** in September. Their **honeymoon** was a week in Shiraz. Next month is their '
                                    .'first wedding **anniversary**.',
                                'words' => [
                                    ['phrase' => 'go out with', 'meaning' => 'to have a romantic relationship with someone', 'meaning_fa' => 'با کسی دوست بودن، با کسی بیرون رفتن (رابطه‌ی عاطفی)', 'pos' => 'phrasal verb', 'example' => 'She went out with him for a year before they got engaged.', 'difficulty' => 'medium'],
                                    ['phrase' => 'break up', 'meaning' => 'to end a romantic relationship', 'meaning_fa' => 'به هم زدن، جدا شدن', 'pos' => 'phrasal verb', 'example' => 'They broke up after six months.', 'difficulty' => 'medium'],
                                    ['phrase' => 'get engaged', 'meaning' => 'to formally agree to marry someone', 'meaning_fa' => 'نامزد کردن', 'pos' => 'phrase', 'example' => 'My sister got engaged last week — look at her ring!', 'image_query' => 'engagement ring proposal', 'difficulty' => 'easy'],
                                    ['phrase' => 'get married', 'meaning' => 'to become husband and wife (get married to someone, not with)', 'meaning_fa' => 'ازدواج کردن', 'pos' => 'phrase', 'example' => 'They got married in a small garden.', 'image_query' => 'wedding couple garden', 'difficulty' => 'easy'],
                                    ['phrase' => 'honeymoon', 'meaning' => 'a holiday that a couple takes just after they get married', 'meaning_fa' => 'ماه عسل', 'pos' => 'noun', 'example' => 'They went to the mountains on their honeymoon.', 'image_query' => 'couple beach honeymoon', 'difficulty' => 'easy'],
                                    ['phrase' => 'anniversary', 'meaning' => 'the same day as an important event in an earlier year', 'meaning_fa' => 'سالگرد', 'pos' => 'noun', 'example' => 'Today is my parents\' 25th wedding anniversary.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — this time copy how Vanessa says how long: "for quite a long time", "for 50 years".',
                                'image_query' => 'couple laughing kitchen',
                                'shadow_lines' => [
                                    'So we\'ve **known** each other for **quite** a **long** **time**.',
                                    'We have **not** been **married** for 50 **years**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M10', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 15,
                                'hook' => 'A relationship is measured in time: "We\'ve known each other since college", "They\'ve been married for 46 years".',
                                'focus' => 'Present Perfect with for / since',
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use units 7-8 (have / has + past
                                // participle, ever / never, just / already / yet) and 11-12
                                // (how long…?, for / since, when…? vs how long…?).
                                'lesson' => [
                                    'intro' => 'The present perfect joins the past to now: they\'ve got engaged, I\'ve never been to a wedding abroad, we\'ve known each other for years.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · have / has + past participle',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Use <strong>have / has + past participle</strong> for something in the past that matters now: <strong>He\'s lost his wedding ring.</strong> (He doesn\'t have it now.)',
                                                    'fa' => 'حال کامل = have / has + اسم مفعول (p.p). برای کاری در گذشته که نتیجه‌اش الان مهم است یا خبرِ تازه است. زمانِ دقیق را نمی‌گوییم؛ با زمانِ تمام‌شده (yesterday, in 2015) گذشته‌ی ساده لازم است. been to یعنی رفته و برگشته، gone to یعنی رفته و هنوز آنجاست.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'have / has + past participle',
                                                    'parts' => [
                                                        ['text' => 'They', 'caption' => 'person'],
                                                        ['text' => '\'ve', 'caption' => 'have / has'],
                                                        ['text' => 'got engaged', 'caption' => 'past participle', 'tone' => 'accent'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I have saw that film three times.', 'right' => 'I have seen that film three times.', 'highlight' => 'seen'],
                                                        ['wrong' => 'He have lost his ring.', 'right' => 'He has lost his ring.', 'highlight' => 'has'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the listening', 'items' => [
                                                            'A lot has happened during that time.',
                                                            'For us, it\'s been really helpful to have some common activities.',
                                                            'I\'ve had to learn to stop and take a little breath.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · ever / never, just / already / yet',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>ever</strong> = at any time in your life (questions); <strong>never</strong> = not at any time. <strong>just</strong> = a moment ago, <strong>already</strong> = sooner than expected, <strong>yet</strong> = until now (questions and negatives, at the end).',
                                                    'fa' => 'ever در سؤال: «تا حالا…؟» و never یعنی «هیچ‌وقت» (با فعل مثبت: I\'ve never…). just و already بین have و p.p می‌آیند؛ yet آخرِ جمله و فقط در سؤال و منفی. بعد از It\'s the first time هم حال کامل می‌آید: It\'s the first time I\'ve been to a wedding.',
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'ever / never', 'words' => ['Have you ever been to…?', 'I\'ve never…', 'the best film I\'ve ever seen']],
                                                        ['label' => 'just / already / yet', 'words' => ['They\'ve just got engaged.', 'I\'ve already told her.', 'Has he called yet?']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'Did you ever been to a wedding abroad?', 'right' => 'Have you ever been to a wedding abroad?', 'highlight' => 'Have you ever been'],
                                                        ['wrong' => 'They haven\'t chosen a date already.', 'right' => 'They haven\'t chosen a date yet.', 'highlight' => 'yet'],
                                                        ['wrong' => 'It\'s the first time I see a wedding like this.', 'right' => 'It\'s the first time I\'ve seen a wedding like this.', 'highlight' => 'I\'ve seen'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the video', 'items' => [
                                                            'Where is the most unforgettable place you have ever been to with Jen?',
                                                            'There\'s some places that we have never been able to go to.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · How long…? for / since',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'For something that started in the past and is still true, ask <strong>How long have you…?</strong> and answer with <strong>for</strong> + a length of time or <strong>since</strong> + the start: <strong>for eight years / since college</strong>.',
                                                    'fa' => 'در فارسی می‌گوییم «ده سال است که می‌شناسمش» با حال ساده؛ در انگلیسی حال کامل لازم است: I\'ve known her for ten years (نه I know her). for + مدت (for ten years, for ages)، since + نقطه‌ی شروع (since 2015, since we met). know و be و have معمولاً ing نمی‌گیرند. for را گاهی می‌اندازند: We\'ve been married eight years.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'How long + for / since',
                                                    'parts' => [
                                                        ['text' => 'We\'ve known', 'caption' => 'have + past participle'],
                                                        ['text' => 'each other', 'caption' => 'rest'],
                                                        ['text' => 'for 13 years', 'caption' => 'for + length', 'tone' => 'accent'],
                                                        ['text' => '/ since college', 'caption' => 'since + start', 'tone' => 'accent'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I know him for five years.', 'right' => 'I\'ve known him for five years.', 'highlight' => 'I\'ve known'],
                                                        ['wrong' => 'They\'ve been married since ten years.', 'right' => 'They\'ve been married for ten years.', 'highlight' => 'for'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'for…', 'words' => ['eight years', 'six months', 'a long time', 'ages']],
                                                        ['label' => 'since…', 'words' => ['2015', 'college', 'last summer', 'we met']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · When…? or How long…?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>When did…?</strong> asks about a finished time (past simple). <strong>How long have…?</strong> asks about the time until now: <strong>When did they meet? — In 2010. How long have they known each other? — Since 2010.</strong>',
                                                    'fa' => 'با زمانِ تمام‌شده (in 2010, last year, ago) گذشته‌ی ساده؛ برای «از آن موقع تا حالا» حال کامل. در منفی: I haven\'t seen her for ages / since May. و می‌توانی بگویی It\'s been two years since we met.',
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Reza',
                                                    'wrong' => 'My parents have met in 1990.',
                                                    'right' => 'My parents met in 1990.',
                                                    'explanation' => 'In 1990 is a finished time, so it needs the past simple. For the time until now, use the present perfect: they\'ve known each other since 1990.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'When did they get engaged? — Last year.',
                                                            'How long have they been engaged? — For a year.',
                                                            'I haven\'t seen my cousins since the summer.',
                                                            'It\'s been ages since we went to a wedding.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'E · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about <strong>your</strong> people — how long you\'ve known them, what you\'ve never done — then watch Bob and Jen answer questions about their marriage, and soon say it out loud in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this right away — in Video Shadowing next, and in AI Conversation #1.',
                                ],
                                'frequency_starters' => [
                                    'My parents have been married for', 'I\'ve known my best friend since', 'I have never',
                                    'My family has lived in our home for', 'I haven\'t seen my cousins since', 'The best film about love I\'ve ever seen',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about the people and relationships in their life, using '
                                    .'the present perfect correctly: have / has + past participle for a period up to now, for + '
                                    .'a length of time or since + a starting point, ever / never / just / already / yet in the '
                                    .'right place, and the past simple only for a finished time ("we met in 2015").',
                                'grammar_major_criteria' => 'the present simple instead of the present perfect for a period '
                                    .'up to now ("I know him for five years"), since with a length of time ("since ten years") or '
                                    .'for with a starting point ("for 2015"), the past simple form instead of the past participle '
                                    .'("I have saw"), has / have not agreeing with the subject, the present perfect with a '
                                    .'finished time ("we have met in 2015"), yet in a positive statement, the sentence does not '
                                    .'actually continue the given starter, or it is not a genuine personal statement',
                                'grammar_context' => 'continues with the present perfect (have / has + past participle) and, '
                                    .'where the starter needs it, a length of time after for or a starting point after since',
                                'quick_check' => [
                                    [
                                        'wrong' => 'I know her since 2015.',
                                        'options' => ['I\'ve known her since 2015.', 'I know her since 2015.', 'I\'m knowing her since 2015.'],
                                        'correct' => 0,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'They have been married since ten years.',
                                        'options' => ['They have been married since ten years.', 'They have been married for ten years.', 'They are married for ten years.'],
                                        'correct' => 1,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'Have you ever went to a wedding abroad?',
                                        'options' => ['Did you ever went to a wedding abroad?', 'Have you ever went to a wedding abroad?', 'Have you ever been to a wedding abroad?'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'How long are they together?',
                                        'options' => ['How long are they together?', 'How long have they been together?', 'How long do they be together?'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['married', 'eight', 'They', 'for', 'been', 'years.', 'have'], 'answer' => 'They have been married for eight years.'],
                                    ['words' => ['since', 'We\'ve', 'other', 'college.', 'each', 'known'], 'answer' => 'We\'ve known each other since college.'],
                                    ['words' => ['been', 'a', 'Have', 'wedding?', 'ever', 'to', 'you'], 'answer' => 'Have you ever been to a wedding?'],
                                ],
                            ],
                            [
                                // A real couple at natural speed: Bob the Canadian and his
                                // wife Jen answer viewers' questions about marriage, each
                                // question shown on a card — cut to about 6 minutes (getting
                                // engaged, rings, disagreeing, the most unforgettable place,
                                // gifts, the secret of a long marriage, the empty nest). The
                                // creator burns no captions in, so the step's own caption
                                // toggle works.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Bob and Jen have been married for a long time. Watch them answer viewers\' questions about marriage — then say their lines about how things have changed.',
                                'source' => 'Learn English with Bob the Canadian — "An English Lesson about Marriage and a Q&A about Marriage" (with Jen)',
                                'video_url' => $shadowingVideoUrl,
                                // The creator's own English captions, re-timed per line on
                                // Whisper's word timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M10', 'video_shadowing_segments'),
                                'topic_summary' => 'Bob and Jen answer viewers\' questions about marriage. Bob proposed and '
                                    .'gave Jen an engagement ring, so she became his fiancée. A woman who has been married '
                                    .'for 46 years asks about rings: Jen\'s ring hasn\'t fit since she had kids, and she '
                                    .'hasn\'t had it redone. They say they have got more mature when they disagree. Bob\'s '
                                    .'most unforgettable place is British Columbia, and they don\'t give each other gifts. '
                                    .'Putting the other person first has really helped them. They call themselves a boring '
                                    .'old couple, and when the kids leave they want to visit places they have never been '
                                    .'able to go to.',
                                'comprehension_check' => [
                                    ['statement' => 'Bob proposed to Jen and gave her an engagement ring.', 'correct' => true],
                                    ['statement' => 'Jen still wears her wedding ring every day.', 'correct' => false],
                                    ['statement' => 'Jen says she has matured a lot in how she responds to Bob.', 'correct' => true],
                                ],
                                // Real phrases Bob and Jen use on camera.
                                'target_phrases' => [
                                    ['phrase' => 'get engaged', 'meaning' => 'to agree to marry someone'],
                                    ['phrase' => 'an engagement ring', 'meaning' => 'the ring you give when you ask someone to marry you'],
                                    ['phrase' => 'get more mature', 'meaning' => 'to become calmer and wiser with time'],
                                    ['phrase' => 'take care of', 'meaning' => 'to look after someone'],
                                    ['phrase' => 'a boring old couple', 'meaning' => 'a couple happy to do quiet, simple things (a joke)'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words Bob
                                // and Jen actually say. Chosen for this mission's grammar:
                                // how long + for, haven't…, have gotten / have matured, ever,
                                // that's been… and never.
                                // Bold marks naturally-stressed content words (rendered by
                                // <x-stress-marked-line>).
                                'shadow_lines' => [
                                    'How **long** have you been **married**? We have been **married** for 46 **years**.',
                                    'I just **haven\'t** gotten it **redone**.',
                                    'I **know** I\'ve **matured** a **lot** in how I **respond** to you.',
                                    'Where is the most **unforgettable** **place** you have **ever** been to with Jen?',
                                    'And I **think** that\'s been **very** **helpful** for us.',
                                    'Places that we have **never** been **able** to go to.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M10', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Has your family had a big get-together lately?', 'answer' => 'No, we haven\'t had one lately. The last one was ages ago.', 'words' => ['lately', 'ages ago']],
                            ['question' => 'Have you seen a good love story recently?', 'answer' => 'Yes, I saw one the other day. It\'s the best film I\'ve seen recently.', 'words' => ['the other day', 'recently']],
                            ['question' => 'How do people meet their partners these days?', 'answer' => 'These days a lot of couples have met online.', 'words' => ['These days', 'have met']],
                            ['question' => 'How long have your grandparents lived in the same house?', 'answer' => 'They\'ve lived there for ages — since 1980.', 'words' => ['for ages', 'since']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the time words that go with the present perfect: for ages, recently, lately.',
                                // Vocabulary in Use unit 60C (approximate periods of time),
                                // our own sentences.
                                'story' => 'I\'ve known my neighbour Mrs Rahimi **for ages**. She moved here **ages ago**, when I '
                                    .'was a child. I haven\'t seen her **lately** — she has been ill **recently**. I visited '
                                    .'her **the other day** with some fruit. "**These days** nobody visits old people," she '
                                    .'said. "Thank you, dear."',
                                'words' => [
                                    ['phrase' => 'for ages', 'meaning' => 'for a very long time (usually with the present perfect)', 'meaning_fa' => 'خیلی وقت است، سال‌ها', 'pos' => 'phrase', 'example' => 'I haven\'t seen him for ages.', 'difficulty' => 'easy'],
                                    ['phrase' => 'ages ago', 'meaning' => 'a long time ago (with the past simple)', 'meaning_fa' => 'خیلی وقت پیش', 'pos' => 'phrase', 'example' => 'They met ages ago, at school.', 'difficulty' => 'medium'],
                                    ['phrase' => 'recently', 'meaning' => 'not long ago; in the last few weeks or months', 'meaning_fa' => 'اخیراً، به‌تازگی', 'pos' => 'adverb', 'example' => 'Have you heard from Sara recently?', 'difficulty' => 'easy'],
                                    ['phrase' => 'lately', 'meaning' => 'in the last few weeks (often in questions and negatives with the present perfect)', 'meaning_fa' => 'این اواخر، تازگی‌ها', 'pos' => 'adverb', 'example' => 'I haven\'t slept well lately.', 'difficulty' => 'medium'],
                                    ['phrase' => 'the other day', 'meaning' => 'a few days ago (with the past simple)', 'meaning_fa' => 'چند روز پیش، همین چند روز پیش', 'pos' => 'phrase', 'example' => 'I saw your brother the other day.', 'difficulty' => 'medium'],
                                    ['phrase' => 'these days', 'meaning' => 'now, compared with the past', 'meaning_fa' => 'این روزها', 'pos' => 'phrase', 'example' => 'These days people often meet online.', 'image_query' => 'young people smartphones cafe', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — now listen for "have been together" and "I\'ve had to learn".',
                                'image_query' => 'old couple laughing together',
                                'shadow_lines' => [
                                    'I know that we have some **friends** who have been **together** for a **long** **time**.',
                                    'So I\'ve had to **learn** to **stop** and take a **little** **breath**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M10', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about a couple or a friendship you '
                                    .'know well — your parents, grandparents, or two friends: how long they have known each '
                                    .'other, how they met, and what has helped them — using for, since and the new '
                                    .'vocabulary, without reading.',
                                'interview_questions' => [
                                    'Who is the couple you know that has been together the longest? How long have they been together?',
                                    'How long have you known your oldest friend? How did you meet?',
                                    'Have you ever been to a wedding that you will never forget?',
                                    'What do you think has helped your parents or grandparents stay together?',
                                    'How have weddings in your country changed recently?',
                                    'Do you think people rush into marriage these days? Why?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Fifty years together — what is their secret? Their granddaughter asked them.',
                                'passage_title' => 'Fifty years and still going strong',
                                'image_query' => 'elderly couple anniversary family',
                                // Written fresh for this app (EOS-009 §14) — reuses the Vocabulary
                                // Builder words in a new context, third person instead of first,
                                // with the present perfect in almost every sentence.
                                'passage' => 'Last Saturday, the Karimi family had a big party: it was Grandma Mahin and '
                                    .'Grandpa Javad\'s golden anniversary. They have been married for fifty years — since the '
                                    .'summer of 1976. Javad went out with Mahin for two years before they got engaged. "We '
                                    .'didn\'t rush into anything," Mahin laughs. Their honeymoon was a short trip to the '
                                    .'Caspian Sea, but they have never forgotten it. Have they ever argued? "Of course!" says '
                                    .'Javad. "I have a temper, and in the first years small problems built up. But I\'ve '
                                    .'learned to take a deep breath." These days they walk together every morning, and they '
                                    .'haven\'t missed a walk for ages. Their granddaughter Sara asked them the other day how '
                                    .'they maintain such a happy marriage. Mahin smiled: "We\'ve always had fun together. But '
                                    .'take our advice with a grain of salt — every couple is different." Recently, Sara has '
                                    .'started to write their story down. She hasn\'t finished it yet.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'anniversary', 'type' => 'reused'],
                                    ['phrase' => 'went out with', 'type' => 'reused'],
                                    ['phrase' => 'got engaged', 'type' => 'reused'],
                                    ['phrase' => 'rush into', 'type' => 'reused'],
                                    ['phrase' => 'honeymoon', 'type' => 'reused'],
                                    ['phrase' => 'have a temper', 'type' => 'reused'],
                                    ['phrase' => 'built up', 'type' => 'reused'],
                                    ['phrase' => 'These days', 'type' => 'reused'],
                                    ['phrase' => 'for ages', 'type' => 'reused'],
                                    ['phrase' => 'the other day', 'type' => 'reused'],
                                    ['phrase' => 'maintain', 'type' => 'reused'],
                                    ['phrase' => 'with a grain of salt', 'type' => 'reused'],
                                    ['phrase' => 'Recently', 'type' => 'reused'],
                                    ['phrase' => 'golden anniversary', 'type' => 'new', 'definition' => 'the day when a couple have been married for fifty years', 'pos' => 'noun', 'example' => 'My grandparents had a big party for their golden anniversary.'],
                                    ['phrase' => 'still going strong', 'type' => 'new', 'definition' => 'still working well or still happy after a long time', 'pos' => 'phrase', 'example' => 'Their friendship started at school, and it\'s still going strong.'],
                                ],
                                'topic_summary' => 'Mahin and Javad have been married for fifty years. He went out with her '
                                    .'for two years before they got engaged, and they have never forgotten their honeymoon. '
                                    .'Javad has a temper, but he has learned to take a deep breath. They walk together every '
                                    .'morning, and their granddaughter Sara has started to write their story down.',
                                'comprehension_check' => [
                                    ['statement' => 'Mahin and Javad have been married since 1976.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'They got engaged a few weeks after they met.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'Sara has already finished writing her grandparents\' story.', 'correct' => false, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'How long have Mahin and Javad been married?',
                                    'What has helped Javad with his temper?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Every family has one couple everyone admires — write their story, and how long it has lasted.',
                                'title' => 'A couple I admire',
                                'prompts' => [
                                    ['label' => 'Who they are', 'image_query' => 'elderly couple smiling portrait'],
                                    ['label' => 'How long they have been together', 'image_query' => 'old wedding photo album'],
                                    ['label' => 'How and when they met', 'image_query' => 'young couple first date cafe'],
                                    ['label' => 'Something that has helped them', 'image_query' => 'couple cooking together kitchen'],
                                    ['label' => 'Something they have never done', 'image_query' => 'couple looking at map travel'],
                                    ['label' => 'What you have learned from them', 'image_query' => 'grandmother talking granddaughter'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['have / has + past participle', 'for + a length of time', 'since + a starting point', 'ever / never', 'just / already / yet', 'anniversary', 'for ages'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Tell me about a couple you know who have been together for a long time.', 'answer' => 'My aunt and uncle have been together for twenty years. They met at university.', 'words' => ['have been together', 'for']],
                            ['question' => 'What is the best film about love you\'ve ever seen?', 'answer' => 'The best one I\'ve ever seen is Titanic. I\'ve watched it three times.', 'words' => ['I\'ve ever seen', 'I\'ve watched']],
                            ['question' => 'What advice would you give a friend who has just got engaged?', 'answer' => 'Don\'t rush into the wedding, and talk about money before you get married.', 'words' => ['rush into', 'get married']],
                            ['question' => 'When did your parents meet, and how long have they known each other?', 'answer' => 'They met in 1990, so they\'ve known each other for over thirty years.', 'words' => ['met', 'they\'ve known', 'for']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'Three generations at one table — how long have these people known each other, and what have they just done?',
                                'image_query' => 'family dinner grandparents',
                                'guiding_questions' => [
                                    'Look at the older couple. How long do you think they have been married?',
                                    'Look at the young man and woman. How long have they known each other, do you think?',
                                    'What have they just put on the table? What haven\'t they eaten yet?',
                                    'The grandparents are raising their glasses. What has just happened — what are they celebrating?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): a family at a wooden
                                // kitchen table — on the left a grey-haired grandfather in a
                                // white jumper and a grandmother with red hair, both holding
                                // glasses, then a little boy, a dark-haired young woman and a
                                // young man holding a curly-haired girl; a roast, salad and
                                // plates on the table. Changing the query or orientation
                                // invalidates these coordinates.
                                'hotspots' => [
                                    ['x' => 30, 'y' => 45, 'question_index' => 0],
                                    ['x' => 71, 'y' => 46, 'question_index' => 1],
                                    ['x' => 54, 'y' => 77, 'question_index' => 2],
                                    ['x' => 33, 'y' => 65, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'Tell me about a couple you know well. How long have they been together, and how did they meet?',
                                    'Who is your oldest friend? How long have you known each other, and how has the friendship changed?',
                                    'What do you think helps a relationship last a long time?',
                                    'Have you ever been to a wedding that was very different from the others? Tell me about it.',
                                    'How have weddings and relationships changed in your country recently?',
                                    'Do you think people get married too young or too late these days? Why?',
                                ],
                                'role_reversal_topic' => 'relationships, marriage and long friendships',
                                'final_prompt' => 'Speak for 3 minutes without stopping about the relationships around you — a couple you admire and your oldest friendship: how long they have lasted and what has kept them strong.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'have / has + past participle',
                                    'How long…? with for / since',
                                    'ever / never or just / already / yet',
                                    'When…? (past) and How long…?',
                                    'A couple or friendship you know well',
                                    'Something that has changed over time',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Seeds M11 "Work" — the roadmap's future forms: will / going to /
     * present continuous. M01/M06/M07's 17-step layout: story_sequence stays,
     * right after Grammar, because a diary of next week ("On Monday she's
     * meeting…") is exactly what the present continuous for arrangements
     * narrates. The lesson follows Grammar in Use units 19 (present tenses
     * for the future), 20 (going to), 21 (will for decisions, offers and
     * promises) and 23 (will or going to?); 22, 24 and 25 are left for
     * later. Vocabulary days 2-3 follow Vocabulary in Use units 37 (Making a
     * career) and 38 (Working in an office), avoiding M03's work words, all
     * rewritten in our own words. Pair speaking cards ask about the
     * learner's own plans, always "work or study" so a learner without a
     * job can answer. Media the user picked: Vanessa and her husband Dan's
     * conversation lesson on work (cut from 35 to about 6 minutes: her ideal
     * office, the office acronyms WFH / OOO / EOD, waiting five minutes for
     * the kids, cutting back for a work-life balance) and Easy English's
     * Brighton street interviews "What Are Your Plans Today?" (cut from 11½
     * to about 6 minutes).
     */
    private function seedM11(): void
    {
        $audioUrl = $this->publishMissionAsset('M11', 'Speak English with Vanessa - Work with Dan.mp3');
        $shadowingVideoUrl = $this->publishMissionAsset('M11', 'Easy English - Plans Today in Brighton.mp4');
        $shadowingCaptionsUrl = $this->publishMissionAsset('M11', 'Easy English - Plans Today in Brighton.en.vtt');

        Mission::updateOrCreate(
            ['code' => 'M11'],
            [
                'title' => 'Work',
                'module' => 'Work',
                'outcome' => 'I can talk about my work or study plans — what I\'m doing next week, what I\'m going to do this year, and what I think will happen.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Day 1',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Would you like to work from home?', 'answer' => 'Yes, I would like to work from home two days a week.', 'words' => ['work from home']],
                            ['question' => 'Do you have a good work-life balance?', 'answer' => 'Not really. I study a lot, so my work-life balance isn\'t great.', 'words' => ['work-life balance']],
                            ['question' => 'What do you say when someone asks you something and you don\'t know the answer?', 'answer' => 'I say, "Let me check and get back to you."', 'words' => ['get back to']],
                            ['question' => 'What would you like to cut back on?', 'answer' => 'I want to cut back on my phone time at night.', 'words' => ['cut back']],
                        ],
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Get Ready',
                                'duration_minutes' => 5,
                                'hook' => 'What are you doing tomorrow? Could you say it in English — and what you\'re going to do next year?',
                                'image_query' => 'people working modern office',
                                'ambient_video_query' => 'office people working laptops',
                                'warm_up_questions' => [
                                    'What do you do — work, study, or both?',
                                    'What are you doing this weekend?',
                                    'What is your dream job?',
                                    'Would you like to work from home one day?',
                                ],
                            ],
                            [
                                'key' => 'vocabulary_builder_1',
                                'label' => 'New Words',
                                'duration_minutes' => 9,
                                'hook' => 'Five phrases Vanessa and Dan use about work — you\'ll need them to talk about any job.',
                                // Day 1 = the Listening episode's own 5 target_phrases (see M01's
                                // vocabulary_builder_1), so every learner meets them here first.
                                'story' => 'My sister Laleh used to stay at the office until nine every night, and she had no '
                                    .'**work-life balance**. Now she **works from home** three days a week, and she has **cut '
                                    .'back** on long meetings. On holiday she turns on her **out of office** message. If her '
                                    .'boss calls during dinner, she just says, "I\'ll **get back to** you tomorrow."',
                                'words' => [
                                    ['phrase' => 'work from home', 'meaning' => 'to do your job at home, not in an office or workplace', 'meaning_fa' => 'دورکاری کردن، از خانه کار کردن', 'pos' => 'phrase', 'example' => 'I work from home on Mondays and Fridays.', 'difficulty' => 'easy'],
                                    ['phrase' => 'work-life balance', 'meaning' => 'a healthy mix of time for your job and time for your family, friends and yourself', 'meaning_fa' => 'تعادل بین کار و زندگی', 'pos' => 'noun', 'example' => 'My new job gives me a better work-life balance.', 'difficulty' => 'medium'],
                                    ['phrase' => 'out of office', 'meaning' => 'away from work (an out of office message says you can\'t answer emails now)', 'meaning_fa' => 'خارج از محل کار، در مرخصی (پیام خودکار ایمیل)', 'pos' => 'phrase', 'example' => 'My boss is out of office this week.', 'difficulty' => 'medium'],
                                    ['phrase' => 'get back to', 'meaning' => 'to answer someone later, or to return to something you were doing', 'meaning_fa' => 'بعداً جواب دادن؛ برگشتن سر کاری', 'pos' => 'phrasal verb', 'example' => 'Let me check, and I\'ll get back to you.', 'difficulty' => 'medium'],
                                    ['phrase' => 'cut back', 'meaning' => 'to do or use less of something', 'meaning_fa' => 'کم کردن، کاهش دادن', 'pos' => 'phrasal verb', 'example' => 'I\'m trying to cut back on coffee.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 18,
                                'hook' => 'Vanessa gives her husband a little office quiz — and describes the office she wants one day. Listen for how they talk about the future.',
                                'source' => 'Speak English with Vanessa — "English Conversation Lesson: Work, Jobs, and Daily Life" (with her husband Dan)',
                                'image_query' => 'woman working on laptop at home',
                                'audio_url' => $audioUrl,
                                'listening_segments' => $this->shadowTimestampsFor('M11', 'listening_segments'),
                                // The same chunks tagged with who is talking — see
                                // missions:align-listening-speakers. Two speakers: Vanessa
                                // and Dan.
                                'listening_turns' => $this->shadowTimestampsFor('M11', 'listening_turns'),
                                // The parts kept in the cut (1:04-1:28, 6:22-6:42, 9:12-10:09,
                                // 10:23-10:58, 11:54-13:25, 13:43-14:31, 29:11-29:59 and
                                // 32:15-32:57 of the original), written from the video's human
                                // captions and checked against a Whisper pass of the cut audio.
                                'transcript' => [
                                    ['speaker' => 'Vanessa', 'text' => 'Today, I have five work-related questions, including a little quiz about some work vocabulary that I don\'t know if Dan will know. Maybe he will. Maybe you will know it. We\'ll see.'],
                                    ['speaker' => 'Dan', 'text' => 'We\'ll see. We both have non-traditional jobs, so we\'ll see what I know about work vocabulary.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yep. All right. Let\'s start with our first question.'],
                                    ['speaker' => 'Vanessa', 'text' => 'All right. Let\'s go on to our second question. Our second question is a little more of a modern question. I want to know, would you rather work from home or at a workplace? The workplace could be an office or in a coffee shop or the location that the job is set at. What would you rather do?'],
                                    ['speaker' => 'Dan', 'text' => 'And how about you?'],
                                    ['speaker' => 'Vanessa', 'text' => 'I think, for me, because I work for myself — I have my own business teaching you English, Speak English with Vanessa — I\'m going to answer neither. I don\'t want to work at home and I also don\'t want to work in a workplace. This is my ideal scenario. Are you ready?'],
                                    ['speaker' => 'Dan', 'text' => 'She wants to be totally independent. She wants to work in space.'],
                                    ['speaker' => 'Vanessa', 'text' => 'I want to work in a castle overlooking a kingdom. No, I want to — and I can probably do this in another couple of years — work for my business, great, but not at home. Last year, when I was pregnant with my baby, I worked at a coworking space. These exist all around the world. Maybe you\'ve heard of them, maybe you haven\'t, but it\'s like a really fun office. They try to make it cool because they want you to go there, and everyone is working just on their laptops for their own jobs. It\'s like the fun place to be. This is, I think, my ideal: I\'m working at the job that I want to work at, being your English teacher, and also in a location where I\'m around other people. Working from home is so convenient with small children. My baby\'s napping in the other room right now. I can nurse her. I can help my kids. I can do those types of things. But when they\'re a little bit older, I\'m out. I want to go and be out in a coworking space. This type of environment, I think, is really healthy for me.'],
                                    ['speaker' => 'Vanessa', 'text' => 'These are specifically things that you would write in an email in the office. Are you ready?'],
                                    ['speaker' => 'Dan', 'text' => 'I already think I\'m not going to be very good at this, but let\'s do it.'],
                                    ['speaker' => 'Vanessa', 'text' => 'The first one is WFH. I\'ll give you a sample sentence.'],
                                    ['speaker' => 'Dan', 'text' => 'Okay.'],
                                    ['speaker' => 'Vanessa', 'text' => 'This is a W…'],
                                    ['speaker' => 'Dan', 'text' => 'Work from home.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yeah. This is a WFH position, or I\'m looking for a WFH position. This is work from home, which is increasingly more common since the pandemic. Yes, work from home. You got it. All right. The next one. You might see this at the bottom of an email: OOO. This is the letter, not the number.'],
                                    ['speaker' => 'Dan', 'text' => 'Ooh. OOO? Orange, ostriches, orangutans…'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes, you got it. No, this is out of office. When someone is going to be gone during usual business days — let\'s say you have a vacation Monday through Wednesday — in your email system, you might write an automated message that goes out to everyone. That\'s your OOO, your out of office message that says, "I am sitting on a beach right now. Ha-ha, you\'re at the office. I\'ll see you on Thursday," or something like this.'],
                                    ['speaker' => 'Vanessa', 'text' => 'All right. The last one is…'],
                                    ['speaker' => 'Dan', 'text' => 'Only three?'],
                                    ['speaker' => 'Vanessa', 'text' => 'There\'s only three. The last quiz is… EOD. Would you like a sample sentence?'],
                                    ['speaker' => 'Dan', 'text' => 'Sure.'],
                                    ['speaker' => 'Vanessa', 'text' => 'The sample sentence is, "I need this finished by EOD," or "I will have this finished by EOD."'],
                                    ['speaker' => 'Dan', 'text' => 'Earliest. Earliest? No. Even?'],
                                    ['speaker' => 'Vanessa', 'text' => 'No. Can you have this finished by EOD? And you would not say this, you would write this in an email or in a text.'],
                                    ['speaker' => 'Dan', 'text' => 'End of date.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Oh, close. End of…'],
                                    ['speaker' => 'Dan', 'text' => 'Day. End of day.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Yes. Can you have this finished by EOD? Can you have this finished by end of day?'],
                                    ['speaker' => 'Vanessa', 'text' => 'Just the other day, they were doing something else and I was doing some work. And they busted in the room: "Mommy, you\'re on the computer. Wow, I want to sit on your lap." There were two children on my lap, and I was halfway through finishing an email that I really wanted to finish. There is no way that you can do that, if you have ever tried to do that. So in my mind, I say, "I\'m not going to do that right now. I will wait five minutes, ten minutes. It\'s going to be okay."'],
                                    ['speaker' => 'Dan', 'text' => 'Ten hours.'],
                                    ['speaker' => 'Vanessa', 'text' => 'Ten hours, it\'s okay. And focus on just whatever they need at that second, and then I can get back to it. Because I think if you try to check your emails while you\'re also talking to your kids or doing something else, your brain is going to go crazy.'],
                                    ['speaker' => 'Dan', 'text' => 'Hobbies, too.'],
                                    ['speaker' => 'Vanessa', 'text' => 'What hobbies do I absolutely have to do?'],
                                    ['speaker' => 'Dan', 'text' => 'Some of them might be painful. I recently stopped watching hockey, which is my favorite sport. That kind of brought a little tear to my eye, but I just don\'t have time for it anymore.'],
                                    ['speaker' => 'Vanessa', 'text' => 'And maybe at another point in your life you\'ll have more.'],
                                    ['speaker' => 'Dan', 'text' => 'I can watch all the hockey games I want.'],
                                    ['speaker' => 'Vanessa', 'text' => 'You\'ll have more figurative bandwidth. But for now, if you\'re feeling really stressed by this work-life balance, lay everything out on the table. What is necessary? How can I cut back so that I feel more balanced and happy? I think you owe it to yourself, you owe it to your family, and you\'ll just be a better person for it. But I\'m still working on that, Dan\'s still working on that, so take it with a grain of salt.'],
                                ],
                                // The same 5 phrases vocabulary_builder_1 taught, each a real
                                // substring of this transcript.
                                'target_phrases' => [
                                    [
                                        'phrase' => 'work from home', 'meaning' => 'do your job at home',
                                        'gap_before' => 'would you rather ',
                                        'gap_after' => ' or at a workplace?',
                                    ],
                                    [
                                        'phrase' => 'out of office', 'meaning' => 'away from work',
                                        'gap_before' => 'That\'s your OOO, your ',
                                        'gap_after' => ' message that says',
                                    ],
                                    [
                                        'phrase' => 'get back to', 'meaning' => 'return to something later',
                                        'gap_before' => 'and then I can ',
                                        'gap_after' => ' it.',
                                    ],
                                    [
                                        'phrase' => 'work-life balance', 'meaning' => 'time for work and time for life',
                                        'gap_before' => 'if you\'re feeling really stressed by this ',
                                        'gap_after' => ', lay everything out on the table.',
                                    ],
                                    [
                                        'phrase' => 'cut back', 'meaning' => 'do less of something',
                                        'gap_before' => 'How can I ',
                                        'gap_after' => ' so that I feel more balanced and happy?',
                                    ],
                                ],
                                'topic_summary' => 'Vanessa gives her husband Dan a little quiz on office words, and he is sure '
                                    .'he isn\'t going to be very good at it. Vanessa doesn\'t want to work from home or in a normal '
                                    .'workplace: when her kids are older, she wants to work in a coworking space. Dan guesses WFH '
                                    .'(work from home), Vanessa explains OOO (out of office) and EOD (end of day). When her kids '
                                    .'come in, she says, "I\'m not going to do that right now. I will wait." And for a better '
                                    .'work-life balance, she says, cut back on what isn\'t necessary — Dan has stopped watching '
                                    .'hockey for now.',
                                'comprehension_check' => [
                                    ['statement' => 'When her kids are older, Vanessa wants to work in a coworking space.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Dan guesses that WFH means "work from home".', 'correct' => true, 'difficulty' => 'medium'],
                                    ['statement' => 'When her kids come in, Vanessa finishes her email first.', 'correct' => false, 'difficulty' => 'hard'],
                                ],
                                'detail_question' => [
                                    'question' => 'What does "EOD" mean in an office email?',
                                    'options' => ['End of day', 'Every other day', 'Earliest date'],
                                    'correct' => 0,
                                ],
                                // No shadow_lines here — Day 1 Listening is comprehension-only;
                                // shadowing is Listen Again's job (daily_listen_2/3).
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Day 2',
                        'mode' => 'solo',
                        'pair_speaking' => [
                            ['question' => 'Are you going to apply for a new job or course this year?', 'answer' => 'Yes, I\'m going to apply for a course in graphic design.', 'words' => ['going to', 'apply for']],
                            ['question' => 'What will you put on your CV?', 'answer' => 'I\'ll put my English course and my computer skills on my CV.', 'words' => ['I\'ll', 'CV']],
                            ['question' => 'Have you got an interview or an exam soon?', 'answer' => 'Yes, I\'m having an interview on Sunday morning.', 'words' => ['I\'m having', 'interview']],
                            ['question' => 'At what age do you think you\'ll retire?', 'answer' => 'I think I\'ll retire at sixty, but I\'m not sure.', 'words' => ['I think I\'ll', 'retire']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_2',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Six words for a career, from your first CV to the day you retire.',
                                // Vocabulary in Use unit 37 (Making a career), our own sentences.
                                'story' => 'My uncle Bahram **applied for** his first job when he was 22. He sent his **CV** to '
                                    .'ten companies and had three **interviews**. A small bank gave him a job, and after five '
                                    .'years he **got promoted** to manager — with a nice **pay rise**. Next year he is going to '
                                    .'**retire**, and he says he\'ll finally learn to paint.',
                                'words' => [
                                    ['phrase' => 'apply for', 'meaning' => 'to ask formally for a job, a place on a course, a visa, etc., usually in writing', 'meaning_fa' => 'درخواست دادن برای (کار، دوره، ویزا)', 'pos' => 'phrasal verb', 'example' => 'She applied for a job at a hospital.', 'difficulty' => 'easy'],
                                    ['phrase' => 'CV', 'meaning' => 'a document that lists your education, skills and the jobs you have done', 'meaning_fa' => 'رزومه', 'pos' => 'noun', 'example' => 'Send your CV to the manager before Friday.', 'difficulty' => 'easy'],
                                    ['phrase' => 'interview', 'meaning' => 'a meeting where someone asks you questions to see if you are right for a job or course', 'meaning_fa' => 'مصاحبه', 'pos' => 'noun', 'example' => 'I\'m nervous about my interview tomorrow.', 'difficulty' => 'easy'],
                                    ['phrase' => 'get promoted', 'meaning' => 'to be given a higher job with more responsibility', 'meaning_fa' => 'ارتقای شغلی گرفتن، ترفیع گرفتن', 'pos' => 'phrase', 'example' => 'He worked hard and got promoted after two years.', 'difficulty' => 'medium'],
                                    ['phrase' => 'pay rise', 'meaning' => 'an increase in the money you get for your job', 'meaning_fa' => 'افزایش حقوق', 'pos' => 'noun', 'example' => 'Everyone in the team got a small pay rise this year.', 'difficulty' => 'medium'],
                                    ['phrase' => 'retire', 'meaning' => 'to stop working because of your age, often at 60 or 65', 'meaning_fa' => 'بازنشسته شدن', 'pos' => 'verb', 'example' => 'My father retired last year, and now he gardens every day.', 'difficulty' => 'easy'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — this time copy how Vanessa talks about what will happen: "Maybe he will", "I\'m going to answer neither".',
                                'image_query' => 'business team meeting office',
                                'shadow_lines' => [
                                    'I don\'t know if Dan will **know**. Maybe he **will**.',
                                    'I\'m **going** to answer **neither**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M11', 'daily_listen_2'),
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar Time',
                                'duration_minutes' => 15,
                                'hook' => 'Three ways to talk about the future: "I\'m meeting her on Monday", "I\'m going to apply", "OK, I\'ll call her".',
                                'focus' => 'will / going to / present continuous for the future',
                                // Our own explanation and examples (EOS-009 §14); the order
                                // follows Grammar in Use units 19 (present continuous and
                                // present simple for the future), 20 (going to), 21 (will for
                                // decisions, offers and promises) and 23 (will or going to?).
                                'lesson' => [
                                    'intro' => 'English has no single future tense. What you say depends on when you decided: I\'m meeting Sara on Monday (arranged), I\'m going to look for a new job (decided), OK, I\'ll call her (deciding now).',
                                    'sections' => [
                                        [
                                            'heading' => 'A · I\'m meeting… — plans already arranged',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Use <strong>am / is / are + -ing</strong> for plans you have already arranged, often with a day or time: <strong>I\'m meeting my manager on Monday.</strong> For timetables (trains, ferries, classes), use the <strong>present simple</strong>: <strong>The ferry leaves at 9.</strong>',
                                                    'fa' => 'برای قرارهایی که از قبل گذاشته‌ای و در تقویمت هست، حال استمراری می‌آید، معمولاً با زمان: I\'m seeing the dentist tomorrow. فارسی هم می‌گوییم «فردا می‌رم دکتر» با فعل حال؛ ولی در انگلیسی حال ساده (I go) غلط است و ing لازم است. فقط برای جدول زمانیِ ثابت (حرکت قطار، شروع کلاس) حال ساده: The class starts at 8.',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'am / is / are + -ing + time',
                                                    'parts' => [
                                                        ['text' => 'I', 'caption' => 'person'],
                                                        ['text' => '\'m meeting', 'caption' => 'am / is / are + -ing', 'tone' => 'accent'],
                                                        ['text' => 'my manager', 'caption' => 'rest'],
                                                        ['text' => 'on Monday', 'caption' => 'when'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I see my manager tomorrow at ten.', 'right' => 'I\'m seeing my manager tomorrow at ten.', 'highlight' => 'I\'m seeing'],
                                                        ['wrong' => 'What do you do this weekend?', 'right' => 'What are you doing this weekend?', 'highlight' => 'are you doing'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the video', 'items' => [
                                                            'Now we\'re heading off to the beach.',
                                                            'Yeah, we\'re getting a nine o\'clock ferry over to Santander.',
                                                            'So we land in Spain on Wednesday morning.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · going to — decided before you speak',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => '<strong>am / is / are going to + verb</strong> = something you decided <strong>before</strong> now: <strong>I\'m going to look for a new job.</strong> Also for a prediction you can see coming: <strong>You\'re going to be tired tomorrow!</strong>',
                                                    'fa' => 'going to یعنی «تصمیمش را گرفته‌ام، قصدش را دارم». برای پیش‌بینی از روی نشانه‌ای که الان می‌بینی هم هست: Look at the sky — it\'s going to rain. منفی: I\'m not going to… و بعد از going to فعل ساده می‌آید (نه ing و نه s).',
                                                ],
                                                [
                                                    'type' => 'formula',
                                                    'label' => 'am / is / are going to + verb',
                                                    'parts' => [
                                                        ['text' => 'We', 'caption' => 'person'],
                                                        ['text' => '\'re going to', 'caption' => 'am / is / are going to', 'tone' => 'accent'],
                                                        ['text' => 'get', 'caption' => 'verb'],
                                                        ['text' => 'some sushi', 'caption' => 'rest'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I going to apply for a new job.', 'right' => 'I\'m going to apply for a new job.', 'highlight' => 'I\'m going to'],
                                                        ['wrong' => 'He\'s going to applies for the course.', 'right' => 'He\'s going to apply for the course.', 'highlight' => 'apply'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the video', 'items' => [
                                                            'We\'re going to get some sushi, actually.',
                                                            'I\'m just going to try and live here as long as I can.',
                                                            'You\'re going to be knackered.',
                                                        ]],
                                                        ['label' => 'From the listening', 'items' => [
                                                            'I\'m going to answer neither.',
                                                            'Your brain is going to go crazy.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · will — deciding now, offers and promises',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Use <strong>will (\'ll) + verb</strong> when you decide <strong>while you speak</strong>, and to offer, promise or agree: <strong>The phone\'s ringing. — I\'ll get it.</strong> Also for what you think about the future: <strong>I think I\'ll…</strong>, <strong>I don\'t think he\'ll…</strong>, <strong>probably</strong>.',
                                                    'fa' => 'will برای تصمیمِ همان لحظه (قبلاً فکرش را نکرده بودی)، پیشنهاد (I\'ll help you)، قول (I\'ll call you tonight) و نظر درباره‌ی آینده (I think it\'ll be fine). منفی: won\'t. برای «فکر نمی‌کنم بیاید» بگو I don\'t think he\'ll come (نه I think he won\'t come).',
                                                ],
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Deciding now', 'words' => ['I\'ll get it.', 'I\'ll have the soup.', 'Maybe I\'ll…']],
                                                        ['label' => 'Offers and promises', 'words' => ['I\'ll help you.', 'I\'ll call you tonight.', 'I won\'t tell anyone.']],
                                                        ['label' => 'What you think', 'words' => ['I think I\'ll…', 'I don\'t think it\'ll…', 'You\'ll probably…']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'do_dont',
                                                    'items' => [
                                                        ['wrong' => 'I think I will to stay at home.', 'right' => 'I think I\'ll stay at home.', 'highlight' => 'I\'ll stay'],
                                                        ['wrong' => 'I think he won\'t come to the meeting.', 'right' => 'I don\'t think he\'ll come to the meeting.', 'highlight' => 'I don\'t think he\'ll'],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'From the video', 'items' => [
                                                            'And then we\'ll drive back to Kent.',
                                                            'Maybe I\'ll just figure it out once I get there.',
                                                            'I think I\'ll always have a connection to that place.',
                                                        ]],
                                                        ['label' => 'From the listening', 'items' => [
                                                            'I\'ll give you a sample sentence.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'D · will or going to?',
                                            'blocks' => [
                                                [
                                                    'type' => 'rule',
                                                    'text' => 'Ask yourself: <strong>when did I decide?</strong> Before speaking → <strong>going to</strong> (or <strong>-ing</strong> if it\'s arranged). While speaking → <strong>will</strong>: <strong>I\'m going to order the sushi wrap</strong> (I chose it this morning). <strong>Hmm… I\'ll have the soup</strong> (I chose just now).',
                                                    'fa' => 'کلید: «کِی تصمیم گرفتی؟» قبل از حرف زدن ← going to (یا حال استمراری اگر با کسی قرار گذاشته‌ای). همین الان، وسط حرف ← will. در ویدیو آدم‌ها برای برنامه‌ی امروز going to و ing می‌گویند و برای تصمیمِ نامطمئن و لحظه‌ای maybe I\'ll.',
                                                ],
                                                [
                                                    'type' => 'mistake_fix',
                                                    'character' => 'Neda',
                                                    'wrong' => 'I\'ll meet my new boss on Monday at nine — we arranged it last week.',
                                                    'right' => 'I\'m meeting my new boss on Monday at nine — we arranged it last week.',
                                                    'explanation' => 'The meeting was arranged last week, so it isn\'t a decision made now: use the present continuous (or going to), not will.',
                                                ],
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        ['label' => 'Examples', 'items' => [
                                                            'A: The printer has run out of paper. B: Oh, I\'ll get some.',
                                                            'I\'m going to buy paper after work — it\'s on my list.',
                                                            'That\'s what I\'m going to order, probably… maybe I\'ll just figure it out.',
                                                            'We\'re having a meeting at ten, so I\'ll call you after that.',
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'E · Make it personal',
                                            'body' => 'Next you\'ll finish sentences about <strong>your</strong> week and your year — what you\'re doing, what you\'re going to do, what you think will happen — then tell Mina\'s week in pictures, and soon say your own plans out loud in AI Conversation #1.',
                                        ],
                                    ],
                                    'bridge_note' => 'You\'ll use this right away — in the Picture Story next, and in AI Conversation #1.',
                                ],
                                'frequency_starters' => [
                                    'This weekend I\'m', 'Next year I\'m going to', 'I think I\'ll',
                                    'I don\'t think I\'ll', 'After this course I\'m going to', 'Tomorrow morning I\'m',
                                ],
                                'grammar_judgment' => 'Judge whether the learner finished this sentence starter into '
                                    .'a true, natural personal sentence about their own future (work, study or free time), using '
                                    .'the right future form: am / is / are + -ing for an arranged plan, going to + verb for a '
                                    .'decision made before or a prediction from what they can see, and will + verb for a decision '
                                    .'made now, an offer, a promise or an opinion about the future (I think I\'ll…, I don\'t think '
                                    .'I\'ll…).',
                                'grammar_major_criteria' => 'the present simple for an arranged plan ("I meet my friend '
                                    .'tomorrow"), a missing am / is / are ("I going to…"), an -ing or -s verb after going to or '
                                    .'will ("going to applying", "I will to go"), will for something clearly arranged before, "I '
                                    .'think I won\'t" instead of "I don\'t think I\'ll", the sentence does not actually continue the '
                                    .'given starter, or it is not a genuine personal statement',
                                'grammar_context' => 'continues with a future form that fits the starter: -ing for an '
                                    .'arranged plan, going to + verb for an intention, or will + verb for an opinion or a decision',
                                'quick_check' => [
                                    [
                                        'wrong' => 'What do you do this evening?',
                                        'options' => ['What do you do this evening?', 'What are you doing this evening?', 'What you are doing this evening?'],
                                        'correct' => 1,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'I going to apply for a new job.',
                                        'options' => ['I\'m going to apply for a new job.', 'I going to apply for a new job.', 'I\'m going to applying for a new job.'],
                                        'correct' => 0,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'The phone is ringing. — I\'m going to get it!',
                                        'options' => ['The phone is ringing. — I get it!', 'The phone is ringing. — I\'m going to get it!', 'The phone is ringing. — I\'ll get it!'],
                                        'correct' => 2,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'I think he won\'t come to the meeting.',
                                        'options' => ['I think he won\'t come to the meeting.', 'I don\'t think he\'ll come to the meeting.', 'I don\'t think he comes to the meeting.'],
                                        'correct' => 1,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                                'word_order' => [
                                    ['words' => ['meeting', 'I\'m', 'on', 'Monday.', 'manager', 'my'], 'answer' => 'I\'m meeting my manager on Monday.'],
                                    ['words' => ['apply', 'We\'re', 'job.', 'to', 'new', 'going', 'for', 'a'], 'answer' => 'We\'re going to apply for a new job.'],
                                    ['words' => ['I\'ll', 'it.', 'think', 'I', 'get'], 'answer' => 'I think I\'ll get it.'],
                                ],
                            ],
                            [
                                'key' => 'story_sequence',
                                'label' => 'Picture Story',
                                // Kept for M11 (see M01's, M06's and M07's notes): a diary of
                                // next week — what is already arranged — is what the present
                                // continuous for the future describes, so the pictures come
                                // right after the lesson as fresh applied practice.
                                'duration_minutes' => 10,
                                'hook' => 'You just learned I\'m meeting… for plans already in the diary. Now say what Mina is doing next week, one picture at a time.',
                                // Captions are ground truth for the AI feedback prompt only —
                                // never rendered to the learner (see the step's own docblock).
                                // Each query was checked against its real first Pexels result
                                // (square), so the picture matches its caption.
                                'sequence_images' => [
                                    ['image_query' => 'woman first day office desk', 'caption' => 'On Monday, Mina is starting her new job, and a colleague is showing her the files', 'alt_description' => 'A young woman at an office desk with papers and a blue folder, listening to a colleague'],
                                    ['image_query' => 'business team meeting office', 'caption' => 'On Tuesday, she is meeting the sales team', 'alt_description' => 'Four people in shirts talking around a white office table'],
                                    ['image_query' => 'businesswoman airport suitcase', 'caption' => 'On Thursday, she is flying to Istanbul for a conference', 'alt_description' => 'Planes parked at an airport at sunset'],
                                    ['image_query' => 'family dinner celebration', 'caption' => 'On Friday evening, she is having her daughter\'s birthday dinner with the family', 'alt_description' => 'A family round a table while a little girl blows out the candles on her birthday cake'],
                                ],
                                'sequencing_words' => ['On Monday', 'Then, on Tuesday', 'After that', 'At the end of the week'],
                                'narration_tense' => 'Present Continuous for the future',
                                'narration_kind' => 'what someone has already arranged for next week',
                                'narration_example' => 'On Monday, Mina is starting her new job...',
                                'narration_not' => 'present simple or will',
                            ],
                            [
                                // Real people in the street at natural speed: Easy English asks
                                // passers-by in Brighton "What are you up to today?" — cut from
                                // 11½ to about 6 minutes (the membership advert left out). The
                                // creator's burned-in captions are blurred out so the step's
                                // own caption toggle works.
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                'duration_minutes' => 14,
                                'hook' => 'Lunch, a concert, a 30-hour ferry: people in Brighton tell you their plans for today. Watch, then say their plans with them.',
                                'source' => 'Easy English — "What Are Your Plans Today? | Easy English 205" (street interviews in Brighton)',
                                'video_url' => $shadowingVideoUrl,
                                // The creator's burned-in captions, read frame by frame and
                                // re-timed per line on Whisper's word timing of the cut.
                                'captions_url' => $shadowingCaptionsUrl,
                                'video_segments' => $this->shadowTimestampsFor('M11', 'video_shadowing_segments'),
                                'topic_summary' => 'In Brighton, two friends on their lunch break tell Easy English they are '
                                    .'going to get some sushi. Other people are heading off to the beach, going to a pop concert, '
                                    .'or pottery painting. One woman is getting a nine o\'clock ferry to Spain and will land on '
                                    .'Wednesday morning — "You\'re going to be knackered!" A student who got her degree today is '
                                    .'going to try and live in Brighton as long as she can, and two friends from Cardiff are '
                                    .'planning a trip to Japan in a few years.',
                                'comprehension_check' => [
                                    ['statement' => 'Two women on their lunch break are going to get sushi.', 'correct' => true],
                                    ['statement' => 'The woman going to Santander is flying there tonight.', 'correct' => false],
                                    ['statement' => 'The friends from Cardiff are planning a trip to Japan in a few years.', 'correct' => true],
                                ],
                                // Real phrases the people in Brighton use on camera.
                                'target_phrases' => [
                                    ['phrase' => 'be up to', 'meaning' => 'to be doing (What are you up to today?)'],
                                    ['phrase' => 'head off', 'meaning' => 'to leave and go somewhere'],
                                    ['phrase' => 'a lunch break', 'meaning' => 'the time off work in the middle of the day to eat'],
                                    ['phrase' => 'figure it out', 'meaning' => 'to find the answer or decide later'],
                                    ['phrase' => 'knackered', 'meaning' => 'very tired (informal British English)'],
                                ],
                                // Real verbatim lines from the video — shadowing means mimicking
                                // the exact audio just heard, and the auto-pause timestamps
                                // (missions:cache-shadow-timestamps) only land on words people
                                // actually say. Chosen for this mission's grammar: going to for
                                // a plan, -ing for an arrangement, will for a decision made now
                                // or later, going to for a prediction, I think I'll.
                                // Bold marks naturally-stressed content words (rendered by
                                // <x-stress-marked-line>).
                                'shadow_lines' => [
                                    'We\'re **going** to get some **sushi**, actually.',
                                    'Now we\'re heading **off** to the **beach**.',
                                    'And then we\'ll drive **back** to **Kent**, which is where I **live**.',
                                    'I actually **haven\'t** decided **yet**, so maybe I\'ll just **figure** it out.',
                                    'You\'re **going** to be **knackered**.',
                                    'I think I\'ll **always** have a **connection** to that **place**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M11', 'video_shadowing'),
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Day 3',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'Are you meeting anyone next week?', 'answer' => 'Yes, I\'m meeting my teacher on Monday to arrange my exam.', 'words' => ['I\'m meeting', 'arrange']],
                            ['question' => 'Do you need to make an appointment with anyone this month?', 'answer' => 'Yes, I\'m going to make an appointment with the dentist.', 'words' => ['going to', 'make an appointment']],
                            ['question' => 'What will you do if you run out of time in an exam?', 'answer' => 'If I run out of time, I\'ll answer the easy questions first.', 'words' => ['run out of', 'I\'ll']],
                            ['question' => 'How much paperwork does your work or school give you?', 'answer' => 'A lot! This week I\'m doing the paperwork for my new course.', 'words' => ['paperwork', 'I\'m doing']],
                        ],
                        'steps' => [
                            [
                                'key' => 'vocabulary_builder_3',
                                'label' => 'New Words',
                                'duration_minutes' => 10,
                                'hook' => 'Last set — the words for a busy day at the office: arrange, attend, paperwork… and things that break down.',
                                // Vocabulary in Use unit 38 (Working in an office), our own
                                // sentences.
                                'story' => 'Today is a bad day for Kaveh. He has to **arrange** a visit for some clients and '
                                    .'**attend** a meeting at eleven, but the printer has **broken down** and the coffee machine '
                                    .'has **run out of** water. His desk is full of **paperwork**. "I\'ll do it after lunch," he '
                                    .'says, and calls the dentist to **make an appointment** for Friday.',
                                'words' => [
                                    ['phrase' => 'arrange', 'meaning' => 'to plan and organise something, such as a meeting or a visit', 'meaning_fa' => 'ترتیب دادن، هماهنگ کردن', 'pos' => 'verb', 'example' => 'Can you arrange a meeting with the new clients?', 'difficulty' => 'medium'],
                                    ['phrase' => 'make an appointment', 'meaning' => 'to agree a time to see someone, like a doctor or a manager', 'meaning_fa' => 'وقت گرفتن، قرار گذاشتن', 'pos' => 'phrase', 'example' => 'I need to make an appointment with the doctor.', 'difficulty' => 'easy'],
                                    ['phrase' => 'attend', 'meaning' => 'to go to a meeting, class or event (a little formal)', 'meaning_fa' => 'شرکت کردن در (جلسه، کلاس)', 'pos' => 'verb', 'example' => 'All the team will attend the meeting on Monday.', 'difficulty' => 'medium'],
                                    ['phrase' => 'paperwork', 'meaning' => 'work with documents, such as forms, reports and letters', 'meaning_fa' => 'کارهای اداری و کاغذی', 'pos' => 'noun', 'example' => 'I spend every Friday doing paperwork.', 'difficulty' => 'easy'],
                                    ['phrase' => 'break down', 'meaning' => 'to stop working (a machine or a car)', 'meaning_fa' => 'خراب شدن (دستگاه، ماشین)', 'pos' => 'phrasal verb', 'example' => 'The photocopier broke down again this morning.', 'difficulty' => 'medium'],
                                    ['phrase' => 'run out of', 'meaning' => 'to have no more of something', 'meaning_fa' => 'تمام کردن، ته کشیدن', 'pos' => 'phrasal verb', 'example' => 'The printer has run out of paper.', 'difficulty' => 'medium'],
                                ],
                            ],
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Listen Again',
                                'duration_minutes' => 7,
                                'hook' => 'Same talk, one more time — now listen for "I\'m not going to…, I will…" and what is going to happen to your brain.',
                                'image_query' => 'woman first day office desk',
                                'shadow_lines' => [
                                    'I\'m **not** going to do that right **now**. I will **wait** five **minutes**.',
                                    'Your **brain** is going to go **crazy**.',
                                ],
                                'shadow_timestamps' => $this->shadowTimestampsFor('M11', 'daily_listen_3'),
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'Talk It Out',
                                'duration_minutes' => 18,
                                'hook' => 'Say it here, alone, before you have to say it to the AI Instructor for real.',
                                'warm_up_task' => 'Record 2 minutes of solo speaking about your plans for work or study: '
                                    .'what you\'re doing next week, what you\'re going to do this year, and where you think '
                                    .'you\'ll be in five years — using the new vocabulary, without reading.',
                                'interview_questions' => [
                                    'What are you doing this weekend?',
                                    'What are you going to do after this course, for your work or study?',
                                    'Where do you think you\'ll be in five years?',
                                    'Have you got any appointments or meetings next week? What are they?',
                                    'Is there something you\'ve decided to change in your work or study life?',
                                    'Would you rather work from home or in an office in the future? Why?',
                                ],
                            ],
                            [
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Arash has a big week coming: an interview on Tuesday. Will he get the job?',
                                'passage_title' => 'A big week for Arash',
                                'image_query' => 'young woman job interview',
                                // Written fresh for this app (EOS-009 §14) — reuses the Vocabulary
                                // Builder words in a new context, third person instead of first,
                                // with a future form in almost every sentence.
                                'passage' => 'Next week is going to be a big week for Arash. He has applied for a job at a '
                                    .'design company, and on Tuesday he\'s having an interview there at ten. He sent his '
                                    .'CV last month, and yesterday the company called to arrange the time. His mum is '
                                    .'worried: "What if the bus breaks down?" "Don\'t worry, I\'ll take a taxi," he says. At '
                                    .'the moment Arash works in a small office, and his job is mostly paperwork. He has never '
                                    .'had a pay rise. "If I get this job, I\'m going to work from home two days a week," '
                                    .'he says. "That\'s the work-life balance I want." On Wednesday he\'s attending a '
                                    .'short training course, and on Thursday he\'s meeting his old manager for lunch. Will he get '
                                    .'the job? "I think I will," he smiles. "And one day I\'m going to get promoted — before I '
                                    .'retire, anyway!" Fingers crossed.',
                                'highlighted_phrases' => [
                                    ['phrase' => 'applied for', 'type' => 'reused'],
                                    ['phrase' => 'interview', 'type' => 'reused'],
                                    ['phrase' => 'CV', 'type' => 'reused'],
                                    ['phrase' => 'arrange', 'type' => 'reused'],
                                    ['phrase' => 'breaks down', 'type' => 'reused'],
                                    ['phrase' => 'paperwork', 'type' => 'reused'],
                                    ['phrase' => 'pay rise', 'type' => 'reused'],
                                    ['phrase' => 'work from home', 'type' => 'reused'],
                                    ['phrase' => 'work-life balance', 'type' => 'reused'],
                                    ['phrase' => 'attending', 'type' => 'reused'],
                                    ['phrase' => 'get promoted', 'type' => 'reused'],
                                    ['phrase' => 'retire', 'type' => 'reused'],
                                    ['phrase' => 'a big week', 'type' => 'new', 'definition' => 'a week with something very important in it', 'pos' => 'phrase', 'example' => 'It\'s a big week for my sister — she\'s getting married on Saturday.'],
                                    ['phrase' => 'Fingers crossed', 'type' => 'new', 'definition' => 'said when you hope something will happen the way you want', 'pos' => 'phrase', 'example' => 'My exam results come out tomorrow. Fingers crossed!'],
                                ],
                                'topic_summary' => 'Arash has applied for a new job, and on Tuesday he is having an interview at a '
                                    .'design company. Now his job is mostly paperwork and he has never had a pay rise. If he gets '
                                    .'the new job, he is going to work from home two days a week. He thinks he will get it.',
                                'comprehension_check' => [
                                    ['statement' => 'Arash\'s interview is on Tuesday at ten.', 'correct' => true, 'difficulty' => 'easy'],
                                    ['statement' => 'Arash is going to take the bus, even if it breaks down.', 'correct' => false, 'difficulty' => 'medium'],
                                    ['statement' => 'In the new job, Arash wants to work from home every day.', 'correct' => false, 'difficulty' => 'hard'],
                                ],
                                'questions' => [
                                    'What is Arash doing on Tuesday and on Wednesday?',
                                    'Why does Arash want the new job?',
                                ],
                            ],
                            [
                                'key' => 'writing',
                                'label' => 'Writing',
                                'duration_minutes' => 12,
                                'hook' => 'Next year starts with a plan — write yours for your work or study.',
                                'title' => 'My plans for the next year',
                                'prompts' => [
                                    ['label' => 'What I\'m doing next week', 'image_query' => 'weekly planner calendar desk'],
                                    ['label' => 'Something I\'m going to learn', 'image_query' => 'woman studying laptop notes'],
                                    ['label' => 'A plan for my work or study', 'image_query' => 'young man office laptop'],
                                    ['label' => 'Someone I\'m going to work or study with', 'image_query' => 'people working modern office'],
                                    ['label' => 'Where I think I\'ll be in five years', 'image_query' => 'woman looking at city view'],
                                    ['label' => 'How I\'ll feel at the end of the year', 'image_query' => 'happy woman celebrating success'],
                                ],
                                // Grammar connectors only — see M01's try_to_use note.
                                'try_to_use' => ['am / is / are + -ing (arranged)', 'going to + verb', 'will / won\'t', 'I think I\'ll…', 'apply for', 'make an appointment', 'work-life balance'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Day 4',
                        'mode' => 'ai',
                        'pair_speaking' => [
                            ['question' => 'What are you doing this weekend?', 'answer' => 'I\'m visiting my grandparents on Friday, and on Saturday I\'m studying.', 'words' => ['I\'m visiting', 'I\'m studying']],
                            ['question' => 'What are you going to do after this course?', 'answer' => 'I\'m going to look for a better job where I can work from home.', 'words' => ['going to', 'work from home']],
                            ['question' => 'Do you think you\'ll get promoted or change jobs one day?', 'answer' => 'I think I\'ll get promoted in a few years, if I work hard.', 'words' => ['I think I\'ll', 'get promoted']],
                            ['question' => 'The phone is ringing and your friend\'s hands are full. What do you say?', 'answer' => 'Don\'t worry, I\'ll get it!', 'words' => ['I\'ll get it']],
                        ],
                        'steps' => [
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                'duration_minutes' => 10,
                                'hook' => 'A team is planning something big — what are they going to do, and what do you think will happen?',
                                'image_query' => 'team meeting whiteboard',
                                'guiding_questions' => [
                                    'Look at the man in the suit. What is he going to say next, do you think?',
                                    'Look at the whiteboard: Germany, Russia, USA. What do you think the team is going to do there?',
                                    'Look at the man in the white jumper. What do you think he\'ll write in his notebook?',
                                    'Look at the laptops. What are they doing after the meeting — what are their plans for next week?',
                                ],
                                // Hand-checked against the real first Pexels result for this
                                // exact query + orientation (landscape): an office meeting — on
                                // the left a man in a dark suit holding papers, a whiteboard with
                                // "Germany, Russia, USA" written on it, a woman in a beige jacket
                                // standing by it, a man in a white jumper writing at the table, a
                                // laptop and tablet on the table, a woman in a checked coat in the
                                // right foreground. Changing the query or orientation invalidates
                                // these coordinates.
                                'hotspots' => [
                                    ['x' => 11, 'y' => 32, 'question_index' => 0],
                                    ['x' => 34, 'y' => 18, 'question_index' => 1],
                                    ['x' => 71, 'y' => 30, 'question_index' => 2],
                                    ['x' => 52, 'y' => 58, 'question_index' => 3],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'My Fixes',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'Final Talk',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — real conversations don't come with warm-up questions.",
                                'round_pool' => [
                                    'What are you doing this week? Tell me about any meetings, classes or appointments.',
                                    'What are you going to do in your work or study this year? Why?',
                                    'Where do you think you\'ll be in five years? What will be different?',
                                    'Would you rather work from home, in an office, or somewhere else? What are you going to choose?',
                                    'How do you think work will change in your country in the next ten years?',
                                    'What are you going to cut back on to have a better work-life balance?',
                                ],
                                'role_reversal_topic' => 'work, study and plans for the future',
                                'final_prompt' => 'Speak for 3 minutes without stopping about your plans for work or study: what you\'re doing next week, what you\'re going to do this year, and what you think your life will be like in five years.',
                                'requirements' => [
                                    '5+ vocabulary expressions',
                                    'am / is / are + -ing for an arranged plan',
                                    'going to for a decision or intention',
                                    'will for a prediction, offer or decision now',
                                    'I think I\'ll… / I don\'t think I\'ll…',
                                    'A plan for your work or study',
                                    'Where you think you\'ll be in five years',
                                ],
                            ],
                            [
                                'key' => 'mission_result',
                                'label' => 'Mission Result',
                                'duration_minutes' => 5,
                                'hook' => "You started this mission with a number — let's see how far it moved.",
                                'skills' => ['Listening', 'Vocabulary', 'Grammar', 'Speaking', 'Writing'],
                                'reflection_questions' => [
                                    'became_easier' => ['label' => 'What became easier?', 'type' => 'skills'],
                                    'still_difficult' => ['label' => 'What is still difficult?', 'type' => 'skills'],
                                    'expression_to_keep' => ['label' => 'One expression I want to keep using', 'type' => 'vocabulary'],
                                    'grammar_to_review' => ['label' => 'One grammar point I need to review', 'type' => 'errors'],
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
     *
     * Audio and video are the exception once a download host is configured
     * (MEDIA_BASE_URL): they are uploaded there by hand, so nothing is
     * copied and the host's URL is returned — the file doesn't even have to
     * exist under document/ on this machine.
     */
    private function publishMissionAsset(string $missionCode, string $filename): string
    {
        $hostedUrl = app(MediaHost::class)->missionFileUrl($missionCode, $filename);

        if ($hostedUrl !== null) {
            return $hostedUrl;
        }

        $source = base_path("document/{$missionCode}/{$filename}");
        $relative = 'missions/'.strtolower($missionCode).'/'.$filename;

        File::ensureDirectoryExists(dirname(storage_path("app/public/{$relative}")));
        File::copy($source, storage_path("app/public/{$relative}"));

        return Storage::disk('public')->url($relative);
    }

    /**
     * Real Whisper-derived start/end times (seconds) for one
     * daily_listen_N step's own shadow_lines, generated offline by
     * `php artisan missions:cache-shadow-timestamps` and checked into
     * document/{code}/shadow_timestamps.json — never regenerated live
     * during a real seed run. Returns [] (not an error) for a mission
     * that hasn't had the command run against it yet, so seeding never
     * hard-fails on missing cache; the shadowing player just has no
     * pause points until it's generated.
     *
     * @return list<array{start: float, end: float}|null>
     */
    private function shadowTimestampsFor(string $missionCode, string $stepKey): array
    {
        static $cache = [];

        if (! array_key_exists($missionCode, $cache)) {
            $path = base_path("document/{$missionCode}/shadow_timestamps.json");
            $cache[$missionCode] = File::exists($path) ? json_decode(File::get($path), true) : [];
        }

        return $cache[$missionCode][$stepKey] ?? [];
    }
}
