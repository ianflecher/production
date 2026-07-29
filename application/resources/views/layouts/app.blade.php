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
    <style>
        :root {
            /* Neutral light workspace with Imprint-red branding.
             * --accent stays the calm interactive blue (links, order numbers,
             * focus) so those never read as errors; --brand is Imprint red and
             * drives primary buttons + active navigation. Variable names are
             * unchanged so existing var(--…) references keep working. */
            --bg: #F4F6F9;
            --surface: #ffffff;
            --surface-2: #F7F9FB;
            --border: #E5E9F0;
            --border-strong: #D3DAE4;
            --ink: #17202E;
            --ink-2: #566172;
            --ink-3: #94A0AE;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --accent-soft: #eff6ff;
            --brand: #E31B23;
            --brand-hover: #B5141A;
            --brand-soft: #FDECEC;
            --danger-soft: #fef2f2;
            --danger-border: #fecaca;
            --danger-ink: #b91c1c;
            --success-soft: #f0fdf4;
            --success-border: #bbf7d0;
            --success-ink: #15803d;
            --sidebar-bg: #0C1626;
            --sidebar-ink: #93A0B4;
            --sidebar-ink-strong: #eef2f8;
            --sidebar-active: #17233A;
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 1px 2px rgba(19, 30, 51, .04), 0 2px 8px rgba(19, 30, 51, .05);
            --shadow-md: 0 4px 12px rgba(19, 30, 51, .06), 0 12px 28px rgba(19, 30, 51, .08);
            --font-body: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            --font-head: 'Space Grotesk', 'Inter', system-ui, sans-serif;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: var(--font-body);
            background: #e9eef7;
            color: var(--ink);
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; text-underline-offset: 2px; }
        .btn:hover, a.card:hover, .nav-item:hover, a.btn { text-decoration: none; }
        button { font-family: inherit; }
        ::selection { background: rgba(227, 27, 35, 0.16); }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; border: 2px solid var(--bg); }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        ::-webkit-scrollbar-track { background: transparent; }

        /* ============ App shell ============ */
        .shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 250px;
            flex-shrink: 0;
            background: var(--sidebar-bg);
            color: var(--sidebar-ink);
            display: flex;
            flex-direction: column;
            padding: 1.25rem 0.85rem;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .brand { display: flex; align-items: center; gap: 0.65rem; padding: 0.25rem 0.6rem 1.25rem; }
        .brand-mark {
            width: 36px; height: 36px;
            border-radius: 9px;
            background: var(--brand);
            display: grid; place-items: center;
            color: #fff; font-weight: 800; font-size: 0.95rem;
            box-shadow: 0 2px 8px rgba(227, 27, 35, 0.35);
        }
        .brand-name { font-family: var(--font-head); font-weight: 700; color: #fff; font-size: 1.02rem; line-height: 1.15; letter-spacing: -0.01em; }
        .brand-name small { display: block; font-weight: 500; color: var(--sidebar-ink); font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase; }
        .nav-section { margin-top: 1rem; }
        .nav-label {
            font-size: 0.68rem; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase;
            color: #5b6b84; padding: 0 0.6rem 0.4rem;
        }
        .nav-item {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.58rem 0.65rem;
            border-radius: 9px;
            color: var(--sidebar-ink);
            font-size: 0.88rem; font-weight: 500;
            margin-bottom: 2px;
            transition: background .15s ease, color .15s ease, box-shadow .15s ease;
        }
        .nav-item svg { width: 17px; height: 17px; flex-shrink: 0; transition: color .15s ease; }
        a.nav-item:hover { background: var(--sidebar-active); color: var(--sidebar-ink-strong); }
        .nav-item.active {
            background: var(--sidebar-active);
            color: #fff; font-weight: 600;
            box-shadow: inset 3px 0 0 var(--brand);
        }
        .nav-item.active svg { color: #ff5a60; }
        .nav-item.soon { cursor: default; opacity: 0.75; }
        .soon-pill {
            margin-left: auto;
            font-size: 0.6rem; font-weight: 700;
            letter-spacing: 0.08em; text-transform: uppercase;
            color: #7dd3fc;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.25);
            padding: 0.1rem 0.45rem;
            border-radius: 999px;
        }
        .count-pill {
            margin-left: auto;
            font-size: 0.68rem; font-weight: 700;
            color: #fff; background: var(--brand);
            padding: 0.06rem 0.5rem; border-radius: 999px;
        }
        .sidebar-foot {
            margin-top: auto;
            padding: 0.85rem 0.6rem 0;
            border-top: 1px solid #1c2740;
            font-size: 0.72rem; color: #5b6b84; line-height: 1.5;
        }

        /* ============ Main column ============ */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .topbar {
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 0.7rem 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            position: sticky; top: 0; z-index: 10;
        }
        .menu-toggle {
            display: none;
            background: none; border: 1px solid var(--border);
            border-radius: 8px; width: 36px; height: 36px;
            cursor: pointer; color: var(--ink-2);
            font-size: 1.1rem; line-height: 1;
        }
        .page-title { font-family: var(--font-head); font-size: 1.02rem; font-weight: 600; letter-spacing: -0.01em; }
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 0.85rem; }
        .user-chip { display: flex; align-items: center; gap: 0.6rem; }
        .avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--brand); color: #fff;
            display: grid; place-items: center;
            font-weight: 700; font-size: 0.8rem;
            box-shadow: 0 2px 6px rgba(227, 27, 35, 0.3);
        }
        .user-meta { line-height: 1.15; }
        .user-meta .name { font-size: 0.85rem; font-weight: 600; }
        .user-meta .role { font-size: 0.72rem; color: var(--ink-3); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
        .content { padding: 1.6rem 1.8rem 2.5rem; max-width: 1920px; width: 100%; animation: fadeUp .32s ease both; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        /* ============ Components ============ */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: box-shadow .18s ease, transform .18s ease, border-color .18s ease;
        }
        a.card:hover {
            transform: translateY(-2px);
            border-color: var(--border-strong);
            box-shadow: var(--shadow-md);
        }
        .panel { padding: 1.4rem 1.45rem; }
        .panel h2 { font-family: var(--font-head); font-weight: 600; font-size: 1.05rem; margin-bottom: 0.2rem; letter-spacing: -0.01em; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .panel h2::before {
            content: ''; width: 4px; height: 1.05em; border-radius: 4px;
            background: var(--brand); flex-shrink: 0;
        }
        .panel .sub { font-size: 0.83rem; color: var(--ink-2); margin-bottom: 1.05rem; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem;
            border: none; border-radius: 9px;
            padding: 0.52rem 1.05rem;
            font-size: 0.88rem; font-weight: 600;
            cursor: pointer;
            transition: background .15s ease, box-shadow .15s ease, transform .06s ease, border-color .15s ease, color .15s ease;
            box-shadow: 0 1px 1.5px rgba(15, 23, 42, 0.06);
        }
        .btn:active { transform: translateY(1px); }
        .btn:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(227, 27, 35, 0.28); }
        .btn-primary { background: var(--brand); color: #fff; box-shadow: 0 1px 2px rgba(227, 27, 35, 0.32), 0 4px 12px rgba(227, 27, 35, 0.2); }
        .btn-primary:hover { background: var(--brand-hover); box-shadow: 0 2px 4px rgba(227, 27, 35, 0.38), 0 6px 16px rgba(227, 27, 35, 0.26); }
        .btn-block { width: 100%; padding: 0.7rem; font-size: 0.95rem; }
        .btn-ghost { background: var(--surface); border: 1px solid var(--border); color: var(--ink-2); box-shadow: 0 1px 1.5px rgba(15, 23, 42, 0.04); }
        .btn-ghost:hover { background: var(--surface-2); color: var(--ink); border-color: var(--border-strong); }
        .btn-success { background: #16a34a; color: #fff; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.35), 0 4px 12px rgba(22, 163, 74, 0.2); }
        .btn-success:hover { background: #15803d; }
        .btn-danger { background: #dc2626; color: #fff; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3), 0 4px 12px rgba(220, 38, 38, 0.18); }
        .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 0.36rem 0.75rem; font-size: 0.8rem; border-radius: 7px; }
        .badge {
            display: inline-flex; align-items: center; gap: 0.32rem;
            font-size: 0.66rem; font-weight: 700;
            letter-spacing: 0.05em;
            padding: 0.22rem 0.58rem;
            border-radius: 999px;
            white-space: nowrap;
            box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.05);
        }
        .badge .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: 0.85; }
        .alert-error, .alert-success {
            display: flex; align-items: flex-start; gap: 0.55rem;
            border-radius: 10px; padding: 0.78rem 1rem; font-size: 0.88rem; margin-bottom: 1rem; font-weight: 500;
            animation: fadeUp .25s ease both;
        }
        .alert-error {
            background: var(--danger-soft); border: 1px solid var(--danger-border); border-left: 4px solid #ef4444; color: var(--danger-ink);
        }
        .content > .alert-error::before { content: '⚠'; flex-shrink: 0; }
        .alert-success {
            background: var(--success-soft); border: 1px solid var(--success-border); border-left: 4px solid #22c55e; color: var(--success-ink);
        }
        .content > .alert-success::before { content: '✓'; font-weight: 800; flex-shrink: 0; }
        label { display: block; font-size: 0.83rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--ink-2); }
        input[type="email"], input[type="password"], input[type="text"], input[type="number"], input[type="date"], select, textarea {
            width: 100%;
            padding: 0.62rem 0.8rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.92rem;
            font-family: inherit;
            background: var(--surface);
            transition: border-color .12s, box-shadow .12s;
        }
        input:hover:not(:focus), select:hover:not(:focus), textarea:hover:not(:focus) { border-color: #aebccd; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        input::placeholder, textarea::placeholder { color: #9aa7b8; text-transform: none; }
        /* Everything typed on the floor is written in capitals, so type it that
           way everywhere. Email and password are excluded — capitalising those
           would break logins — and so is anything marked .no-caps. */
        input[type="text"], input[type="search"], textarea, select {
            text-transform: uppercase;
        }
        input[type="email"], input[type="password"], input.no-caps, textarea.no-caps {
            text-transform: none;
        }
        .field { margin-bottom: 1rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0 1rem; }
        .tbl-wrap { overflow-x: auto; border-radius: 10px; }
        table.tbl { width: 100%; border-collapse: collapse; font-size: 0.88rem; font-variant-numeric: tabular-nums; }
        .tbl th {
            text-align: left;
            font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.07em;
            color: var(--ink-3);
            background: #fafbfd;
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .tbl th:first-child { border-top-left-radius: 8px; }
        .tbl th:last-child { border-top-right-radius: 8px; }
        .tbl td { padding: 0.7rem 0.75rem; border-bottom: 1px solid #eef2f7; vertical-align: middle; }
        .tbl tr:last-child td { border-bottom: none; }
        .tbl tbody tr { transition: background .12s ease; }
        .tbl tbody tr:hover td { background: #fafcff; }
        .tbl tr.row-link { cursor: pointer; }
        .tbl tr.row-link:hover td { background: var(--accent-soft); }
        .progress { background: #e8edf4; border-radius: 999px; height: 7px; min-width: 90px; overflow: hidden; }
        .progress > div { background: linear-gradient(90deg, #22c55e, #16a34a); height: 100%; border-radius: 999px; transition: width .4s ease; }
        details.inline-form { display: inline-block; position: relative; }
        details.inline-form summary { list-style: none; cursor: pointer; }
        details.inline-form summary::-webkit-details-marker { display: none; }
        details.inline-form .pop {
            margin-top: 0.5rem;
            padding: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(15, 23, 42, .08), 0 16px 40px rgba(15, 23, 42, .14);
            min-width: 260px;
            animation: popIn .14s ease both;
        }
        @keyframes popIn { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
        h1 { font-family: var(--font-head); font-size: 1.5rem; letter-spacing: -0.025em; font-weight: 700; }
        p.muted { color: var(--ink-2); font-size: 0.9rem; }
        .page-head { margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap; }
        .page-head .grow { flex: 1; min-width: 200px; }
        .page-head h1 { margin-bottom: 0.3rem; }

        /* ============ Guest (login) ============ */
        .guest-main {
            min-height: 100vh;
            display: grid; place-items: center;
            padding: 1.5rem;
            background:
                radial-gradient(600px 300px at 15% 0%, rgba(37, 99, 235, 0.08), transparent 70%),
                radial-gradient(500px 300px at 90% 100%, rgba(56, 189, 248, 0.08), transparent 70%),
                var(--bg);
        }
        /* The login page keeps its original blue treatment — the red brand
           button and red focus are for the authenticated app only. */
        .guest-main .btn-primary { background: var(--accent); box-shadow: 0 1px 2px rgba(37, 99, 235, 0.35), 0 4px 12px rgba(37, 99, 235, 0.22); }
        .guest-main .btn-primary:hover { background: var(--accent-hover); box-shadow: 0 2px 4px rgba(37, 99, 235, 0.4), 0 6px 16px rgba(37, 99, 235, 0.28); }
        .guest-main .btn:focus-visible { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.28); }

        /* ============ Responsive ============ */
        @media (max-width: 900px) {
            .sidebar {
                position: fixed;
                left: 0; top: 0; bottom: 0;
                z-index: 40;
                transform: translateX(-100%);
                transition: transform .18s ease-out;
                height: 100%;
            }
            .sidebar.open { transform: translateX(0); box-shadow: 0 0 40px rgba(2, 6, 23, 0.5); }
            .menu-toggle { display: block; }
            .user-meta { display: none; }
            .scrim { position: fixed; inset: 0; background: rgba(2, 6, 23, 0.45); z-index: 30; display: none; }
            .scrim.show { display: block; }
        }

        /* Design previews are often transparent PNGs with light/white artwork
           (made for dark garments). A checkerboard backing makes them visible. */
        .design-preview {
            background-color: #d1d5db;
            background-image:
                linear-gradient(45deg, #9ca3af 25%, transparent 25%),
                linear-gradient(-45deg, #9ca3af 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #9ca3af 75%),
                linear-gradient(-45deg, transparent 75%, #9ca3af 75%);
            background-size: 18px 18px;
            background-position: 0 0, 0 9px, 9px -9px, -9px 0;
        }

        /* Flash messages fade themselves out — see the script at the end. */
        .flash { transition: opacity .4s ease, transform .4s ease; }
        .flash.is-gone { opacity: 0; transform: translateY(-14px); }
        /* Floating, centered toast stack near the top of the viewport. */
        .flash-wrap {
            position: fixed; top: 1rem; left: 50%; transform: translateX(-50%);
            z-index: 1000; width: min(560px, calc(100vw - 2rem));
            display: flex; flex-direction: column; gap: 0.6rem;
            pointer-events: none;
        }
        .flash-wrap .flash {
            pointer-events: auto; margin-bottom: 0;
            box-shadow: 0 12px 34px rgba(15, 23, 42, .18);
            animation: flashIn .3s ease both;
        }
        @keyframes flashIn { from { opacity: 0; transform: translateY(-14px); } to { opacity: 1; transform: none; } }
        .flash-wrap .alert-success::before { content: '✓'; font-weight: 800; flex-shrink: 0; }
        .flash-wrap .alert-error::before { content: '⚠'; flex-shrink: 0; }

        /* Nothing marked .no-print ever reaches paper, on any page. */
        @media print {
            .no-print { display: none !important; }
        }

        /* Respect users who ask the OS to reduce motion (accessibility). */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.001ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* =====================================================================
           MODERN REFRESH  —  a colorful, more dimensional layer over the base
           theme. Scoped to the authenticated app (.main / .shell) so the LOGIN
           page (.guest-main) is deliberately left exactly as it was.
           ===================================================================== */

        /* Bold, colorful ambient wash so the white cards read as raised. */
        .main {
            /* Pinned to the viewport (background-attachment: fixed) so the colour
               glows sit at the screen corners on any page height — no plain white
               band at the top or bottom on short pages. */
            background:
                radial-gradient(820px 520px at 100% 0%, rgba(37, 99, 235, 0.18), transparent 55%),
                radial-gradient(760px 520px at 0% 4%, rgba(227, 27, 35, 0.14), transparent 55%),
                radial-gradient(720px 520px at 8% 100%, rgba(139, 92, 246, 0.16), transparent 55%),
                radial-gradient(1000px 560px at 95% 100%, rgba(56, 189, 248, 0.16), transparent 55%),
                linear-gradient(180deg, #eef2f9 0%, #e9eef7 100%);
            background-attachment: fixed;
        }

        /* Keep the content centered so wide monitors stay balanced, not left-heavy. */
        .content { margin-inline: auto; }

        /* Livelier brand + avatar marks. */
        .brand-mark { background: linear-gradient(135deg, #ff5860, var(--brand) 52%, #a81217); }
        .avatar { background: linear-gradient(135deg, #ff5860, var(--brand)); }

        /* Gradient primary/action buttons (login keeps its blue via .guest-main). */
        .btn-primary { background: linear-gradient(135deg, #f5373f, var(--brand)); }
        .btn-primary:hover { background: linear-gradient(135deg, var(--brand), var(--brand-hover)); }
        .btn-success { background: linear-gradient(135deg, #22c55e, #15a03f); }
        .btn-success:hover { background: linear-gradient(135deg, #16a34a, #147a37); }
        .btn-danger { background: linear-gradient(135deg, #f5474b, #dc2626); }
        .btn-danger:hover { background: linear-gradient(135deg, #dc2626, #b91c1c); }

        /* Panel heading accent bar as a gradient tick. */
        .panel h2::before {
            background: linear-gradient(180deg, #ff6b71, var(--brand));
            width: 5px; box-shadow: 0 1px 4px rgba(227, 27, 35, 0.35);
        }

        /* Cards: rounder, crisp white against the tinted body, real elevation,
           and a hairline colored top accent so panels feel designed, not plain. */
        .card { border-radius: 18px; background: #ffffff; border-color: #e7ecf4; }
        .panel {
            position: relative;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 14px 34px rgba(15, 23, 42, .06);
        }
        .panel > h2:first-child { padding-top: 0.15rem; }
        /* (Removed the colored left spine on panels — kept the cards clean.) */
        .panel:hover { box-shadow: 0 2px 6px rgba(15, 23, 42, .05), 0 20px 44px rgba(15, 23, 42, .10); }

        /* Give section headings a small gradient icon-badge feel via the tick. */
        .panel h2::before { width: 6px; height: 1.15em; border-radius: 4px; }

        /* Section titles get a stronger presence. */
        .page-head h1, h1 { color: #141d2b; }
        p.muted { color: var(--ink-2); }

        /* Tables feel more alive: tinted gradient header, zebra rows, blue hover. */
        .tbl th {
            background: linear-gradient(180deg, #f2f7fd, #e9f1fb);
            color: #45566d; border-bottom: 1px solid #dbe4f0;
        }
        .tbl tbody tr:nth-child(even) td { background: #fbfcfe; }
        .tbl tbody tr:hover td { background: var(--accent-soft); }

        /* Inputs: soft rounded, clearer on the lighter panels. */
        input[type="email"], input[type="password"], input[type="text"], input[type="number"],
        input[type="date"], input[type="search"], select, textarea { border-radius: 9px; }
        /* Checkboxes / radios in brand red for a pop of colour. */
        input[type="checkbox"], input[type="radio"] { accent-color: var(--brand); }
        /* A slightly bigger, bluer focus ring on every field. */
        input:focus, select:focus, textarea:focus { box-shadow: 0 0 0 3px rgba(37, 99, 235, .18); }
        /* Buttons get a faint top sheen so they look a touch glossy. */
        .btn-primary, .btn-success, .btn-danger { background-blend-mode: normal; position: relative; }
        .btn-primary::before, .btn-success::before, .btn-danger::before {
            content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
            background: linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,0) 55%);
        }
        /* Login page stays exactly as it was — no red checkbox, no button sheen. */
        .guest-main input[type="checkbox"], .guest-main input[type="radio"] { accent-color: var(--accent); }
        .guest-main .btn-primary::before { content: none; }

        /* Sidebar: deep navy with a colored glow up top and a hairline edge. */
        .sidebar {
            background:
                radial-gradient(460px 280px at 50% -12%, rgba(227, 27, 35, 0.18), transparent 62%),
                radial-gradient(420px 300px at 50% 112%, rgba(37, 99, 235, 0.16), transparent 60%),
                linear-gradient(180deg, #0e1a2e 0%, #0b1424 55%, #0c111d 100%);
            border-right: 1px solid rgba(148, 163, 184, 0.08);
        }
        .nav-label { color: #6d80a4; }
        /* Section labels get a tiny colored bullet. */
        .nav-label::before {
            content: ''; display: inline-block; width: 6px; height: 6px; margin-right: 6px;
            border-radius: 50%; background: linear-gradient(135deg, #ff5860, var(--accent));
            vertical-align: middle;
        }

        /* Sidebar active item gets a brighter red gradient sheen + glow. */
        .nav-item.active {
            background: linear-gradient(90deg, rgba(227, 27, 35, 0.30), rgba(37, 99, 235, 0.12) 90%);
            box-shadow: inset 3px 0 0 var(--brand), 0 4px 14px rgba(227, 27, 35, 0.18);
        }
        .count-pill { box-shadow: 0 2px 8px rgba(227, 27, 35, 0.4); }

        /* Progress bars a bit bolder. */
        .progress { height: 8px; }

        /* ---- Extra polish ---- */

        /* Page-header "hero" — the calendar / raw-materials look, applied to every
           page's .page-head: a rounded gradient card with a soft glow corner. */
        .page-head {
            position: relative;
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: 20px;
            padding: 1.4rem 1.6rem;
            background:
                radial-gradient(circle at 0% 0%, rgba(37, 99, 235, 0.24), transparent 42%),
                radial-gradient(circle at 100% 130%, rgba(168, 85, 247, 0.22), transparent 46%),
                radial-gradient(circle at 62% -30%, rgba(227, 27, 35, 0.12), transparent 40%),
                linear-gradient(135deg, #ecf3ff 0%, #f4eeff 55%, #ffeff4 100%);
            box-shadow: 0 16px 38px rgba(37, 99, 235, 0.10);
        }
        /* Page titles: solid dark ink with a short gradient underline accent. */
        .page-head h1 { position: relative; padding-bottom: 0.6rem; color: #16203a; }
        .page-head h1::after {
            content: ''; position: absolute; left: 0; bottom: 0;
            width: 64px; height: 3px; border-radius: 3px;
            background: linear-gradient(90deg, var(--brand), #a855f7 55%, var(--accent));
        }

        /* Section headings sit in a soft colored gradient band, so every card has
           a pop of colour at the top (matching the calendar / raw-materials feel). */
        .panel h2 {
            color: #1a2438;
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.10), rgba(168, 85, 247, 0.06) 60%, rgba(227, 27, 35, 0.04));
            border-radius: 10px;
            padding: 0.45rem 0.7rem;
            margin-bottom: 0.9rem;
        }
        .panel h2::before { margin-left: -0.15rem; }

        /* Vivid progress bars everywhere. */
        .progress > div {
            background: linear-gradient(90deg, var(--brand), #a855f7 50%, var(--accent)) !important;
        }

        /* Tables: stronger blue→violet header, colorful hover. */
        .tbl th {
            background: linear-gradient(120deg, #eaf1fd, #efeafe);
            color: #4b3d78;
        }
        .tbl tbody tr:hover td { background: color-mix(in srgb, var(--accent) 8%, #ffffff); }

        /* Nav items tint on hover. */
        a.nav-item:hover { background: linear-gradient(90deg, rgba(227,27,35,0.16), rgba(37,99,235,0.10)); color: #fff; }

        /* =====================================================================
           FORM STEPS  —  opt-in polished multi-section forms. Add class
           "form-steps" to a <form> and each section card becomes a numbered,
           elevated step with a divider under its heading.
           ===================================================================== */
        .form-steps { counter-reset: sec; }
        .form-steps .card.panel {
            counter-increment: sec;
            padding: 1.5rem 1.6rem 1.5rem 1.75rem;
            box-shadow: 0 2px 4px rgba(19, 30, 51, .04), 0 16px 40px rgba(19, 30, 51, .09);
        }
        /* Numbered gradient chip replaces the plain heading tick. */
        .form-steps .card.panel > h2:first-child {
            padding-bottom: 0.7rem; margin-bottom: 0.9rem;
            border-bottom: 1px solid transparent;
            border-image: linear-gradient(90deg, rgba(227,27,35,.35), rgba(124,58,237,.28) 55%, rgba(37,99,235,.12)) 1;
        }
        .form-steps .card.panel > h2:first-child::before {
            content: counter(sec);
            width: 30px; height: 30px; border-radius: 10px; flex: 0 0 30px;
            display: inline-grid; place-items: center;
            background: linear-gradient(135deg, var(--brand), #7c3aed 55%, var(--accent));
            color: #fff; -webkit-text-fill-color: #fff;
            font-family: var(--font-head); font-size: 0.9rem; font-weight: 800;
            box-shadow: 0 4px 12px rgba(124, 58, 237, .38);
        }
        .form-steps .panel .sub { color: #5a6b84; margin-top: -0.2rem; }
        .form-steps label { color: #46566e; }
        /* The whole field row gets a faint highlight while focused. */
        .form-steps .field:focus-within > label { color: var(--accent); }
        /* Bigger, glowier submit row. */
        .form-steps + div .btn-primary,
        .form-steps .btn-primary[type="submit"],
        .form-steps button.btn-primary { padding: 0.7rem 1.6rem; font-size: 0.95rem; }

        /* Topbar picks up a faint colored tint. */
        .topbar {
            background: linear-gradient(90deg, rgba(255,255,255,0.9), rgba(238,244,255,0.86) 55%, rgba(253,236,236,0.86));
        }

        /* Panels pick up a faint brand-tinted glow on hover. */
        .panel:hover {
            border-color: #dfe4ee;
            box-shadow: 0 2px 6px rgba(19, 30, 51, .06), 0 18px 44px rgba(19, 30, 51, .10),
                        0 0 0 1px rgba(37, 99, 235, .04);
        }

        /* Buttons: rounder, with a colored glow that blooms on hover. */
        .btn { border-radius: 10px; }
        .btn-primary:hover { box-shadow: 0 3px 8px rgba(227, 27, 35, .38), 0 10px 24px rgba(227, 27, 35, .3); }
        .btn-success:hover { box-shadow: 0 3px 8px rgba(22, 163, 74, .35), 0 10px 24px rgba(22, 163, 74, .26); }
        .btn-danger:hover  { box-shadow: 0 3px 8px rgba(220, 38, 38, .32), 0 10px 24px rgba(220, 38, 38, .24); }
        .btn-ghost:hover   { border-color: var(--accent); color: var(--accent); }

        /* Brand + avatar get a soft ring so they feel like a badge. */
        .brand-mark { box-shadow: 0 3px 10px rgba(227, 27, 35, .4), 0 0 0 3px rgba(227, 27, 35, .12); }
        .avatar { box-shadow: 0 3px 8px rgba(227, 27, 35, .32), 0 0 0 3px rgba(227, 27, 35, .1); }

        /* Topbar: hairline gradient underline for a crisper edge. */
        .topbar { border-bottom: 1px solid transparent;
            border-image: linear-gradient(90deg, rgba(227,27,35,.35), rgba(37,99,235,.35)) 1; }

        /* Sidebar nav: hover tints the icon toward the brand. */
        a.nav-item:hover svg { color: #ff8a8f; }

        /* Colored, chunkier scrollbar thumb (brand → accent). */
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #c3ccd9, #aab6c6);
        }
        ::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, #ff8a8f, #7ea6f5); }

        /* Table container reads as one crisp component. */
        .tbl-wrap { border: 1px solid var(--border); background: #fff; }

        /* ---- Bigger monitors: scale the shell up so it uses the space ---- */
        @media (min-width: 1600px) {
            body { font-size: 15.5px; }
            .sidebar { width: 274px; }
            .content { max-width: 1720px; padding: 2rem 2.6rem 3rem; }
        }
        @media (min-width: 1920px) {
            body { font-size: 16px; }
            .sidebar { width: 300px; }
            .content { max-width: 2040px; padding: 2.2rem 3rem 3.4rem; }
            .topbar { padding: 0.85rem 2rem; }
        }
        @media (min-width: 2560px) {
            body { font-size: 17px; }
            .content { max-width: 2440px; padding: 2.4rem 3.4rem 4rem; }
        }
    </style>
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
                @endif
            </nav>

            @if (auth()->user()->isLeader())
            <nav class="nav-section">
                <div class="nav-label">Management</div>
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