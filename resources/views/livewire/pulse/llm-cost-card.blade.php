<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="LLM API Usage & Costs"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if (empty($costStats))
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="40%" />
                    <col width="20%" />
                    <col width="20%" />
                    <col width="20%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Client</x-pulse::th>
                        <x-pulse::th class="text-right">Jobs Processed</x-pulse::th>
                        <x-pulse::th class="text-right">Total Tokens</x-pulse::th>
                        <x-pulse::th class="text-right">Est. Cost (USD)</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($costStats as $stat)
                        <tr wire:key="{{ $stat['client_name'] }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $stat['client_name'] }}-row">
                            <x-pulse::td class="overflow-hidden">
                                <span class="font-bold text-gray-900 dark:text-gray-100">{{ $stat['client_name'] }}</span>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ number_format($stat['job_count']) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ number_format($stat['total_tokens']) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="{{ $stat['total_cost'] > 10 ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-700 dark:text-gray-300 font-bold' }}">
                                ${{ number_format($stat['total_cost'], 4) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
