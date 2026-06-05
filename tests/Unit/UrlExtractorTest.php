<?php

namespace Tests\Unit;

use App\Support\UrlExtractor;
use PHPUnit\Framework\TestCase;

class UrlExtractorTest extends TestCase
{
    public function test_full_url_is_returned_and_trailing_punctuation_stripped(): void
    {
        $this->assertSame(
            'https://primelaser.bg',
            UrlExtractor::first('Моят сайт е https://primelaser.bg. Виж го.'),
        );
    }

    public function test_bare_domain_is_normalised_to_https(): void
    {
        $this->assertSame(
            'https://primelaser.bg',
            UrlExtractor::first('Анализирай сайта primelaser.bg, моля.'),
        );
        $this->assertSame(
            'https://www.example.com/uslugi',
            UrlExtractor::first('виж www.example.com/uslugi за цени'),
        );
    }

    public function test_emails_are_not_treated_as_domains(): void
    {
        $this->assertNull(UrlExtractor::first('пиши на info@primelaser.bg днес'));
    }

    public function test_plain_text_and_filenames_yield_no_url(): void
    {
        $this->assertSame([], UrlExtractor::all('Няма URL тук, само текст.'));
        $this->assertSame([], UrlExtractor::all('Генерирай седмично съдържание за мрежите.'));
        $this->assertNull(UrlExtractor::first('приложи report.pdf към имейла'));
    }

    public function test_a_domain_inside_a_full_url_is_not_double_counted(): void
    {
        $this->assertSame(
            ['https://primelaser.bg'],
            UrlExtractor::all('Сайтът https://primelaser.bg е готов.'),
        );
    }
}
