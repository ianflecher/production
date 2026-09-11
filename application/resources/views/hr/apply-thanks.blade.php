<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank you — Imprint Customs</title>
    @include('partials.fonts')
    <style>
        :root {
            --font-body: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            --font-head: 'Space Grotesk', 'Inter', system-ui, sans-serif;
            --bg: #F4F6F9; --surface: #fff; --border: #E5E9F0; --ink: #17202E;
            --ink-2: #566172; --ink-3: #94A0AE; --brand: #E31B23;
            --success: #15803d; --success-soft: #f0fdf4;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-body); background: var(--bg); color: var(--ink);
            font-size: 16px; line-height: 1.5; -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 560px; margin: 0 auto; padding: 3rem 1.1rem; }
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 14px; padding: 2rem 1.5rem; text-align: center;
        }
        .tick {
            width: 54px; height: 54px; border-radius: 50%; margin: 0 auto 1rem;
            background: var(--success-soft); color: var(--success);
            display: grid; place-items: center; font-size: 1.6rem; font-weight: 700;
        }
        h1 { font-family: var(--font-head); font-size: 1.35rem; letter-spacing: -0.02em; margin-bottom: 0.5rem; }
        p { color: var(--ink-2); font-size: 0.95rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="tick">✓</div>
        <h1>Thank you — we have your application</h1>
        <p>
            Somebody from Imprint Customs will call you on the number you gave us.
            You can close this page now.
        </p>
    </div>
</div>
</body>
</html>
