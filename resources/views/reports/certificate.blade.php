<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>COLISAP Certificate — {{ $member->account_no }}</title>
    <style>
        @page { margin: 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        .frame { border: 3px double #3B3FA6; padding: 28px 32px; }
        .center { text-align: center; }
        .logo { width: 90px; height: 90px; }
        .org { font-size: 15px; font-weight: bold; color: #1e2a5a; margin-top: 6px; }
        .program { font-size: 12px; color: #6b7280; }
        h1 { font-size: 22px; color: #3B3FA6; letter-spacing: 2px; margin: 18px 0 6px; }
        .name { font-size: 18px; font-weight: bold; margin: 10px 0 2px; }
        table.details { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.details td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
        table.details td.label { color: #6b7280; width: 38%; }
        h2 { font-size: 12px; color: #3B3FA6; margin: 18px 0 6px; }
        table.benef { width: 100%; border-collapse: collapse; }
        table.benef th { background: #3B3FA6; color: #fff; text-align: left; padding: 4px 6px; }
        table.benef td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        .sign { margin-top: 48px; width: 100%; }
        .sign td { width: 50%; text-align: center; padding-top: 30px; }
        .line { border-top: 1px solid #1f2937; margin: 0 30px; padding-top: 4px; }
        .small { font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
<div class="frame">
    <div class="center">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="COLISAP logo">@endif
        <div class="org">Barbaza Multi-Purpose Cooperative</div>
        <div class="program">Coop Life Savings Program (COLISAP)</div>
        <h1>CERTIFICATE OF PARTICIPATION</h1>
        <div>This certifies that</div>
        <div class="name">{{ $member->fullName() }}</div>
        <div>is a participant of the Coop Life Savings Program under the following terms:</div>
    </div>

    <table class="details">
        <tr><td class="label">Acct. Number</td><td>{{ $member->account_no }}</td></tr>
        <tr><td class="label">Branch</td><td>{{ $member->branch?->name }}</td></tr>
        <tr><td class="label">Benefit category</td><td>{{ \App\Models\Member::categoryLabel($member->category) }} — PHP {{ number_format($member->benefitAmount(), 2) }} mortuary benefit</td></tr>
        <tr><td class="label">Application approved</td><td>{{ $member->approval_date?->format('F j, Y') }}</td></tr>
        <tr><td class="label">Effective on</td><td>{{ $member->effectivityDate()?->format('F j, Y') }} (after the {{ app(\App\Services\Policy\PolicySettings::class)->int('effectivity_days') }}-day waiting period)</td></tr>
        <tr><td class="label">Maintaining balance</td><td>PHP {{ number_format($member->minimumBalance(), 2) }}</td></tr>
    </table>

    <h2>Designated beneficiaries</h2>
    <table class="benef">
        <thead><tr><th>Name</th><th>Relationship</th><th>Share</th></tr></thead>
        <tbody>
        @forelse ($member->activeBeneficiaries as $beneficiary)
            <tr><td>{{ $beneficiary->full_name }}</td><td>{{ $beneficiary->relationship }}</td><td>{{ rtrim(rtrim(number_format((float) $beneficiary->share_percentage, 2), '0'), '.') }}%</td></tr>
        @empty
            <tr><td colspan="3">No beneficiary designated.</td></tr>
        @endforelse
        </tbody>
    </table>

    <table class="sign">
        <tr>
            <td><div class="line">Participant</div></td>
            <td><div class="line">COLISAP Officer-in-Charge</div></td>
        </tr>
    </table>

    <p class="small center">Issued {{ now()->format('F j, Y') }}. Benefits are subject to the COLISAP General Provisions, including the maintaining balance and replenishment rules.</p>
</div>
</body>
</html>
