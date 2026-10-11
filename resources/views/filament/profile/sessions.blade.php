{{-- The browsers this account is signed in on. --}}
<div class="profile-tablewrap">
    <table class="profile-table">
        <thead>
            <tr><th>Browser</th><th>Address</th><th>Last active</th></tr>
        </thead>
        <tbody>
            @forelse ($sessions as $session)
                <tr>
                    <td>
                        {{ $session['description'] }}
                        @if ($session['current'])
                            <span class="profile-here">This browser</span>
                        @endif
                    </td>
                    <td>{{ $session['ip'] ?: 'Unknown address' }}</td>
                    <td>{{ $session['current'] ? 'Now' : $session['lastActive']->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No active sessions found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
