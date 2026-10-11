@php
    use Illuminate\Support\Str;

    $initials = Str::of($user->name)->explode(' ')->filter()->take(2)->map(fn ($word) => Str::upper(Str::substr($word, 0, 1)))->implode('');
@endphp

<div class="profile-who">
    <span class="profile-mono" aria-hidden="true">{{ $initials }}</span>
    <div>
        <h1>
            {{ $user->name }}
            <span class="profile-role">{{ $role }}</span>
        </h1>
        <p>{{ $user->email }}</p>
    </div>
</div>
