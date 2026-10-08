<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The house writing rules that a machine can check, ported from
 * `scripts/verify-copy.ts` in the KonstelasiWebsite repository. Posts no
 * longer live in that repository's `src/`, so its `npm run verify:copy`
 * never sees them, and the admin enforces the same rules instead.
 *
 * Every field gets the dash rule. A title also gets the heading rule, and
 * a Markdown body gets the heading rule on each of its `#` headings.
 *
 * One rule here is not a port. A Markdown body also rejects an image with
 * empty alt text, because the admin is where images are added and a screen
 * reader has nothing to say about `![](...)`. `verify-copy.ts` has no such
 * check, so this is the only place it is enforced.
 */
class HouseStyle implements ValidationRule
{
    public const TEXT = 'text';

    public const TITLE = 'title';

    public const MARKDOWN = 'markdown';

    /** An em dash or an en dash, the same set verify-copy.ts looks for. */
    private const DASH = '/[\x{2013}\x{2014}]/u';

    /**
     * A hyphen, or two, with a space on both sides and text before it on the
     * line, as in "fast - and small". A list bullet at the start of a line,
     * a hyphenated word such as "end-to-end" and an empty table cell such as
     * "| - |" don't match. verify-copy.ts leaves this one to review, but
     * nobody reviews a post after the admin saves it.
     */
    private const SPACED_HYPHEN = '/(?<=[^\s|])[ \t]+-{1,2}[ \t]+(?=[^\s|])/u';

    private const TITLE_PUNCT = '/[:;]/';

    /** A Markdown image whose alt text, the part in square brackets, is empty. */
    private const EMPTY_ALT = '/!\[\s*\]\([^)]*\)/u';

    /** Characters of text kept on each side of a match when a message quotes it. */
    private const CONTEXT = 15;

    public function __construct(private string $kind = self::TEXT) {}

    public static function text(): self
    {
        return new self(self::TEXT);
    }

    public static function title(): self
    {
        return new self(self::TITLE);
    }

    public static function markdown(): self
    {
        return new self(self::MARKDOWN);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        foreach (self::problems($value, $this->kind) as $problem) {
            $fail($problem);
        }
    }

    /**
     * Every rule the text breaks, as sentences for a validation message.
     *
     * @return list<string>
     */
    public static function problems(string $value, string $kind = self::TEXT): array
    {
        $problems = [];
        $lines = preg_split('/\r\n|\n|\r/', $value);

        // Each preg_match below uses PREG_OFFSET_CAPTURE, so a match is the
        // text and its byte offset.
        /** @var array<int, array{0: string, 1: int}> $match */
        $match = [];

        // verify-copy.ts flags a dash on any line, code included.
        foreach ($lines as $i => $line) {
            if (preg_match(self::DASH, $line, $match, PREG_OFFSET_CAPTURE)) {
                $problems[] = self::where($kind, $i, count($lines)).'has an em or en dash'.self::near($line, $match[0]).'. Use a comma, a full stop or parentheses instead.';
                break;
            }
        }

        $inFence = false;
        $spacedHyphen = false;
        $heading = false;
        $emptyAlt = false;

        foreach ($lines as $i => $line) {
            if ($kind === self::MARKDOWN && str_starts_with(trim($line), '```')) {
                $inFence = ! $inFence;

                continue;
            }
            if ($inFence) {
                continue;
            }

            // Inline code may hold a command such as `a - b`, so it is left out.
            $prose = $kind === self::MARKDOWN ? preg_replace('/`[^`]*`/', '', $line) : $line;
            if (! $spacedHyphen && preg_match(self::SPACED_HYPHEN, $prose, $match, PREG_OFFSET_CAPTURE)) {
                $spacedHyphen = true;
                $problems[] = self::where($kind, $i, count($lines)).'has a spaced hyphen standing in for a dash'.self::near($prose, $match[0]).'. Use a comma, a full stop or parentheses instead.';
            }

            if (! $emptyAlt && $kind === self::MARKDOWN && preg_match(self::EMPTY_ALT, $prose, $match, PREG_OFFSET_CAPTURE)) {
                $emptyAlt = true;
                $problems[] = self::where($kind, $i, count($lines)).'has an image with no alt text'.self::near($prose, $match[0]).'. Describe the image between the square brackets.';
            }

            if (! $heading && $kind === self::MARKDOWN && preg_match('/^#{1,6}\s/', $line)
                && preg_match(self::TITLE_PUNCT, preg_replace('/^#+\s*/', '', $line))) {
                $heading = true;
                $problems[] = 'Line '.($i + 1).' is a heading with a colon or semicolon, "'.mb_strimwidth(trim($line), 0, 60).'". Headings take neither.';
            }
        }

        if ($kind === self::TITLE && preg_match(self::TITLE_PUNCT, $value, $match, PREG_OFFSET_CAPTURE)) {
            $problems[] = 'A title takes no colon or semicolon'.self::near($value, $match[0]).'.';
        }

        return $problems;
    }

    /**
     * A few words around a match, quoted, so the writer can find it without
     * counting lines. `$match` is one entry of a PREG_OFFSET_CAPTURE result,
     * the matched text and its byte offset.
     *
     * @param  array{0: string, 1: int}  $match
     */
    private static function near(string $line, array $match): string
    {
        [$text, $byteOffset] = $match;
        $start = mb_strlen(substr($line, 0, $byteOffset));
        $length = mb_strlen($line);
        $from = max(0, $start - self::CONTEXT);
        $to = min($length, $start + mb_strlen($text) + self::CONTEXT);

        // Grow each edge to the end of a word, so the quote never starts or
        // stops halfway through one.
        while ($from > 0 && ! preg_match('/\s/u', mb_substr($line, $from - 1, 1))) {
            $from--;
        }
        while ($to < $length && ! preg_match('/\s/u', mb_substr($line, $to, 1))) {
            $to++;
        }

        return ' near "'.trim(mb_substr($line, $from, $to - $from)).'"';
    }

    private static function where(string $kind, int $index, int $total): string
    {
        return $kind === self::MARKDOWN && $total > 1 ? 'Line '.($index + 1).' ' : 'This field ';
    }
}
