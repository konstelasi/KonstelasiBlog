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

    private function passes(HouseStyle $rule, string $value): bool
    {
        $passed = true;
        $rule->validate('field', $value, function () use (&$passed) {
            $passed = false;
        });

        return $passed;
    }
}
