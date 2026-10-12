<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>System Usage Report</title>
    <style>
        @page { margin: 34px 38px 46px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #374151; }
        .header { width: 100%; border-bottom: 1.5px solid #2E3A8C; padding-bottom: 12px; margin-bottom: 18px; }
        .header td { vertical-align: middle; }
        .logo { width: 46px; height: 46px; }
        .org { font-size: 9px; color: #6b7280; letter-spacing: .4px; text-transform: uppercase; }
        .title { font-size: 18px; font-weight: bold; color: #161C52; margin-top: 2px; }
        .period { text-align: right; font-size: 9px; color: #6b7280; line-height: 1.5; }
        .period strong { font-size: 11px; color: #161C52; }
        .eyebrow { font-size: 8px; font-weight: bold; letter-spacing: .9px; text-transform: uppercase; color: #6b7280; }
        table.summary { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.summary > tbody > tr > td { vertical-align: top; }
        .rate { font-size: 38px; font-weight: bold; color: #161C52; line-height: 1; margin-top: 6px; }
        .rate-text { font-size: 11px; font-weight: bold; color: #161C52; margin-top: 4px; }
        table.bar { width: 100%; border-collapse: separate; border-spacing: 1.5px 0; margin: 10px 0 6px -1.5px; }
        table.bar td { height: 5px; padding: 0; }
        .tone-using { background: #12A27B; }
        .tone-signed_in { background: #F5B800; }
        .tone-none { background: #E11D48; }
        .tone-deactivated { background: #94A3B8; }
        .tone-empty { background: #e5e7eb; }
        .dot { display: inline-block; width: 6px; height: 6px; margin-right: 3px; }
        .legend { font-size: 8.5px; color: #4b5563; }
        .legend span.item { margin-right: 12px; }
        .legend strong { color: #161C52; }
        .followup { margin-top: 8px; padding-top: 7px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #4b5563; }
        .followup strong { color: #161C52; }
        table.figures { width: 100%; border-collapse: collapse; }
        table.figures td { width: 33.3%; padding: 2px 0 12px 16px; border-left: 1px solid #e5e7eb; vertical-align: top; }
        .figure-value { font-size: 17px; font-weight: bold; color: #161C52; margin-top: 3px; }
        .figure-note { font-size: 8px; color: #6b7280; margin-top: 1px; }
        h2 { font-size: 11px; color: #161C52; margin: 0 0 7px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; padding: 6px 8px; font-size: 7.5px; font-weight: bold; letter-spacing: .8px; text-transform: uppercase; color: #6b7280; background: #f5f6fb; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
        table.data td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; line-height: 1.4; }
        .name { font-weight: bold; color: #161C52; }
        .sub { color: #6b7280; font-size: 8.5px; }
        .status { font-weight: bold; color: #161C52; }
        table.days { border-collapse: separate; border-spacing: 1.5px; margin: 1px 0 3px -1.5px; }
        table.days td { width: 6px; height: 6px; padding: 0; border: 0; background: #e5e7eb; }
        table.days td.on { background: #2E3A8C; }
        .notes { margin-top: 12px; color: #6b7280; font-size: 8px; line-height: 1.5; }
        .footer { position: fixed; bottom: -28px; left: 0; right: 0; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $rate = $summary['accounts'] === 0 ? 0 : (int) round($summary['using'] / $summary['accounts'] * 100);
        $followUp = [...$groups['none'], ...$groups['signed_in']];
        $figures = [
            ['Sign-ins', $summary['sign_ins'], 'times staff logged in'],
            ['Members added', $summary['members_added'], 'typed in one at a time'],
            ['Members edited', $summary['members_edited'], 'corrected one at a time'],
            ['Excel files uploaded', $summary['files_imported'], number_format($summary['records_imported']).' member records'],
            ['Text messages sent', $summary['sms_sent'], 'reminders to members'],
            ['Reports downloaded', $summary['reports_exported'], 'Excel, CSV or PDF'],
        ];
    @endphp

    <table class="header">
        <tr>
            @if ($logo)
                <td style="width: 56px"><img class="logo" src="{{ $logo }}" alt="COLISAP logo"></td>
            @endif
            <td>
                <div class="org">Barbaza Multi-Purpose Cooperative · COLISAP Monitoring System</div>
                <div class="title">System Usage Report</div>
            </td>
            <td class="period">
                <strong>{{ $period_label }}</strong><br>
                {{ $range_label }}<br>
                Generated {{ $generatedAt->format('M j, Y g:i A') }}
            </td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td style="width: 40%; padding-right: 28px">
                <div class="eyebrow">Staff adoption</div>
                <div class="rate">{{ $rate }}%</div>
                <div class="rate-text">{{ $summary['using'] }} of {{ $summary['accounts'] }} staff accounts used the system</div>

                <table class="bar">
                    <tr>
                        @forelse (array_filter($groups) as $status => $names)
                            <td class="tone-{{ $status }}" style="width: {{ round(count($names) / max(1, $summary['accounts']) * 100, 2) }}%"></td>
                        @empty
                            <td class="tone-empty"></td>
                        @endforelse
                    </tr>
                </table>

                <div class="legend">
                    @foreach ($groups as $status => $names)
                        <span class="item"><span class="dot tone-{{ $status }}"></span> {{ $statuses[$status]['label'] }} <strong>{{ count($names) }}</strong></span>@unless ($loop->last)&nbsp;&nbsp;&nbsp;&nbsp;@endunless
                    @endforeach
                </div>

                <div class="followup">Needs follow-up: <strong>{{ $followUp === [] ? 'No one' : implode(', ', $followUp) }}</strong></div>
            </td>
            <td>
                <table class="figures">
                    @foreach (array_chunk($figures, 3) as $chunk)
                        <tr>
                            @foreach ($chunk as [$label, $value, $note])
                                <td>
                                    <div class="eyebrow">{{ $label }}</div>
                                    <div class="figure-value">{{ number_format($value) }}</div>
                                    <div class="figure-note">{{ $note }}</div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <h2>Staff activity</h2>
    <table class="data">
        <thead>
            <tr>
                <th style="width: 20%">Staff</th>
                <th style="width: 16%">Status</th>
                <th style="width: 19%">Days used</th>
                <th>What they did</th>
                <th style="width: 12%">Last used</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($staff as $row)
                <tr>
                    <td><span class="name">{{ $row['name'] }}</span><br><span class="sub">{{ $row['role'] }} · {{ $row['branch'] }}</span></td>
                    <td class="status"><span class="dot tone-{{ $row['status'] }}"></span> {{ $statuses[$row['status']]['label'] }}</td>
                    <td>
                        @if ($days !== [])
                            <table class="days">
                                <tr>
                                    @foreach ($days as $day)
                                        <td @class(['on' => in_array($day->toDateString(), $row['active_days'], true)])></td>
                                    @endforeach
                                </tr>
                            </table>
                        @endif
                        <span class="sub">{{ $row['days_active'] }} of {{ $period_days }} {{ \Illuminate\Support\Str::plural('day', $period_days) }}</span>
                    </td>
                    <td>{{ $row['did'] }}</td>
                    <td>{{ $row['last_active']?->format('M j, Y') ?? 'Never' }}<br><span class="sub">{{ $row['last_active']?->format('g:i A') }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5">There are no staff accounts to report on.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="notes">
        <strong>Using the system</strong> — did real work: added, edited, uploaded, sent or downloaded something.
        <strong>Signed in only</strong> — logged in, but did not do any work.
        <strong>Not using the system</strong> — no sign-in and no work recorded.
        @if ($days !== [])
            Each small square is one day of the period; a dark square means the account was used that day.
        @endif
        All figures are counted automatically by the system and cannot be edited.
        @if ($tracking_since)
            Sign-ins are counted from {{ $tracking_since->format('M j, Y') }}, when sign-in tracking was switched on.
        @else
            Sign-in tracking has just been switched on, so no sign-ins are recorded yet.
        @endif
    </div>

    <div class="footer">COLISAP Monitoring System · Barbaza Multi-Purpose Cooperative · System Usage Report</div>
</body>
</html>
