{{-- The browsers this account is signed in on. Plain rows, no cards. --}}
<ul class="flex flex-col divide-y divide-gray-200 text-sm dark:divide-white/10">
    @forelse ($sessions as $session)
        <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
            <span class="font-medium text-gray-950 dark:text-white">
                {{ $session['description'] }}
                @if ($session['current'])
                    <span class="ms-2 rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-white/10 dark:text-primary-400">This browser</span>
                @endif
            </span>
            <span class="text-gray-600 dark:text-gray-400">
                {{ $session['ip'] ?: 'Unknown address' }}, last active {{ $session['lastActive']->diffForHumans() }}
            </span>
        </li>
    @empty
        <li class="py-2 text-gray-600 dark:text-gray-400">No active sessions found.</li>
    @endforelse
</ul>
