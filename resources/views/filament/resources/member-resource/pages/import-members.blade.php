<x-filament-panels::page>
    @php
        $status = $batch?->status;
        $steps = ['Upload', 'Map sheets', 'Preview', 'Import'];
        $current = match (true) {
            $batch === null => 0,
            $status === 'analyzed' || $staging => 1,
            $status === 'staged' => 2,
            default => 3,
        };
    @endphp

    <nav aria-label="Import progress">
        <ol class="colisap-steps">
            @foreach ($steps as $index => $label)
                <li @class(['colisap-step', 'colisap-step--current' => $index === $current]) @if ($index === $current) aria-current="step" @endif>
                    <span class="colisap-step__num">@if ($index < $current) ✓ @else {{ $index + 1 }} @endif</span>
                    {{ $label }}
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- 1. Upload --}}
    @if (! $batch)
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

    {{-- 2. Mapping / validation --}}
    @if ($batch && $status === 'analyzed')
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
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Confirm sheets, branches and columns</x-slot>
                <x-slot name="description">
                    {{ $batch->file_name }} · as of {{ $batch->as_of_date->toFormattedDateString() }}.
                    Sheets were matched to branches by name and columns by their headers — adjust anything that is wrong. Only ticked sheets are imported.
                </x-slot>

                <div class="colisap-stack">
                    @foreach ($batch->sheets as $position => $sheet)
                        <div wire:key="sheet-{{ $position }}" class="colisap-sheet">
                            <div class="colisap-sheet__header">
                                <label class="colisap-check">
                                    <x-filament::input.checkbox wire:model.live="mapping.{{ $position }}.include" :disabled="! $sheet['header_row']" />
                                    <span>
                                        <span class="colisap-sheet__title">{{ $sheet['name'] }}</span><br>
                                        <span class="colisap-muted">
                                            @if ($sheet['header_row'])
                                                Header on row {{ $sheet['header_row'] }} · {{ number_format($sheet['data_rows']) }} rows below it
                                            @else
                                                No header row with Acct. Number / Account Name found — sheet skipped
                                            @endif
                                        </span>
                                    </span>
                                </label>

                                @if ($sheet['header_row'])
                                    <div class="colisap-sheet__branch">
                                        <x-filament::input.wrapper :valid="! (($mapping[$position]['include'] ?? false) && empty($mapping[$position]['branch_id']))">
                                            <x-filament::input.select wire:model="mapping.{{ $position }}.branch_id" aria-label="Branch for {{ $sheet['name'] }}">
                                                <option value="">Choose branch…</option>
                                                @foreach ($branches as $id => $name)
                                                    <option value="{{ $id }}">{{ $name }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                @endif
                            </div>

                            @if ($sheet['header_row'] && ($mapping[$position]['include'] ?? false))
                                <details class="colisap-details" @if (! isset($sheet['columns']['account_no'])) open @endif>
                                    <summary>Column mapping ({{ count(array_filter($mapping[$position]['columns'] ?? [], fn ($column) => $column !== null && $column !== '')) }} mapped)</summary>
                                    <div class="colisap-field-grid">
                                        @foreach ($fields as $field => $label)
                                            <label class="colisap-field">
                                                {{ $label }}
                                                <x-filament::input.wrapper>
                                                    <x-filament::input.select wire:model="mapping.{{ $position }}.columns.{{ $field }}">
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

    {{-- 3. Preview · 4. Import · Done --}}
    @if ($batch && in_array($status, ['staged', 'importing', 'completed', 'failed', 'cancelled'], true))
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

        @if ($status === 'staged')
            <x-filament::section>
                <x-slot name="heading">Ready to import</x-slot>
                <x-slot name="description">
                    {{ number_format($batch->importableCount()) }} member(s) will be written. Duplicates and invalid rows are skipped — download the issues report to correct them in Excel.
                </x-slot>
                <div class="colisap-actions">
                    <x-filament::button wire:click="startImport" icon="heroicon-o-arrow-down-on-square-stack" wire:confirm="Import {{ number_format($batch->importableCount()) }} member(s) now?">
                        Import {{ number_format($batch->importableCount()) }} member(s)
                    </x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-document-arrow-down" wire:click="downloadIssues">Download issues (CSV)</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-adjustments-horizontal" wire:click="backToMapping">Change mapping</x-filament::button>
                    <x-filament::button color="danger" outlined wire:click="cancel" wire:confirm="Cancel this import?">Cancel</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if ($status === 'importing')
            <x-filament::section wire:poll.250ms="importNext">
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

        @if ($status === 'completed')
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

        @if (in_array($status, ['failed', 'cancelled'], true))
            <x-filament::section>
                <x-slot name="heading">Import {{ $status }}</x-slot>
                <x-slot name="description">{{ $batch->error_message }}</x-slot>
                <x-filament::button color="gray" wire:click="startOver">Start a new import</x-filament::button>
            </x-filament::section>
        @endif

        {{ $this->table }}
    @endif
</x-filament-panels::page>
