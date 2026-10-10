<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Organization;

/**
 * What on this server can decode WebP, and GD's decoding of it.
 *
 * Magento's GD2 image adapter cannot open WebP, and whether Imagick or GD can depends on how they
 * were built. Kept apart from LogoFileValidator so its choice of decoder can be tested on a server
 * with or without either.
 */
class WebpSupport
{
    /**
     * Whether Imagick is loaded and lists WebP among the formats it reads.
     *
     * @return bool
     */
    public function imagick(): bool
    {
        return class_exists(\Imagick::class) && \Imagick::queryFormats('WEBP') !== [];
    }

    /**
     * Whether PHP's GD was built with WebP.
     *
     * @return bool
     */
    public function gd(): bool
    {
        return \function_exists('imagecreatefromwebp') && !empty(gd_info()['WebP Support']);
    }

    /**
     * Whether GD decodes the file as a WebP image.
     *
     * @param string $filePath
     * @return bool
     */
    public function decodesWithGd(string $filePath): bool
    {
        try {
            // Decoding is the check, and a damaged file is an answer, not an error.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction,Generic.PHP.NoSilencedErrors.Discouraged
            return @imagecreatefromwebp($filePath) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
