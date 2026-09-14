<?php

use App\Models\Lead;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        return Lead::latest()->paginate(20);
    }

    public function markContacted(int $leadId): void
    {
        Lead::whereKey($leadId)->update(['contacted_at' => now()]);
    }
}; ?>

<div>
    <flux:heading size="xl">{{ __('Leads') }}</flux:heading>
    <flux:subheading>{{ __('Funeral homes that requested a demo from the marketing site.') }}</flux:subheading>

    <div class="mt-6 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                <tr>
                    <th class="px-4 py-3">{{ __('Received') }}</th>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Funeral home') }}</th>
                    <th class="px-4 py-3">{{ __('Contact') }}</th>
                    <th class="px-4 py-3">{{ __('Message') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->leads() as $lead)
                    <tr wire:key="lead-{{ $lead->id }}" class="align-top">
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $lead->created_at->format('M j, Y') }}</td>
                        <td class="px-4 py-3 font-medium">{{ $lead->name }}</td>
                        <td class="px-4 py-3">{{ $lead->funeral_home_name ?: '—' }}</td>
                        <td class="px-4 py-3">
                            <p>{{ $lead->email }}</p>
                            @if ($lead->phone)
                                <p class="text-zinc-500 dark:text-zinc-400">{{ $lead->phone }}</p>
                            @endif
                        </td>
                        <td class="max-w-xs px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ $lead->message }}</td>
                        <td class="px-4 py-3">
                            @if ($lead->contacted_at)
                                <flux:badge size="sm" color="green">{{ __('Contacted') }}</flux:badge>
                            @else
                                <flux:button size="sm" variant="ghost" wire:click="markContacted({{ $lead->id }})">{{ __('Mark contacted') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No leads yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->leads()->links() }}
    </div>
</div>
