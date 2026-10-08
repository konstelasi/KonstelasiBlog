<?php

namespace Tests\Unit;

use App\Models\Post;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostReadingTest extends TestCase
{
    public function test_words_are_split_on_any_whitespace(): void
    {
        $this->assertSame(0, Post::wordCount(null));
        $this->assertSame(0, Post::wordCount("  \n\t "));
        $this->assertSame(5, Post::wordCount("One two\nthree\t\tfour   five"));
    }

    /** @return array<string, array{int, int}> */
    public static function readingTimes(): array
    {
        return [
            'empty still reads in a minute' => [0, 1],
            'a short post' => [150, 1],
            'just under two minutes rounds to one' => [299, 1],
            'exactly half way rounds up' => [300, 2],
            'ten minutes' => [2000, 10],
        ];
    }

    #[DataProvider('readingTimes')]
    public function test_reading_time_matches_the_public_site(int $words, int $minutes): void
    {
        $this->assertSame($minutes, Post::readingMinutes(str_repeat('word ', $words)));
    }
}
