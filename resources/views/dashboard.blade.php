<div wire:poll.3s>
    <div class="page-head">
        <h1>Backfills</h1>
        <p>One-off data changes, and where each of them got to.</p>
    </div>

    @php($summary = $this->summary)
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-label"><x-backfill::icon name="list" :size="13" /> Backfills</div>
            <div class="kpi-value">{{ number_format($summary['total']) }}</div>
        </div>
        <div class="kpi @if($summary['running']) is-live @endif">
            <div class="kpi-label">
                @if ($summary['running'])
                    <span class="pulse" style="color: var(--brand)"></span>
                @else
                    <x-backfill::icon name="play" :size="13" />
                @endif
                Running now
            </div>
            <div class="kpi-value">{{ number_format($summary['running']) }}</div>
        </div>
        <div class="kpi ok">
            <div class="kpi-label"><x-backfill::icon name="check" :size="13" /> Rows processed</div>
            <div class="kpi-value">{{ number_format($summary['processed']) }}</div>
        </div>
        <div class="kpi @if($summary['failed']) bad @endif">
            <div class="kpi-label"><x-backfill::icon name="error" :size="13" /> Rows failed</div>
            <div class="kpi-value">{{ number_format($summary['failed']) }}</div>
        </div>
    </div>

    @if ($flash)
        <div class="note note-ok">
            <x-backfill::icon name="check" />
            <div>{{ $flash }}</div>
        </div>
    @endif

    @if ($error)
        <div class="note note-bad">
            <x-backfill::icon name="error" />
            <div>{{ $error }}</div>
        </div>
    @endif

    @if ($confirming)
        <div class="note note-warn">
            <x-backfill::icon name="warning" />
            <div style="flex:1">
                <strong>Hold on — {{ $confirming }}</strong>
                <div style="margin-bottom:12px">{{ $confirmMessage }}</div>
                <div class="actions">
                    <button wire:click="runAnyway" class="danger">
                        <x-backfill::icon name="play" :size="13" solid /> Run anyway
                    </button>
                    <button wire:click="cancelConfirmation">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    <div class="panel">
        <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th>Backfill</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th class="right">Failed</th>
                        <th>Cursor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        @php($run = $row['run'])
                        @php($status = $run?->status->value ?? 'none')
                        <tr wire:key="bf-{{ $row['name'] }}">
                            <td>
                                <button class="link subtle" wire:click="select('{{ $row['name'] }}')">
                                    {{ $row['name'] }}
                                </button>
                                <code style="display:block; margin-top:2px">{{ $row['class'] }}</code>
                            </td>
                            <td>
                                <span class="badge badge-{{ $status }}">
                                    <x-backfill::icon :size="13" :name="match($status) {
                                        'running' => 'play',
                                        'completed' => 'check',
                                        'paused' => 'pause',
                                        'failed', 'cancelled' => 'error',
                                        'interrupted' => 'warning',
                                        'pending' => 'clock',
                                        default => 'never',
                                    }" />
                                    {{ $run?->status->label() ?? 'Never run' }}
                                </span>
                                @if ($row['stale'])
                                    <div style="margin-top:4px"><code>heartbeat cold</code></div>
                                @endif
                            </td>
                            <td>
                                @if ($run && $run->total_estimate)
                                    <div class="bar @if($status === 'completed') is-done @endif">
                                        <span style="width: {{ $run->progressPercent() }}%"></span>
                                    </div>
                                    <code>{{ number_format($run->processed_count) }} / {{ number_format($run->total_estimate) }}</code>
                                @elseif ($run)
                                    <code>{{ number_format($run->processed_count) }} processed</code>
                                @else
                                    <code>—</code>
                                @endif
                            </td>
                            <td class="right">
                                @if ($run && $run->failed_count > 0)
                                    <span class="badge badge-failed">{{ number_format($run->failed_count) }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td><code>{{ $run?->cursor ?? '—' }}</code></td>
                            <td>
                                <div class="actions">
                                    @if ($run && $status === 'running')
                                        <button wire:click="pause('{{ $row['name'] }}')">
                                            <x-backfill::icon name="pause" :size="14" /> Pause
                                        </button>
                                        <button wire:click="cancel('{{ $row['name'] }}')" class="icon-only" title="Cancel">
                                            <x-backfill::icon name="stop" :size="14" />
                                        </button>
                                    @elseif ($run && $run->status->isResumable())
                                        <button wire:click="resume('{{ $row['name'] }}')" class="primary">
                                            <x-backfill::icon name="play" :size="13" solid /> Resume
                                        </button>
                                        <button wire:click="cancel('{{ $row['name'] }}')" class="icon-only" title="Cancel">
                                            <x-backfill::icon name="stop" :size="14" />
                                        </button>
                                    @else
                                        <button wire:click="start('{{ $row['name'] }}')" class="primary">
                                            <x-backfill::icon name="play" :size="13" solid /> Run
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <div class="ring"><x-backfill::icon name="empty" :size="26" /></div>
                                    <p>No backfills found in <code>{{ config('backfill.path') }}</code>.</p>
                                    <p class="muted">Create one with <code>php artisan make:backfill BackfillUserSlugs</code></p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($this->run)
        @php($run = $this->run)
        <div class="panel">
            <div class="panel-head">
                <x-backfill::icon name="gauge" :size="15" class="ico muted" />
                <h2>{{ $run->backfill }} — run #{{ $run->id }}</h2>
                <span class="spacer"></span>
                <button class="icon-only" wire:click="select(null)" title="Close">
                    <x-backfill::icon name="close" :size="15" />
                </button>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="label">Status</div>
                    <div class="value" style="font-size:15px">
                        <span class="badge badge-{{ $run->status->value }}">{{ $run->status->label() }}</span>
                    </div>
                </div>
                <div class="stat">
                    <div class="label">Processed</div>
                    <div class="value">{{ number_format($run->processed_count) }}</div>
                </div>
                <div class="stat">
                    <div class="label">Failed</div>
                    <div class="value">{{ number_format($run->failed_count) }}</div>
                </div>
                <div class="stat">
                    <div class="label">Throughput</div>
                    <div class="value">{{ number_format($run->throughputPerSecond() ?? 0) }}<small> rows/s</small></div>
                </div>
                <div class="stat">
                    <div class="label">Batches</div>
                    <div class="value">{{ number_format($run->batch_count) }}</div>
                </div>
                <div class="stat">
                    <div class="label">Cursor</div>
                    <div class="value" style="font-size:15px"><span class="mono">{{ $run->cursor ?? '—' }}</span></div>
                </div>
                <div class="stat">
                    <div class="label">Started by</div>
                    <div class="value" style="font-size:14px">{{ $run->started_by ?? '—' }}</div>
                </div>
                <div class="stat">
                    <div class="label">Heartbeat</div>
                    <div class="value" style="font-size:14px">{{ $run->heartbeat_at?->diffForHumans() ?? '—' }}</div>
                </div>
            </div>

            <div class="panel-body">
                @if (! empty($run->meta['stop_reason']))
                    <div class="note note-warn" style="margin-bottom:16px">
                        <x-backfill::icon name="warning" />
                        <div>{{ $run->meta['stop_reason'] }}</div>
                    </div>
                @endif

                @if ($run->error)
                    <div class="note note-bad" style="margin-bottom:16px">
                        <x-backfill::icon name="error" />
                        <div><code>{{ $run->error }}</code></div>
                    </div>
                @endif

                <div class="label muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:650; margin-bottom:8px">
                    Batch duration
                    @if ($this->sparkline)
                        — last {{ count($this->sparkline) }} batches
                    @endif
                </div>

                @if ($this->sparkline)
                    <div class="spark">
                        @foreach ($this->sparkline as $bar)
                            <i style="height: {{ max(3, $bar['height']) }}%" title="{{ $bar['ms'] }}ms"></i>
                        @endforeach
                    </div>
                @else
                    <p class="muted" style="margin:0">
                        No batch timings recorded. Set <code>backfill.record_batches</code> to <code>true</code> to collect them.
                    </p>
                @endif
            </div>
        </div>

        @if ($this->errors->isNotEmpty())
            <div class="panel">
                <div class="panel-head">
                    <x-backfill::icon name="error" :size="15" class="ico" style="color: var(--bad)" />
                    <h2>Failed rows</h2>
                    <span class="spacer"></span>
                    <button wire:click="retryFailed('{{ $run->backfill }}')">
                        <x-backfill::icon name="retry" :size="14" /> Retry all
                    </button>
                </div>
                <div class="scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Exception</th>
                                <th>Message</th>
                                <th class="right">Attempts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->errors as $failure)
                                <tr wire:key="err-{{ $failure->id }}">
                                    <td><code>{{ $failure->record_id ?? '—' }}</code></td>
                                    <td><code>{{ class_basename($failure->exception_class) }}</code></td>
                                    <td>{{ \Illuminate\Support\Str::limit($failure->message, 90) }}</td>
                                    <td class="right">{{ $failure->attempts }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
