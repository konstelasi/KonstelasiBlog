<div class="profile-state">
    <strong><span @class(['profile-dot', 'is-on' => $enabled])></span>Two-factor sign-in is {{ $enabled ? 'on' : 'off' }}</strong>
    @if ($enabled)
        <p>
            You enter a six-digit code from your authenticator app after your password.
            @if ($recoveryCodes !== null)
                {{ $recoveryCodes === 1 ? '1 recovery code is left.' : $recoveryCodes.' recovery codes are left.' }}
                Each works once, in place of a code, if you lose your phone.
            @endif
        </p>
    @else
        <p>After your password you would also enter a six-digit code from an authenticator app such as Google Authenticator or Authy.</p>
        <ol>
            <li>Enter your current password.</li>
            <li>Scan the QR code with the app.</li>
            <li>Type the first code to confirm, then save your recovery codes.</li>
        </ol>
    @endif
</div>
