<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushPostsTest extends TestCase
{
    /** @var list<string> Folders made by stage(), removed after each test. */
    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.blog_api.url' => 'https://blog.example.test/',
            'services.blog_api.token' => 'secret-token',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->staged as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    /**
     * Writes paired posts into a fresh folder, as `ImportPostsTest` does.
     *
     * @param  array<string, array<string, array<string, string>>>  $posts  slug => language => header fields plus `body`
     */
    private function stage(array $posts): string
    {
        $dir = storage_path('framework/testing/push-'.uniqid());
        $this->staged[] = $dir;

        foreach ($posts as $slug => $languages) {
            foreach ($languages as $lang => $post) {
                File::ensureDirectoryExists("{$dir}/{$lang}");

                $header = collect($post)->except('body')->map(fn ($value, $key) => "{$key}: {$value}")->implode("\n");
                File::put("{$dir}/{$lang}/{$slug}.md", "---\n{$header}\n---\n\n{$post['body']}\n");
            }
        }

        return $dir;
    }

    /**
     * @param  array<string, string>  $english
     * @return array<string, array<string, string>>
     */
    private function pair(string $title, array $english = []): array
    {
        return [
            'en' => ['title' => $title, 'description' => "About {$title}.", ...$english, 'body' => 'Body text.'],
            'id' => ['title' => "Judul {$title}", 'description' => "Tentang {$title}.", 'body' => 'Isi teks.'],
        ];
    }

    /**
     * Answers like the API: a created post for a slug with no `existing`
     * mark, and an unchanged one otherwise.
     *
     * @param  array<string, array{status: int, body: array<string, mixed>}>  $answers  By post slug, or `tag:<slug>` for a tag, to override the default.
     * @param  list<string>  $tags  The slugs the blog already has.
     */
    private function fakeApi(array $answers = [], array $tags = ['mysql', 'stardust']): void
    {
        Http::fake(function (Request $request) use ($answers, $tags) {
            $dry = str_contains($request->url(), 'dry_run=1');

            if (preg_match('#/api/v1/tags/([^/?]+)#', $request->url(), $match)) {
                if (isset($answers["tag:{$match[1]}"])) {
                    return Http::response($answers["tag:{$match[1]}"]['body'], $answers["tag:{$match[1]}"]['status']);
                }

                return Http::response([
                    'data' => ['slug' => $match[1], 'en' => $request->data()['en'] ?? null, 'id' => $request->data()['id'] ?? null],
                    'meta' => ['action' => 'created', 'dry_run' => $dry],
                ], $dry ? 200 : 201);
            }

            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/v1/tags')) {
                return Http::response(['data' => array_map(fn (string $slug): array => ['slug' => $slug, 'en' => $slug, 'id' => $slug], $tags)]);
            }

            preg_match('#/api/v1/posts/([^/?]+)#', $request->url(), $match);
            $slug = $match[1];

            if (isset($answers[$slug])) {
                return Http::response($answers[$slug]['body'], $answers[$slug]['status']);
            }

            return Http::response([
                'data' => ['slug' => $slug, 'status' => 'draft'],
                'meta' => ['action' => 'created', 'changes' => ['title_en'], 'warnings' => [], 'dry_run' => str_contains($request->url(), 'dry_run=1')],
            ], str_contains($request->url(), 'dry_run=1') ? 200 : 201);
        });
    }

    private function push(string $dir, array $options = []): array
    {
        $code = Artisan::call('posts:push', ['path' => $dir, ...$options]);

        return [$code, Artisan::output()];
    }

    public function test_it_needs_a_token(): void
    {
        config(['services.blog_api.token' => null]);
        Http::fake();

        [$code, $output] = $this->push($this->stage(['hello' => $this->pair('Hello')]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('BLOG_API_TOKEN is not set', $output);
        Http::assertNothingSent();
    }

    public function test_it_checks_every_post_first_and_then_writes_each_one(): void
    {
        $this->fakeApi();

        [$code, $output] = $this->push($this->stage(['one' => $this->pair('One'), 'two' => $this->pair('Two')]));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created one (draft)', $output);
        $this->assertStringContainsString('Created two (draft)', $output);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'https://blog.example.test/api/v1/posts/one?dry_run=1');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://blog.example.test/api/v1/posts/one');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_it_sends_the_text_the_date_and_the_tags_and_no_status_or_author(): void
    {
        $this->fakeApi();
        $dir = $this->stage(['hello' => $this->pair('Hello', ['published' => '"2026-11-17 01:00"', 'tags' => '[mysql, stardust]', 'author' => 'A Writer'])]);

        $this->push($dir);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://blog.example.test/api/v1/posts/hello'
                && $body['en'] === ['title' => 'Hello', 'description' => 'About Hello.', 'body' => "Body text.\n"]
                && $body['id']['title'] === 'Judul Hello'
                && $body['published_at'] === '2026-11-17T01:00:00Z'
                && $body['tags'] === ['mysql', 'stardust']
                && ! array_key_exists('status', $body)
                && ! array_key_exists('author', $body);
        });
    }

    public function test_it_leaves_out_the_date_and_the_tags_when_the_file_has_none(): void
    {
        $this->fakeApi();

        $this->push($this->stage(['hello' => $this->pair('Hello')]));

        Http::assertSent(fn (Request $r) => ! array_key_exists('published_at', $r->data()) && ! array_key_exists('tags', $r->data()));
    }

    public function test_publish_sends_the_status(): void
    {
        $this->fakeApi();

        $this->push($this->stage(['hello' => $this->pair('Hello')]), ['--publish' => true]);

        Http::assertSent(fn (Request $r) => ($r->data()['status'] ?? null) === 'published');
    }

    public function test_a_dry_run_sends_only_checks(): void
    {
        $this->fakeApi();

        [$code, $output] = $this->push($this->stage(['hello' => $this->pair('Hello')]), ['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Would create hello (draft)', $output);
        $this->assertStringContainsString('Dry run, nothing was written.', $output);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dry_run=1'));
    }

    public function test_a_refusal_in_the_check_stops_the_run_before_any_write(): void
    {
        $this->fakeApi(['bad' => [
            'status' => 422,
            'body' => [
                'message' => 'A post dated in the future has to stay a draft.',
                'code' => 'future_date_requires_draft',
                'errors' => ['published_at' => ['A post dated in the future has to stay a draft.']],
            ],
        ]]);

        [$code, $output] = $this->push($this->stage(['good' => $this->pair('Good'), 'bad' => $this->pair('Bad')]), ['--publish' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refused bad (future_date_requires_draft)', $output);
        $this->assertStringContainsString('published_at: A post dated in the future has to stay a draft.', $output);
        $this->assertStringContainsString('Nothing was pushed.', $output);
        Http::assertNotSent(fn (Request $r) => ! str_contains($r->url(), 'dry_run=1'));
    }

    public function test_it_prints_what_changed_and_the_style_warnings(): void
    {
        $this->fakeApi([
            'edited' => ['status' => 200, 'body' => [
                'data' => ['slug' => 'edited', 'status' => 'draft'],
                'meta' => ['action' => 'updated', 'changes' => ['title_en', 'tags'], 'warnings' => ['en.body: Line 1 has an em or en dash.']],
            ]],
            'same' => ['status' => 200, 'body' => [
                'data' => ['slug' => 'same', 'status' => 'draft'],
                'meta' => ['action' => 'unchanged', 'changes' => [], 'warnings' => []],
            ]],
        ]);

        [, $output] = $this->push($this->stage(['edited' => $this->pair('Edited'), 'same' => $this->pair('Same')]));

        $this->assertStringContainsString('Updated edited (title_en, tags)', $output);
        $this->assertStringContainsString('edited en.body: Line 1 has an em or en dash.', $output);
        $this->assertStringContainsString('Unchanged same', $output);
    }

    public function test_a_refused_token_is_reported_plainly(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        [$code, $output] = $this->push($this->stage(['hello' => $this->pair('Hello')]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The blog refused the token', $output);
        Http::assertSentCount(1);
    }

    public function test_an_unreachable_blog_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6'));

        [$code, $output] = $this->push($this->stage(['hello' => $this->pair('Hello')]));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Could not reach https://blog.example.test', $output);
    }

    public function test_a_bad_file_stops_the_run_before_any_request(): void
    {
        Http::fake();
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => 'mysql'])]);

        [$code, $output] = $this->push($dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('en/hello.md has a tags line that is not a list of tag slugs.', $output);
        Http::assertNothingSent();
    }

    public function test_a_file_name_that_is_not_a_slug_stops_the_run_before_any_request(): void
    {
        Http::fake();

        foreach (['Bad_Name', 'with.dot', 'two--hyphens', '-leading', 'a%3Fdry_run%3D1'] as $name) {
            [$code, $output] = $this->push($this->stage([$name => $this->pair('Hello')]));

            $this->assertSame(1, $code, $name);
            $this->assertStringContainsString("{$name}.md is not a usable slug.", $output, $name);
        }

        Http::assertNothingSent();
    }

    /** The requests made so far, as `METHOD path`, with `?dry` on the checks. */
    private function requests(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): string => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH).(str_contains($pair[0]->url(), 'dry_run=1') ? '?dry' : ''))
            ->all();
    }

    private function tagsYaml(string $dir, string $yaml): void
    {
        File::put("{$dir}/tags.yml", $yaml);
    }

    public function test_it_creates_a_missing_tag_the_folder_defines_before_it_writes_the_posts(): void
    {
        $this->fakeApi(tags: ['mysql']);
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => '[mysql, software-development]'])]);
        $this->tagsYaml($dir, "software-development:\n  en: Software development\n  id: Pengembangan software\n");

        [$code, $output] = $this->push($dir);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Created tag software-development', $output);
        $this->assertStringContainsString('Created hello (draft)', $output);
        $this->assertSame([
            'GET /api/v1/tags',
            'PUT /api/v1/tags/software-development?dry',
            'PUT /api/v1/tags/software-development',
            'PUT /api/v1/posts/hello?dry',
            'PUT /api/v1/posts/hello',
        ], $this->requests());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/tags/software-development')
            && $r->data() === ['en' => 'Software development', 'id' => 'Pengembangan software']);
    }

    public function test_a_tag_that_exists_is_not_sent_again_even_when_the_folder_defines_it(): void
    {
        $this->fakeApi(tags: ['mysql']);
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => '[mysql]'])]);
        $this->tagsYaml($dir, "mysql:\n  en: MySQL\n  id: MySQL\n");

        $this->push($dir);

        $this->assertNotContains('PUT /api/v1/tags/mysql', $this->requests());
        $this->assertNotContains('PUT /api/v1/tags/mysql?dry', $this->requests());
    }

    public function test_a_tag_the_folder_does_not_define_stops_the_run_before_any_write(): void
    {
        $this->fakeApi(tags: ['mysql']);
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => '[mysql, typo-tag]'])]);

        [$code, $output] = $this->push($dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No tag has the slug typo-tag, and tags.yml does not define it.', $output);
        $this->assertStringContainsString('Nothing was pushed.', $output);
        $this->assertSame(['GET /api/v1/tags'], $this->requests());
    }

    public function test_a_definition_no_post_uses_is_never_created(): void
    {
        $this->fakeApi();
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => '[mysql]'])]);
        $this->tagsYaml($dir, "unused:\n  en: Unused\n  id: Tidak dipakai\n");

        $this->push($dir);

        $this->assertNotContains('PUT /api/v1/tags/unused', $this->requests());
        $this->assertNotContains('PUT /api/v1/tags/unused?dry', $this->requests());
    }

    public function test_a_dry_run_checks_the_new_tags_and_lets_their_posts_through_the_check(): void
    {
        $this->fakeApi(['hello' => [
            'status' => 422,
            'body' => ['message' => 'No tag has the slug software-development.', 'code' => 'unknown_tag'],
        ]], tags: []);
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => '[software-development]'])]);
        $this->tagsYaml($dir, "software-development:\n  en: Software development\n  id: Pengembangan software\n");

        [$code, $output] = $this->push($dir, ['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Would create tag software-development', $output);
        $this->assertStringContainsString('Would push hello once its new tags exist', $output);
        $this->assertStringContainsString('Dry run, nothing was written.', $output);
        $this->assertSame([
            'GET /api/v1/tags',
            'PUT /api/v1/tags/software-development?dry',
            'PUT /api/v1/posts/hello?dry',
        ], $this->requests());
    }

    public function test_a_refused_tag_stops_the_run_before_any_tag_or_post_is_written(): void
    {
        $this->fakeApi(['tag:software-development' => [
            'status' => 403,
            'body' => ['message' => 'Your account may not create tags. Ask an Editor or an Admin.', 'code' => 'not_allowed'],
        ]], tags: []);
        $dir = $this->stage(['hello' => $this->pair('Hello', ['tags' => '[software-development]'])]);
        $this->tagsYaml($dir, "software-development:\n  en: Software development\n  id: Pengembangan software\n");

        [$code, $output] = $this->push($dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refused tag software-development (not_allowed)', $output);
        $this->assertStringContainsString('Nothing was pushed.', $output);
        $this->assertSame(['GET /api/v1/tags', 'PUT /api/v1/tags/software-development?dry'], $this->requests());
    }

    public function test_a_tags_file_that_is_not_valid_stops_the_run_before_any_request(): void
    {
        Http::fake();

        foreach ([
            "Bad_Slug:\n  en: A\n  id: B\n" => 'is not a slug',
            "software-development:\n  en: Only English\n" => 'something other than an English and an Indonesian name',
            "software-development:\n  en: ''\n  id: B\n" => 'has no en name',
            "- just\n- a list\n" => 'must map each tag slug',
            "software-development: [unclosed\n" => 'is not valid YAML',
        ] as $yaml => $message) {
            $dir = $this->stage(['hello' => $this->pair('Hello')]);
            $this->tagsYaml($dir, $yaml);

            [$code, $output] = $this->push($dir);

            $this->assertSame(1, $code, $yaml);
            $this->assertStringContainsString($message, $output, $yaml);
        }

        Http::assertNothingSent();
    }

    public function test_a_post_with_no_tags_line_makes_no_request_about_tags(): void
    {
        $this->fakeApi();

        $this->push($this->stage(['hello' => $this->pair('Hello')]));

        $this->assertNotContains('GET /api/v1/tags', $this->requests());
    }

    public function test_a_file_with_no_twin_stops_the_run(): void
    {
        Http::fake();
        $dir = $this->stage(['hello' => $this->pair('Hello')]);
        File::delete("{$dir}/id/hello.md");
        File::ensureDirectoryExists("{$dir}/id");

        [$code, $output] = $this->push($dir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('en/hello.md has no id/hello.md', $output);
        Http::assertNothingSent();
    }
}
