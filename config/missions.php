<?php

/**
 * Mission steps the learner never sees. Hiding is done when a mission's
 * phases are read (App\Models\Mission::phases), so the seeded content, the
 * media and every learner's saved answers stay as they are, and removing a
 * key from this list brings the step back. A comma-separated
 * MISSIONS_HIDDEN_STEPS in .env overrides the list ("" shows everything).
 */
return [
    'hidden_steps' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MISSIONS_HIDDEN_STEPS', 'story_sequence,picture_description,daily_listen_2,daily_listen_3')),
    ))),
];
