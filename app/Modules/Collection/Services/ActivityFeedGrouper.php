<?php

declare(strict_types=1);

namespace App\Modules\Collection\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cuts the Activity feed's additions into rows: a bulk import would otherwise
 * bury the few cards worth a line under dozens of pennies.
 *
 * Additions priced under the threshold collapse into one group per day and
 * currency, placed where the group's newest member sat. Everything else stays
 * a row of its own: price moves, additions at or over the threshold, and
 * additions with no price of their own (nothing says they are small). A group
 * needs two members; a lone small addition keeps its row. Display only: the
 * rows carry the same entries in the same order.
 */
final class ActivityFeedGrouper
{
    public const SEVERAL_SETS = 'Several sets';

    private const MIN_GROUP = 2;

    /**
     * @param  Collection<int, array<string, mixed>>  $entries  Newest first, as Activity builds them
     * @param  int  $thresholdMinor  Minor units of each entry's own currency; under it is small
     * @return Collection<int, array<string, mixed>> `type` is 'entry' (the entry, plus its type) or 'group'
     */
    public static function group(Collection $entries, int $thresholdMinor): Collection
    {
        $buckets = [];

        foreach ($entries as $index => $entry) {
            $key = self::smallKey($entry, $thresholdMinor);

            if ($key !== null) {
                $buckets[$key][] = $index;
            }
        }

        // A bucket of one is not worth a group; its entry keeps its row.
        $buckets = array_filter($buckets, fn (array $indexes): bool => count($indexes) >= self::MIN_GROUP);
        $groupAt = [];
        $inGroup = [];

        foreach ($buckets as $indexes) {
            $groupAt[$indexes[0]] = $indexes;

            foreach ($indexes as $index) {
                $inGroup[$index] = true;
            }
        }

        $rows = [];

        foreach ($entries as $index => $entry) {
            if (isset($groupAt[$index])) {
                $rows[] = self::groupRow($entries, $groupAt[$index]);
            } elseif (! isset($inGroup[$index])) {
                $rows[] = [...$entry, 'type' => 'entry'];
            }
        }

        return new Collection($rows);
    }

    /**
     * The day-and-currency bucket a small addition belongs to; null for anything else.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function smallKey(array $entry, int $thresholdMinor): ?string
    {
        $snapshot = $entry['snapshot'] ?? null;

        if (($entry['kind'] ?? null) !== 'added' || $snapshot === null || $snapshot->market_minor === null || $snapshot->market_minor >= $thresholdMinor) {
            return null;
        }

        return self::dayOf($entry)->toDateString().'|'.$snapshot->currency;
    }

    /** @param  array<string, mixed>  $entry */
    private static function dayOf(array $entry): CarbonImmutable
    {
        return ($entry['at'] ?? CarbonImmutable::createFromTimestamp(0))->startOfDay();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  list<int>  $indexes
     * @return array<string, mixed>
     */
    private static function groupRow(Collection $entries, array $indexes): array
    {
        $all = $entries->all();
        $members = new Collection(array_map(fn (int $index): array => $all[$index], $indexes));
        $first = $members->firstOrFail();

        return [
            'type' => 'group',
            'kind' => 'group',
            'at' => $first['at'],
            'day' => self::dayOf($first),
            'count' => $members->count(),
            'currency' => $first['snapshot']->currency,
            'maxMinor' => (int) $members->max(fn (array $m): int => $m['snapshot']->market_minor),
            'setName' => self::setNameOf($members->all()),
            'entries' => $members,
        ];
    }

    /**
     * The set most of the group comes from, or SEVERAL_SETS when no set has more
     * than half of it: the line must not name a set most of the cards are not from.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private static function setNameOf(array $members): string
    {
        $counts = array_count_values(array_map(fn (array $m): string => (string) $m['card']?->set?->name, $members));
        arsort($counts);
        $top = array_key_first($counts);

        return $top !== null && $top !== '' && count($members) < $counts[$top] * 2 ? $top : self::SEVERAL_SETS;
    }
}
