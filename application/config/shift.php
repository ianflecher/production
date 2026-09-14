<?php

/*
 * The working day.
 *
 * Kept here rather than in a settings table because the shop has one shift
 * and has had it for years: everybody starts at eight and leaves at five.
 * A table would mean a screen, a permission and a history for a figure that
 * changes less often than the price list does.
 *
 * Changing these does NOT re-score days already recorded. Attendance keeps
 * the minutes it was measured with at the time — see the attendance minutes
 * migration for why.
 */
return [

    'start' => '08:00',

    'end' => '17:00',

    /*
     * Minutes past the start that are forgiven entirely.
     *
     * Forgiven, not discounted: somebody inside the grace is not late at all,
     * and somebody past it is late from the start of the shift rather than
     * from the end of the grace. A quarter of an hour is traffic; twenty
     * minutes is twenty minutes.
     */
    'grace_minutes' => 15,

];
