{{--
    Label / value details in a 4-column table (label, value, label, value), one pair per line on phones.
    Rows come from the entry's state: each row holds one or two items; a lone item spans the whole row.
    Item: ['label' => string, 'value' => ?string, 'badge' => ?string (badge colour), 'mono' => ?bool]
--}}
<div class="colisap-details-table">
    <table>
        <tbody>
            @foreach ($getState() as $row)
                <tr>
                    @foreach ($row as $item)
                        @php($isEmpty = blank($item['value'] ?? null))
                        <th scope="row">{{ $item['label'] }}</th>
                        <td
                            @if (count($row) === 1) colspan="3" @endif
                            @class(['is-mono' => $item['mono'] ?? false, 'is-empty' => $isEmpty])
                        >
                            @if ($isEmpty)
                                —
                            @elseif ($item['badge'] ?? null)
                                <x-filament::badge :color="$item['badge']" class="w-fit">{{ $item['value'] }}</x-filament::badge>
                            @else
                                {{ $item['value'] }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
