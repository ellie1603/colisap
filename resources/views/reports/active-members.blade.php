@extends('reports.layout')

@section('report-title', 'Active Members List')

@section('content')
<table>
    <thead>
        <tr>
            <th>Member No.</th>
            <th>Name</th>
            <th>Contact</th>
            <th>Membership Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($members as $member)
            <tr>
                <td>{{ $member->member_no }}</td>
                <td>{{ $member->fullName() }}</td>
                <td>{{ $member->contact_number }}</td>
                <td>{{ $member->membership_date->format('M j, Y') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p>Total active members: {{ $members->count() }}</p>
@endsection
