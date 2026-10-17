<?php
/**
 * The demo scripts were written with "today" = 2026-10-12. shift() moves any of their literal dates by the number of
 * days between that day and the real today, so the demo always has an open business day today and recent history.
 */
$SHIFT_DAYS = (int) (new DateTime('2026-10-12'))->diff(new DateTime('today'))->format('%r%a');
function shift(string $d): string
{
    global $SHIFT_DAYS;
    $dt = (new DateTime($d))->modify(($SHIFT_DAYS >= 0 ? '+' : '') . $SHIFT_DAYS . ' days');
    return $dt->format(strlen($d) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d');
}
