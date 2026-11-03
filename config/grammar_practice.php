<?php

/*
|--------------------------------------------------------------------------
| Grammar Time — extra "Which one is right?" pairs
|--------------------------------------------------------------------------
|
| Each lesson section of Grammar Time gets three "Which one is right?"
| questions (see ⚡grammar-in-context sectionPairs()): first its own seeded
| spot-the-mistake / don't-do pairs from MissionSeeder, then these, until
| there are three. Keyed by mission code, then by the section's index in
| the seeded lesson ("Make it personal" sections have none). Each pair is
| one common B1 mistake on the section's rule, its fix, and a short why.
| Add a mission's pairs here when the mission is built.
|
*/

return [
    'M01' => [
        0 => [
            ['wrong' => 'My brother go to the gym after work.', 'right' => 'My brother goes to the gym after work.', 'why' => 'he / she / it → goes (go + es).'],
            ['wrong' => 'She watchs TV every evening.', 'right' => 'She watches TV every evening.', 'why' => 'After ch, sh, s or x, add -es: watches.'],
        ],
        1 => [
            ['wrong' => 'Do your sister work at weekends?', 'right' => 'Does your sister work at weekends?', 'why' => 'he / she / it → does in questions.'],
        ],
        2 => [
            ['wrong' => 'I wake up always at seven.', 'right' => 'I always wake up at seven.', 'why' => 'always goes before the main verb.'],
            ['wrong' => 'She usually is tired after work.', 'right' => 'She is usually tired after work.', 'why' => 'With am / is / are, the adverb goes after it.'],
            ['wrong' => 'I never don\'t eat breakfast.', 'right' => 'I never eat breakfast.', 'why' => 'never is already "no" — don\'t add don\'t.'],
        ],
    ],
    'M02' => [
        0 => [
            ['wrong' => 'Water is boiling at 100 degrees.', 'right' => 'Water boils at 100 degrees.', 'why' => 'A fact that is always true → Present Simple.'],
            ['wrong' => 'Look! My friends wait for us outside.', 'right' => 'Look! My friends are waiting for us outside.', 'why' => 'Happening right now → Present Continuous.'],
        ],
        1 => [
            ['wrong' => 'Are you know her brother?', 'right' => 'Do you know her brother?', 'why' => 'know is a Present Simple verb → ask with do.'],
            ['wrong' => 'What does she doing these days?', 'right' => 'What is she doing these days?', 'why' => 'these days = now → is she doing.'],
        ],
        2 => [
            ['wrong' => 'He is visiting his parents every Sunday.', 'right' => 'He visits his parents every Sunday.', 'why' => 'every Sunday = a habit → Present Simple.'],
            ['wrong' => 'At the moment I study for an exam.', 'right' => 'At the moment I\'m studying for an exam.', 'why' => 'at the moment → Present Continuous.'],
            ['wrong' => 'I\'m not understanding this question.', 'right' => 'I don\'t understand this question.', 'why' => 'understand is a state verb — no -ing.'],
        ],
    ],
    'M03' => [
        0 => [
            ['wrong' => 'You don\'t have to smoke in the office — it\'s not allowed.', 'right' => 'You mustn\'t smoke in the office — it\'s not allowed.', 'why' => 'Not allowed → mustn\'t. don\'t have to = not necessary.'],
            ['wrong' => 'She have to wear a uniform at work.', 'right' => 'She has to wear a uniform at work.', 'why' => 'he / she / it → has to.'],
        ],
        1 => [
            ['wrong' => 'He musts start work at eight.', 'right' => 'He must start work at eight.', 'why' => 'must never takes -s.'],
        ],
        2 => [
            ['wrong' => 'I can\'t to come to the meeting tomorrow.', 'right' => 'I can\'t come to the meeting tomorrow.', 'why' => 'After can / can\'t, no to.'],
            ['wrong' => 'Can you to help me with this report?', 'right' => 'Can you help me with this report?', 'why' => 'Can you + plain verb.'],
            ['wrong' => 'She cans use the new software.', 'right' => 'She can use the new software.', 'why' => 'can never takes -s.'],
        ],
    ],
    'M04' => [
        0 => [
            ['wrong' => 'I drink two waters a day.', 'right' => 'I drink two glasses of water a day.', 'why' => 'water is uncountable → count the glasses.'],
            ['wrong' => 'Can you give me an advice about healthy food?', 'right' => 'Can you give me some advice about healthy food?', 'why' => 'advice is uncountable → some advice, not an advice.'],
        ],
        1 => [
            ['wrong' => 'There isn\'t many milk in the fridge.', 'right' => 'There isn\'t much milk in the fridge.', 'why' => 'milk is uncountable → much.'],
            ['wrong' => 'I eat a little apples every day.', 'right' => 'I eat a few apples every day.', 'why' => 'apples are countable → a few.'],
            ['wrong' => 'How much eggs do we need?', 'right' => 'How many eggs do we need?', 'why' => 'eggs are countable → how many.'],
        ],
    ],
    'M05' => [
        0 => [
            ['wrong' => 'I don\'t mind to wait for you.', 'right' => 'I don\'t mind waiting for you.', 'why' => 'don\'t mind + -ing.'],
            ['wrong' => 'She finished to paint the room at six.', 'right' => 'She finished painting the room at six.', 'why' => 'finish + -ing.'],
        ],
        1 => [
            ['wrong' => 'We\'re planning going to the mountains.', 'right' => 'We\'re planning to go to the mountains.', 'why' => 'plan + to + verb.'],
        ],
        2 => [
            ['wrong' => 'He\'s good at play the guitar.', 'right' => 'He\'s good at playing the guitar.', 'why' => 'After at, the verb takes -ing.'],
            ['wrong' => 'Thanks for help me yesterday.', 'right' => 'Thanks for helping me yesterday.', 'why' => 'After for, the verb takes -ing.'],
        ],
    ],
    'M06' => [
        0 => [
            ['wrong' => 'I studyed English at school.', 'right' => 'I studied English at school.', 'why' => 'Consonant + y → -ied: studied.'],
            ['wrong' => 'We stoped the lesson at ten.', 'right' => 'We stopped the lesson at ten.', 'why' => 'Short verb ending in one vowel + consonant → double it: stopped.'],
            ['wrong' => 'Yesterday I watch a film in English.', 'right' => 'Yesterday I watched a film in English.', 'why' => 'Finished past time → past form: watched.'],
        ],
        1 => [
            ['wrong' => 'She buyed a new dictionary.', 'right' => 'She bought a new dictionary.', 'why' => 'buy → bought (its own past form).'],
            ['wrong' => 'I teached English for two years.', 'right' => 'I taught English for two years.', 'why' => 'teach → taught.'],
        ],
        3 => [
            ['wrong' => 'We was very tired after the exam.', 'right' => 'We were very tired after the exam.', 'why' => 'we / you / they → were.'],
        ],
    ],
    'M07' => [
        0 => [
            ['wrong' => 'What you were doing at nine last night?', 'right' => 'What were you doing at nine last night?', 'why' => 'Question: were comes before you.'],
        ],
        1 => [
            ['wrong' => 'While I walked home, it started to rain.', 'right' => 'While I was walking home, it started to rain.', 'why' => 'The longer action → was walking.'],
            ['wrong' => 'I was cooking when my mum was calling.', 'right' => 'I was cooking when my mum called.', 'why' => 'The short action in the middle → past simple.'],
        ],
        2 => [
            ['wrong' => 'When I was a child, I was used to climb trees.', 'right' => 'When I was a child, I used to climb trees.', 'why' => 'used to + verb — no was.'],
        ],
        3 => [
            ['wrong' => 'Where did you used to live?', 'right' => 'Where did you use to live?', 'why' => 'After did, use to (no -d).'],
        ],
    ],
    'M08' => [
        1 => [
            ['wrong' => 'My new phone is very better than the old one.', 'right' => 'My new phone is much better than the old one.', 'why' => 'Before a comparative, use much, not very.'],
        ],
        2 => [
            ['wrong' => 'This film isn\'t as good than the book.', 'right' => 'This film isn\'t as good as the book.', 'why' => 'as … as — never as … than.'],
            ['wrong' => 'He is as taller as his father.', 'right' => 'He is as tall as his father.', 'why' => 'as + the plain adjective + as.'],
        ],
        3 => [
            ['wrong' => 'It was the most happiest day of my life.', 'right' => 'It was the happiest day of my life.', 'why' => 'Use -est OR most — never both.'],
        ],
    ],
    'M09' => [
        0 => [
            ['wrong' => 'He\'s the kind of person which always helps.', 'right' => 'He\'s the kind of person who always helps.', 'why' => 'For a person, use who — not which.'],
        ],
        2 => [
            ['wrong' => 'The book that I read it was very funny.', 'right' => 'The book that I read was very funny.', 'why' => 'Don\'t add it again after that.'],
            ['wrong' => 'She is the friend I always talk.', 'right' => 'She is the friend I always talk to.', 'why' => 'The preposition stays at the end: talk to.'],
        ],
        3 => [
            ['wrong' => 'That\'s the girl who\'s brother is in my class.', 'right' => 'That\'s the girl whose brother is in my class.', 'why' => 'whose = her / his / their.'],
        ],
    ],
    'M10' => [
        0 => [
            ['wrong' => 'She has went to the wedding without me.', 'right' => 'She has gone to the wedding without me.', 'why' => 'has + past participle: gone, not went.'],
        ],
        2 => [
            ['wrong' => 'We have been together since three years.', 'right' => 'We have been together for three years.', 'why' => 'A length of time → for.'],
        ],
        3 => [
            ['wrong' => 'When have you met your husband?', 'right' => 'When did you meet your husband?', 'why' => 'When…? asks about a finished time → past simple.'],
            ['wrong' => 'How long do you know your best friend?', 'right' => 'How long have you known your best friend?', 'why' => 'How long…? until now → have you known.'],
        ],
    ],
    'M11' => [
        0 => [
            ['wrong' => 'I meet the new team on Friday — it\'s in my calendar.', 'right' => 'I\'m meeting the new team on Friday — it\'s in my calendar.', 'why' => 'An arranged plan → am / is / are + -ing.'],
        ],
        1 => [
            ['wrong' => 'Look at those clouds — it will rain.', 'right' => 'Look at those clouds — it\'s going to rain.', 'why' => 'You can see it coming → going to.'],
        ],
        2 => [
            ['wrong' => 'You look busy. I\'m going to help you with that box!', 'right' => 'You look busy. I\'ll help you with that box!', 'why' => 'An offer you decide now → will.'],
        ],
        3 => [
            ['wrong' => 'A: We have no milk. B: Oh, I\'m going to get some now.', 'right' => 'A: We have no milk. B: Oh, I\'ll get some now.', 'why' => 'Decided while speaking → will.'],
            ['wrong' => 'I\'ll start a new course next month — I signed up last week.', 'right' => 'I\'m going to start a new course next month — I signed up last week.', 'why' => 'Decided before speaking → going to.'],
        ],
    ],
];
