<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28px 28px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #3B3FA6; padding-bottom: 8px; margin-bottom: 10px; }
        .header td { vertical-align: middle; }
        .logo { width: 52px; height: 52px; }
        .org { font-size: 13px; font-weight: bold; color: #1e2a5a; }
        .title { font-size: 12px; font-weight: bold; color: #3B3FA6; margin-top: 2px; }
        .meta { color: #6b7280; font-size: 8.5px; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #3B3FA6; color: #fff; text-align: left; padding: 4px 5px; font-size: 8.5px; }
        table.data td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .num { text-align: right; }
        .note { margin-top: 8px; color: #b45309; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            @if ($logo)
                <td style="width: 60px"><img class="logo" src="{{ $logo }}" alt="COLISAP logo"></td>
            @endif
            <td>
                <div class="org">Barbaza Multi-Purpose Cooperative — COLISAP</div>
                <div class="title">{{ $title }}</div>
                <div class="meta">
                    {{ number_format($total) }} record(s) · Generated {{ $generatedAt->format('M j, Y g:i A') }}
                    @foreach ($filters as $filter) · {{ $filter }} @endforeach
                </div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td @class(['num' => is_float($value) || is_int($value)])>
                            {{ is_float($value) ? number_format($value, 2) : $value }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">No records match the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        <p class="note">Only the first {{ number_format(count($rows)) }} of {{ number_format($total) }} records are shown in PDF. Export to Excel for the complete list.</p>
    @endif

    <div class="footer">COLISAP Management System · Barbaza Multi-Purpose Cooperative</div>
</body>
</html>
