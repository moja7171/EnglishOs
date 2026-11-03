<?php

/*
|--------------------------------------------------------------------------
| Who am I? secrets
|--------------------------------------------------------------------------
|
| One player is secretly one of these; the other asks yes/no questions
| ("Am I an animal? Do I work in a hospital?") until they guess it. Each
| secret: [the secret, its category, the first mission it can come up in —
| a learner meets jobs in M03, food in M04, hobbies in M05, family in M07].
| The category is shown to the guesser up to M04, so beginners have a
| starting point. Everyday, concrete and easy to ask about.
|
*/

return [
    'categories' => [
        'job' => 'a job',
        'animal' => 'an animal',
        'food' => 'food or a drink',
        'place' => 'a place',
        'thing' => 'a thing',
        'hobby' => 'a hobby or sport',
        'person' => 'a person in a family',
    ],

    'secrets' => [
        // Animals and things: everyday words, from the start.
        ['cat', 'animal', 1], ['dog', 'animal', 1], ['horse', 'animal', 1], ['cow', 'animal', 1],
        ['bird', 'animal', 1], ['fish', 'animal', 1], ['lion', 'animal', 1], ['elephant', 'animal', 1],
        ['monkey', 'animal', 1], ['chicken', 'animal', 1], ['snake', 'animal', 1], ['rabbit', 'animal', 1],
        ['phone', 'thing', 1], ['bed', 'thing', 1], ['car', 'thing', 1], ['bus', 'thing', 1],
        ['alarm clock', 'thing', 1], ['toothbrush', 'thing', 1], ['umbrella', 'thing', 1], ['bike', 'thing', 1],
        ['laptop', 'thing', 1], ['shower', 'thing', 1], ['mirror', 'thing', 1], ['key', 'thing', 1],

        // Places, from the start (home, school, town).
        ['kitchen', 'place', 1], ['bathroom', 'place', 1], ['school', 'place', 1], ['park', 'place', 1],
        ['supermarket', 'place', 1], ['cinema', 'place', 1], ['hospital', 'place', 2], ['airport', 'place', 2],
        ['beach', 'place', 2], ['library', 'place', 2], ['gym', 'place', 2], ['café', 'place', 2],

        // M03 · Work & Study
        ['teacher', 'job', 3], ['doctor', 'job', 3], ['nurse', 'job', 3], ['chef', 'job', 3],
        ['police officer', 'job', 3], ['taxi driver', 'job', 3], ['farmer', 'job', 3], ['student', 'job', 3],
        ['pilot', 'job', 3], ['waiter', 'job', 3], ['hairdresser', 'job', 3], ['engineer', 'job', 3],
        ['dentist', 'job', 3], ['shop assistant', 'job', 3], ['office', 'place', 3], ['university', 'place', 3],

        // M04 · Food & Lifestyle
        ['pizza', 'food', 4], ['rice', 'food', 4], ['bread', 'food', 4], ['ice cream', 'food', 4],
        ['tea', 'food', 4], ['coffee', 'food', 4], ['apple', 'food', 4], ['banana', 'food', 4],
        ['egg', 'food', 4], ['chocolate', 'food', 4], ['soup', 'food', 4], ['kebab', 'food', 4],
        ['orange juice', 'food', 4], ['cheese', 'food', 4], ['restaurant', 'place', 4], ['fridge', 'thing', 4],

        // M05 · Hobbies & Free Time
        ['football', 'hobby', 5], ['swimming', 'hobby', 5], ['reading', 'hobby', 5], ['cooking', 'hobby', 5],
        ['camping', 'hobby', 5], ['rock climbing', 'hobby', 5], ['painting', 'hobby', 5], ['chess', 'hobby', 5],
        ['video games', 'hobby', 5], ['running', 'hobby', 5], ['guitar', 'thing', 5], ['tent', 'thing', 5],

        // M07 · Family
        ['grandmother', 'person', 7], ['uncle', 'person', 7], ['cousin', 'person', 7], ['twin', 'person', 7],
        ['only child', 'person', 7], ['baby', 'person', 7], ['grandfather', 'person', 7], ['aunt', 'person', 7],

        // M10 · Relationships, M11 · Work
        ['wedding', 'place', 10], ['boss', 'job', 11], ['interviewer', 'job', 11], ['receptionist', 'job', 11],
    ],
];
