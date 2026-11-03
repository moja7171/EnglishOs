<?php

/*
|--------------------------------------------------------------------------
| Taboo bonus words
|--------------------------------------------------------------------------
|
| From M05 on, a Taboo card can carry two "bonus" words: the two words a
| learner would most likely use to explain it. Explaining without them is
| optional and earns one extra point. Written in advance for every mission
| word (add the new mission's words with each mission from M12 on); a word
| from a learner's own notebook gets its pair from the AI once and cached
| (see App\Services\TabooDeck). Keys are lower case.
|
*/

return [
    // M05 · Hobbies & Free Time
    'hang out' => ['friends', 'time'],
    'once or twice' => ['times', 'two'],
    'get (someone) into (something)' => ['start', 'like'],
    'beat' => ['win', 'game'],
    'get the chance' => ['opportunity', 'can'],
    'go camping' => ['tent', 'outside'],
    'rock climbing' => ['mountain', 'climb'],
    'do a bit of' => ['little', 'some'],
    'work out' => ['gym', 'exercise'],
    'keep (someone) fit' => ['healthy', 'exercise'],
    'for fun' => ['enjoy', 'money'],
    'into' => ['like', 'interested'],
    'quite like' => ['like', 'little'],
    "don't mind" => ['okay', 'problem'],
    "can't stand" => ['hate', 'like'],
    'keen on' => ['like', 'interested'],
    'have a lot in common' => ['same', 'similar'],

    // M06 · Learning English
    'quit' => ['stop', 'leave'],
    'a good fit' => ['right', 'match'],
    'one-on-one' => ['two', 'teacher'],
    'gain confidence' => ['sure', 'feel'],
    "open (someone's) eyes" => ['see', 'understand'],
    'fluent' => ['speak', 'easily'],
    'accent' => ['sound', 'country'],
    'accurately' => ['correct', 'mistakes'],
    'make yourself understood' => ['understand', 'people'],
    'pick up' => ['learn', 'easily'],
    'make progress' => ['better', 'improve'],
    'take an exam' => ['test', 'school'],
    'pass' => ['exam', 'test'],
    'fail' => ['exam', 'pass'],
    'revise for' => ['study', 'exam'],
    'do your best' => ['try', 'hard'],
    'get a good grade' => ['score', 'mark'],

    // M07 · Family
    'back in the day' => ['past', 'ago'],
    'afford' => ['money', 'buy'],
    'pay rent' => ['house', 'month'],
    'thick as thieves' => ['friends', 'close'],
    'tell (someone) off' => ['angry', 'wrong'],
    'relatives' => ['family', 'aunt'],
    'close family' => ['parents', 'brother'],
    'only child' => ['brother', 'sister'],
    'elder' => ['older', 'brother'],
    'twins' => ['two', 'same'],
    'get on well with' => ['like', 'friends'],
    'grow up' => ['child', 'older'],
    'be brought up' => ['parents', 'child'],
    'strict' => ['rules', 'parents'],
    'be allowed to' => ['can', 'permission'],
    'let (someone) do' => ['allow', 'can'],
    'stay out late' => ['night', 'home'],

    // M08 · Friends
    'stay close' => ['friends', 'touch'],
    'drift apart' => ['friends', 'slowly'],
    'put the effort in' => ['try', 'work'],
    'a lifelong friend' => ['life', 'always'],
    'introverted' => ['quiet', 'alone'],
    'confident' => ['sure', 'afraid'],
    'shy' => ['quiet', 'nervous'],
    'hard-working' => ['work', 'lazy'],
    'patient' => ['wait', 'angry'],
    'generous' => ['give', 'money'],
    'honest' => ['truth', 'lie'],
    'sensible' => ['good', 'decisions'],
    'silly' => ['funny', 'stupid'],
    'trust' => ['believe', 'secret'],
    'creative' => ['ideas', 'art'],
    'talented' => ['good', 'skill'],
    'sense of humour' => ['funny', 'laugh'],

    // M09 · Personality
    'outgoing' => ['people', 'friendly'],
    'reserved' => ['quiet', 'shy'],
    'drain' => ['tired', 'energy'],
    'recharge' => ['energy', 'rest'],
    'on top of the world' => ['happy', 'very'],
    'proud' => ['happy', 'did'],
    'disappointed' => ['sad', 'expected'],
    'curious' => ['know', 'questions'],
    'anxious' => ['worried', 'nervous'],
    'jealous' => ['want', 'angry'],
    'upset' => ['sad', 'angry'],
    'reliable' => ['trust', 'always'],
    'sociable' => ['people', 'friends'],
    'reasonable' => ['fair', 'sensible'],
    'helpful' => ['help', 'people'],
    'careful' => ['mistakes', 'slowly'],
    'careless' => ['mistakes', 'careful'],

    // M10 · Relationships
    'maintain' => ['keep', 'same'],
    'take it with a grain of salt' => ['believe', 'true'],
    'rush into' => ['fast', 'quickly'],
    'have a temper' => ['angry', 'quickly'],
    'build up' => ['grow', 'slowly'],
    'go out with' => ['date', 'boyfriend'],
    'break up' => ['end', 'relationship'],
    'get engaged' => ['marry', 'ring'],
    'get married' => ['wedding', 'wife'],
    'honeymoon' => ['wedding', 'holiday'],
    'anniversary' => ['year', 'married'],
    'for ages' => ['long', 'time'],
    'ages ago' => ['long', 'past'],
    'recently' => ['ago', 'new'],
    'lately' => ['recently', 'days'],
    'the other day' => ['ago', 'day'],
    'these days' => ['now', 'today'],

    // M11 · Work
    'work from home' => ['office', 'house'],
    'work-life balance' => ['time', 'family'],
    'out of office' => ['away', 'holiday'],
    'get back to' => ['reply', 'later'],
    'cut back' => ['less', 'reduce'],
    'apply for' => ['job', 'form'],
    'cv' => ['job', 'experience'],
    'interview' => ['job', 'questions'],
    'get promoted' => ['job', 'higher'],
    'pay rise' => ['money', 'more'],
    'retire' => ['stop', 'old'],
    'arrange' => ['plan', 'organise'],
    'make an appointment' => ['doctor', 'time'],
    'attend' => ['go', 'meeting'],
    'paperwork' => ['forms', 'documents'],
    'break down' => ['car', 'stop'],
    'run out of' => ['finish', 'none'],
];
