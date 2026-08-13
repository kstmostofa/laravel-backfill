<div wire:poll.3s>
    <h1>Run a task</h1>
    <p class="sub">Pick a task, fill in the details, and press Run. It carries on in the background.</p>

    @if ($flash)
        <div class="note note-ok">{{ $flash }}</div>
    @endif

    @if ($errors)
        <div class="note note-bad">
            @foreach ($errors as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    @if ($this->available->isEmpty())
        <div class="panel">
            <p class="muted">
                No tasks are available. A developer marks one available by setting
                <code>public bool $operatorRunnable = true;</code> on it.
            </p>
        </div>
    @else
        <div class="panel">
            <h2>Available tasks</h2>
            <div class="scroll">
                <table>
                    <tbody>
                        @foreach ($this->available as $backfill)
                            <tr>
                                <td>
                                    <strong>{{ $backfill->description() ?: $backfill->name() }}</strong>
                                    @if ($backfill->description())
                                        <div><code>{{ $backfill->name() }}</code></div>
                                    @endif
                                </td>
                                <td class="right">
                                    <button wire:click="select('{{ $backfill->name() }}')">
                                        {{ $selected === $backfill->name() ? 'Selected' : 'Choose' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($this->backfill)
        <div class="panel">
            <h2>
                {{ $this->backfill->description() ?: $this->backfill->name() }}
                <button class="link" wire:click="select(null)" style="float: right">close</button>
            </h2>

            @forelse ($this->backfill->parameters() as $parameter)
                <div style="margin-bottom: 16px">
                    <label style="display:block; font-weight:600; margin-bottom:4px">
                        {{ $parameter->label }}
                        @if ($parameter->required)
                            <span style="color: var(--bad)">*</span>
                        @endif
                    </label>

                    @if ($parameter->help)
                        <div class="muted" style="font-size:13px; margin-bottom:6px">{{ $parameter->help }}</div>
                    @endif

                    @if ($parameter->type === 'ids' || $parameter->type === 'textarea')
                        <textarea
                            wire:model="input.{{ $parameter->key }}"
                            rows="5"
                            placeholder="{{ $parameter->placeholder }}"
                            style="width:100%; padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--text); font-family:ui-monospace, monospace"
                        ></textarea>
                        @if ($parameter->type === 'ids' && $parameter->max)
                            <div class="muted" style="font-size:12px; margin-top:4px">
                                One per line, or separated by commas. Up to {{ number_format($parameter->max) }}.
                            </div>
                        @endif
                    @elseif ($parameter->type === 'select')
                        <select
                            wire:model="input.{{ $parameter->key }}"
                            style="padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--text)"
                        >
                            <option value="">Choose…</option>
                            @foreach ($parameter->options as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @elseif ($parameter->type === 'boolean')
                        <label style="font-weight:400">
                            <input type="checkbox" wire:model="input.{{ $parameter->key }}"> Yes
                        </label>
                    @else
                        <input
                            type="{{ $parameter->type === 'number' ? 'number' : 'text' }}"
                            wire:model="input.{{ $parameter->key }}"
                            placeholder="{{ $parameter->placeholder }}"
                            style="width:100%; max-width:340px; padding:8px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--text)"
                        >
                    @endif
                </div>
            @empty
                <p class="muted">This task needs no details — just press Run.</p>
            @endforelse

            <button wire:click="run" style="border-color: var(--accent); color: var(--accent); font-weight:600">
                Run
            </button>
        </div>
    @endif

    @if ($this->run)
        @php($run = $this->run)
        <div class="panel">
            <h2>Progress</h2>

            <p style="font-size:16px; margin:0 0 12px">{{ $this->progressLabel }}</p>

            @if ($run->total_estimate)
                <div class="bar" style="width:100%; height:10px">
                    <span style="width: {{ $run->progressPercent() }}%"></span>
                </div>
                <div class="muted" style="font-size:13px; margin-top:6px">
                    {{ number_format($run->processed_count) }} of {{ number_format($run->total_estimate) }}
                    ({{ $run->progressPercent() }}%)
                </div>
            @endif

            @if (! empty($run->meta['parameter_summary']))
                <div class="muted" style="font-size:13px; margin-top:10px">
                    Started with — {{ $run->meta['parameter_summary'] }}
                </div>
            @endif

            @if ($run->failed_count > 0)
                <div class="note note-bad" style="margin-top:14px">
                    {{ number_format($run->failed_count) }} rows could not be processed.
                    Everything else went through. Ask a developer to look at the details.
                </div>
            @endif
        </div>
    @endif
</div>
