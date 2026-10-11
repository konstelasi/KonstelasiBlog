{{-- Which section shows is the page's `section` property, which lives in the URL. --}}
<nav class="profile-sub" aria-label="Profile sections">
    @foreach ($items as $key => $item)
        <button
            type="button"
            wire:click="$set('section', '{{ $key }}')"
            @if ($key === $current) aria-current="page" @endif
        >
            {{ $item['label'] }}
            <small>{{ $item['hint'] }}</small>
        </button>
    @endforeach
</nav>
