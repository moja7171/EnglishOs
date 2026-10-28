<?php

/*
|--------------------------------------------------------------------------
| Pair Practice games
|--------------------------------------------------------------------------
|
| Content for the "Game" part of a Pair Practice round. Taboo needs nothing
| here (it is made from the round's own word cards). Role cards are written
| per mission — add 2 with every new mission. Minimal pairs and the
| "two truths and a lie" topics work for every mission.
|
*/

return [
    /*
     * Words that sound alike for a Persian speaker. One says a word, the
     * other taps the one they heard. 'tip' helps the speaker say it right.
     */
    'minimal_pairs' => [
        ['ship', 'sheep', 'short i / long ee'],
        ['live', 'leave', 'short i / long ee'],
        ['bit', 'beat', 'short i / long ee'],
        ['sit', 'seat', 'short i / long ee'],
        ['hill', 'heel', 'short i / long ee'],
        ['full', 'fool', 'short u / long oo'],
        ['pull', 'pool', 'short u / long oo'],
        ['bad', 'bed', 'a / e'],
        ['pan', 'pen', 'a / e'],
        ['man', 'men', 'a / e'],
        ['cat', 'cut', 'a / u'],
        ['bus', 'boss', 'u / o'],
        ['west', 'vest', 'w / v'],
        ['wet', 'vet', 'w / v'],
        ['wine', 'vine', 'w / v'],
        ['very', 'berry', 'v / b'],
        ['think', 'sink', 'th / s'],
        ['three', 'tree', 'th / t'],
        ['thin', 'tin', 'th / t'],
        ['then', 'den', 'th / d'],
        ['hat', 'at', 'h / no h'],
        ['hair', 'air', 'h / no h'],
        ['sing', 'sin', 'ng / n'],
        ['king', 'kin', 'ng / n'],
        ['pack', 'back', 'p / b'],
        ['pill', 'bill', 'p / b'],
        ['pig', 'big', 'p / b'],
        ['chair', 'share', 'ch / sh'],
        ['cheap', 'sheep', 'ch / sh'],
        ['walk', 'work', 'aw / ur'],
    ],

    /*
     * "Two truths and a lie": the speaker says three things about the topic,
     * two true and one lie, and the other player finds the lie. The last
     * number is the first mission whose grammar the hint uses — an earlier
     * round never gets the topic.
     */
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

    /*
     * Role-play: two secret cards per scene. 'a' belongs to the one whose
     * turn it is, 'b' to the other player; neither sees the other's goal.
     */
    'roles' => [
        'M01' => [
            ['scene' => 'Morning in a shared flat', 'a' => ['role' => 'A flatmate who overslept', 'goal' => 'Get the bathroom first. You have a bus in 10 minutes.'], 'b' => ['role' => 'A flatmate who is also in a hurry', 'goal' => 'Say yes only if your flatmate does the dishes tonight.']],
            ['scene' => 'Planning Friday', 'a' => ['role' => 'Someone who always sleeps in', 'goal' => 'Meet your friend at 11 a.m., not earlier.'], 'b' => ['role' => 'A real morning person', 'goal' => 'Ask to meet at 8 a.m. Then agree on one time.']],
        ],
        'M02' => [
            ['scene' => 'A new neighbour', 'a' => ['role' => 'You just moved in', 'goal' => 'Ask three questions about the neighbourhood.'], 'b' => ['role' => 'The friendly neighbour', 'goal' => 'Ask about their job and family. Invite them for tea.']],
            ['scene' => 'A party', 'a' => ['role' => 'You know only the host', 'goal' => 'Start a talk with a stranger and find two things you both like.'], 'b' => ['role' => 'A shy guest', 'goal' => 'Answer in short sentences, then ask one question back.']],
        ],
        'M03' => [
            ['scene' => 'A job interview', 'a' => ['role' => 'The interviewer', 'goal' => 'Find out the experience and the start date.'], 'b' => ['role' => 'The candidate', 'goal' => 'Get the job. You can only start next month. Ask about the salary.']],
            ['scene' => 'Study group', 'a' => ['role' => 'A student who needs help', 'goal' => 'Find one time to study together this week.'], 'b' => ['role' => 'A busy classmate', 'goal' => 'You are free only on Tuesday and Thursday evenings.']],
        ],
        'M04' => [
            ['scene' => 'At a restaurant', 'a' => ['role' => 'A customer who is vegetarian', 'goal' => 'Order a dish with no meat and no fish.'], 'b' => ['role' => 'The waiter', 'goal' => 'Today’s special has fish. Offer other dishes and sell a dessert.']],
            ['scene' => 'Dinner for guests', 'a' => ['role' => 'Someone who cooks for four people tonight', 'goal' => 'Ask for a simple menu and what to buy.'], 'b' => ['role' => 'A friend who loves cooking', 'goal' => 'Suggest a menu and a shopping list.']],
        ],
        'M05' => [
            ['scene' => 'Joining a club', 'a' => ['role' => 'A new member', 'goal' => 'Ask when the club meets and how much it costs.'], 'b' => ['role' => 'Works at the club', 'goal' => 'Ask about their experience. Invite them to a free first class.']],
            ['scene' => 'Try my hobby', 'a' => ['role' => 'A person who loves a hobby', 'goal' => 'Invite your friend to try it this Saturday.'], 'b' => ['role' => 'A friend who is not sure', 'goal' => 'Ask two questions, then say yes or give a nice reason.']],
        ],
        'M06' => [
            ['scene' => 'Choosing an English class', 'a' => ['role' => 'A student', 'goal' => 'Find a class to speak more, in the evening.'], 'b' => ['role' => 'The school adviser', 'goal' => 'Ask about their level and time. Offer the Tuesday class.']],
            ['scene' => 'A language partner', 'a' => ['role' => 'Someone who wants a partner', 'goal' => 'Agree on the days and how to practise.'], 'b' => ['role' => 'A busy person', 'goal' => 'You are free only at the weekend. Practise by voice, not text.']],
        ],
        'M07' => [
            ['scene' => 'Mum’s birthday', 'a' => ['role' => 'The one who plans the dinner', 'goal' => 'Decide the day and who cooks.'], 'b' => ['role' => 'A brother or sister', 'goal' => 'You are busy on Friday. You will bring the dessert.']],
            ['scene' => 'A phone call to grandma', 'a' => ['role' => 'A grandchild', 'goal' => 'Ask how she is and promise a visit.'], 'b' => ['role' => 'Grandma', 'goal' => 'Say you miss them. Ask when they will come.']],
        ],
        'M08' => [
            ['scene' => 'Cancelling plans', 'a' => ['role' => 'A friend who must cancel today', 'goal' => 'Say sorry and offer another day.'], 'b' => ['role' => 'A friend who is a little upset', 'goal' => 'Accept only if the new plan is better.']],
            ['scene' => 'An old friend', 'a' => ['role' => 'A friend you have not seen for three years', 'goal' => 'Ask what changed and tell your own news.'], 'b' => ['role' => 'The old friend', 'goal' => 'You have a new job in a new city. Ask about their life too.']],
        ],
        'M09' => [
            ['scene' => 'Finding a flatmate', 'a' => ['role' => 'Someone who needs a room', 'goal' => 'Describe your personality honestly.'], 'b' => ['role' => 'The owner of the flat', 'goal' => 'You want a quiet person. Ask three questions about personality.']],
            ['scene' => 'Before a new job', 'a' => ['role' => 'A shy person', 'goal' => 'Ask for advice about the first day.'], 'b' => ['role' => 'A confident friend', 'goal' => 'Give three tips.']],
        ],
        'M10' => [
            ['scene' => 'After an argument', 'a' => ['role' => 'You forgot your partner’s birthday', 'goal' => 'Say sorry and make a plan.'], 'b' => ['role' => 'Your hurt partner', 'goal' => 'Say how you feel. Ask for a real plan, not only sorry.']],
            ['scene' => 'Meeting a friend’s partner', 'a' => ['role' => 'You meet them for the first time', 'goal' => 'Make small talk and ask about hobbies.'], 'b' => ['role' => 'The partner’s best friend', 'goal' => 'Find out their plans for the weekend and invite them.']],
        ],
        'M11' => [
            ['scene' => 'A day off', 'a' => ['role' => 'An employee', 'goal' => 'Get Friday off.'], 'b' => ['role' => 'The manager', 'goal' => 'You are very busy. Agree only if the report is finished first.']],
            ['scene' => 'First day at work', 'a' => ['role' => 'A new colleague', 'goal' => 'Ask where things are and when lunch is.'], 'b' => ['role' => 'A friendly colleague', 'goal' => 'Show the office and invite them to lunch.']],
        ],
    ],
];
