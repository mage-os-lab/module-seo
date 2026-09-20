<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Organisation;

use Laminas\Uri\Exception\ExceptionInterface as UriException;
use Laminas\Uri\UriFactory;

/**
 * Decides whether a URL an administrator typed is safe to publish.
 *
 * Organisation URLs, social profiles and the logo are emitted into JSON-LD and Open Graph tags,
 * which means they end up in `href`, `src` and `@id` positions in the page. A `javascript:` or
 * `data:` URI in any of them is stored XSS waiting for a click, so only the schemes that make
 * sense for a public web address are accepted.
 *
 * Relative paths are allowed: the logo is normally a media path, and the renderers make those
 * absolute against the store's base URL.
 */
class UrlValidator
{
    /**
     * Schemes a published URL may use.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Whether a value is safe to store and publish.
     *
     * @param string $url
     * @return bool
     */
    public function isValid(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }

        // Checked before parsing, not instead of it: Laminas accepts a URL with an embedded
        // newline as valid, and such a value would break out of the attribute or header it
        // lands in.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        try {
            $uri = UriFactory::factory($url);
        } catch (UriException) {
            // Laminas has no class registered for schemes such as javascript:, data: and
            // vbscript:, and says so by throwing. Those are precisely the ones to refuse.
            return false;
        }

        $scheme = (string) $uri->getScheme();
        if ($scheme !== '') {
            return \in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
        }

        // No scheme is fine for a media path, but "//evil.test/logo.png" has no scheme either:
        // it adopts the page's and points at another host, which a host here reveals.
        return (string) $uri->getHost() === '';
    }

    /**
     * Keep only the values that are safe to publish.
     *
     * @param string[] $urls
     * @return string[] Re-indexed, preserving order
     */
    public function filter(array $urls): array
    {
        return array_values(array_filter($urls, fn (string $url): bool => $this->isValid($url)));
    }
}
