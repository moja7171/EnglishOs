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
                                'key' => 'vocabulary_builder',
                                'label' => 'Vocabulary Builder',
                                // Was 15, then 16 for the "pick which words join My Words" recap
                                // step, then 17 for 4 more story words woven in to overlap with the
                                // real Listening episode's own vocabulary; now 18 for the new
                                // meaning-check Quick Round warm-up between the story and practice.
                                'duration_minutes' => 18,
                                'hook' => 'Next time someone asks about your morning, will these words be ready — or will you go quiet?',
                                // Word selection follows every sub-topic of English Vocabulary in Use
                                // Unit 16, "Daily routines" (Sleep / Food / Bathroom routines / Housework
                                // / Spare time — the book's own 5 sections). The story and every meaning
                                // below are written fresh for this app, not copied from it (see EOS-009
                                // §14: content stays original, licensing/piracy risk).
                                // "sleep in", "oversleep", "morning person", and "skip breakfast" are
                                // deliberately woven in here too, not just their own vocabulary — the
                                // real BBC Listening audio right after this step uses these same 4
                                // words/phrases (plus "get up", already above), so every learner reads
                                // them here first regardless of which words they personally select,
                                // then hears them again in context. Previously the two steps' word
                                // pools barely overlapped.
                                'story' => [
                                    [
                                        'heading' => 'Sleep',
                                        'text' => 'I\'m not really a **morning person**, so during the '
                                            .'week I **wake up** at half past six and **get up** straight '
                                            .'away, before I can **oversleep**. I usually **go to bed** '
                                            .'around eleven and **go to sleep** quickly, but sometimes I '
                                            .'**have a late night** if I\'m out with friends. At weekends, '
                                            .'though, I love to **sleep in** and **have a sleep** in the '
                                            .'afternoon too.',
                                    ],
                                    [
                                        'heading' => 'Food',
                                        'text' => 'I never **skip breakfast** — for breakfast I usually have **cereal**, then at work I '
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
                                // difficulty (Story 4, requirements review, 2026-09-04) drives
                                // <x-quick-round>'s adaptive mode in meaningCheckCards() — a
                                // genuine judgment call per phrase, not alphabetical: short,
                                // transparent, high-frequency words/phrases are "easy"; idiomatic
                                // collocations or words easily confused with a close relative
                                // (e.g. "have a wash" vs "have a shower") are "hard".
                                'story_words' => [
                                    // Sleep
                                    ['phrase' => 'wake up', 'meaning' => 'to stop sleeping and become conscious', 'difficulty' => 'easy'],
                                    ['phrase' => 'get up', 'meaning' => 'to get out of bed after waking up', 'difficulty' => 'easy'],
                                    ['phrase' => 'go to bed', 'meaning' => 'to get into bed to sleep', 'difficulty' => 'easy'],
                                    ['phrase' => 'go to sleep', 'meaning' => 'to start sleeping', 'difficulty' => 'easy'],
                                    ['phrase' => 'have a late night', 'meaning' => 'to go to bed much later than usual', 'difficulty' => 'medium'],
                                    ['phrase' => 'have a sleep', 'meaning' => 'to rest for a short period during the day', 'difficulty' => 'medium'],
                                    // Same 4 words/phrases the real Listening episode uses (see the
                                    // "story" comment above) — same meanings as Listening's own
                                    // target_phrases, so the definition never contradicts itself
                                    // between the two steps.
                                    ['phrase' => 'morning person', 'meaning' => 'someone who has a lot of energy at the start of the day', 'difficulty' => 'medium'],
                                    ['phrase' => 'oversleep', 'meaning' => 'to sleep longer than you should, by accident', 'difficulty' => 'medium'],
                                    // accepted_paraphrases: a hand-picked, safe alternate wording
                                    // Active Recall's local (non-AI) check also accepts for this
                                    // exact phrase — never inferred generically (a phrasal verb's
                                    // particle is load-bearing, so it can't be stripped the way an
                                    // article can), just an author's judgment call per word. See
                                    // ⚡active-recall.blade.php's matches().
                                    ['phrase' => 'sleep in', 'meaning' => 'to stay in bed and sleep later than usual', 'difficulty' => 'hard', 'accepted_paraphrases' => ['sleep late']],
                                    // Food
                                    ['phrase' => 'skip breakfast', 'meaning' => 'to not eat breakfast, when you usually do', 'difficulty' => 'easy'],
                                    ['phrase' => 'cereal', 'meaning' => 'a breakfast food made from grain, eaten with milk', 'image_query' => 'bowl of cereal breakfast', 'difficulty' => 'easy'],
                                    ['phrase' => 'have a light lunch', 'meaning' => 'to eat a small meal at midday', 'difficulty' => 'medium'],
                                    ['phrase' => 'snack', 'meaning' => 'a small amount of food eaten between meals', 'image_query' => 'healthy snack food', 'difficulty' => 'easy'],
                                    ['phrase' => "don't bother", 'meaning' => "to not do something because it's too much effort", 'difficulty' => 'hard'],
                                    ['phrase' => 'takeaway', 'meaning' => 'a meal bought from a restaurant but eaten at home', 'image_query' => 'takeaway food box', 'difficulty' => 'medium'],
                                    ['phrase' => 'feed', 'meaning' => 'to give food to a person or animal', 'image_query' => 'feeding cat pet', 'difficulty' => 'easy'],
                                    // Bathroom routines — image_query marks the concrete-noun words
                                    // worth a picture flashcard (dual coding — see EOS-009 §8); an
                                    // abstract phrase like "have a wash" gets no query and simply
                                    // shows no image, on purpose.
                                    ['phrase' => 'have a shower', 'meaning' => 'to wash your whole body under running water', 'image_query' => 'shower bathroom', 'difficulty' => 'easy', 'accepted_paraphrases' => ['shower']],
                                    ['phrase' => 'have a wash', 'meaning' => 'to quickly clean part of your body', 'difficulty' => 'hard', 'accepted_paraphrases' => ['wash']],
                                    ['phrase' => 'clean my teeth', 'meaning' => 'to brush your teeth', 'image_query' => 'toothbrush brushing teeth', 'difficulty' => 'easy', 'accepted_paraphrases' => ['brush my teeth', 'brush teeth']],
                                    ['phrase' => 'puts on', 'meaning' => 'applies something, like make-up, to the face', 'difficulty' => 'medium'],
                                    ['phrase' => 'have a bath', 'meaning' => 'to sit and wash in a bath full of water', 'image_query' => 'bathtub bath', 'difficulty' => 'easy', 'accepted_paraphrases' => ['bath']],
                                    // Housework
                                    ['phrase' => 'Fortunately', 'meaning' => 'luckily; because of good luck', 'difficulty' => 'medium'],
                                    ['phrase' => 'cleaner', 'meaning' => 'a person who is paid to clean a home', 'image_query' => 'person cleaning house', 'difficulty' => 'easy'],
                                    ['phrase' => 'housework', 'meaning' => 'the work of keeping a home clean and tidy', 'image_query' => 'cleaning house vacuum', 'difficulty' => 'easy'],
                                    ['phrase' => 'ironing', 'meaning' => 'using an iron to make clothes smooth', 'image_query' => 'ironing clothes', 'difficulty' => 'easy'],
                                    ['phrase' => 'do the shopping', 'meaning' => 'to buy food and other things you need', 'image_query' => 'grocery shopping', 'difficulty' => 'easy'],
                                    ['phrase' => 'doing the washing', 'meaning' => 'washing dirty clothes', 'image_query' => 'laundry basket washing clothes', 'difficulty' => 'medium'],
                                    // Spare time
                                    ['phrase' => 'stay in', 'meaning' => 'to spend your evening at home instead of going out', 'difficulty' => 'easy'],
                                    ['phrase' => 'go out', 'meaning' => 'to leave home to do something for fun', 'difficulty' => 'easy'],
                                    ['phrase' => 'eat out', 'meaning' => 'to have a meal at a restaurant instead of at home', 'difficulty' => 'easy'],
                                    // allow_embedded_match: opt-in ONLY, per word — Active Recall's
                                    // local check may credit this phrase when it appears verbatim
                                    // inside a longer natural sentence ("he might come round later").
                                    // Verified safe specifically for these two: neither is a common
                                    // prefix of a different fixed idiom (unlike e.g. "stay in", which
                                    // would wrongly match "stay in touch" if this were a blanket
                                    // default — see ⚡active-recall.blade.php's matches()). Every
                                    // other word deliberately has no such flag.
                                    ['phrase' => 'come round', 'meaning' => 'to visit someone at their home', 'difficulty' => 'hard', 'allow_embedded_match' => true],
                                    ['phrase' => 'once a week', 'meaning' => 'happening one time every week', 'difficulty' => 'easy', 'allow_embedded_match' => true],
                                    ['phrase' => 'chat', 'meaning' => 'to have an informal, friendly conversation', 'difficulty' => 'easy'],
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
                                    ['speaker' => 'Neil', 'text' => "It depends if I have had enough sleep. If I've had enough sleep, I can get up early. If I haven't, I want to sleep in. How about you?"],
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
                                        'gap_before' => "It depends if I have had enough sleep. If I've had enough sleep, I can ",
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
                                // fact from the episode, plus 3 curated real lines to shadow (repeat
                                // out loud with the audio) once the transcript is unlocked.
                                'detail_question' => [
                                    'question' => 'What time did Neil need to get up to catch his flight, the last time he skipped breakfast?',
                                    'options' => ['3am', '7am', '9am'],
                                    'correct' => 0,
                                ],
                                // Bold marks the naturally-stressed content words (nouns, main verbs,
                                // adjectives, question words) — function words (articles,
                                // prepositions, auxiliary "do"/"does") stay unstressed, standard
                                // English sentence-rhythm teaching. Rendered by <x-stress-marked-line>.
                                'shadow_lines' => [
                                    'So, **Neil**, do you **like** to **get up** **early** or do you **prefer** to **sleep in**?',
                                    "Yes, I **think** it **does**. I'm a **morning person**. That **means** someone that has **a lot of energy** at the **start** of the **day**.",
                                    "**Sometimes** I **skip breakfast**, because when I **wake up**, I'm **not hungry**.",
                                ],
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Build',
                        'mode' => 'solo',
                        'steps' => [
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Daily Listening',
                                // Was 2, then 3 for the recall prompt (+1); now 8 — the audio-ended
                                // gate genuinely requires the full 6:44 (ffprobe-verified) BBC
                                // episode to play through, which 3 never accounted for.
                                'duration_minutes' => 8,
                                'hook' => 'Let your ear warm up to English again — the same real episode, start to finish.',
                                // Purely decorative — a different image than Day 1's own for
                                // visual variety across the 4 listens of the same episode.
                                'image_query' => 'sunrise bedroom window',
                                'recall_prompt' => 'Write one word or phrase you remember hearing.',
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar in Context',
                                'duration_minutes' => 12,
                                'hook' => "Every \"I usually...\" you get right here is one less pause when you're speaking for real.",
                                'focus' => 'Present Simple + Adverbs of Frequency',
                                // The lesson is fully data-driven (see grammar-in-context.blade.php)
                                // so this same step can teach any grammar point a mission seeds it
                                // with — a `sections` list of {heading, body, blocks[]}, each block
                                // one of the component's generic shapes: pairs / examples / chips /
                                // rule_examples. M01 below still renders byte-identically to the
                                // pre-generalization hardcoded Blade version — only the *source* of
                                // this content moved, not what it says.
                                'lesson' => [
                                    'intro' => "We'll cover three things here: how the verb form changes for "
                                        .'he / she / it, how to ask and answer with do / does, and where words '
                                        .'like always, usually, and never go in a sentence.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · The verb changes with he / she / it',
                                            'body' => 'With <strong>I / we / you / they</strong> the verb stays simple. '
                                                .'With <strong>he / she / it</strong> it takes an <strong>-s</strong> '
                                                .'(or an irregular form, like <em>have → has</em>).',
                                            'blocks' => [
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
                                            'heading' => 'B · Questions and negatives use do / does',
                                            'body' => "Use <strong>do</strong>/<strong>don't</strong> with I/we/you/they, "
                                                .'and <strong>does</strong>/<strong>doesn\'t</strong> with he/she/it — '
                                                .'the main verb goes back to its simple form.',
                                            'blocks' => [
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        [
                                                            'items' => [
                                                                'Do you usually wake up early?',
                                                                'Does she work on Saturdays?',
                                                                "I don't usually wake up before seven.",
                                                                "He doesn't work on Sundays.",
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · Where the frequency word goes',
                                            'blocks' => [
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['words' => ['always', 'usually', 'often', 'sometimes', 'rarely', 'never']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'rule_examples',
                                                    'items' => [
                                                        ['rule' => 'One-word verb → the adverb goes before it', 'example' => 'I always wake up early.', 'highlight' => 'always'],
                                                        ['rule' => 'With am/is/are → the adverb goes after it', 'example' => "I'm usually tired in the morning.", 'highlight' => 'usually'],
                                                        ['rule' => 'Two-word verb → the adverb goes after the first word', 'example' => "I don't usually work at night.", 'highlight' => 'usually'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'bridge_note' => "You'll put this straight to use next — in Activation, when you talk about your real daily routine out loud.",
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
                                        'options' => ['She goes to work at eight.', 'She gos to work at eight.', 'She go to work at eight.'],
                                        'correct' => 0,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => "I doesn't exercise every day.",
                                        'options' => ["I don't exercise every day.", "I doesn't exercises every day.", 'I not exercise every day.'],
                                        'correct' => 0,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'He usually wake up late.',
                                        'options' => ['He usually wakes up late.', 'He usually waking up late.', 'He usually wake ups late.'],
                                        'correct' => 0,
                                        'difficulty' => 'hard',
                                    ],
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
                                'sequence_images' => [
                                    ['image_query' => 'alarm clock ringing bedroom morning', 'caption' => 'She wakes up'],
                                    ['image_query' => 'woman brushing teeth bathroom mirror', 'caption' => 'She has a shower and gets ready'],
                                    ['image_query' => 'woman eating breakfast kitchen table', 'caption' => 'She has breakfast'],
                                    ['image_query' => 'woman walking out front door leaving house', 'caption' => 'She leaves for work'],
                                ],
                                'sequencing_words' => ['First', 'Then', 'After that', 'Finally'],
                            ],
                            [
                                'key' => 'activation',
                                'label' => 'Activation',
                                'duration_minutes' => 12,
                                'hook' => "Say it once here, alone — it'll come out easier when someone's actually listening.",
                                'task' => 'Write 5 personal sentences about your daily life using the new vocabulary, then record 2 minutes of solo speaking without reading.',
                            ],
                            [
                                'key' => 'video_shadowing',
                                'label' => 'Video Shadowing',
                                // Was 12, when this required 2 AI-checked comprehension
                                // sentences (same shape as Listening's gist/expression) plus 1
                                // shadowed line; now 10 — those sentences were dropped in favor
                                // of shadowing 2 of 3 lines instead, keeping this step's format
                                // genuinely distinct from Listening's (both real work, different
                                // shape).
                                'duration_minutes' => 10,
                                'hook' => 'Real people, real English, real speed — watch how an English speaker actually talks about her morning, then try to sound just like her.',
                                'source' => "Rachel's English — \"My Morning Routine\"",
                                'video_id' => 'KfVfjL8-R-0',
                                'video_url' => 'https://youtu.be/KfVfjL8-R-0',
                                // Summarized in Claude's own words from the real video's own
                                // published transcript (rachelsenglish.com/my-morning-routine) —
                                // the video is embedded live from YouTube, but its transcript is
                                // never copied verbatim into this app (see EOS-009 §14: content
                                // stays original, no licensing/piracy risk), same principle as
                                // the vocabulary story never copying Cambridge textbook text.
                                'topic_summary' => 'An English-speaking mother shows her family\'s '
                                    .'morning routine on camera: getting her kids breakfast (one '
                                    .'child doesn\'t feel like having cereal, so she makes an egg '
                                    .'instead), getting everyone together to eat, and getting the '
                                    .'kids dressed and their teeth brushed before school — while '
                                    .'explaining some of the real English she naturally uses along '
                                    .'the way.',
                                'comprehension_check' => [
                                    ['statement' => 'The video shows someone\'s morning routine.', 'correct' => true],
                                    ['statement' => 'Everyone in the family wants exactly the same breakfast.', 'correct' => false],
                                    ['statement' => 'The speaker also explains some real English vocabulary and pronunciation.', 'correct' => true],
                                ],
                                // Real vocabulary the video itself teaches, in Claude's own
                                // words — "snack" and "skip" deliberately echo the exact same
                                // meanings already used in Vocabulary Builder/Listening's own
                                // pools (see EOS-009 §8 content-authoring convention).
                                'target_phrases' => [
                                    ['phrase' => 'feel like (something)', 'meaning' => 'to want something at that particular moment'],
                                    ['phrase' => 'get together', 'meaning' => 'to meet up and spend time with someone'],
                                    ['phrase' => 'snack', 'meaning' => 'a small amount of food eaten between meals'],
                                    ['phrase' => 'skip (something)', 'meaning' => 'to not do a usual part of your routine'],
                                    ['phrase' => 'chaotic', 'meaning' => 'in a state of complete confusion and disorder'],
                                ],
                                // Original short lines written by Claude for this app — inspired
                                // by real moments in the video, not copied from its transcript
                                // (see the topic_summary comment above). Bold marks naturally-
                                // stressed content words, same convention as Listening's
                                // shadow_lines — rendered by <x-stress-marked-line>.
                                'shadow_lines' => [
                                    "I don't **feel like** having **cereal** this **morning**.",
                                    'What **time** are you guys **getting together**?',
                                    'We always have a quick **snack** in the **afternoon**.',
                                ],
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
                                'key' => 'daily_listen_3',
                                'label' => 'Daily Listening',
                                // Was 2, then 3 for the recall prompt (+1); now 8 — same real 6:44
                                // (ffprobe-verified) audio-ended gate as daily_listen_2.
                                'duration_minutes' => 8,
                                'hook' => 'Same audio, one more time — familiar is exactly the point.',
                                'image_query' => 'alarm clock wake up bed',
                                'recall_prompt' => "Write a different word or phrase this time — try not to repeat yesterday's.",
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'AI Conversation #1',
                                // Was 10 — bumped since an off-topic spoken answer now asks for a retry.
                                'duration_minutes' => 12,
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
                                'duration_minutes' => 3,
                                'hook' => 'A second pair of ears just heard everything you said — here\'s what stood out.',
                            ],
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
                                // New (2026-09-04) — a real CEFR/IELTS "describe this picture"
                                // task. Everything else in M01 narrates the learner's OWN routine
                                // (Activation, ai_conversation_1/2) — this is the only step that
                                // practices describing someone else's scene objectively: present
                                // continuous for what's happening right now, "there is/are",
                                // prepositions of place. A genuine skill gap, not a repeat.
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
                                    ['phrase' => 'commute', 'type' => 'new', 'definition' => 'a regular journey to and from work'],
                                    ['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired'],
                                    ['phrase' => 'unwind', 'type' => 'new', 'definition' => 'to relax after being busy or stressed'],
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
                                'key' => 'daily_listen_4',
                                'label' => 'Daily Listening',
                                // Was 2, then 3 for the recall prompt (+1); now 8 — same real 6:44
                                // (ffprobe-verified) audio-ended gate as daily_listen_2.
                                'duration_minutes' => 8,
                                'hook' => 'Last time hearing this one — notice how much easier it sounds now.',
                                'image_query' => 'cozy morning breakfast table',
                                'recall_prompt' => "One more — this time, are you sure you'll remember it?",
                            ],
                            [
                                'key' => 'active_recall',
                                'label' => 'Active Recall',
                                // Was 8 — bumped for the optional cross-mission spaced-repetition practice card.
                                'duration_minutes' => 9,
                                'hook' => 'No peeking. This is exactly how real conversations work — no notes, just what stuck.',
                                'instruction' => 'Without looking at the previous pages.',
                                'sections' => [
                                    ['key' => 'expressions', 'label' => '5 expressions I learned', 'count' => 5],
                                    ['key' => 'listening_facts', 'label' => '3 things I learned from the listening', 'count' => 3],
                                    [
                                        'key' => 'present_simple_sentences',
                                        'label' => '3 Present Simple sentences',
                                        'count' => 3,
                                        // Moved verbatim from the old hardcoded PHP case in
                                        // ⚡active-recall.blade.php's runSentenceCheck() — see
                                        // EOS-009 §8 for the "AI judgment lives in seeded content"
                                        // convention (mirrored from grammar_in_context's
                                        // grammar_judgment) this generalizes active_recall onto.
                                        'judgment' => 'Judge whether the learner wrote a genuine, natural personal sentence, correctly '
                                            .'using the present simple tense.',
                                        'major_criteria' => 'the verb is not in the present simple tense, or it is not a genuine personal '
                                            .'statement',
                                        'context' => 'a personal sentence using the present simple tense',
                                        'recap_label' => 'sentences correctly used the present simple',
                                    ],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'Error Log',
                                // Was 6 — bumped for the optional AI-generated fill-in-the-blank drills.
                                'duration_minutes' => 7,
                                'hook' => 'Mistakes are proof you tried something new. Let\'s fix a few, for good.',
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'AI Conversation #2 — Final Challenge',
                                // Was 10 — bumped since an off-topic spoken answer now asks for a retry.
                                'duration_minutes' => 12,
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
    }

    /**
     * Seeds M02 with its real content from document/M02/ (M02.pdf, BBC
     * Learning English "6 Minute English: Making Male Friends"), matching
     * the phase/step map in EOS-009 §7. Split into its own method (unlike
     * M01's inline run()) purely for readability given its size — no
     * behavioral difference.
     *
     * grammar_in_context and active_recall were generalized (commit
     * 42608a8) specifically so this mission could reuse them for a
     * different grammar focus/extra grammar-check section — see
     * grammar-in-context.blade.php's lesson.sections shape and
     * active-recall.blade.php's per-section 'judgment' shape.
     */
    private function seedM02(): void
    {
        $audioUrl = $this->publishMissionAsset('M02', '6_minute_english_making_male_friends.mp3');

        Mission::updateOrCreate(
            ['code' => 'M02'],
            [
                'title' => 'People I Know',
                'module' => 'Relationships',
                'outcome' => 'I can describe people I know, talk about friendships, and explain what makes a good relationship.',
                'phases' => [
                    [
                        'phase' => 'foundation',
                        'label' => 'Foundation',
                        'mode' => 'solo',
                        'steps' => [
                            [
                                'key' => 'mission_brief',
                                'label' => 'Mission Brief',
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
                                'key' => 'vocabulary_builder',
                                'label' => 'Vocabulary Builder',
                                'duration_minutes' => 14,
                                'hook' => 'Next time someone asks about the people in your life, will these words be ready — or will you go quiet?',
                                // Word selection follows Cambridge English Vocabulary in Use's
                                // "Relationships" territory (friends, friendship, family, exes) —
                                // the story and every meaning below are written fresh for this
                                // app, not copied from it (see EOS-009 §14: content stays
                                // original, no licensing/piracy risk).
                                // "times are tough", "get through", "drift away", "Billy No-Mates",
                                // and "double-edged sword" are deliberately woven in here too, not
                                // just their own vocabulary — the real BBC Listening audio right
                                // after this step uses these same 5 words/phrases, so every learner
                                // reads them here first regardless of which words they personally
                                // select, then hears them again in context (same content-authoring
                                // convention as M01).
                                'story' => [
                                    [
                                        'heading' => 'Friends',
                                        'text' => "I'm quite an **outgoing** person, so making friends has never felt "
                                            .'like hard work for me — though I know that isn\'t true for everyone. '
                                            .'My **best friend**, Dan, is actually an **old friend**: we met on our '
                                            .'first day of university, and it took us about a month to really '
                                            .'**get to know each other** properly. Now, more than ten years later, '
                                            .'we still **get on well with** each other, even though we live in '
                                            .'different cities. Before I met Dan, my flatmate used to joke that I '
                                            .'was turning into **Billy No-Mates**, because I hardly ever went out! '
                                            .'These days it\'s the opposite — most weekends I\'m meeting up with a '
                                            .'**mate** from work or catching up with someone from my old football '
                                            .'team. Of course, having a big social life is a bit of a '
                                            .'**double-edged sword**: I love seeing everyone, but some weeks I '
                                            .'barely have an evening free. Sadly, not every friendship lasts. A '
                                            .'couple of my school friends and I have gradually **drifted away** '
                                            .'from each other since we stopped living in the same town — nobody\'s '
                                            .'fault, it just happens.',
                                    ],
                                    [
                                        'heading' => 'Family & Keeping In Touch',
                                        'text' => 'My **close family** is quite small — just my parents, my sister, '
                                            .'and a few **relatives** who live nearby. My sister\'s **current** '
                                            .'boyfriend is actually really funny, much better than her '
                                            .'**ex-partner**, who I never really liked! Whenever **times are '
                                            .'tough** — like the year my grandmother was ill — it\'s usually '
                                            .'family who help me **get through** it, more than anyone else. '
                                            .'That\'s probably why I try to see my relatives at least once a '
                                            .'month, even when life gets busy.',
                                    ],
                                ],
                                'story_words' => [
                                    // Friends
                                    ['phrase' => 'best friend', 'meaning' => 'the friend you are closest to and trust the most', 'difficulty' => 'easy'],
                                    ['phrase' => 'old friend', 'meaning' => 'a friend you have known for a long time (not necessarily an elderly friend)', 'difficulty' => 'medium'],
                                    ['phrase' => 'friendship', 'meaning' => 'the relationship between friends', 'difficulty' => 'easy'],
                                    ['phrase' => 'get to know each other', 'meaning' => 'to gradually become familiar with a person', 'difficulty' => 'medium'],
                                    // accepted_paraphrases: hand-picked, safe alternate wording
                                    // Active Recall's local (non-AI) check also accepts for this
                                    // exact phrase — same author's-judgment-call convention as M01.
                                    ['phrase' => 'get on well with', 'meaning' => 'to have a friendly, easy relationship with someone', 'difficulty' => 'hard', 'accepted_paraphrases' => ['get along with', 'get along well with']],
                                    ['phrase' => 'mate', 'meaning' => '(British informal) a friend', 'image_query' => 'two friends high five outdoors', 'difficulty' => 'medium'],
                                    ['phrase' => 'outgoing', 'meaning' => 'friendly and enjoys meeting/talking to people', 'difficulty' => 'medium'],
                                    ['phrase' => 'Billy No-Mates', 'meaning' => '(slang) a person with no friends', 'difficulty' => 'hard'],
                                    ['phrase' => 'double-edged sword', 'meaning' => 'something with both good and bad consequences', 'difficulty' => 'hard'],
                                    ['phrase' => 'drift away', 'meaning' => 'to gradually grow apart from someone until the relationship ends', 'difficulty' => 'medium', 'accepted_paraphrases' => ['grow apart'], 'allow_embedded_match' => true],
                                    // Family & Keeping In Touch
                                    ['phrase' => 'close family', 'meaning' => 'your nearest family members (parents, siblings, etc.)', 'image_query' => 'family dinner table together', 'difficulty' => 'easy'],
                                    ['phrase' => 'relatives', 'meaning' => 'members of your family', 'image_query' => 'extended family reunion', 'difficulty' => 'easy'],
                                    ['phrase' => 'current', 'meaning' => 'happening or existing now (as opposed to before)', 'difficulty' => 'medium'],
                                    ['phrase' => 'ex-partner', 'meaning' => "a person's former boyfriend/girlfriend/husband/wife", 'difficulty' => 'hard'],
                                    ['phrase' => 'times are tough', 'meaning' => 'periods of trouble, unhappiness, or financial difficulty in life', 'difficulty' => 'medium', 'accepted_paraphrases' => ['times are hard']],
                                    ['phrase' => 'get through', 'meaning' => 'to manage to live through a difficult period of time', 'difficulty' => 'hard', 'allow_embedded_match' => true],
                                ],
                            ],
                            [
                                'key' => 'listening',
                                'label' => 'Listening',
                                'duration_minutes' => 22,
                                'hook' => 'Neil and Beth are asking why British men have fewer close friends than women — do you agree?',
                                'source' => 'BBC Learning English — 6 Minute English: Making Male Friends (2023)',
                                'image_query' => 'two friends talking coffee shop',
                                'audio_url' => $audioUrl,
                                'transcript_ref' => 'document/M02/6_minute_english_making_male_friends.pdf',
                                // Full real transcript (BBC Learning English, "6 Minute English:
                                // Making Male Friends", 2023 — the PDF's own disclaimer notes it's
                                // "not a word-for-word transcript", i.e. this already IS the BBC's
                                // own published paraphrase) — shown in-app only after the learner
                                // has genuinely listened twice (see ⚡listening.blade.php), so it's
                                // a check, not a shortcut. ffprobe-verified real audio: 374.4s (6:14).
                                'transcript' => [
                                    ['speaker' => 'Neil', 'text' => "Hello. This is 6 Minute English from BBC Learning English. I'm Neil."],
                                    ['speaker' => 'Beth', 'text' => "And I'm Beth. There's a famous English saying, 'a friend in need is a friend indeed', and it's true - everyone needs friends to share life's ups and downs. Do you have many friends, Neil?"],
                                    ['speaker' => 'Neil', 'text' => 'Yes, I have some close friends, but maybe not as many as I\'d like.'],
                                    ['speaker' => 'Beth', 'text' => "That's interesting because often it's women who have many friends while men find it harder to maintain strong friendships, especially as they get older. In fact, according to one recent survey, only 27% of British men say they have at least six close friends."],
                                    ['speaker' => 'Neil', 'text' => "So, is it true that men find it difficult to make friends? We'll be hearing from Max Dickins, author of a new book on male friendships called 'Billy No-Mates', and, as usual, we'll be learning some useful new vocabulary as well."],
                                    ['speaker' => 'Beth', 'text' => "But first I have a question for you. We know that close friends are important, not just for having fun but for good mental health as well. So according to research by Oxford University's Institute of Cognitive Anthropology, how many close friends do we need for our mental wellbeing? Is it: a) five? b) ten? or, c) twenty?"],
                                    ['speaker' => 'Neil', 'text' => "I'll say we need at least five close friends."],
                                    ['speaker' => 'Beth', 'text' => "OK, Neil. I'll reveal the answer at the end of the programme. Now, however many friends you have, it's a stereotype that women are better than men at making and keeping close friends. Here's Claudia Hammond outlining the problem for BBC Radio 4 programme, All in the Mind:"],
                                    ['speaker' => 'Claudia Hammond', 'text' => "Now, when times are tough, friends are often the people who get us through, who are there to listen, to reassure, maybe to advise us, if that's what we want. So why do we sometimes find it hard to make friends, or that the friends we used to have seemed to have somehow drifted away? Now, there is an idea that women are much better at maintaining their friendships, and that men are more likely to hang out with whoever is around rather than to nurture those relationships, and we were wondering whether this was really true or is that just a stereotype?"],
                                    ['speaker' => 'Neil', 'text' => 'Claudia uses the phrase, times are tough, to describe a situation of trouble, unhappiness or financial difficulty. Friends help us get through these difficult periods of life. The phrasal verb, get through, has several meanings, but here it means manage to live through an unpleasant period of time.'],
                                    ['speaker' => 'Beth', 'text' => "The problem may be that your friends have drifted away – gradually moved further and further away until your connection with them has broken. And it seems that's especially true for men."],
                                    ['speaker' => 'Neil', 'text' => "That's right. When author Max Dickins was getting married, he realised he didn't have any close male friends he could ask to be his 'best man', the person who helps the groom at a wedding. This led him to write the book 'Billy No-Mates', looking at why he didn't have any close male friends. Here's Claudia Hammond again talking with Max for BBC Radio 4 programme, All in the Mind:"],
                                    ['speaker' => 'Claudia Hammond', 'text' => "Max, it's interesting that you kind of went public on this, if you like… Your book is even called 'Billy No-Mates', the very thing that, you know, a lot of us would dread being. It can't have been easy to decide to say this publicly…"],
                                    ['speaker' => 'Max Dickins', 'text' => "No, it's a real double-edged sword being the face of a book called 'Billy No-Mates', I've gotta say… but I think… so loneliness doesn't look like me. I'm in my early to mid-30s, I'm pretty outgoing, I'm quick to buy my round, it shouldn't look like me, but increasingly it does. So loneliness isn't just the elderly anymore, it's younger people…"],
                                    ['speaker' => 'Beth', 'text' => "Max called his book Billy No-Mates, slang for a person with no friends. It's a memorable book title, but Max says being the public face of a book called 'Billy No-Mates' is a double-edged sword - something with unfavourable as well as favourable consequences."],
                                    ['speaker' => 'Neil', 'text' => "In fact, Max doesn't look like someone with no friends: he's young, generous, and outgoing – an adjective describing someone who's friendly and enjoys meeting people. But increasingly, loneliness is affecting younger men, thanks partly to social media which can make it seem as though everyone is having a great time with their mates, except you!"],
                                    ['speaker' => 'Beth', 'text' => "Max thinks the answer is getting out and meeting people in 'third spaces', places like sports clubs or reading groups which are separate from either home or work."],
                                    ['speaker' => 'Neil', 'text' => "All of which helps get closer to the magical number of friends needed for good mental health. I think it's time you revealed the answer to your question, Beth."],
                                    ['speaker' => 'Beth', 'text' => "Yes, I asked how many close friends we need for our mental wellbeing. You said it was five, which was… the correct answer! According to Oxford University's Professor Robin Dunbar, we need a core circle of five close friends, plus a wider support network of about ten, making a total number of fifteen friends for good mental health. OK, let's recap the vocabulary from the programme, starting with the phrase times are tough, which describes periods of trouble or difficulty in life."],
                                    ['speaker' => 'Neil', 'text' => 'If you get through something, you manage to live through a difficult situation.'],
                                    ['speaker' => 'Beth', 'text' => 'To drift away means to gradually move further apart from someone until your relationship with them eventually ends.'],
                                    ['speaker' => 'Neil', 'text' => 'Billy No-Mates is slang for someone who has no friends.'],
                                    ['speaker' => 'Beth', 'text' => 'A double-edged sword describes something with unfavourable as well as favourable consequences.'],
                                    ['speaker' => 'Neil', 'text' => 'And finally, the adjective outgoing describes someone who is very friendly and enjoys talking to people. Once again, our six minutes are up. Goodbye for now!'],
                                    ['speaker' => 'Beth', 'text' => 'Bye!'],
                                ],
                                // The exact 5 expressions, with the meanings Neil & Beth themselves
                                // gave in the podcast's own end-of-episode recap (page 5).
                                'target_phrases' => [
                                    ['phrase' => 'times are tough', 'meaning' => 'periods of trouble, unhappiness or financial difficulty in life'],
                                    ['phrase' => 'get through', 'meaning' => 'manage to live through a difficult situation'],
                                    ['phrase' => 'drift away', 'meaning' => 'gradually move further and further apart from someone until your relationship with them is broken'],
                                    ['phrase' => 'Billy No-Mates', 'meaning' => 'slang for someone who has no friends'],
                                    ['phrase' => 'double-edged sword', 'meaning' => 'something with unfavourable as well as favourable consequences'],
                                    ['phrase' => 'outgoing', 'meaning' => 'very friendly; enjoys meeting and talking to people'],
                                ],
                                'topic_summary' => 'Neil and Beth discuss why British men often have fewer close friends '
                                    .'than women, especially as they get older — a survey found only 27% of British '
                                    .'men have at least six close friends. They hear from Claudia Hammond on why '
                                    .'friendships can drift apart, and from author Max Dickins about his book '
                                    ."'Billy No-Mates', on male loneliness. The episode ends with Professor Robin "
                                    .'Dunbar\'s research on the ideal number of friends for good mental health: 5 '
                                    .'close friends plus a wider network of 10, for a total of 15.',
                                'comprehension_check' => [
                                    ['statement' => 'The programme says women generally find it easier than men to keep close friendships.', 'correct' => true],
                                    ['statement' => 'Max Dickins wrote his book because he had too many close friends to choose just one as his best man.', 'correct' => false],
                                    ['statement' => 'According to Professor Robin Dunbar, the ideal total number of friends for good mental health is fifteen.', 'correct' => true],
                                ],
                                'shadow_lines' => [
                                    'So, **is** it **true** that **men** find it **difficult** to **make friends**?',
                                    'To **drift away** means to **gradually** move **further apart** from **someone** until your **relationship** with them **eventually ends**.',
                                    "He's **young**, **generous**, and **outgoing** – he's **quick** to **buy** his **round**.",
                                ],
                            ],
                        ],
                    ],
                    [
                        'phase' => 'build',
                        'label' => 'Build',
                        'mode' => 'solo',
                        'steps' => [
                            [
                                'key' => 'daily_listen_2',
                                'label' => 'Daily Listening',
                                'duration_minutes' => 8,
                                'hook' => 'Let your ear warm up to English again — the same real episode, start to finish.',
                                'image_query' => 'two friends chatting park bench',
                                'recall_prompt' => 'Write one word or phrase you remember hearing.',
                            ],
                            [
                                'key' => 'grammar_in_context',
                                'label' => 'Grammar in Context',
                                'duration_minutes' => 12,
                                'hook' => 'Every "she\'s living" you get right here is one less pause when you\'re talking about someone real.',
                                'focus' => 'Present Simple vs Present Continuous',
                                'lesson' => [
                                    'intro' => "We'll cover three things: what each tense is for, how to ask and "
                                        .'answer in each, and how to spot which one a sentence needs.',
                                    'sections' => [
                                        [
                                            'heading' => 'A · What each tense is for',
                                            'body' => '<strong>Present Simple</strong> describes habits, routines, and '
                                                .'general facts about a person — things that are usually true. '
                                                .'<strong>Present Continuous</strong> describes something temporary, '
                                                .'or happening around now, not necessarily this exact second.',
                                            'blocks' => [
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        [
                                                            'label' => 'Present Simple — habits & facts',
                                                            'items' => ['My friend usually helps me.', 'She lives near me.', 'They get on well with each other.'],
                                                        ],
                                                        [
                                                            'label' => 'Present Continuous — temporary & now',
                                                            'items' => ["She's working a lot these days.", "He's living with his friends at the moment.", "We're getting to know each other."],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'B · Asking and answering',
                                            'body' => 'Present Simple questions/negatives use <strong>do/does</strong>; '
                                                .'Present Continuous questions/negatives use <strong>am/is/are</strong>.',
                                            'blocks' => [
                                                [
                                                    'type' => 'examples',
                                                    'groups' => [
                                                        [
                                                            'label' => 'Questions',
                                                            'items' => ['Does she get on well with her sister?', 'Is he still working abroad?'],
                                                        ],
                                                        [
                                                            'label' => 'Negatives',
                                                            'items' => ["They don't see each other very often.", "She isn't living at home right now."],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                        [
                                            'heading' => 'C · Spotting which tense a sentence needs',
                                            'blocks' => [
                                                [
                                                    'type' => 'chips',
                                                    'groups' => [
                                                        ['label' => 'Present Simple time expressions', 'words' => ['usually', 'always', 'every week', 'never']],
                                                        ['label' => 'Present Continuous time expressions', 'words' => ['at the moment', 'these days', 'right now', 'currently']],
                                                    ],
                                                ],
                                                [
                                                    'type' => 'rule_examples',
                                                    'items' => [
                                                        ['rule' => 'A general fact about a person → Present Simple', 'example' => 'My best friend lives in Manchester.', 'highlight' => 'lives'],
                                                        ['rule' => 'A temporary situation right now → Present Continuous', 'example' => 'My best friend is living in Manchester for a few months.', 'highlight' => 'is living'],
                                                        ['rule' => 'A habit → Present Simple', 'example' => 'She usually calls me on Sundays.', 'highlight' => 'calls'],
                                                        ['rule' => 'Something happening around now, not necessarily this second → Present Continuous', 'example' => "She's calling me a lot more since the wedding.", 'highlight' => 'calling'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'bridge_note' => "You'll use this straight away — in Activation, describing someone you're close to.",
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
                                        'wrong' => 'She is knowing him for ten years.',
                                        'options' => ["She's known him for ten years.", 'She is knowing him for ten years.', 'She know him for ten years.'],
                                        'correct' => 0,
                                        'difficulty' => 'easy',
                                    ],
                                    [
                                        'wrong' => 'My friend works in London this month, just for a project.',
                                        'options' => ['My friend is working in London this month, just for a project.', 'My friend works in London this month, just for a project.', 'My friend working in London this month.'],
                                        'correct' => 0,
                                        'difficulty' => 'medium',
                                    ],
                                    [
                                        'wrong' => 'She usually is getting on well with her sister.',
                                        'options' => ['She usually gets on well with her sister.', 'She usually is getting on well with her sister.', 'She usually get on well with her sister.'],
                                        'correct' => 0,
                                        'difficulty' => 'hard',
                                    ],
                                ],
                            ],
                            [
                                'key' => 'activation',
                                'label' => 'Activation',
                                'duration_minutes' => 12,
                                'hook' => 'Say it here, alone, before you have to say it to a real person tomorrow.',
                                'task' => 'Choose a friend or someone close to you. Write at least 3 Present Simple '
                                    .'sentences, 2 Present Continuous sentences, and use at least 3 vocabulary '
                                    .'expressions describing them — then record 2 minutes of solo speaking about '
                                    .'them without reading.',
                            ],
                        ],
                    ],
                    [
                        'phase' => 'practice',
                        'label' => 'Practice',
                        'mode' => 'solo',
                        'steps' => [
                            [
                                'key' => 'daily_listen_3',
                                'label' => 'Daily Listening',
                                'duration_minutes' => 8,
                                'hook' => 'Same audio, one more time — familiar is exactly the point.',
                                'image_query' => 'friends walking together talking',
                                'recall_prompt' => "Write a different word or phrase this time — try not to repeat yesterday's.",
                            ],
                            [
                                'key' => 'ai_conversation_1',
                                'label' => 'AI Conversation #1',
                                'duration_minutes' => 12,
                                'hook' => 'This is the real thing — the AI Instructor is listening, not testing.',
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
                                'key' => 'ai_feedback_1',
                                'label' => 'AI Feedback #1',
                                'duration_minutes' => 3,
                                'hook' => "A second pair of ears just heard how you talk about the people in your life — here's what stood out.",
                            ],
                            [
                                'key' => 'picture_description',
                                'label' => 'Picture Description',
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
                                'key' => 'reading_comprehension',
                                'label' => 'Reading',
                                'duration_minutes' => 12,
                                'hook' => 'Meet Sara — her friendships probably look a lot like yours. Can you follow her story in English?',
                                'passage_title' => 'Meet Sara',
                                'image_query' => 'young woman smiling portrait friendly',
                                // Written fresh for this app (see EOS-009 §14) — deliberately
                                // reuses several Vocabulary Builder pool words/phrases (close,
                                // best friends, gets on well with, outgoing, old friend,
                                // friendship, drifted away, getting to know, close family,
                                // relatives, times are tough, get through) so whichever words a
                                // learner picked there, they meet again here in a fresh context.
                                'passage' => 'Sara has three very close school friends, and they\'ve stayed best '
                                    .'friends for almost fifteen years. She usually sees them once a month for '
                                    .'dinner, but these days it\'s harder to plan, because two of them are '
                                    .'working abroad for a few months. Sara says she gets on well with all three '
                                    .'of them, even though they\'re very different people — one is quiet and '
                                    .'careful, and another is much more outgoing and always wants to organise '
                                    .'something new. Sara also has an old friend, Mina, who she met at her first '
                                    ."job. They don't talk every week anymore, but Sara doesn't think their "
                                    .'friendship has drifted away — she thinks a good friendship can survive a '
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
                                    ['phrase' => 'drifted away', 'type' => 'reused'],
                                    ['phrase' => 'getting to know', 'type' => 'reused'],
                                    ['phrase' => 'close family', 'type' => 'reused'],
                                    ['phrase' => 'relatives', 'type' => 'reused'],
                                    ['phrase' => 'times are tough', 'type' => 'reused'],
                                    ['phrase' => 'get through', 'type' => 'reused'],
                                    ['phrase' => 'colleague', 'type' => 'new', 'definition' => 'a person you work with'],
                                    ['phrase' => 'organise', 'type' => 'new', 'definition' => 'to plan and arrange an event or activity'],
                                    ['phrase' => 'survive', 'type' => 'new', 'definition' => 'to continue existing despite a difficult situation'],
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
                                    ['label' => 'Support', 'image_query' => 'friend comforting supporting another'],
                                ],
                                'try_to_use' => ['because', 'for example', 'another important quality is', 'I think', 'in my opinion'],
                                'min_words' => 100,
                                'max_words' => 150,
                            ],
                        ],
                    ],
                    [
                        'phase' => 'challenge',
                        'label' => 'Challenge',
                        'mode' => 'solo',
                        'steps' => [
                            [
                                'key' => 'active_recall',
                                'label' => 'Active Recall',
                                'duration_minutes' => 9,
                                'hook' => 'No peeking. This is exactly how real conversations work — no notes, just what stuck.',
                                'instruction' => 'Without looking at the previous pages.',
                                'sections' => [
                                    ['key' => 'expressions', 'label' => '5 expressions I learned', 'count' => 5],
                                    ['key' => 'listening_facts', 'label' => '3 things I learned from the BBC episode', 'count' => 3],
                                    [
                                        'key' => 'present_simple_sentences',
                                        'label' => '1 Present Simple sentence about someone I know',
                                        'count' => 1,
                                        'judgment' => 'Judge whether the learner wrote a genuine, natural personal '
                                            .'sentence about someone they know, correctly using the present simple tense.',
                                        'major_criteria' => 'the verb is not in the present simple tense, or it is not '
                                            .'a genuine personal statement about someone the learner knows',
                                        'context' => 'a personal sentence about someone the learner knows, using the present simple tense',
                                        'recap_label' => 'sentences correctly used the present simple',
                                    ],
                                    [
                                        'key' => 'present_continuous_sentences',
                                        'label' => '1 Present Continuous sentence about what someone is doing these days',
                                        'count' => 1,
                                        'judgment' => 'Judge whether the learner wrote a genuine, natural personal '
                                            .'sentence correctly using present continuous tense for something '
                                            .'happening now or temporarily (not a general habit).',
                                        'major_criteria' => 'the verb is not in the present continuous tense, it '
                                            .'describes a general habit rather than something temporary/current, or '
                                            .'it is not a genuine personal statement',
                                        'context' => 'a personal sentence about something someone is doing now or '
                                            .'temporarily, using the present continuous tense',
                                        'recap_label' => 'sentences correctly used the present continuous',
                                    ],
                                ],
                            ],
                            [
                                'key' => 'error_log',
                                'label' => 'Error Log',
                                'duration_minutes' => 7,
                                'hook' => "Every mistake here is one you won't make in tomorrow's Final Challenge.",
                            ],
                            [
                                'key' => 'partner_speaking_session',
                                'label' => 'Partner Speaking Session',
                                'duration_minutes' => 15,
                                'hook' => 'Time to actually talk to someone — a real friend, or yourself, out loud.',
                                'round_groups' => [
                                    [
                                        'label' => 'Your Friends',
                                        'questions' => [
                                            'Who is your closest friend?',
                                            'How did you get to know each other?',
                                            'How long have you known each other?',
                                            'What do you usually do together?',
                                        ],
                                    ],
                                    [
                                        'label' => 'Personality',
                                        'questions' => [
                                            'What is your friend like?',
                                            'What personality traits do you like?',
                                            'What makes someone a good friend?',
                                        ],
                                    ],
                                    [
                                        'label' => 'Deeper',
                                        'questions' => [
                                            'Is it harder to make friends as you get older? Why?',
                                            "How do people maintain friendships when they're busy?",
                                            'Can online friendships be as strong as real-life ones?',
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'key' => 'ai_conversation_2',
                                'label' => 'AI Conversation #2 — Final Challenge',
                                'duration_minutes' => 12,
                                'hook' => "This one's harder on purpose — describe someone who matters, with no script to hide behind.",
                                'rounds' => [
                                    'Introduce this person and explain how you know them.',
                                    'Describe what they\'re like as a person, and what you usually do together.',
                                    'Talk about what they\'re doing these days, and why they matter to you.',
                                ],
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
