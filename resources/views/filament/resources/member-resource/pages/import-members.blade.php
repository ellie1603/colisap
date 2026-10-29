<x-filament-panels::page>
    @php
        $status = $batch?->status;
        $steps = ['Upload', 'Map sheets', 'Preview', 'Import'];
        // $current is how far the import has come; $shown is the step being looked at (never further than that).
        $current = $this->reachedStep();
        $shown = min($viewStep ?? $current, $current);
        $isLookingBack = $shown < $current;
    @endphp

    {{-- The import keeps running whichever step is being looked at. --}}
    @if ($status === 'importing')
        <div wire:poll.250ms="importNext" class="hidden"></div>
    @endif

    <nav aria-label="Import progress">
        <ol class="colisap-steps">
            @foreach ($steps as $index => $label)
                @php
                    $canOpen = $index <= $current && ! $staging;
                @endphp
                <li
                    @class(['colisap-step', 'colisap-step--current' => $index === $shown, 'colisap-step--open' => $canOpen && $index !== $shown])
                    @if ($index === $shown) aria-current="step" @endif
                    @if ($canOpen && $index !== $shown)
                        role="button" tabindex="0" title="Go to {{ $label }}"
                        wire:click="goToStep({{ $index }})" wire:keydown.enter="goToStep({{ $index }})"
                    @endif
                >
                    <span class="colisap-step__num">@if ($index < $current) ✓ @else {{ $index + 1 }} @endif</span>
                    {{ $label }}
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- 1. Upload --}}
    @if ($shown === 0 && ! $batch)
        <x-filament::section>
            <x-slot name="heading">Upload the masterlist</x-slot>
            <x-slot name="description">
                Members are matched by Acct. Number: existing accounts are updated, new accounts are added. Computed Excel columns (NEW STATUS, # of Days, upload flags) and values such as #N/A are ignored — the system recalculates statuses from policy.
            </x-slot>

            <form wire:submit="analyze" class="colisap-stack">
                {{ $this->uploadForm }}

                <div>
                    <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="analyze,upload.file">
                        <span wire:loading.remove wire:target="analyze">Analyze workbook</span>
                        <span wire:loading wire:target="analyze">Reading sheets…</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif

    {{-- 1. Upload, looked at again --}}
    @if ($shown === 0 && $batch)
        <x-filament::section>
            <x-slot name="heading">Uploaded file</x-slot>
            <x-slot name="description">This is the file this import is reading. Looking at this step changes nothing.</x-slot>

            <dl class="colisap-facts">
                <div><dt>File</dt><dd>{{ $batch->file_name }}</dd></div>
                <div><dt>Uploaded</dt><dd>{{ $batch->created_at?->format('M j, Y g:i A') }}</dd></div>
                <div><dt>Uploaded by</dt><dd>{{ $batch->uploader?->name ?? '—' }}</dd></div>
                <div><dt>Balances as of</dt><dd>{{ $batch->as_of_date?->toFormattedDateString() }}</dd></div>
                <div><dt>Sheets in the file</dt><dd>{{ count($batch->sheets ?? []) }}</dd></div>
            </dl>

            <div class="colisap-actions">
                <x-filament::button icon="heroicon-o-arrow-right" wire:click="goToStep({{ $current }})">Back to {{ $steps[$current] }}</x-filament::button>
                @if (in_array($status, ['analyzed', 'staged'], true))
                    <x-filament::button color="gray" icon="heroicon-o-arrow-up-tray" wire:click="uploadCorrectedFile" wire:confirm="Discard this file and upload a different one? Nothing has been imported.">Upload a different file</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{-- 2. Mapping / validation --}}
    @if ($shown === 1 && $batch && $status === 'analyzed')
        @if ($staging)
            @php
                $included = collect($batch->sheets)->filter(fn ($sheet) => $sheet['include'] ?? false);
                $done = $included->filter(fn ($sheet) => $sheet['staged'] ?? false)->count();
                $percent = $included->count() ? (int) floor($done / $included->count() * 100) : 0;
            @endphp
            <x-filament::section wire:poll.500ms="stageNext">
                <x-slot name="heading">Validating {{ $included->count() }} sheet(s)…</x-slot>
                <div class="colisap-stack" role="status" aria-live="polite">
                    <div class="colisap-progress"><div class="colisap-progress__bar" style="width: {{ $percent }}%"></div></div>
                    <p class="colisap-muted">
                        {{ $done }} of {{ $included->count() }} sheets checked · {{ number_format($included->sum('staged_rows')) }} rows read.
                        Checking Acct. Numbers, dates, categories, segmentation and duplicates.
                    </p>
                </div>
                <div class="colisap-actions">
                    <x-filament::button color="gray" icon="heroicon-o-x-mark" wire:click="cancelValidation" wire:loading.attr="disabled" wire:target="cancelValidation">
                        <span wire:loading.remove wire:target="cancelValidation">Cancel</span>
                        <span wire:loading wire:target="cancelValidation">Cancelling — finishing the current sheet…</span>
                    </x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Confirm sheets, branches and columns</x-slot>
                <x-slot name="description">
                    {{ $batch->file_name }} · as of {{ $batch->as_of_date->toFormattedDateString() }}.
                    Sheets were matched to branches by name (or by their Branch column) and columns by their headers — adjust anything that is wrong. Only ticked sheets are imported.
                    @if ($ownBranchName)
                        Your account is limited to the {{ $ownBranchName }} branch, so only its sheet is shown and imported.
                    @endif
                </x-slot>

                <div class="colisap-stack">
                    @foreach ($batch->sheets as $position => $sheet)
                        @continue(! in_array($position, $mappableSheets, true))
                        @php
                            $isContactList = \App\Services\Masterlist\WorkbookParser::isContactList($mapping[$position]['columns'] ?? []);
                            $hasBranchColumn = filled($mapping[$position]['columns']['branch'] ?? null);
                        @endphp
                        <div wire:key="sheet-{{ $position }}" class="colisap-sheet">
                            <div class="colisap-sheet__header">
                                <label class="colisap-check">
                                    <x-filament::input.checkbox wire:model.live="mapping.{{ $position }}.include" :disabled="! $sheet['header_row']" />
                                    <span>
                                        <span class="colisap-sheet__title">{{ $sheet['name'] }}</span><br>
                                        <span class="colisap-muted">
                                            @if ($sheet['header_row'])
                                                Header on row {{ $sheet['header_row'] }}@if ($sheet['staged'] ?? false) · {{ number_format($sheet['staged_rows'] ?? 0) }} member rows read @endif
                                            @else
                                                No header row with Acct. Number / Account Name found — sheet skipped
                                            @endif
                                        </span>
                                    </span>
                                </label>

                                @if ($sheet['header_row'] && $isContactList)
                                    <div class="colisap-sheet__branch">
                                        <x-filament::badge color="info" icon="heroicon-m-device-phone-mobile">Mobile numbers only</x-filament::badge>
                                    </div>
                                @elseif ($sheet['header_row'])
                                    <div class="colisap-sheet__branch">
                                        <x-filament::input.wrapper :valid="$hasBranchColumn || ! (($mapping[$position]['include'] ?? false) && empty($mapping[$position]['branch_id']))">
                                            <x-filament::input.select wire:model="mapping.{{ $position }}.branch_id" aria-label="Branch for {{ $sheet['name'] }}">
                                                <option value="">{{ $hasBranchColumn ? 'From the Branch column' : 'Choose branch…' }}</option>
                                                @foreach ($branches as $id => $name)
                                                    <option value="{{ $id }}">{{ $name }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                @endif
                            </div>

                            @if ($sheet['header_row'] && $isContactList)
                                <p class="colisap-muted">
                                    This sheet has only the Acct. Number, name and mobile number, so it updates the mobile number of members already in the system, matched by Acct. Number.
                                    Names, branch, status, category and savings are not changed, and no new members are added.
                                </p>
                            @endif

                            @if ($sheet['header_row'] && ($mapping[$position]['include'] ?? false))
                                <details class="colisap-details" @if (! isset($sheet['columns']['account_no'])) open @endif>
                                    <summary>Column mapping ({{ count(array_filter($mapping[$position]['columns'] ?? [], fn ($column) => $column !== null && $column !== '')) }} mapped)</summary>
                                    <div class="colisap-field-grid">
                                        @foreach ($fields as $field => $label)
                                            <label class="colisap-field">
                                                {{ $label }}
                                                <x-filament::input.wrapper>
                                                    {{-- Mapping the Branch column re-labels the sheet's branch choice straight away. --}}
                                                    <x-filament::input.select wire:model="mapping.{{ $position }}.columns.{{ $field }}" :wire:change="$field === 'branch' ? '$refresh' : false">
                                                        <option value="">— not in file —</option>
                                                        @foreach ($sheet['headers'] as $column => $header)
                                                            <option value="{{ $column }}">{{ $header }}</option>
                                                        @endforeach
                                                    </x-filament::input.select>
                                                </x-filament::input.wrapper>
                                            </label>
                                        @endforeach
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="colisap-actions">
                    <x-filament::button wire:click="validateMapping" icon="heroicon-o-check-circle" wire:loading.attr="disabled">Validate &amp; preview</x-filament::button>
                    <x-filament::button color="gray" wire:click="cancel" wire:confirm="Cancel this import? Nothing has been imported yet.">Cancel</x-filament::button>
                </div>
            </x-filament::section>
        @endif
    @endif

    {{-- 2. Mapping, looked at again --}}
    @if ($shown === 1 && $batch && $status !== 'analyzed')
        <x-filament::section>
            <x-slot name="heading">Sheets and branches used</x-slot>
            <x-slot name="description">How this file was mapped. Looking at this step changes nothing.</x-slot>

            <div class="colisap-usage-table-wrap">
                <table class="colisap-mapping">
                    <thead>
                        <tr><th scope="col">Sheet</th><th scope="col">Branch</th><th scope="col">Included</th><th scope="col">Columns mapped</th><th scope="col">Rows read</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($batch->sheets as $position => $sheet)
                            @continue(! in_array($position, $mappableSheets, true))
                            <tr>
                                <td>{{ $sheet['name'] }}</td>
                                <td>{{ $sheet['branch_name'] ?? (\App\Services\Masterlist\WorkbookParser::isContactList($sheet['columns'] ?? []) ? 'Mobile numbers only' : '—') }}</td>
                                <td>{{ ($sheet['include'] ?? false) ? 'Yes' : 'No' }}</td>
                                <td>{{ count($sheet['columns'] ?? []) }}</td>
                                <td>{{ ($sheet['include'] ?? false) ? number_format($sheet['staged_rows'] ?? 0) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="colisap-actions">
                <x-filament::button icon="heroicon-o-arrow-right" wire:click="goToStep({{ $current }})">Back to {{ $steps[$current] }}</x-filament::button>
                @if ($status === 'staged')
                    <x-filament::button color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="backToMapping" wire:confirm="Change the mapping? The file will be checked again before you can preview it.">Change mapping</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{-- 3. Preview · 4. Import --}}
    @if ($batch && $shown >= 2)
        @php
            $cards = [
                ['Total rows', $batch->total_rows, 'gray'],
                ['New members', $batch->new_rows, 'success'],
                ['Updated members', $batch->update_rows, 'info'],
                ['Duplicates', $batch->duplicate_rows, 'warning'],
                ['Invalid', $batch->invalid_rows, 'danger'],
                ['With warnings', $batch->warning_rows, 'warning'],
            ];
        @endphp

        <div class="colisap-stats">
            @foreach ($cards as [$label, $value, $color])
                <div class="colisap-stat colisap-stat--{{ $color }}">
                    <p class="colisap-stat__label">{{ $label }}</p>
                    <p class="colisap-stat__value">{{ number_format($value) }}</p>
                </div>
            @endforeach
        </div>

        @if ($shown === 2 && $status === 'staged' && $batch->duplicate_rows > 0)
            <x-filament::section icon="heroicon-o-no-symbol" icon-color="danger">
                <x-slot name="heading">Import blocked — this file has {{ number_format($batch->duplicate_rows) }} duplicate(s)</x-slot>
                <x-slot name="description">
                    The file lists the same Acct. Number more than once, so nothing has been imported.
                    Correct the file in Excel and upload it again, or tick the duplicate rows in the table below and choose "Delete selected".
                    Each duplicate says which sheet and row it repeats; the issues report lists them all.
                </x-slot>
                <div class="colisap-actions">
                    <x-filament::button color="danger" icon="heroicon-o-funnel" wire:click="showDuplicates">Show the duplicates</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadIssues">Download issues (CSV)</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-arrow-up-tray" wire:click="uploadCorrectedFile" wire:confirm="Discard this file and upload a corrected one? Nothing has been imported.">Upload a corrected file</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="backToMapping">Change mapping</x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($shown === 2 && $status === 'staged')
            <x-filament::section>
                <x-slot name="heading">{{ $canApprove ? 'Ready to import' : 'Ready to send for approval' }}</x-slot>
                <x-slot name="description">
                    The file was checked and has no duplicates. {{ number_format($batch->importableCount()) }} member(s) will be written. Invalid rows are skipped — download the issues report to correct them in Excel.
                    To leave a row out, tick its checkbox in the table below and choose "Delete selected".
                    @unless ($canApprove)
                        Nothing is added to the system until the Administrator approves this import.
                    @endunless
                </x-slot>
                <div class="colisap-actions">
                    @if ($canApprove)
                        <x-filament::button wire:click="startImport" icon="heroicon-o-arrow-down-on-square-stack" wire:confirm="Import {{ number_format($batch->importableCount()) }} member(s) now?">
                            Import {{ number_format($batch->importableCount()) }} member(s)
                        </x-filament::button>
                    @else
                        <x-filament::button wire:click="startImport" icon="heroicon-o-paper-airplane" wire:confirm="Send {{ number_format($batch->importableCount()) }} member(s) to the Administrator for approval?">
                            Send {{ number_format($batch->importableCount()) }} member(s) for approval
                        </x-filament::button>
                    @endif
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadIssues">Download issues (CSV)</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="backToMapping">Change mapping</x-filament::button>
                    <x-filament::button color="danger" outlined wire:click="cancel" wire:confirm="Cancel this import?">Cancel</x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($shown === 2 && $status === 'awaiting_approval' && $canApprove)
            <x-filament::section icon="heroicon-o-inbox-arrow-down" icon-color="warning">
                <x-slot name="heading">Waiting for your approval</x-slot>
                <x-slot name="description">
                    Sent by {{ $batch->uploader?->name ?? 'a branch' }}. Check the rows below. Approving adds {{ number_format($batch->new_rows) }} new member(s) and updates {{ number_format($batch->update_rows) }}; nothing has been added yet.
                    To reject it with a reason, use Import Approvals.
                </x-slot>
                <div class="colisap-actions">
                    <x-filament::button color="success" icon="heroicon-o-check-circle" wire:click="approveImport" wire:confirm="Approve this import and write {{ number_format($batch->importableCount()) }} member(s) now?">Approve &amp; import</x-filament::button>
                    <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\ImportApprovals::getUrl()" icon="heroicon-o-inbox-arrow-down">Import Approvals</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadIssues">Download issues (CSV)</x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($shown === 2 && $status === 'awaiting_approval')
            <x-filament::section icon="heroicon-o-clock" icon-color="warning">
                <x-slot name="heading">Waiting for the Administrator's approval</x-slot>
                <x-slot name="description">
                    This import has been sent. Nothing has been added to the system yet — the {{ number_format($batch->importableCount()) }} member(s) are written once the Administrator approves it. You can follow it in Import History.
                </x-slot>
                <div class="colisap-actions">
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadIssues">Download issues (CSV)</x-filament::button>
                    <x-filament::button color="danger" outlined wire:click="cancel" wire:confirm="Withdraw this import? The Administrator will no longer see it.">Withdraw this import</x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($shown === 2)
            <x-filament::section>
                <x-slot name="heading">Preview</x-slot>
                <x-slot name="description">The rows of this file as they were checked. Looking at this step changes nothing.</x-slot>
                <x-filament::button icon="heroicon-o-arrow-right" wire:click="goToStep({{ $current }})">Back to {{ $steps[$current] }}</x-filament::button>
            </x-filament::section>
        @endif

        @if ($shown === 3 && $status === 'importing')
            <x-filament::section>
                <x-slot name="heading">Importing… {{ $batch->progressPercent() }}%</x-slot>
                <div class="colisap-stack" role="status" aria-live="polite">
                    <div class="colisap-progress"><div class="colisap-progress__bar" style="width: {{ $batch->progressPercent() }}%"></div></div>
                    <p class="colisap-muted">
                        {{ number_format($batch->processed_rows) }} of {{ number_format($batch->importableCount()) }} rows ·
                        {{ number_format($batch->created_count) }} added · {{ number_format($batch->updated_count) }} updated.
                        Statuses, replenishment notices and dashboards update as each member is saved. Keep this page open until it finishes.
                    </p>
                </div>
            </x-filament::section>
        @endif

        @if ($shown === 3 && $status === 'completed')
            <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
                <x-slot name="heading">Import completed</x-slot>
                <x-slot name="description">
                    {{ number_format($batch->created_count) }} added · {{ number_format($batch->updated_count) }} updated · {{ number_format($batch->unchanged_count) }} unchanged ·
                    {{ number_format($batch->duplicate_rows + $batch->invalid_rows) }} skipped.
                </x-slot>
                <div class="colisap-actions">
                    <x-filament::button tag="a" :href="\App\Filament\Resources\MemberResource::getUrl('index')" icon="heroicon-o-users">View members</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadIssues">Download issues (CSV)</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-arrow-up-tray" wire:click="startOver">Import another file</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if ($shown === 3 && $status === 'rejected')
            <x-filament::section icon="heroicon-o-x-circle" icon-color="danger">
                <x-slot name="heading">The Administrator rejected this import</x-slot>
                <x-slot name="description">Reason: {{ $batch->error_message }} — nothing from this file was added to the system.</x-slot>
                <x-filament::button color="gray" icon="heroicon-o-arrow-up-tray" wire:click="startOver">Upload a corrected file</x-filament::button>
            </x-filament::section>
        @endif

        @if ($shown === 3 && in_array($status, ['failed', 'cancelled'], true))
            <x-filament::section>
                <x-slot name="heading">Import {{ $status }}</x-slot>
                <x-slot name="description">{{ $batch->error_message }}</x-slot>
                <x-filament::button color="gray" wire:click="startOver">Start a new import</x-filament::button>
            </x-filament::section>
        @endif

        {{ $this->table }}
    @endif
</x-filament-panels::page>
