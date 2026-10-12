<x-filament-panels::page>
    @php
        $report = $this->report;
        $summary = $report['summary'];
        $statuses = \App\Services\Reports\StaffUsageReport::STATUSES;
        $days = $report['days'];
        $rate = $summary['accounts'] === 0 ? 0 : (int) round($summary['using'] / $summary['accounts'] * 100);
        $followUp = [...$report['groups']['none'], ...$report['groups']['signed_in']];
        $figures = [
            ['Sign-ins', $summary['sign_ins'], 'times staff logged in'],
            ['Members added', $summary['members_added'], 'typed in one at a time'],
            ['Members edited', $summary['members_edited'], 'corrected one at a time'],
            ['Excel files uploaded', $summary['files_imported'], number_format($summary['records_imported']).' member records'],
            ['Text messages sent', $summary['sms_sent'], 'reminders to members'],
            ['Reports downloaded', $summary['reports_exported'], 'Excel, CSV or PDF'],
        ];
        $initials = fn (string $name): string => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
    @endphp

    <x-filament::tabs label="Report period">
        @foreach (\App\Services\Reports\StaffUsageReport::PERIODS as $key => $label)
            <x-filament::tabs.item :active="$period === $key" wire:click="switchPeriod('{{ $key }}')">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="colisap-usage">
        {{-- Summary: the adoption rate on the left, what was done on the right --}}
        <section class="colisap-usage-card colisap-usage-summary" aria-label="Summary">
            <div>
                <p class="colisap-usage-eyebrow">Staff adoption · {{ $report['period_label'] }} · {{ $report['range_label'] }}</p>

                <p class="colisap-usage-rate">
                    <span class="colisap-usage-rate__value">{{ $rate }}%</span>
                    <span class="colisap-usage-rate__text">{{ $summary['using'] }} of {{ $summary['accounts'] }} staff accounts used the system</span>
                </p>

                <div
                    class="colisap-usage-bar"
                    role="img"
                    aria-label="{{ $summary['using'] }} using the system, {{ $summary['signed_in_only'] }} signed in only, {{ $summary['no_activity'] }} not using the system"
                >
                    @foreach ($report['groups'] as $status => $names)
                        @if ($names !== [])
                            <span class="colisap-usage-bar__part colisap-usage-tone--{{ $status }}" style="flex-grow: {{ count($names) }}"></span>
                        @endif
                    @endforeach
                </div>

                <ul class="colisap-usage-legend">
                    @foreach ($report['groups'] as $status => $names)
                        <li title="{{ $statuses[$status]['meaning'] }}">
                            <span class="colisap-usage-dot colisap-usage-tone--{{ $status }}" aria-hidden="true"></span>
                            {{ $statuses[$status]['label'] }} <strong>{{ count($names) }}</strong>
                        </li>
                    @endforeach
                </ul>

                <p class="colisap-usage-followup">
                    Needs follow-up: <strong>{{ $followUp === [] ? 'No one' : implode(', ', $followUp) }}</strong>
                </p>
            </div>

            <dl class="colisap-usage-figures">
                @foreach ($figures as [$label, $value, $note])
                    <div class="colisap-usage-figure">
                        <dt>{{ $label }}</dt>
                        <dd>
                            <span class="colisap-usage-figure__value">{{ number_format($value) }}</span>
                            <span class="colisap-usage-figure__note">{{ $note }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Staff activity --}}
        <section class="colisap-usage-card" aria-label="Staff activity">
            <header class="colisap-usage-card__header">
                <h2>Staff activity</h2>
                <p>
                    Accounts needing follow-up are listed first.
                    @if ($days !== [])
                        Each square is one day of the period; a filled square means the account was used that day.
                    @endif
                </p>
            </header>

            <div class="colisap-usage-table-wrap">
                <table class="colisap-usage-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 17rem">Staff</th>
                            <th scope="col" style="width: 12rem">Status</th>
                            <th scope="col" style="width: 14rem">Days used</th>
                            <th scope="col">What they did</th>
                            <th scope="col" style="width: 11rem">Last used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['staff'] as $row)
                            <tr wire:key="usage-{{ $row['id'] }}">
                                <td>
                                    <div class="colisap-usage-person">
                                        <span class="colisap-usage-avatar" aria-hidden="true">{{ $initials($row['name']) }}</span>
                                        <span>
                                            <span class="colisap-usage-person__name">{{ $row['name'] }}</span>
                                            <span class="colisap-usage-sub">{{ $row['role'] }} · {{ $row['branch'] }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="colisap-usage-status" title="{{ $statuses[$row['status']]['meaning'] }}">
                                        <span class="colisap-usage-dot colisap-usage-tone--{{ $row['status'] }}" aria-hidden="true"></span>
                                        {{ $statuses[$row['status']]['label'] }}
                                    </span>
                                </td>
                                <td>
                                    @if ($days !== [])
                                        <span class="colisap-days" role="img" aria-label="Used on {{ $row['days_active'] }} of {{ $report['period_days'] }} days">
                                            @foreach ($days as $day)
                                                @php
                                                    $wasUsed = in_array($day->toDateString(), $row['active_days'], true);
                                                @endphp
                                                <span @class(['colisap-day', 'colisap-day--on' => $wasUsed]) title="{{ $day->format('D, M j') }} — {{ $wasUsed ? 'used' : 'not used' }}"></span>
                                            @endforeach
                                        </span>
                                    @endif
                                    <span class="colisap-usage-sub">{{ $row['days_active'] }} of {{ $report['period_days'] }} {{ \Illuminate\Support\Str::plural('day', $report['period_days']) }}</span>
                                </td>
                                <td>{{ $row['did'] }}</td>
                                <td>
                                    @if ($row['last_active'])
                                        {{ $row['last_active']->diffForHumans() }}
                                        <span class="colisap-usage-sub">{{ $row['last_active']->format('M j, Y · g:i A') }}</span>
                                    @else
                                        <span class="colisap-usage-sub">Never</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="colisap-usage-sub">There are no staff accounts to report on.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="colisap-usage-note">
                Every figure is counted automatically by the system and cannot be edited.
                @if ($report['tracking_since'])
                    Sign-ins are counted from {{ $report['tracking_since']->format('M j, Y') }}, when sign-in tracking was switched on.
                @else
                    No sign-in has been recorded yet — sign-in tracking has just been switched on.
                @endif
            </p>
        </section>
    </div>
</x-filament-panels::page>
