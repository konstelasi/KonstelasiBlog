<div class="profile-who">
    <span class="profile-mono" aria-hidden="true">{{ $user->initials() }}</span>
    <div>
        <h1>
            {{ $user->name }}
            <span class="profile-role">{{ $role }}</span>
        </h1>
        <p>{{ $user->email }}</p>
    </div>
</div>
