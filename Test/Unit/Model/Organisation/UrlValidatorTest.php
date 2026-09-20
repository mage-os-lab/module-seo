<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Organisation;

use MageOS\Seo\Model\Organisation\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review finding S4: no server-side URL validation, so javascript:/data: URIs could reach
 * structured data — where they land in href, src and @id positions.
 */
class UrlValidatorTest extends TestCase
{
    /**
     * @param string $url
     * @return void
     *
     * @dataProvider publishableUrls
     */
    #[DataProvider('publishableUrls')]
    public function testAPublishableUrlIsAccepted(string $url): void
    {
        $this->assertTrue((new UrlValidator())->isValid($url), $url);
    }

    /**
     * @param string $url
     * @return void
     *
     * @dataProvider unsafeUrls
     */
    #[DataProvider('unsafeUrls')]
    public function testAnUnsafeUrlIsRefused(string $url): void
    {
        $this->assertFalse((new UrlValidator())->isValid($url), $url);
    }

    public function testFilterKeepsOnlyThePublishableOnes(): void
    {
        $filtered = (new UrlValidator())->filter([
            'https://example.com/a',
            'javascript:alert(1)',
            'https://example.com/b',
        ]);

        // Re-indexed, so the stored JSON is a list rather than an object with gaps.
        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $filtered);
    }

    /**
     * @return array<string, string[]>
     */
    public static function publishableUrls(): array
    {
        return [
            'https'          => ['https://example.com/page'],
            'http'           => ['http://example.com'],
            'uppercase'      => ['HTTPS://EXAMPLE.COM'],
            'empty'          => [''],
            'whitespace'     => ['   '],
            // The logo is normally a media path, made absolute by the renderers.
            'media path'     => ['/media/logo/stores/1/logo.png'],
            'relative path'  => ['media/logo.png'],
        ];
    }

    /**
     * @return array<string, string[]>
     */
    public static function unsafeUrls(): array
    {
        return [
            'javascript'        => ['javascript:alert(1)'],
            'javascript spaced' => ['JavaScript:alert(1)'],
            'data'              => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript'          => ['vbscript:msgbox(1)'],
            'file'              => ['file:///etc/passwd'],
            // Protocol-relative: adopts the page's scheme and points at another host.
            'protocol relative' => ['//evil.test/logo.png'],
            // A newline would break out of the attribute or header the value lands in.
            'newline'           => ["https://example.com/a\nContact: attacker@evil.test"],
            'tab'               => ["https://example.com/\tx"],
        ];
    }
}
