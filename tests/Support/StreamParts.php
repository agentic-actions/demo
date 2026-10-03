<?php

namespace Tests\Support;

use UnexpectedValueException;

/**
 * Reads the assistant's streamed body the way useChat does: one part per "data:" line, and the rows it leaves.
 */
final class StreamParts
{
    /**
     * The body's parts in order, "[DONE]" as the string itself.
     *
     * @return list<array<string, mixed>|'[DONE]'>
     */
    public static function of(string $body): array
    {
        $parts = [];

        foreach (explode("\n\n", trim($body)) as $frame) {
            if (! str_starts_with($frame, 'data: ')) {
                throw new UnexpectedValueException("Not a data line: [{$frame}]");
            }

            $data = substr($frame, 6);

            $parts[] = $data === '[DONE]' ? '[DONE]' : json_decode($data, true, flags: JSON_THROW_ON_ERROR);
        }

        return $parts;
    }

    /**
     * Each part's type, "[DONE]" as itself.
     *
     * @param  list<array<string, mixed>|'[DONE]'>  $parts
     * @return list<string>
     */
    public static function types(array $parts): array
    {
        return array_map(fn (array|string $part): string => is_string($part) ? $part : (string) $part['type'], $parts);
    }

    /**
     * The rows the panel keeps: each data-action id once, in order of first appearance, with its last data.
     *
     * @param  list<array<string, mixed>|'[DONE]'>  $parts
     * @return list<array<string, mixed>>
     */
    public static function rows(array $parts): array
    {
        $rows = [];

        foreach ($parts as $part) {
            if (is_array($part) && $part['type'] === 'data-action') {
                $rows[$part['id']] = $part['data'];
            }
        }

        return array_values($rows);
    }

    /**
     * The running labels, in order: what each row said before its tool finished.
     *
     * @param  list<array<string, mixed>|'[DONE]'>  $parts
     * @return list<string>
     */
    public static function runningLabels(array $parts): array
    {
        return array_values(array_map(
            fn (array $part): string => $part['data']['label'],
            array_filter($parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'data-action' && $part['data']['status'] === 'running'),
        ));
    }

    /**
     * The reply's words, joined.
     *
     * @param  list<array<string, mixed>|'[DONE]'>  $parts
     */
    public static function text(array $parts): string
    {
        return implode('', array_map(
            fn (array $part): string => $part['delta'],
            array_filter($parts, fn (array|string $part): bool => is_array($part) && $part['type'] === 'text-delta'),
        ));
    }
}
