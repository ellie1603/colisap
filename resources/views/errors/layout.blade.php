{{-- Branded error page. Self-contained (no database, session or build assets), so it still renders when those fail. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · COLISAP — Barbaza MPC</title>
    <link rel="icon" href="{{ asset('images/favicon.png') }}">
    <style>
        :root { --bg: #f1f3f9; --card: #ffffff; --text: #141a3a; --muted: #4a5476; --ring: #cbd1e2; --indigo: #2E3A8C; --code: #B8520F; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b0f28; --card: #141a3a; --text: #ffffff; --muted: #949dba; --ring: rgba(255, 255, 255, .12); --indigo: #3F4FB8; --code: #E8691C; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text);
            font-family: "Segoe UI", system-ui, -apple-system, sans-serif; }
        .card { width: 100%; max-width: 30rem; background: var(--card); border-radius: 18px; padding: 36px 32px 32px; position: relative; overflow: hidden;
            box-shadow: 0 24px 60px -32px rgba(46, 58, 140, .55), 0 0 0 1px var(--ring); }
        .card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px;
            background: #2E3A8C; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
        .brand img { width: 40px; height: 40px; border-radius: 10px; background: #fff; padding: 4px; box-shadow: 0 0 0 1px var(--ring); }
        .code { font-size: 13px; font-weight: 700; letter-spacing: .12em; color: var(--code); }
        h1 { margin: 6px 0 10px; font-size: 26px; line-height: 1.2; letter-spacing: -.02em; }
        p { margin: 0; font-size: 15px; line-height: 1.6; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 26px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 18px; border-radius: 10px; font: inherit; font-size: 14px;
            font-weight: 600; text-decoration: none; cursor: pointer; border: 0; }
        .btn-primary { background: var(--indigo); color: #fff; }
        .btn-secondary { background: transparent; color: var(--text); box-shadow: inset 0 0 0 1px var(--ring); }
        .btn:focus-visible { outline: 2px solid #FFD200; outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <img src="{{ asset('images/logo-192.png') }}" alt="">
            <span>COLISAP · Barbaza MPC</span>
        </div>

        <div class="code">ERROR @yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>

        <div class="actions">
            @section('actions')
                <a class="btn btn-primary" href="{{ url('/admin') }}">Go to the dashboard</a>
                <button class="btn btn-secondary" type="button" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/admin') }}')">Go back</button>
            @show
        </div>
    </main>
</body>
</html>
