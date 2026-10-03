<?php

namespace Tests\Support;

use Closure;

/**
 * Process environment variables for one callback: set where env() and the app's boot read them ($_ENV, $_SERVER and
 * getenv()), and every one put back afterwards, so the next test starts from phpunit.xml's environment again.
 */
final class Environment
{
    /**
     * Run the callback with these variables set, then restore the environment as it was.
     *
     * @template TReturn
     *
     * @param  array<string, string>  $variables
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function with(array $variables, Closure $callback): mixed
    {
        $names = array_keys($variables);
        $saved = [$_ENV, $_SERVER, array_combine($names, array_map(fn (string $name): string|false => getenv($name), $names))];

        foreach ($variables as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return $callback();
        } finally {
            [$_ENV, $_SERVER] = $saved;

            foreach ($saved[2] as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }
}
