<x-pulse::card :cols="$cols" :rows="$rows" :class="$class" wire:poll.5s>
    <x-pulse::card-header name="Backfills">
        <x-slot:icon>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75" />
            </svg>
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand">
        @if ($runs->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Backfill</x-pulse::th>
                        <x-pulse::th class="text-right">Progress</x-pulse::th>
                        <x-pulse::th class="text-right">Status</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr wire:key="backfill-run-{{ $run->id }}">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $run->backfill }}">
                                    {{ $run->backfill }}@if ($run->tenant)<span class="text-gray-500"> · {{ $run->tenant }}</span>@endif
                                </code>
                                @if (! empty($run->meta['stop_reason']))
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $run->meta['stop_reason'] }}">
                                        {{ $run->meta['stop_reason'] }}
                                    </p>
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 text-sm">
                                @if ($run->total_estimate)
                                    {{ $run->progressPercent() }}%
                                @else
                                    {{ number_format($run->processed_count) }}
                                @endif
                                @if ($run->failed_count > 0)
                                    <span class="text-red-500">/ {{ number_format($run->failed_count) }} failed</span>
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-sm">
                                <span @class([
                                    'text-red-500' => in_array($run->status->value, ['failed', 'interrupted']),
                                    'text-yellow-500' => $run->status->value === 'paused',
                                    'text-gray-500' => ! in_array($run->status->value, ['failed', 'interrupted', 'paused']),
                                ])>
                                    {{ $run->isStale() ? 'stale' : $run->status->value }}
                                </span>
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
