<?php

namespace App\Support;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * What the post will read like on the website, built from the form's current
 * state, so unsaved changes show too. It is a plain class so every part can be
 * tested without a page, like `ProfileOverview` and `DashboardOverview`.
 *
 * The website turns Markdown into HTML with Astro's default pipeline, which is
 * GitHub-flavoured Markdown plus smart punctuation (curly quotes and real
 * ellipses). `markdown()` uses the same two extensions. Two things differ on
 * purpose. Code blocks are not highlighted here, and the HTML is cleaned, so a
 * Writer's draft cannot run a script in an Admin's session when the Admin
 * opens the preview.
 */
class PostPreview
{
    /**
     * The words of the post page itself, in each language, as `Post.astro`
     * has them. Change both together.
     */
    private const COPY = [
        'en' => [
            'all' => 'All posts',
            'published' => 'Published',
            'author' => 'Written by',
            'reading' => 'Reading time',
            'topics' => 'Topics',
            'minute' => 'minute',
            'minutes' => 'minutes',
            'unset' => 'Set when published',
            'locale' => 'en',
        ],
        'id' => [
            'all' => 'Semua tulisan',
            'published' => 'Terbit',
            'author' => 'Penulis',
            'reading' => 'Waktu baca',
            'topics' => 'Topik',
            'minute' => 'menit',
            'minutes' => 'menit',
            'unset' => 'Diatur saat terbit',
            'locale' => 'id',
        ],
    ];

    /**
     * @param  array<string, mixed>  $state  The form's raw state, validated or not.
     */
    public function __construct(
        private readonly array $state,
        private readonly ?Post $record = null,
        private readonly ?User $viewer = null,
    ) {}

    /** @param  array<string, mixed>  $state */
    public static function fromState(array $state, ?Post $record = null): self
    {
        return new self($state, $record, auth()->user());
    }

    public function title(string $lang): string
    {
        return $this->text("title_{$lang}");
    }

    public function description(string $lang): string
    {
        return $this->text("description_{$lang}");
    }

    public function body(string $lang): string
    {
        return (string) ($this->state["body_{$lang}"] ?? '');
    }

    /** Whether nothing has been written in this language yet. */
    public function isEmpty(string $lang): bool
    {
        return $this->title($lang) === '' && $this->description($lang) === '' && trim($this->body($lang)) === '';
    }

    public function html(string $lang): string
    {
        return self::markdown($this->body($lang));
    }

    /** The reading time as the page prints it, such as "3 minutes". */
    public function readingTime(string $lang): string
    {
        $minutes = Post::readingMinutes($this->body($lang));
        $copy = self::COPY[$lang];

        return $minutes.' '.($minutes === 1 ? $copy['minute'] : $copy['minutes']);
    }

    /**
     * The date as the page prints it, in Jakarta time. A draft with no date
     * has none to show, and a post about to be published without one takes
     * the moment it goes out, as the form says.
     */
    public function published(string $lang): string
    {
        $copy = self::COPY[$lang];
        $date = $this->state['published_at'] ?? null;

        if (filled($date)) {
            $at = Carbon::parse($date, config('app.timezone'));
        } elseif ($this->isPublishing()) {
            $at = now();
        } else {
            return $copy['unset'];
        }

        return $at->timezone('Asia/Jakarta')->locale($copy['locale'])->translatedFormat('j F Y');
    }

    /** The name the page prints as the author. */
    public function writer(): string
    {
        return $this->record?->byline() ?: ($this->viewer?->name ?: Post::DEFAULT_AUTHOR);
    }

    /**
     * The names of the chosen tags in this language, by name. The page links
     * a tag only once two live posts carry it, which a preview can't know, so
     * they are plain text here.
     *
     * @return list<string>
     */
    public function tags(string $lang): array
    {
        $ids = array_filter((array) ($this->state['tags'] ?? []));

        if ($ids === []) {
            return [];
        }

        return Tag::query()->whereKey($ids)->get()
            ->map(fn (Tag $tag): string => $lang === 'id' ? $tag->name_id : $tag->name_en)
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function copy(string $lang): array
    {
        return self::COPY[$lang];
    }

    /**
     * Markdown to cleaned HTML. GitHub-flavoured tables, strikethrough and
     * autolinks and smart punctuation, as on the website. Links with a
     * script scheme are dropped while the Markdown is read, and the sanitiser
     * then removes script, style, frames, forms and event attributes from
     * whatever HTML the text carried.
     */
    public static function markdown(string $source): string
    {
        if (trim($source) === '') {
            return '';
        }

        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new SmartPunctExtension);

        $html = (string) (new MarkdownConverter($environment))->convert($source);

        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->allowLinkSchemes(['http', 'https', 'mailto'])
                ->allowMediaSchemes(['http', 'https'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer'),
        );

        return $sanitizer->sanitize($html);
    }

    private function text(string $key): string
    {
        return trim((string) ($this->state[$key] ?? ''));
    }

    private function isPublishing(): bool
    {
        $status = $this->state['status'] ?? null;
        $status = $status instanceof PostStatus ? $status : PostStatus::tryFrom((string) $status);

        return $status === PostStatus::Published;
    }
}
