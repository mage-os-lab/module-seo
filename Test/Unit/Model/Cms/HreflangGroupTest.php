<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Cms;

use MageOS\Seo\Model\Cms\HreflangGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HreflangGroupTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function normaliseProvider(): array
    {
        return [
            'already canonical' => ['about-us', 'about-us'],
            'upper case'        => ['About-Us', 'about-us'],
            'surrounding space' => ['  about-us ', 'about-us'],
            'empty'             => ['', null],
            'only space'        => ['   ', null],
            'null'              => [null, null],
        ];
    }

    /**
     * @dataProvider normaliseProvider
     */
    #[DataProvider('normaliseProvider')]
    public function testNormalise(?string $entered, ?string $expected): void
    {
        $this->assertSame($expected, (new HreflangGroup())->normalise($entered));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function validityProvider(): array
    {
        return [
            'slug'                 => ['about-us', true],
            'dots and underscores' => ['legal.terms_2026', true],
            'digits'               => ['404', true],
            'space'                => ['about us', false],
            'accent'               => ['café', false],
            'upper case'           => ['About', false],
            'leading hyphen'       => ['-about', false],
            'slash'                => ['about/us', false],
            'too long'             => [str_repeat('a', 256), false],
            'longest allowed'      => [str_repeat('a', 255), true],
        ];
    }

    /**
     * @dataProvider validityProvider
     */
    #[DataProvider('validityProvider')]
    public function testIsValid(string $group, bool $expected): void
    {
        $this->assertSame($expected, (new HreflangGroup())->isValid($group));
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function slugifyProvider(): array
    {
        return [
            'already a group'     => ['about-us', 'about-us'],
            'words'               => ['About Us', 'about-us'],
            'punctuation run'     => ['Terms & Conditions!', 'terms-conditions'],
            'accent'              => ['Café', 'caf'],
            'nothing usable'      => ['***', null],
            'empty'               => ['', null],
            'trailing separators' => ['about.', 'about'],
        ];
    }

    /**
     * @dataProvider slugifyProvider
     */
    #[DataProvider('slugifyProvider')]
    public function testSlugify(?string $value, ?string $expected): void
    {
        $group = (new HreflangGroup())->slugify($value);

        $this->assertSame($expected, $group);
        if ($group !== null) {
            $this->assertTrue((new HreflangGroup())->isValid($group), 'A slug is always a valid group.');
        }
    }
}
