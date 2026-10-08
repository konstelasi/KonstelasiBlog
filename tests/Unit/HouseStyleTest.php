<?php

namespace Tests\Unit;

use App\Rules\HouseStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HouseStyleTest extends TestCase
{
    public static function rejected(): array
    {
        return [
            'em dash in a body' => [HouseStyle::MARKDOWN, "First line.\n\nFast \u{2014} and small."],
            'en dash in a description' => [HouseStyle::TEXT, "Versions 2\u{2013}3"],
            'em dash inside a code fence' => [HouseStyle::MARKDOWN, "```\necho \u{2014}\n```"],
            'colon in a title' => [HouseStyle::TITLE, 'StarDust: the next step'],
            'semicolon in a title' => [HouseStyle::TITLE, 'Faster; smaller'],
            'colon in a Markdown heading' => [HouseStyle::MARKDOWN, "Intro.\n\n## Step one: install\n\nText."],
            'spaced hyphen as a dash' => [HouseStyle::TEXT, 'Fast - and small'],
            'spaced double hyphen as a dash' => [HouseStyle::MARKDOWN, 'Fast -- and small'],
        ];
    }

    public static function accepted(): array
    {
        return [
            'plain title' => [HouseStyle::TITLE, 'The stars align as StarCore reaches v0.2.0'],
            'hyphenated words' => [HouseStyle::TEXT, 'An end-to-end, framework-neutral library.'],
            'colon in prose' => [HouseStyle::MARKDOWN, 'One thing matters here: speed.'],
            'list bullets' => [HouseStyle::MARKDOWN, "- one\n- two\n  - nested"],
            'horizontal rule' => [HouseStyle::MARKDOWN, "Above.\n\n---\n\nBelow."],
            'table' => [HouseStyle::MARKDOWN, "| a | b |\n| --- | --- |\n| 1 | - |"],
            'spaced hyphen in inline code' => [HouseStyle::MARKDOWN, 'Run `a - b` first.'],
            'colon inside a fenced comment' => [HouseStyle::MARKDOWN, "```bash\n# note: keep it\nls - l\n```"],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_rejects(string $kind, string $value): void
    {
        $this->assertNotEmpty(HouseStyle::problems($value, $kind));
        $this->assertFalse($this->passes(new HouseStyle($kind), $value));
    }

    #[DataProvider('accepted')]
    public function test_it_accepts(string $kind, string $value): void
    {
        $this->assertSame([], HouseStyle::problems($value, $kind));
        $this->assertTrue($this->passes(new HouseStyle($kind), $value));
    }

    /** @return array<string, array{string, string, string}> */
    public static function quoted(): array
    {
        return [
            'dash in a body' => [HouseStyle::MARKDOWN, "First line.\n\nThe release is fast \u{2014} and small.", 'near "release is fast '],
            'dash in a short field' => [HouseStyle::TEXT, "Versions 2\u{2013}3", 'near "Versions 2'],
            'spaced hyphen' => [HouseStyle::TEXT, 'Fast - and small', 'near "Fast - and small"'],
            'colon in a title' => [HouseStyle::TITLE, 'StarDust: the next step', 'near "StarDust: the next'],
            'colon in a heading' => [HouseStyle::MARKDOWN, "Intro.\n\n## Step one: install\n\nText.", '"## Step one: install"'],
        ];
    }

    #[DataProvider('quoted')]
    public function test_a_message_quotes_the_text_it_means(string $kind, string $value, string $expected): void
    {
        $messages = implode("\n", HouseStyle::problems($value, $kind));

        $this->assertStringContainsString($expected, $messages);
    }

    public function test_the_quote_keeps_to_a_few_words(): void
    {
        $long = str_repeat('word ', 40)."and \u{2014} then ".str_repeat('word ', 40);

        $message = HouseStyle::problems($long, HouseStyle::TEXT)[0];

        $this->assertSame(1, preg_match('/near "([^"]*)"/u', $message, $quote));
        $this->assertLessThan(60, mb_strlen($quote[1]), 'The message quotes a few words, not the whole field.');
        $this->assertStringContainsString("and \u{2014} then", $quote[1]);
        $this->assertStringStartsWith('word', $quote[1], 'The quote starts on a whole word.');
        $this->assertStringEndsWith('word', $quote[1], 'The quote ends on a whole word.');
    }

    private function passes(HouseStyle $rule, string $value): bool
    {
        $passed = true;
        $rule->validate('field', $value, function () use (&$passed) {
            $passed = false;
        });

        return $passed;
    }
}
