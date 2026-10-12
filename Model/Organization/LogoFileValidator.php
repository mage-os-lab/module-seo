<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Organization;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Image\Adapter\AdapterInterface;
use Magento\Framework\Image\Adapter\ConfigInterface as ImageAdapterConfig;
use Magento\Framework\Image\AdapterFactory;

/**
 * A logo upload must be the JPEG, PNG, GIF or WebP image its name says, and must decode (issue #20).
 *
 * - **The type comes from the file's bytes** (`getimagesize()`), and must match the extension.
 *   This is the security check, and it never relies on an image adapter: ImageMagick decodes
 *   whatever a file's content says it is, SVG and PDF included. Before, any type `getimagesize()`
 *   knew passed, a Flash movie named `.jpg` among them, and core's own validator lets through the
 *   types it does not list.
 * - **A file cut short is refused** by its structure (ImageCompleteness): decoders are lenient, and
 *   read a JPEG cut short, or under GD a GIF, without complaint.
 * - **The decode checks for damage** in a file already known to be one of the four types:
 *   - JPEG, PNG and GIF with Magento's configured image adapter, GD2 or ImageMagick, as core's
 *     validator does;
 *   - WebP with ImageMagick where that is the configured adapter and it reads WebP, otherwise with
 *     GD. Magento's GD2 adapter cannot open WebP. Where neither can, a WebP is refused, never
 *     accepted unchecked.
 */
class LogoFileValidator
{
    /**
     * Each extension a logo may have, with the image type its content must be.
     */
    public const TYPES = [
        'jpg'  => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        'png'  => IMAGETYPE_PNG,
        'gif'  => IMAGETYPE_GIF,
        'webp' => IMAGETYPE_WEBP,
    ];

    private const LABELS = [
        IMAGETYPE_JPEG => 'JPEG',
        IMAGETYPE_PNG  => 'PNG',
        IMAGETYPE_GIF  => 'GIF',
        IMAGETYPE_WEBP => 'WebP',
    ];

    /**
     * @param AdapterFactory $adapterFactory
     * @param ImageAdapterConfig $adapterConfig
     * @param WebpSupport $webp
     * @param ImageCompleteness $completeness
     */
    public function __construct(
        private readonly AdapterFactory     $adapterFactory,
        private readonly ImageAdapterConfig $adapterConfig,
        private readonly WebpSupport        $webp,
        private readonly ImageCompleteness  $completeness
    ) {
    }

    /**
     * Refuse a file that is not the image its extension says, or does not decode.
     *
     * @param string $filePath
     * @param string $extension The extension the file was uploaded with
     * @throws LocalizedException Saying what is wrong with it
     * @return void
     */
    public function validate(string $filePath, string $extension): void
    {
        $type = self::TYPES[strtolower($extension)] ?? null;
        if ($type === null) {
            throw new LocalizedException(__('Upload a JPEG, PNG, GIF or WebP image.'));
        }

        if (($this->imageSize($filePath)[2] ?? null) !== $type) {
            throw new LocalizedException(__('The uploaded file is not a %1 image.', self::LABELS[$type]));
        }

        // Cut short is found by the structure: decoders read a JPEG, or under GD a GIF, that is.
        if (!$this->completeness->isComplete($filePath, $type) || !$this->decodes($filePath, $type)) {
            throw new LocalizedException(__('The uploaded file is a damaged or incomplete image.'));
        }
    }

    /**
     * Whether a file known to be of the type decodes.
     *
     * Throws when nothing here can decode a WebP, or the image is too large to check.
     *
     * @param string $filePath
     * @param int $type
     * @throws LocalizedException
     * @return bool
     */
    private function decodes(string $filePath, int $type): bool
    {
        if ($type === IMAGETYPE_WEBP && !$this->adapterReadsWebp()) {
            if (!$this->webp->gd()) {
                throw new LocalizedException(
                    __('WebP images cannot be checked on this server. Upload a JPEG, PNG or GIF image.')
                );
            }

            return $this->webp->decodesWithGd($filePath);
        }

        // Outside the try: an adapter that is not set up is the server's fault, not the file's.
        $adapter = $this->adapterFactory->create();
        try {
            $adapter->open($filePath);
        } catch (\OverflowException $e) {
            throw new LocalizedException(
                __('The image is too large to check within the server\'s memory limit.'),
                $e
            );
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * Whether the configured adapter is ImageMagick and reads WebP.
     *
     * @return bool
     */
    private function adapterReadsWebp(): bool
    {
        return $this->adapterConfig->getAdapterAlias() === AdapterInterface::ADAPTER_IM && $this->webp->imagick();
    }

    /**
     * What `getimagesize()` reads from the file, or false for something it does not know.
     *
     * @param string $filePath
     * @return array<int|string, mixed>|false
     */
    private function imageSize(string $filePath): array|false
    {
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction,Generic.PHP.NoSilencedErrors.Discouraged -- content sniffing requires getimagesize
            return @getimagesize($filePath);
        } catch (\Throwable) {
            return false;
        }
    }
}
