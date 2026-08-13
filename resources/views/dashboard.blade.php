<div wire:poll.3s>
    <h1>Backfills</h1>
    <p class="sub">One-off data changes, and where each of them got to.</p>

    @if ($flash)
        <div class="note note-ok">{{ $flash }}</div>
    @endif

    @if ($error)
        <div class="note note-bad">{{ $error }}</div>
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
                        <tr>
                            <td>
                                <button class="link" wire:click="select('{{ $row['name'] }}')">{{ $row['name'] }}</button>
                                <div><code>{{ $row['class'] }}</code></div>
                            </td>
                            <td>
                                <span class="badge badge-{{ $run?->status->value ?? 'none' }}">
                                    {{ $run?->status->label() ?? 'never run' }}
                                </span>
                                @if ($row['stale'])
                                    <div><code>heartbeat cold</code></div>
                                @endif
                            </td>
                            <td>
                                @if ($run && $run->total_estimate)
                                    <div class="bar"><span style="width: {{ $run->progressPercent() }}%"></span></div>
                                    <code>{{ number_format($run->processed_count) }} / {{ number_format($run->total_estimate) }}</code>
                                @elseif ($run)
                                    <code>{{ number_format($run->processed_count) }} processed</code>
                                @else
                                    <code>—</code>
                                @endif
                            </td>
                            <td class="right">
                                @if ($run && $run->failed_count > 0)
                                    <span style="color: var(--bad)">{{ number_format($run->failed_count) }}</span>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td><code>{{ $run?->cursor ?? '—' }}</code></td>
                            <td>
                                <div class="actions">
                                    @if ($run && $run->status->value === 'running')
                                        <button wire:click="pause('{{ $row['name'] }}')">Pause</button>
                                        <button wire:click="cancel('{{ $row['name'] }}')">Cancel</button>
                                    @elseif ($run && $run->status->isResumable())
                                        <button wire:click="resume('{{ $row['name'] }}')">Resume</button>
                                        <button wire:click="cancel('{{ $row['name'] }}')">Cancel</button>
                                    @else
                                        <button wire:click="start('{{ $row['name'] }}')">Run</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="muted">
                                No backfills found in <code>{{ config('backfill.path') }}</code>.
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
            <h2>
                {{ $run->backfill }} — run #{{ $run->id }}
                <button class="link" wire:click="select(null)" style="float: right">close</button>
            </h2>

            <div class="grid">
                <div class="stat">
                    <div class="label">Status</div>
                    <div class="value">
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
                    <div class="value">{{ $run->throughputPerSecond() ?? '—' }}<span class="muted" style="font-size:12px"> rows/s</span></div>
                </div>
                <div class="stat">
                    <div class="label">Batches</div>
                    <div class="value">{{ number_format($run->batch_count) }}</div>
                </div>
                <div class="stat">
                    <div class="label">Cursor</div>
                    <div class="value"><code style="font-size:14px">{{ $run->cursor ?? '—' }}</code></div>
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

            @if (! empty($run->meta['stop_reason']))
                <div class="note note-bad" style="margin-top:16px">{{ $run->meta['stop_reason'] }}</div>
            @endif

            @if ($run->error)
                <div class="note note-bad" style="margin-top:16px"><code>{{ $run->error }}</code></div>
            @endif

            @if ($this->sparkline)
                <div style="margin-top:20px">
                    <div class="label muted" style="font-size:12px; text-transform:uppercase; letter-spacing:.04em">
                        Batch duration — last {{ count($this->sparkline) }} batches
                    </div>
                    <div class="spark">
                        @foreach ($this->sparkline as $bar)
                            <i style="height: {{ max(2, $bar['height']) }}%" title="{{ $bar['ms'] }}ms"></i>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="muted" style="margin-top:20px">
                    No batch timings recorded. Set <code>backfill.record_batches</code> to true to collect them.
                </p>
            @endif
        </div>

        @if ($this->errors->isNotEmpty())
            <div class="panel">
                <h2>
                    Failed rows
                    <button wire:click="retryFailed('{{ $run->backfill }}')" style="float: right">Retry all</button>
                </h2>
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
                                <tr>
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
