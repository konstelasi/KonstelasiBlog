<p class="dash-line">
    @if (! $enabled)
        Automatic rebuilds are not set up on this server.
    @elseif ($requestedAt)
        The public site was last asked to rebuild {{ $requestedAt->diffForHumans() }}.
    @else
        No rebuild has been requested since this was set up.
    @endif

    @if ($enabled)
        <a class="profile-act" href="{{ $actionsUrl }}" target="_blank" rel="noopener">See builds on GitHub</a>
    @endif
</p>
