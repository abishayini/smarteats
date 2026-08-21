<?php
/**
 * Smart Eats - trading hours (Phase 11B)
 *
 * Until now a restaurant was open because somebody had left the
 * "accepting orders" switch on. That works until the evening a kitchen
 * closes and nobody remembers to press it, and the first anyone knows is
 * a customer paying for food that will not be cooked.
 *
 * This file adds a weekly schedule the system can act on. It does not
 * replace the manual switch, it sits underneath it: the switch is still
 * the immediate "stop now" control, and the schedule handles the routine
 * open and close.
 *
 * A restaurant only follows its schedule once its owner sets
 * `uses_schedule`. A restaurant that has not opted in behaves exactly as
 * it did before, which is what makes this safe to add to a system that
 * already has restaurants trading on it.
 */

require_once __DIR__ . '/functions.php';

/** Day names, indexed the way the database stores them: 0 is Monday. */
function weekday_names(): array
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

/**
 * Today's index, 0 for Monday through 6 for Sunday.
 * PHP's date('N') counts 1 to 7 from Monday, and MySQL's WEEKDAY()
 * counts 0 to 6 from Monday. One subtraction keeps both agreeing rather
 * than introducing a third convention.
 */
function today_index(): int
{
    return (int) date('N') - 1;
}

/** The seven rows for one restaurant, keyed by day, gaps filled in. */
function restaurant_hours(int $restaurantId): array
{
    $rows = db_all(
        'SELECT day_of_week, opens_at, closes_at, is_closed
         FROM restaurant_hours
         WHERE restaurant_id = ?
         ORDER BY day_of_week',
        [$restaurantId]
    );

    $byDay = [];
    foreach ($rows as $row) {
        $byDay[(int) $row['day_of_week']] = $row;
    }

    // A restaurant added before this table existed, or a day somehow
    // missing, still gets a sensible row rather than a warning.
    for ($day = 0; $day <= 6; $day++) {
        $byDay[$day] = $byDay[$day] ?? [
            'day_of_week' => $day,
            'opens_at'    => '11:00:00',
            'closes_at'   => '22:30:00',
            'is_closed'   => 0,
        ];
    }

    ksort($byDay);
    return $byDay;
}

/**
 * Is the restaurant inside its trading hours at this moment?
 *
 * Returns true when the restaurant does not use a schedule at all, so
 * the caller can combine this with the manual switch without special
 * cases.
 *
 * The awkward part is the shift that runs past midnight. A pizzeria open
 * 17:00 to 01:00 is closed at 16:00 on Tuesday and open at 00:30 on
 * Wednesday, and both of those are Tuesday's row. The check therefore
 * looks at yesterday's row as well as today's.
 */
function restaurant_within_hours(array $restaurant, ?int $now = null): bool
{
    if ((int) ($restaurant['uses_schedule'] ?? 0) !== 1) {
        return true;
    }

    $now   = $now ?? time();
    $hours = restaurant_hours((int) $restaurant['id']);

    $todayIndex     = (int) date('N', $now) - 1;
    $yesterdayIndex = ($todayIndex + 6) % 7;
    $minutesNow     = ((int) date('G', $now) * 60) + (int) date('i', $now);

    // Today's shift, which may or may not wrap past midnight.
    $today = $hours[$todayIndex];
    if ((int) $today['is_closed'] !== 1) {
        $opens  = time_to_minutes($today['opens_at']);
        $closes = time_to_minutes($today['closes_at']);

        if ($closes > $opens) {
            if ($minutesNow >= $opens && $minutesNow < $closes) {
                return true;
            }
        } else {
            // Wraps: open from the opening time to the end of the day.
            if ($minutesNow >= $opens) {
                return true;
            }
        }
    }

    // The tail of yesterday's shift, if it ran past midnight.
    $yesterday = $hours[$yesterdayIndex];
    if ((int) $yesterday['is_closed'] !== 1) {
        $opens  = time_to_minutes($yesterday['opens_at']);
        $closes = time_to_minutes($yesterday['closes_at']);

        if ($closes <= $opens && $minutesNow < $closes) {
            return true;
        }
    }

    return false;
}

/** "17:30:00" to 1050 minutes past midnight. */
function time_to_minutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', $time));
    return ($hour * 60) + $minute;
}

/** "17:30:00" to "17:30", which is how a customer expects to read it. */
function pretty_time(string $time): string
{
    return substr($time, 0, 5);
}

/**
 * Today's trading line for the restaurant page, e.g.
 * "Open today 12:00 to 23:00" or "Closed today, open again Wednesday".
 */
function todays_hours_line(array $restaurant): string
{
    if ((int) ($restaurant['uses_schedule'] ?? 0) !== 1) {
        return (string) ($restaurant['opening_hours'] ?? '');
    }

    $hours = restaurant_hours((int) $restaurant['id']);
    $today = $hours[today_index()];
    $names = weekday_names();

    if ((int) $today['is_closed'] === 1) {
        // Look forward for the next day that is actually open.
        for ($offset = 1; $offset <= 7; $offset++) {
            $day = (today_index() + $offset) % 7;
            if ((int) $hours[$day]['is_closed'] !== 1) {
                return 'Closed today, open again '
                     . ($offset === 1 ? 'tomorrow' : $names[$day])
                     . ' from ' . pretty_time($hours[$day]['opens_at']);
            }
        }
        return 'Closed today';
    }

    return 'Open today ' . pretty_time($today['opens_at'])
         . ' to ' . pretty_time($today['closes_at']);
}

/**
 * The whole week as readable lines, consecutive identical days merged.
 * "Monday to Friday 09:00 to 17:00" reads better than five near
 * identical rows, and is how a restaurant writes it on its own door.
 */
function weekly_hours_summary(int $restaurantId): array
{
    $hours = restaurant_hours($restaurantId);
    $names = weekday_names();
    $lines = [];

    $runStart = null;
    $runValue = null;

    for ($day = 0; $day <= 7; $day++) {
        $value = $day <= 6
            ? ((int) $hours[$day]['is_closed'] === 1
                ? 'Closed'
                : pretty_time($hours[$day]['opens_at']) . ' to ' . pretty_time($hours[$day]['closes_at']))
            : null;   // one past the end, to flush the final run

        if ($value !== $runValue) {
            if ($runValue !== null) {
                $label = $runStart === $day - 1
                    ? $names[$runStart]
                    : $names[$runStart] . ' to ' . $names[$day - 1];
                $lines[] = ['days' => $label, 'hours' => $runValue];
            }
            $runStart = $day;
            $runValue = $value;
        }
    }

    return $lines;
}

/* ------------------------------------------------------------------ */
/* Delivery zones                                                      */
/* ------------------------------------------------------------------ */

/**
 * The outward codes a restaurant delivers to, e.g. ['E1','E2','N1'].
 * An empty list means no restriction, which is the default and stays the
 * default: a restaurant that has not thought about zones should not
 * quietly stop delivering.
 */
function delivery_zones(array $restaurant): array
{
    $raw = trim((string) ($restaurant['delivery_postcodes'] ?? ''));

    if ($raw === '') {
        return [];
    }

    $zones = preg_split('/[,\s]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique($zones));
}

/**
 * The outward code from a UK address, or '' if none can be found.
 *
 * Deliberately forgiving. The address is a free text box, the postcode
 * may sit anywhere in it, and the cost of a false negative is refusing a
 * real order, so an address with no recognisable postcode is passed
 * rather than blocked.
 */
function outward_code(string $address): string
{
    // Outward code: one or two letters, a digit, optionally another
    // digit or letter. Matched at a word boundary near the end.
    if (preg_match_all('/\b([A-Z]{1,2}\d[A-Z\d]?)\b/i', strtoupper($address), $matches)) {
        return strtoupper(end($matches[1]));
    }
    return '';
}

/**
 * Does this restaurant deliver to this address?
 * Returns [ok, message]. An unrecognised or unrestricted address is
 * allowed through, with the reasoning above.
 */
function delivers_to(array $restaurant, string $address): array
{
    $zones = delivery_zones($restaurant);

    if (!$zones) {
        return [true, ''];
    }

    $code = outward_code($address);

    if ($code === '') {
        return [true, ''];
    }

    if (in_array($code, $zones, true)) {
        return [true, ''];
    }

    return [false,
        $restaurant['name'] . ' does not deliver to ' . $code . '. They cover '
        . implode(', ', $zones) . '. You can still choose collection.'];
}
