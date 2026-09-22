@extends('reports.layout')

@section('report-title', 'Contribution Summary: '.$from->format('M j, Y').' to '.$until->format('M j, Y'))

@section('content')
<table>
    <thead>
        <tr>
            <th>Member No.</th>
            <th>Member</th>
            <th># of Deposits</th>
            <th>Total Contributed</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($summary as $row)
            <tr>
                <td>{{ $row->member?->member_no }}</td>
                <td>{{ $row->member?->fullName() }}</td>
                <td>{{ $row->count }}</td>
                <td>₱{{ number_format($row->total, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3">Grand Total</td>
            <td>₱{{ number_format($grandTotal, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
