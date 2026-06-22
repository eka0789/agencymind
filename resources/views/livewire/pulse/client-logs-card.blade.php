<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Client Log Volumes"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if (empty($clientStats))
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="30%" />
                    <col width="15%" />
                    <col width="15%" />
                    <col width="15%" />
                    <col width="25%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Client / Tenant</x-pulse::th>
                        <x-pulse::th class="text-right">Total Logs</x-pulse::th>
                        <x-pulse::th class="text-right">Errors</x-pulse::th>
                        <x-pulse::th class="text-right">Warnings</x-pulse::th>
                        <x-pulse::th>Last Ingested Log</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($clientStats as $client)
                        <tr wire:key="{{ $client['slug'] }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $client['slug'] }}-row">
                            <x-pulse::td class="overflow-hidden">
                                <div class="flex items-center gap-2">
                                    @if ($client['status'] === 'active' && $client['error_logs'] === 0)
                                        <div class="h-2 w-2 rounded-full bg-green-500" title="All good"></div>
                                    @elseif ($client['status'] === 'active' && $client['error_logs'] > 0)
                                        <div class="h-2 w-2 rounded-full bg-red-500 animate-pulse" title="Contains error logs"></div>
                                    @else
                                        <div class="h-2 w-2 rounded-full bg-gray-400" title="Inactive or Error"></div>
                                    @endif
                                    <span class="font-bold text-gray-900 dark:text-gray-100">{{ $client['name'] }}</span>
                                    <code class="text-xs text-gray-400">({{ $client['slug'] }})</code>
                                </div>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($client['total_logs']) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="{{ $client['error_logs'] > 0 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-700 dark:text-gray-300' }}">
                                {{ number_format($client['error_logs']) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="{{ $client['warning_logs'] > 0 ? 'text-amber-600 dark:text-amber-400 font-bold' : 'text-gray-700 dark:text-gray-300' }}">
                                {{ number_format($client['warning_logs']) }}
                            </x-pulse::td>
                            <x-pulse::td class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                {{ $client['last_ingested_at'] }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
