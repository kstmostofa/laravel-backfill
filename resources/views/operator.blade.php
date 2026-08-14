<div wire:poll.3s>
    <div class="page-head">
        <h1>Run a task</h1>
        <p>Pick a task, fill in the details, and press Run. It carries on in the background.</p>
    </div>

    @if ($flash)
        <div class="note note-ok">
            <x-backfill::icon name="check" />
            <div>{{ $flash }}</div>
        </div>
    @endif

    @if ($errors)
        <div class="note note-bad">
            <x-backfill::icon name="error" />
            <div>
                <strong>That did not work</strong>
                @foreach ($errors as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->available->isEmpty())
        <div class="panel">
            <div class="empty">
                <div class="ring"><x-backfill::icon name="empty" :size="26" /></div>
                <p>There are no tasks available to you.</p>
                <p class="muted">
                    A developer makes one available by setting
                    <code>public bool $operatorRunnable = true;</code> on it.
                </p>
            </div>
        </div>
    @else
        <div class="panel">
            <div class="panel-head">
                <span class="step">1</span>
                <h2>Choose a task</h2>
            </div>
            <div class="scroll">
                <table>
                    <tbody>
                        @foreach ($this->available as $backfill)
                            <tr wire:key="task-{{ $backfill->name() }}">
                                <td>
                                    <div class="row-name">{{ $backfill->description() ?: $backfill->name() }}</div>
                                    <code style="display:block; margin-top:2px">{{ $backfill->name() }}</code>
                                </td>
                                <td class="right">
                                    @if ($selected === $backfill->name())
                                        <span class="badge badge-completed">
                                            <x-backfill::icon name="check" :size="13" /> Selected
                                        </span>
                                    @else
                                        <button wire:click="select('{{ $backfill->name() }}')">Choose</button>
                                    @endif
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
            <div class="panel-head">
                <span class="step">2</span>
                <h2>{{ $this->backfill->description() ?: $this->backfill->name() }}</h2>
                <span class="spacer"></span>
                <button class="icon-only" wire:click="select(null)" title="Close">
                    <x-backfill::icon name="close" :size="15" />
                </button>
            </div>

            <div class="panel-body">
                @forelse ($this->backfill->parameters() as $parameter)
                    <label class="field" wire:key="p-{{ $parameter->key }}">
                        <span class="lab">
                            {{ $parameter->label }}
                            @if ($parameter->required)<span class="req">*</span>@endif
                        </span>

                        @if ($parameter->help)
                            <span class="hint">{{ $parameter->help }}</span>
                        @endif

                        @if ($parameter->type === 'ids' || $parameter->type === 'textarea')
                            <textarea wire:model="input.{{ $parameter->key }}" rows="5"
                                placeholder="{{ $parameter->placeholder }}"></textarea>
                            @if ($parameter->type === 'ids' && $parameter->max)
                                <span class="hint" style="margin-top:5px; display:block">
                                    One per line, or separated by commas. Up to {{ number_format($parameter->max) }}.
                                </span>
                            @endif
                        @elseif ($parameter->type === 'select')
                            <select wire:model="input.{{ $parameter->key }}" style="max-width:280px">
                                <option value="">Choose…</option>
                                @foreach ($parameter->options as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif ($parameter->type === 'boolean')
                            <span style="display:flex; align-items:center; gap:8px; font-weight:400">
                                <input type="checkbox" wire:model="input.{{ $parameter->key }}" style="width:auto">
                                Yes
                            </span>
                        @else
                            <input type="{{ $parameter->type === 'number' ? 'number' : 'text' }}"
                                wire:model="input.{{ $parameter->key }}"
                                placeholder="{{ $parameter->placeholder }}">
                        @endif
                    </label>
                @empty
                    <p class="muted">This task needs no details — just press Run.</p>
                @endforelse

                <button wire:click="run" class="primary" wire:loading.attr="disabled">
                    <x-backfill::icon name="play" :size="14" solid />
                    <span wire:loading.remove wire:target="run">Run</span>
                    <span wire:loading wire:target="run">Starting…</span>
                </button>
            </div>
        </div>
    @endif

    @if ($this->run)
        @php($run = $this->run)
        @php($status = $run->status->value)
        <div class="panel">
            <div class="panel-head">
                <span class="step">3</span>
                <h2>Progress</h2>
                <span class="spacer"></span>
                <span class="badge badge-{{ $status }}">
                    <x-backfill::icon :size="13" :name="match($status) {
                        'running' => 'play',
                        'completed' => 'check',
                        'paused', 'interrupted' => 'pause',
                        'failed', 'cancelled' => 'error',
                        default => 'clock',
                    }" />
                    {{ $run->status->label() }}
                </span>
            </div>

            <div class="panel-body">
                <p style="font-size:16px; margin:0 0 14px; font-weight:550">{{ $this->progressLabel }}</p>

                @if ($run->total_estimate)
                    <div class="bar @if($status === 'completed') is-done @endif" style="width:100%; height:10px">
                        <span style="width: {{ $run->progressPercent() }}%"></span>
                    </div>
                    <div class="muted" style="font-size:13px; margin-top:7px">
                        {{ number_format($run->processed_count) }} of {{ number_format($run->total_estimate) }}
                        ({{ $run->progressPercent() }}%)
                    </div>
                @endif

                @if (! empty($run->meta['parameter_summary']))
                    <div class="muted" style="font-size:13px; margin-top:12px">
                        Started with — {{ $run->meta['parameter_summary'] }}
                    </div>
                @endif

                @if ($run->failed_count > 0)
                    <div class="note note-bad" style="margin:16px 0 0">
                        <x-backfill::icon name="warning" />
                        <div>
                            <strong>{{ number_format($run->failed_count) }} rows could not be processed.</strong>
                            Everything else went through. Ask a developer to look at the details.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
