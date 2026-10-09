@props(['detail', 'revealedSsn' => null])

{{--
    A family's Vital Statistics for staff and admins. The Social Security
    number shows masked until the viewer clicks Reveal, which calls the
    parent component's revealSsn() action (and is logged there).
--}}
<div {{ $attributes->class('rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900') }}>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="sm" class="text-zinc-500">{{ __('Vital Statistics') }}</flux:heading>
        @if ($detail->isSubmitted())
            <flux:badge color="green" size="sm">{{ __('Submitted :date', ['date' => $detail->submitted_at->format('M j, Y')]) }}</flux:badge>
        @else
            <flux:badge color="amber" size="sm">{{ __('In progress — not submitted yet') }}</flux:badge>
        @endif
    </div>

    @if ($detail->has_pacemaker)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4" :heading="__('Pacemaker and/or defibrillator reported. It must be removed before cremation.')" />
    @endif

    @foreach ($detail->sections() as $heading => $answers)
        <div class="mt-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ __($heading) }}</p>
            <dl class="mt-2 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                @foreach ($answers as $label => $value)
                    <div>
                        <dt class="text-zinc-400">{{ __($label) }}</dt>
                        <dd class="text-zinc-800 dark:text-zinc-100">
                            @if ($label === \App\Models\OrderDetail::SSN_LABEL)
                                <span class="font-mono">{{ $revealedSsn ?? $value }}</span>
                                @unless ($revealedSsn)
                                    <button type="button" wire:click="revealSsn" class="ml-2 text-xs underline hover:text-zinc-600">{{ __('Reveal') }}</button>
                                @endunless
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach

    @foreach (['obituary_text' => __('Memorial Story'), 'service_preferences' => __('Service preferences'), 'additional_notes' => __('Anything else')] as $column => $heading)
        @if ($detail->{$column})
            <div class="mt-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-400">{{ $heading }}</p>
                <p class="mt-2 whitespace-pre-line text-sm text-zinc-800 dark:text-zinc-100">{{ $detail->{$column} }}</p>
            </div>
        @endif
    @endforeach
</div>
