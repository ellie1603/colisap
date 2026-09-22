<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #17332C; }
        h1 { font-size: 16px; margin-bottom: 2px; color: #17332C; }
        .subtitle { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #2C5A4C; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
        td { padding: 5px 8px; border-bottom: 1px solid #E7E2D6; font-size: 10px; }
        tfoot td { font-weight: bold; border-top: 2px solid #2C5A4C; }
        .badge { padding: 2px 6px; border-radius: 3px; font-size: 9px; color: #fff; }
        .footer { margin-top: 20px; font-size: 9px; color: #888; }
    </style>
</head>
<body>
    <h1>Barbaza Multi-Purpose Cooperative — COLISAP</h1>
    <div class="subtitle">@yield('report-title')</div>

    @yield('content')

    <div class="footer">Generated {{ $generatedAt->format('F j, Y g:i A') }}</div>
</body>
</html>
