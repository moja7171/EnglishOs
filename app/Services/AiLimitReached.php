<?php

namespace App\Services;

use RuntimeException;

/**
 * The admin capped this learner's AI requests on the app's key for the
 * day (users.ai_daily_limit) and the cap is used up. Thrown before any
 * request is made; AiFailure turns it into a plain "try again tomorrow".
 */
class AiLimitReached extends RuntimeException {}
