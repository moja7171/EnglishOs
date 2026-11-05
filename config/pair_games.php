<?php

/*
|--------------------------------------------------------------------------
| Two Truths topics
|--------------------------------------------------------------------------
|
| "Two truths and a lie": the speaker says three things about the topic,
| two true and one lie, and the other player finds the lie. The last number
| is the first mission whose grammar the hint uses — an earlier game never
| gets the topic.
|
*/

return [
    'truth_topics' => [
        ['Your morning', 'Present simple: I get up… I usually…', 1],
        ['Food you like or hate', 'I love… I never eat… I always drink…', 1],
        ['Your family', 'My brother is… My mother works…', 1],
        ['Your hobbies', 'I like… I play… In my free time…', 1],
        ['Your best friend', 'He/She is… We often…', 1],
        ['Your home', 'There is… There are… My room has…', 1],
        ['Your job or studies', 'I work as… I study… I like…', 1],
        ['Things you can do', 'I can… I can’t… I am good at…', 3],
        ['Your last weekend', 'Past simple: I went… I ate… I met…', 6],
        ['When you were a child', 'I was… I lived… I liked…', 6],
        ['Places you have been', 'I have been to… I have never…', 10],
        ['Your plans for next week', 'I am going to… I will…', 11],
    ],
];
