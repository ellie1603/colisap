<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $claim->claim_no }}</title>
    <style>
        @page { margin: 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #3B3FA6; padding-bottom: 8px; margin-bottom: 14px; }
        .logo { width: 56px; height: 56px; }
        .org { font-size: 14px; font-weight: bold; color: #1e2a5a; }
        .title { font-size: 13px; font-weight: bold; color: #3B3FA6; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.grid td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        table.grid td.label { color: #6b7280; width: 30%; }
        h2 { font-size: 11.5px; color: #3B3FA6; margin: 14px 0 6px; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #3B3FA6; color: #fff; text-align: left; padding: 4px 6px; }
        table.list td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; }
        .total td { font-weight: bold; border-top: 2px solid #1f2937; }
        .sign { margin-top: 40px; width: 100%; }
        .sign td { width: 33%; text-align: center; padding-top: 26px; }
        .line { border-top: 1px solid #1f2937; margin: 0 14px; padding-top: 4px; font-size: 9.5px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            @if ($logo)<td style="width: 64px"><img class="logo" src="{{ $logo }}" alt="COLISAP logo"></td>@endif
            <td>
                <div class="org">Barbaza Multi-Purpose Cooperative — COLISAP</div>
                <div class="title">Mortuary Claim {{ $claim->claim_no }} · {{ \App\Models\Claim::STATUSES[$claim->status] ?? $claim->status }}</div>
            </td>
        </tr>
    </table>

    <table class="grid">
        <tr><td class="label">Deceased member</td><td>{{ $claim->member?->fullName() }} ({{ $claim->member?->account_no }})</td></tr>
        <tr><td class="label">Branch</td><td>{{ $claim->member?->branch?->name }}</td></tr>
        <tr><td class="label">Category</td><td>{{ \App\Models\Member::categoryLabel($claim->category) }}</td></tr>
        <tr><td class="label">Date of death</td><td>{{ $claim->date_of_death?->format('F j, Y') }}</td></tr>
        <tr><td class="label">Date filed</td><td>{{ $claim->date_filed?->format('F j, Y') }}</td></tr>
        <tr><td class="label">Claimant</td><td>{{ $claim->beneficiary?->full_name }}</td></tr>
        <tr><td class="label">Documents verified</td><td>Death certificate: {{ $claim->death_certificate_verified ? 'Yes' : 'No' }} · COLISAP application/certificate: {{ $claim->certificate_verified ? 'Yes' : 'No' }}</td></tr>
    </table>

    <h2>Benefit computation</h2>
    <table class="list">
        <tr><td>Gross mortuary benefit</td><td class="num">{{ number_format((float) $claim->gross_benefit, 2) }}</td></tr>
        @foreach ($claim->deductions as $deduction)
            <tr><td>Less: {{ \App\Models\ClaimDeduction::TYPES[$deduction->type] ?? $deduction->type }} — {{ $deduction->description }}</td><td class="num">({{ number_format((float) $deduction->amount, 2) }})</td></tr>
        @endforeach
        <tr class="total"><td>Net benefit</td><td class="num">PHP {{ number_format((float) $claim->net_benefit, 2) }}</td></tr>
    </table>

    @if ($claim->payouts->isNotEmpty())
        <h2>Beneficiary settlement</h2>
        <table class="list">
            <thead><tr><th>Beneficiary</th><th>Share</th><th class="num">Amount</th><th>Released</th></tr></thead>
            <tbody>
            @foreach ($claim->payouts as $payout)
                <tr>
                    <td>{{ $payout->beneficiary_name }}</td>
                    <td>{{ rtrim(rtrim(number_format((float) $payout->share_percentage, 2), '0'), '.') }}%</td>
                    <td class="num">{{ number_format((float) $payout->amount, 2) }}</td>
                    <td>{{ $payout->released_at?->format('M j, Y') ?? 'Pending' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td><div class="line">Processed by</div></td>
            <td><div class="line">Approved by{{ $claim->approver ? ': '.$claim->approver->name : '' }}</div></td>
            <td><div class="line">Received by (beneficiary)</div></td>
        </tr>
    </table>
</body>
</html>
