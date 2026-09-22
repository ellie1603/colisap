@extends('reports.layout')

@section('report-title', 'Eligibility Status Report — Rule: '.($rule->name ?? 'None active'))

@section('content')
<table>
    <thead>
        <tr>
            <th>Member No.</th>
            <th>Name</th>
            <th>Membership Months</th>
            <th>Contribution Balance</th>
            <th>Eligible</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($members as $member)
            <tr>
                <td>{{ $member->member_no }}</td>
                <td>{{ $member->fullName() }}</td>
                <td>{{ $member->months }}</td>
                <td>₱{{ number_format($member->balance, 2) }}</td>
                <td>{{ $member->eligible ? 'Yes' : 'No' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>Eligible: {{ $members->where('eligible', true)->count() }} / {{ $members->count() }}</p>
@endsection
