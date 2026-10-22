<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A Gemini reply in the shape LearnerFeedback asks for — one fixed
     * sentence, one good one, one technique — with any key overridable.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function learnerFeedbackReply(array $overrides = []): string
    {
        return json_encode($overrides + [
            'summary' => 'پیامت روشن بود.',
            'severity' => 'minor',
            'sentences' => [
                ['original' => 'I am work in a hospital.', 'status' => 'fix', 'corrected' => 'I work in a hospital.', 'why' => 'بعد از «I» فعل ساده می‌آید.', 'pattern' => 'present simple'],
                ['original' => 'I like my job.', 'status' => 'ok', 'corrected' => null, 'why' => null, 'pattern' => null],
            ],
            'positives' => ['دلیل آوردی.'],
            'negatives' => ['زمان فعل‌ها را تمرین کن.'],
            'techniques' => [['key' => 'linking', 'tip' => 'جمله‌ها را وصل کن.', 'example' => 'I like my job, so I work hard.']],
            'better_version' => 'I work in a hospital, and I really like my job.',
        ]);
    }
}
