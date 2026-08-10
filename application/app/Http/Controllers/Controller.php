<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Rows per page on the long list screens (orders, messages, stock sheets).
     *
     * These lists only ever grow, so they are paged rather than loaded whole.
     * Kept in one place so the office can be given a longer or shorter page
     * everywhere at once.
     */
    public const PER_PAGE = 10;
}
