@extends('reports.layout')

@section('report-title', 'Claims Processed: '.$from->format('M j, Y').' to '.$until->format('M j, Y'))

@section('content')
<table>
    <thead>
        <tr>
            <th>Claim No.</th>
            <th>Member</th>
            <th>Claimant</th>
            <th>Date Filed</th>
            <th>Status</th>
            <th>Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($claims as $claim)
            <tr>
                <td>{{ $claim->claim_no }}</td>
                <td>{{ $claim->member?->fullName() }}</td>
                <td>{{ $claim->beneficiary?->full_name }}</td>
                <td>{{ $claim->date_filed->format('M j, Y') }}</td>
                <td>{{ \App\Models\Claim::STATUSES[$claim->status] ?? $claim->status }}</td>
                <td>₱{{ number_format($claim->approved_amount ?? $claim->claim_amount ?? 0, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>Total claims: {{ $claims->count() }} — Paid: {{ $claims->where('status', 'paid')->count() }}</p>
@endsection
