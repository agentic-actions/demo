<?php

namespace App\Http\Responses;

/**
 * The line a 429 answers with: what to do, and how long to wait, from the limit's Retry-After. Most limits refill
 * within a minute; "Try the demo" counts an hour, client registration and the assistant's daily allowance a day, and
 * "Wait a minute" would send someone back to a second refusal.
 */
final class TooManyTries
{
    /**
     * The line for a wait of $retryAfter seconds, or for an unknown wait.
     */
    public static function message(int|string|null $retryAfter): string
    {
        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : 0;

        if ($seconds <= 60) {
            return __('Too many tries. Wait a minute and try again.');
        }

        if ($seconds < 3600) {
            return __('Too many tries. Try again in :minutes minutes.', ['minutes' => (int) ceil($seconds / 60)]);
        }

        $hours = (int) round($seconds / 3600);

        return $hours === 1
            ? __('Too many tries. Try again in about an hour.')
            : __('Too many tries. Try again in about :hours hours.', ['hours' => $hours]);
    }
}
