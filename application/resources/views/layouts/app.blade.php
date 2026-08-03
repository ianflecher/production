<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Imprint Production')</title>
    {{-- Inter for all UI text. System fallback keeps things readable if the
         font CDN is unreachable. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    {{-- App styles: extracted from this layout into a browser-cacheable stylesheet.
         The ?v= filemtime busts the cache automatically whenever the CSS changes. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
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
                    </a>
                    <a href="{{ route('orders.create') }}" class="nav-item {{ request()->routeIs('orders.create') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        New order
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
                @elseif (auth()->user()->isArtist())
                    {{-- Only artists work from a task list; everyone else on the
                         floor works from the Station board. --}}
                    <a href="{{ route('tasks.mine') }}" class="nav-item {{ request()->routeIs('tasks.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3 8-8"/><path d="M21 12v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h11"/></svg>
                        My Tasks
                    </a>
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
                {{-- Problems surface here rather than waiting for someone to report them. --}}
                @php $errCount = \App\Services\ErrorLog::countRecent(7); @endphp
                <a href="{{ route('system.errors') }}" class="nav-item {{ request()->routeIs('system.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Errors
                    @if ($errCount > 0)
                        <span style="margin-left:auto; min-width:20px; padding:0 6px; border-radius:99px; background:#E31B23; color:#fff; font-weight:700; font-size:0.72rem; line-height:20px; text-align:center;">{{ $errCount > 99 ? '99+' : $errCount }}</span>
                    @endif
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
                        <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}{{ strtoupper(substr(strstr(auth()->user()->name, ' ') ?: ' ', 1, 1)) }}</div>
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
            var dirty = false;

            ['input', 'change'].forEach(function (ev) {
                document.addEventListener(ev, function (e) {
                    if (e.target && e.target.matches && e.target.matches('input, textarea, select')) dirty = true;
                }, true);
            });

            function safeToReload() {
                if (document.visibilityState !== 'visible') return false;
                if (dirty) return false;
                var ae = document.activeElement;
                if (ae && ae.matches && ae.matches('input, textarea, select')) return false;
                if (document.querySelector('details[open]')) return false;
                return true;
            }

            setInterval(function () {
                if (!safeToReload()) return;
                fetch(@json(route('poll.version')), { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (d && d.v && d.v !== version) location.reload();
                    })
                    .catch(function () {});
            }, 5000);
        })();


        /* ---------- Simple in-app notification with sound ---------- */
    (function () {
        const NOTIF_URL = @json(route('poll.notifications'));

        let audioContext = null;

        /*
         * Browsers require at least one user interaction before audio
         * is allowed. The first click or key press unlocks the sound.
         */
        function unlockAudio() {
            const AudioContextClass =
                window.AudioContext || window.webkitAudioContext;

            if (!AudioContextClass) {
                return null;
            }

            if (!audioContext) {
                audioContext = new AudioContextClass();
            }

            if (audioContext.state === 'suspended') {
                audioContext.resume().catch(function () {});
            }

            return audioContext;
        }

        ['click', 'keydown', 'pointerdown'].forEach(function (eventName) {
            window.addEventListener(eventName, unlockAudio, {
                once: true
            });
        });

        /*
         * Generates a short two-tone sound.
         * No MP3 or sound file is required.
         */
        function playNotificationSound() {
            try {
                const context = unlockAudio();

                if (!context || context.state !== 'running') {
                    return;
                }

                const notes = [
                    { frequency: 880, delay: 0 },
                    { frequency: 1175, delay: 0.15 }
                ];

                notes.forEach(function (note) {
                    const oscillator = context.createOscillator();
                    const gain = context.createGain();
                    const startTime = context.currentTime + note.delay;

                    oscillator.type = 'sine';
                    oscillator.frequency.setValueAtTime(
                        note.frequency,
                        startTime
                    );

                    gain.gain.setValueAtTime(0.0001, startTime);
                    gain.gain.exponentialRampToValueAtTime(
                        0.22,
                        startTime + 0.02
                    );
                    gain.gain.exponentialRampToValueAtTime(
                        0.0001,
                        startTime + 0.3
                    );

                    oscillator.connect(gain);
                    gain.connect(context.destination);

                    oscillator.start(startTime);
                    oscillator.stop(startTime + 0.32);
                });
            } catch (error) {
                console.warn('Notification sound failed:', error);
            }
        }

        function createToastContainer() {
            let container = document.getElementById(
                'simpleNotificationContainer'
            );

            if (container) {
                return container;
            }

            container = document.createElement('div');
            container.id = 'simpleNotificationContainer';

            container.style.cssText = [
                'position:fixed',
                'right:20px',
                'bottom:20px',
                'z-index:10000',
                'display:flex',
                'flex-direction:column',
                'gap:10px',
                'width:min(360px,calc(100vw - 40px))',
                'pointer-events:none'
            ].join(';');

            document.body.appendChild(container);

            return container;
        }

        function showToast(notification) {
            const container = createToastContainer();
            const toast = document.createElement('div');

            toast.style.cssText = [
                'background:#0f172a',
                'color:#ffffff',
                'border:1px solid rgba(255,255,255,0.12)',
                'border-radius:12px',
                'padding:14px 16px',
                'box-shadow:0 12px 32px rgba(2,6,23,0.35)',
                'cursor:pointer',
                'pointer-events:auto',
                'opacity:0',
                'transform:translateY(12px)',
                'transition:opacity .2s ease,transform .2s ease'
            ].join(';');

            const title = document.createElement('div');
            title.textContent = notification.title || 'New notification';
            title.style.cssText =
                'font-size:14px;font-weight:700;margin-bottom:4px;';

            const body = document.createElement('div');
            body.textContent = notification.body || '';
            body.style.cssText =
                'font-size:13px;line-height:1.45;color:#cbd5e1;';

            toast.appendChild(title);
            toast.appendChild(body);

            toast.addEventListener('click', function () {
                if (notification.url) {
                    window.location.href = notification.url;
                    return;
                }

                removeToast(toast);
            });

            container.appendChild(toast);

            requestAnimationFrame(function () {
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
            });

            setTimeout(function () {
                removeToast(toast);
            }, 8000);
        }

        function removeToast(toast) {
            if (!toast || !toast.parentNode) {
                return;
            }

            toast.style.opacity = '0';
            toast.style.transform = 'translateY(8px)';

            setTimeout(function () {
                toast.remove();
            }, 250);
        }

        function displayNotifications(notifications) {
            if (!Array.isArray(notifications) || notifications.length === 0) {
                return;
            }

            // Play only one sound even when several notifications arrive.
            playNotificationSound();

            notifications.forEach(function (notification) {
                showToast(notification);
            });
        }

        function pollNotifications() {
            fetch(NOTIF_URL, {
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(
                            'Notification request failed: ' +
                            response.status
                        );
                    }

                    return response.json();
                })
                .then(function (data) {
                    displayNotifications(data.notifications || []);
                })
                .catch(function (error) {
                    console.warn('Notification polling failed:', error);
                });
        }

        // Initial check after page load.
        setTimeout(pollNotifications, 1500);

        // Check for new notifications every six seconds.
        setInterval(pollNotifications, 6000);
    })();
    </script>
    @else
    <main class="guest-main">
        @yield('content')
    </main>
    @endauth
</body>
</html>