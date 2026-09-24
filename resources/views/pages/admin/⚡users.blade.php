<?php

use App\Models\User;
use App\Notifications\AdminInvitationNotification;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin users')] class extends Component
{
    public string $email = '';

    /**
     * Pre-approve an email address. The person gets access as soon as they
     * sign in with the matching Google account.
     */
    public function invite(): void
    {
        Gate::authorize('manage-admins');

        $this->email = Str::lower(trim($this->email));

        $this->validate(
            ['email' => ['required', 'email', 'max:255', 'unique:users,email']],
            ['email.unique' => __('That email already has an account or a pending request.')],
        );

        $invitedUser = new User([
            'name' => $this->email,
            'email' => $this->email,
            'avatar' => '',
        ]);
        $invitedUser->forceFill(['approved_at' => now()])->save();

        $invitedUser->notify(new AdminInvitationNotification(auth()->user()));

        $this->reset('email');
        unset($this->admins);

        Flux::toast(variant: 'success', text: __('Invitation sent.'));
    }

    public function approve(int $userId): void
    {
        Gate::authorize('manage-admins');

        User::awaitingApproval()->findOrFail($userId)->approve();

        unset($this->accessRequests, $this->admins);

        Flux::toast(variant: 'success', text: __('Access approved.'));
    }

    /**
     * Deny a pending request, cancel an invitation, or revoke an admin's
     * access. Super admins (including yourself) cannot be removed here.
     */
    public function remove(int $userId): void
    {
        Gate::authorize('manage-admins');

        User::where('is_super_admin', false)->findOrFail($userId)->delete();

        unset($this->accessRequests, $this->admins);

        Flux::toast(text: __('Access removed.'));
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function accessRequests(): Collection
    {
        return User::awaitingApproval()->oldest()->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function admins(): Collection
    {
        return User::approved()->orderByDesc('is_super_admin')->orderBy('name')->get();
    }
}; ?>

<div>
    <flux:heading size="xl">{{ __('Admin users') }}</flux:heading>
    <flux:subheading>{{ __('Signing in with Google does not grant access on its own. Invite people here, or approve their requests.') }}</flux:subheading>

    <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg">{{ __('Invite an admin') }}</flux:heading>
        <form wire:submit="invite" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            <flux:field class="flex-1">
                <flux:input type="email" wire:model="email" :placeholder="__('name@altmeyer.com')" required />
                <flux:error name="email" />
            </flux:field>
            <flux:button type="submit" variant="primary">{{ __('Send invitation') }}</flux:button>
        </form>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Access requests') }}</flux:heading>
    <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Email') }}</th>
                    <th class="px-4 py-3">{{ __('Requested') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @forelse ($this->accessRequests as $requester)
                    <tr wire:key="request-{{ $requester->id }}">
                        <td class="px-4 py-3 font-medium">{{ $requester->name }}</td>
                        <td class="px-4 py-3">{{ $requester->email }}</td>
                        <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">{{ $requester->created_at->format('M j, Y') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="primary" wire:click="approve({{ $requester->id }})">{{ __('Approve') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="remove({{ $requester->id }})" wire:confirm="{{ __('Deny this access request?') }}">{{ __('Deny') }}</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No one is waiting for approval.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Admins') }}</flux:heading>
    <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400 dark:border-zinc-700">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Email') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                @foreach ($this->admins as $admin)
                    <tr wire:key="admin-{{ $admin->id }}">
                        <td class="px-4 py-3 font-medium">{{ $admin->name }}</td>
                        <td class="px-4 py-3">{{ $admin->email }}</td>
                        <td class="px-4 py-3">
                            @if ($admin->is_super_admin)
                                <flux:badge size="sm" color="purple">{{ __('Super admin') }}</flux:badge>
                            @elseif ($admin->hasPendingInvitation())
                                <flux:badge size="sm" color="amber">{{ __('Invited') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @unless ($admin->is_super_admin)
                                <flux:button size="sm" variant="ghost" wire:click="remove({{ $admin->id }})" wire:confirm="{{ $admin->hasPendingInvitation() ? __('Cancel this invitation?') : __('Remove this admin\'s access?') }}">
                                    {{ $admin->hasPendingInvitation() ? __('Cancel invitation') : __('Remove access') }}
                                </flux:button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
