<section class="dash-twofactor">
    <h2>Two-factor sign-in</h2>

    @if ($without->isEmpty())
        <p class="profile-lead">Every account uses it.</p>
    @else
        <p class="profile-lead">{{ $total - $without->count() }} of {{ $total }} {{ $total === 1 ? 'account uses' : 'accounts use' }} it.</p>
        <ul class="dash-plain">
            @foreach ($without as $account)
                <li>{{ $account->name }} <small>{{ \Illuminate\Support\Str::ucfirst((string) $account->roles->first()?->name) }}</small></li>
            @endforeach
        </ul>
    @endif
</section>
