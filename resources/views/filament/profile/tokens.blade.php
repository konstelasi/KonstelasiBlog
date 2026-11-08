{{-- The signed-in account's own tokens. Only a hash of each is stored, so no secret shows here. --}}
<div class="profile-tablewrap profile-tokens">
    <table class="profile-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Can do</th>
                <th>Last used</th>
                <th>Expires</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tokens as $token)
                <tr>
                    <td>{{ $token->name }}</td>
                    <td>{{ implode(', ', $token->abilityLabels()) }}</td>
                    <td>{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        {{ $token->expires_at?->format('j M Y') ?? 'Never' }}
                        @if ($token->isExpired())
                            <span class="profile-expired">Expired</span>
                        @endif
                    </td>
                    <td class="profile-token-act">
                        <button type="button" class="profile-act is-danger" wire:click="mountAction('revokeToken', { token: {{ $token->id }} })">Revoke</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">You have no tokens yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
