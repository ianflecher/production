<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Imprint Production')</title>
    @include('partials.fonts')
    {{-- App styles: extracted from this layout into a browser-cacheable stylesheet.
         The ?v= filemtime busts the cache automatically whenever the CSS changes. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{-- Page-specific stylesheets. A view pushes its own with @push('styles'),
         so a heavy page's CSS is cached by the browser instead of being sent
         again inside the HTML on every visit. --}}
    @stack('styles')
</head>
<body>
    @auth
    <div class="shell">
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <div class="brand-mark">IP</div>
                <div class="brand-name">
                    Imprint
                    <small>Production</small>
                </div>
            </div>

            <nav class="nav-section">
                <div class="nav-label">Workspace</div>
                <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
                    Dashboard
                </a>

                {{-- Messages — everyone can reach their own inbox. --}}
                @php $msgUnread = \App\Models\Message::unreadFor(auth()->id()); @endphp
                <a href="{{ route('messages.index') }}" class="nav-item {{ request()->routeIs('messages.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    Messages
                    @if ($msgUnread > 0)
                        <span id="msgBadge" style="margin-left:auto; min-width:20px; padding:0 6px; border-radius:99px; background:#E31B23; color:#fff; font-weight:700; font-size:0.72rem; line-height:20px; text-align:center;">{{ $msgUnread }}</span>
                    @endif
                </a>

                @if (auth()->user()->isLeader())
                    <a href="{{ route('orders.index') }}" class="nav-item {{ request()->routeIs('orders.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                        Production Orders
                    </a>
                    {{-- The follow-up list and the client book. Sales work
                         their own; a leader, supervisor or admin sees the
                         whole shop's — the scope is already in the query. --}}
                    <a href="{{ route('inquiries.index') }}" class="nav-item {{ request()->routeIs('inquiries.index') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Follow-ups
                    </a>
                    <a href="{{ route('clients.index') }}" class="nav-item {{ request()->routeIs('clients.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Clients
                    </a>
                    <a href="{{ route('approvals') }}" class="nav-item {{ request()->routeIs('approvals') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11"/></svg>
                        Approvals
                        @if (($pendingApprovals ?? 0) > 0)
                            <span class="count-pill">{{ $pendingApprovals }}</span>
                        @endif
                    </a>
                    <a href="{{ route('calendar') }}" class="nav-item {{ request()->routeIs('calendar') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Calendar
                    </a>
                @elseif (auth()->user()->isSales())
                    <a href="{{ route('orders.index') }}" class="nav-item {{ request()->routeIs('orders.index') || request()->routeIs('orders.show') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                        Orders
                        @if (($openOrders ?? 0) > 0)
                            <span class="count-pill">{{ $openOrders }}</span>
                        @endif
                    </a>
                    {{-- Taking an order starts by writing down who is asking,
                         so the way in is the inquiry, not the order form. --}}
                    <a href="{{ route('inquiries.create') }}" class="nav-item {{ request()->routeIs('inquiries.create') || request()->routeIs('orders.create') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        New order
                    </a>
                    {{-- Only its own page. "inquiries.*" also matched
                         inquiries.create, which is where New order goes, so
                         both items lit up at once. --}}
                    <a href="{{ route('inquiries.index') }}" class="nav-item {{ request()->routeIs('inquiries.index') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Follow-ups
                    </a>
                    <a href="{{ route('sample.review') }}" class="nav-item {{ request()->routeIs('sample.review') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Sample Review
                        @if (($pendingSamples ?? 0) > 0)
                            <span class="count-pill">{{ $pendingSamples }}</span>
                        @endif
                    </a>
                    <a href="{{ route('calendar') }}" class="nav-item {{ request()->routeIs('calendar') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Calendar
                    </a>
                @elseif (auth()->user()->isMover())
                    {{-- The mover works from the conversations: each thread now
                         carries the job's pipeline, so a separate Job Orders tab
                         was a second way to the same thing. The pages are still
                         reachable — "Open job order" sits in every thread. --}}
                    <a href="{{ route('calendar') }}" class="nav-item {{ request()->routeIs('calendar') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Calendar
                    </a>
                @elseif (auth()->user()->isArtist())
                    {{-- Only artists work from a task list; everyone else on the
                         floor works from the Station board. --}}
                    <a href="{{ route('inquiries.layouts') }}" class="nav-item {{ request()->routeIs('inquiries.layouts') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
                        Layouts
                        @if (($layoutsToDraw ?? 0) > 0)
                            <span class="count-pill">{{ $layoutsToDraw }}</span>
                        @endif
                    </a>
                    <a href="{{ route('tasks.mine') }}" class="nav-item {{ request()->routeIs('tasks.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11"/></svg>
                        My Tasks
                        @if (($myActiveOrders ?? 0) > 0)
                            <span class="count-pill">{{ $myActiveOrders }}</span>
                        @endif
                    </a>
                    {{-- The artist leader works the bench like the rest of them,
                         and on top of that checks what they hand in. --}}
                    @if (auth()->user()->isArtistLead())
                        <a href="{{ route('approvals') }}" class="nav-item {{ request()->routeIs('approvals') ? 'active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11"/></svg>
                            Tech packs to check
                            @if (($pendingApprovals ?? 0) > 0)
                                <span class="count-pill">{{ $pendingApprovals }}</span>
                            @endif
                        </a>
                        <a href="{{ route('users.index') }}" class="nav-item {{ request()->routeIs('users.*') ? 'active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            Artists
                        </a>
                    @endif
                @endif

                @if (auth()->user()->canManageInventory())
                    <a href="{{ route('inventory.index') }}" class="nav-item {{ request()->routeIs('inventory.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        Raw Materials
                        {{-- The count is for the raw-materials desk, not leaders/admins. --}}
                        @if (($pendingMaterials ?? 0) > 0 && ! auth()->user()->isLeader())
                            <span class="count-pill">{{ $pendingMaterials }}</span>
                        @endif
                    </a>
                @endif

                {{-- Finished-products inventory — the products desk (e.g. "Inventory"). --}}
                @if (auth()->user()->canManageProducts())
                    <a href="{{ route('products.index') }}" class="nav-item {{ request()->routeIs('products.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                        Inventory
                    </a>
                @endif

                {{-- Station board — only the people who run machines. --}}
                @if (auth()->user()->canUseStations())
                    <a href="{{ route('stations.index') }}" class="nav-item {{ request()->routeIs('stations.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Stations
                    </a>
                @endif

                {{-- Finance — all payments & proof. --}}
                @if (auth()->user()->canManageFinance())
                    <a href="{{ route('finance.index') }}" class="nav-item {{ request()->routeIs('finance.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        Finance
                    </a>

                    {{-- Bookkeeping — money in vs money out, and expenses. --}}
                    <a href="{{ route('books.index') }}" class="nav-item {{ request()->routeIs('books.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        Bookkeeping
                    </a>
                @endif
            </nav>

            @if (auth()->user()->isLeader())
            <nav class="nav-section">
                <div class="nav-label">Management</div>
                {{-- Errors is not in the sidebar. It is a page for whoever is
                     working ON the system, not for the people running the shop
                     on it, and a red count of things they cannot act on only
                     ever read as something being wrong with their work. Still
                     at /system/errors for anyone who needs it. --}}

                {{-- The board says what every station is doing and the calendar
                     says what is due. Neither answers "what is holding us up?" --}}
                <a href="{{ route('reports.bottlenecks') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="4" width="3" height="14"/></svg>
                    Where work gets stuck
                </a>

                <a href="{{ route('users.index') }}" class="nav-item {{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Users
                </a>
            </nav>
            @endif

            <nav class="nav-section">
                <div class="nav-label">Account</div>
                <a href="{{ route('password.edit') }}" class="nav-item {{ request()->routeIs('password.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    My account
                </a>
            </nav>

            <div class="sidebar-foot">
                Signed in as<br>
                <strong style="color: var(--sidebar-ink-strong);">{{ auth()->user()->email }}</strong>
            </div>
        </aside>
        <div class="scrim" id="scrim"></div>

        <div class="main">
            <header class="topbar">
                <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
                <div class="page-title">@yield('page-title', 'Dashboard')</div>
                <div class="topbar-right">
                    <div class="user-chip">
                        {{-- The picture somebody uploaded, or their initials.

                             My Account says the picture "can be displayed beside
                             your name throughout the production system", and
                             this is the one place their name is always beside
                             them — so it showed initials to a person who had
                             just chosen a photograph. --}}
                        <div class="avatar">
                            @if (auth()->user()->profile_photo_path)
                                <img src="{{ asset('storage/'.auth()->user()->profile_photo_path) }}"
                                     alt="{{ auth()->user()->name }}">
                            @else
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}{{ strtoupper(substr(strstr(auth()->user()->name, ' ') ?: ' ', 1, 1)) }}
                            @endif
                        </div>
                        <div class="user-meta">
                            <div class="name">{{ auth()->user()->name }}</div>
                            <div class="role">{{ auth()->user()->positionLabel() }}{{ auth()->user()->teamLabel() ? ' · '.auth()->user()->teamLabel() : '' }}</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">Log out</button>
                    </form>
                </div>
            </header>

            {{-- Flash messages float centered near the top and clear after 5s. --}}
            @if (session('success') || $errors->any())
                <div class="flash-wrap no-print">
                    @if (session('success'))
                        <div class="alert-success flash">{{ session('success') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="alert-error flash">{{ $errors->first() }}</div>
                    @endif
                </div>
            @endif

            <main class="content">
                @yield('content')
            </main>
        </div>
    </div>

    <script>
        (function () {
            var toggle = document.getElementById('menuToggle');
            var sidebar = document.getElementById('sidebar');
            var scrim = document.getElementById('scrim');
            if (!toggle) return;
            toggle.addEventListener('click', function () {
                sidebar.classList.toggle('open');
                scrim.classList.toggle('show');
            });
            scrim.addEventListener('click', function () {
                sidebar.classList.remove('open');
                scrim.classList.remove('show');
            });
        })();

        /* Flash messages clear themselves after 5 seconds so they can't linger
           on screen or end up on a printout. */
        (function () {
            document.querySelectorAll('.flash').forEach(function (el) {
                setTimeout(function () {
                    el.classList.add('is-gone');
                    setTimeout(function () { el.remove(); }, 400);
                }, 5000);
            });
        })();

        // ===== Live updates: reload when the data changes (every 5s) =====
        // Never interrupts you: skipped while typing, while a form has unsaved
        // input, while a popup is open, or while the tab is in the background.
        (function () {
            var version = @json(\App\Services\DataVersion::current());

            /* Is there typing on this page that a reload would throw away?
             *
             * This used to be a flag set by the first keystroke and never
             * cleared, so touching any box — a search field, a filter dropdown
             * — stopped that tab ever refreshing again for as long as it was
             * open. Asking the question each time answers it honestly: a box
             * the person emptied again is not unsaved work, and a search box is
             * not work at all.
             */
            function hasUnsavedTyping() {
                var els = document.querySelectorAll(
                    'input:not([type=hidden]):not([type=search]):not([type=submit]):not([type=button]), textarea, select'
                );

                for (var i = 0; i < els.length; i++) {
                    var el = els[i];

                    if (el.type === 'checkbox' || el.type === 'radio') {
                        if (el.checked !== el.defaultChecked) return true;
                        continue;
                    }

                    if (el.tagName === 'SELECT') {
                        // When the server marks no option as selected, the
                        // browser picks the first one itself — selected true,
                        // defaultSelected false. Reading that as "somebody
                        // changed this" meant a page with any plain dropdown on
                        // it, such as the orders status filter, never refreshed.
                        var hasServerDefault = false;
                        for (var j = 0; j < el.options.length; j++) {
                            if (el.options[j].defaultSelected) { hasServerDefault = true; break; }
                        }

                        if (hasServerDefault) {
                            for (var k = 0; k < el.options.length; k++) {
                                if (el.options[k].selected !== el.options[k].defaultSelected) return true;
                            }
                        }

                        continue;
                    }

                    if ((el.value || '') !== (el.defaultValue || '')) return true;
                }

                return false;
            }

            function safeToReload() {
                if (document.visibilityState !== 'visible') return false;
                if (hasUnsavedTyping()) return false;
                var ae = document.activeElement;
                if (ae && ae.matches && ae.matches('input, textarea, select')) return false;
                // An open <details> that holds a form is somebody mid-way
                // through something; reloading would shut it and lose what
                // they typed. An open one that is only showing information —
                // the job order sheet on the finish page, a help panel — is
                // not a reason to stop the screen ever refreshing again.
                var open = document.querySelectorAll('details[open]');
                for (var i = 0; i < open.length; i++) {
                    if (open[i].querySelector('input, textarea, select')) return false;
                }
                return true;
            }

            function check() {
                if (!safeToReload()) return;
                fetch(@json(route('poll.version')), { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (d && d.v && d.v !== version) location.reload();
                    })
                    .catch(function () {});
            }

            /* Coming back to a window checks straight away.
             *
             * A background tab is deliberately not polled — thirty of them
             * asking on a timer is the load this interval exists to avoid. But
             * it also meant the window you just switched to sat there showing
             * yesterday's screen until the next tick came round, which is
             * exactly the moment somebody is looking at it. */
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') check();
            });
            window.addEventListener('focus', check);

            setInterval(function () {
                check();
            // Every open tab in the shop runs this, so the interval is what
            // decides the background load: at five seconds, 34 people asking
            // was already more than the server could answer. Fifteen keeps the
            // screens honest without the app spending its day being asked
            // whether anything happened.
            }, 15000);
        })();


        /* Click an alert to put it away. One handler on the page catches
           every one of them -- the flash strip up top and the ones a page
           prints itself -- including any that arrive after a reload. */
        document.addEventListener('click', function (e) {
            var alert = e.target.closest('.alert-error, .alert-success');
            if (!alert || alert.classList.contains('is-dismissed')) return;

            alert.classList.add('is-dismissed');
            // Gone from the layout once the animation has run, so it leaves no
            // gap behind it.
            setTimeout(function () { alert.remove(); }, 200);
        });

        /* ---------- Staying up to date ----------
           There is no notification polling. Every open tab used to ask the
           server for new notifications every six seconds AND for a data
           fingerprint every five, which for a shop this size added up to
           more requests per second than the server could answer -- so the
           app spent its time telling people nothing had happened.

           The page now simply reloads itself when the data behind it
           actually changes (see the version check above). Alerts that need
           to reach someone who is not looking at the screen go out as web
           push, which the server sends -- nothing has to keep asking. */
    </script>
    @else
    <main class="guest-main">
        @yield('content')
    </main>
    @endauth

{{-- ============================================================
     A confirmation dialog that belongs to this app.

     The browser's own confirm() box says "127.0.0.1:8000 says" above the
     question, cannot be styled, cannot say which button is the dangerous
     one, and on a shop-floor screen looks like something has gone wrong
     rather than like a question being asked.

     window.icConfirm({...}) returns a Promise<bool>. Nothing on the page
     blocks while it is open, so callers await it.
============================================================ --}}
<div id="icConfirm" class="icc-back" hidden>
    <div class="icc-card" role="dialog" aria-modal="true" aria-labelledby="iccTitle" aria-describedby="iccBody">
        <div class="icc-mark" id="iccMark" aria-hidden="true">!</div>
        <h2 class="icc-title" id="iccTitle">Are you sure?</h2>
        <p class="icc-body" id="iccBody"></p>
        <div class="icc-acts">
            <button type="button" class="icc-btn icc-go" id="iccGo">Yes</button>
            <button type="button" class="icc-btn icc-no" id="iccNo">Cancel</button>
        </div>
    </div>
</div>


<script>
    (function () {
        const back = document.getElementById('icConfirm');
        const card = back.querySelector('.icc-card');
        const titleEl = document.getElementById('iccTitle');
        const bodyEl = document.getElementById('iccBody');
        const goEl = document.getElementById('iccGo');
        const noEl = document.getElementById('iccNo');

        let settle = null;

        function close(answer) {
            back.hidden = true;
            document.body.style.overflow = '';
            const done = settle;
            settle = null;
            if (done) { done(answer); }
        }

        goEl.addEventListener('click', () => close(true));
        noEl.addEventListener('click', () => close(false));

        // Clicking the backdrop or pressing Escape means no. A dialog you
        // cannot dismiss is one people click through to make it go away.
        back.addEventListener('click', e => { if (e.target === back) { close(false); } });
        document.addEventListener('keydown', e => {
            if (back.hidden) { return; }
            if (e.key === 'Escape') { close(false); }
            if (e.key === 'Enter') { close(true); }
        });

        window.icConfirm = function (opts) {
            const o = opts || {};

            titleEl.textContent = o.title || 'Are you sure?';
            bodyEl.textContent = o.message || '';
            goEl.textContent = o.confirmText || 'Yes';
            noEl.textContent = o.cancelText || 'Cancel';
            card.classList.toggle('is-danger', o.tone === 'danger');

            back.hidden = false;
            document.body.style.overflow = 'hidden';
            goEl.focus();

            return new Promise(resolve => { settle = resolve; });
        };
    })();
</script>

</body>
</html>