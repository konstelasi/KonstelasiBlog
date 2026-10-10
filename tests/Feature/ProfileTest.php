<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_role_and_cannot_change_it(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(Profile::class)
            ->assertFormFieldDisabled('role')
            ->assertSchemaStateSet(['role' => 'Editor'])
            ->fillForm(['role' => 'Admin'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['editor'], auth()->user()->fresh()->getRoleNames()->all());
    }

    public function test_changing_the_name_says_how_many_posts_it_touches(): void
    {
        $writer = User::factory()->writer()->create(['name' => 'Rani Putri']);
        Post::factory()->published()->create(['user_id' => $writer->id]);
        Post::factory()->create(['user_id' => $writer->id]);
        Post::factory()->published()->create();
        $this->actingAs($writer);

        Livewire::test(Profile::class)
            ->assertSee('Printed to readers as the author of your posts.')
            ->set('data.name', 'Rani P.')
            ->assertSee('This changes the author name on 2 posts, 1 of them live.')
            ->set('data.name', 'Rani Putri')
            ->assertDontSee('This changes the author name');
    }

    public function test_the_warning_is_silent_for_a_writer_with_no_posts(): void
    {
        $this->actingAs(User::factory()->writer()->create(['name' => 'Rani Putri']));

        Livewire::test(Profile::class)
            ->set('data.name', 'Rani P.')
            ->assertDontSee('This changes the author name');
    }

    public function test_renaming_a_writer_with_a_live_post_starts_a_rebuild(): void
    {
        $writer = User::factory()->writer()->create(['name' => 'Rani Putri']);
        Post::factory()->published()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);
        Bus::fake();

        Livewire::test(Profile::class)
            ->fillForm(['name' => 'Rani P.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Rani P.', $writer->fresh()->name);
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_renaming_a_writer_with_only_drafts_starts_nothing(): void
    {
        $writer = User::factory()->writer()->create(['name' => 'Rani Putri']);
        Post::factory()->create(['user_id' => $writer->id]);
        $this->actingAs($writer);
        Bus::fake();

        Livewire::test(Profile::class)
            ->fillForm(['name' => 'Rani P.'])
            ->call('save')
            ->assertHasNoFormErrors();

        Bus::assertNotDispatchedAfterResponse(RebuildSite::class);
    }

    public function test_the_users_screen_rename_also_starts_a_rebuild(): void
    {
        $writer = User::factory()->writer()->create(['name' => 'Rani Putri']);
        Post::factory()->published()->create(['user_id' => $writer->id]);
        Bus::fake();

        $writer->update(['name' => 'Rani P.']);
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);

        $writer->update(['email' => 'new@example.com']);
        Bus::assertDispatchedAfterResponse(RebuildSite::class, 1);
    }

    public function test_a_password_change_needs_the_current_password(): void
    {
        $user = User::factory()->writer()->create(['password' => 'the-old-password']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm([
                'password' => 'a-new-Passw0rd!',
                'passwordConfirmation' => 'a-new-Passw0rd!',
                'currentPassword' => 'not-my-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('the-old-password', $user->fresh()->password));

        Livewire::test(Profile::class)
            ->fillForm([
                'password' => 'a-new-Passw0rd!',
                'passwordConfirmation' => 'a-new-Passw0rd!',
                'currentPassword' => 'the-old-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-new-Passw0rd!', $user->fresh()->password));
    }

    public function test_an_email_change_needs_the_current_password_when_mail_is_off(): void
    {
        $user = User::factory()->writer()->create(['email' => 'old@example.com', 'password' => 'the-old-password']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'wrong'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertSame('old@example.com', $user->fresh()->email);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'the-old-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }
}
