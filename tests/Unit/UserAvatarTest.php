<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserAvatarTest extends TestCase
{
    public function test_initials_are_the_first_letters_of_the_first_two_words(): void
    {
        $this->assertSame('DS', (new User(['name' => 'Damar Syah Maulana']))->initials());
        $this->assertSame('B', (new User(['name' => '  budi  ']))->initials());
        $this->assertSame('', (new User(['name' => '']))->initials());
    }

    public function test_the_avatar_is_drawn_locally_and_carries_the_initials(): void
    {
        $url = (new User(['name' => 'Damar Syah Maulana']))->getFilamentAvatarUrl();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $this->assertStringContainsString('>DS</text>', base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))));
        $this->assertStringNotContainsString('ui-avatars', $url);
    }

    public function test_a_name_cannot_break_out_of_the_drawing(): void
    {
        $url = (new User(['name' => '<script>alert(1)</script>']))->getFilamentAvatarUrl();
        $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;', $svg);
    }
}
