<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Organization;

use Magento\Config\Model\Config\Backend\Image\Logo as DesignLogo;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;

/**
 * The URL of the Organization's logo on a store view, worked out when the page is rendered (issue
 * #37).
 *
 * Nothing is stored but the choice and the custom logo's path, so a record at any scope serves
 * every store view below it correctly:
 * - **Design:** each store view's own design logo, from Content → Design → Configuration. It
 *   follows a change there without the Organization being saved again.
 * - **Custom:** the uploaded logo, under each store view's media URL. The admin's media URL may not
 *   be public.
 */
class LogoUrl
{
    /**
     * Where uploaded custom logos are stored, relative to the media directory.
     */
    public const UPLOAD_DIR = 'mage-os/seo/logo';

    private const PATH_LOGO_SRC = 'design/header/logo_src';

    private const PATH_SINGLE_STORE_MODE = 'general/single_store_mode/enabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem            $filesystem
    ) {
    }

    /**
     * The logo the Organization publishes on a store view, or null for none.
     *
     * @param OrganizationInterface $organization
     * @param int $storeId
     * @return string|null
     */
    public function forStore(OrganizationInterface $organization, int $storeId): ?string
    {
        return $organization->getLogoSource() === OrganizationInterface::LOGO_SOURCE_DESIGN
            ? $this->designLogo($storeId)
            : $this->customLogo($organization->getLogoPath(), $storeId);
    }

    /**
     * A store view's design logo, or null when it has none.
     *
     * Read as core's header reads it: at store view scope, or website scope in single-store mode,
     * and only when the file is in the media directory. Where it is not, the header shows the
     * theme's placeholder logo, which is never the brand's, so nothing is published.
     *
     * @param int $storeId
     * @return string|null
     */
    public function designLogo(int $storeId): ?string
    {
        $store = $this->storeManager->getStore($storeId);
        $file  = (string) ($this->scopeConfig->getValue(self::PATH_SINGLE_STORE_MODE) === '1'
            ? $this->scopeConfig->getValue(self::PATH_LOGO_SRC, ScopeInterface::SCOPE_WEBSITE, $store->getWebsiteId())
            : $this->scopeConfig->getValue(self::PATH_LOGO_SRC, ScopeInterface::SCOPE_STORE, $storeId));
        if ($file === '') {
            return null;
        }

        $path = DesignLogo::UPLOAD_DIR . '/' . ltrim($file, '/');
        if (!$this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->isFile($path)) {
            return null;
        }

        return $this->mediaUrl($storeId) . $path;
    }

    /**
     * A custom logo's URL on a store view, or null when there is none.
     *
     * A media-relative path goes under the store view's media URL. An absolute URL, which records
     * saved before paths were stored hold, is used as it is.
     *
     * @param string $path
     * @param int $storeId
     * @return string|null
     */
    public function customLogo(string $path, int $storeId): ?string
    {
        if ($path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $this->mediaUrl($storeId) . ltrim($path, '/');
    }

    /**
     * Whether a path is a file uploaded as a custom logo: directly in UPLOAD_DIR.
     *
     * @param string $path
     * @return bool
     */
    public function isUpload(string $path): bool
    {
        $folder = self::UPLOAD_DIR . '/';
        if (!str_starts_with($path, $folder)) {
            return false;
        }

        $name = substr($path, \strlen($folder));

        return $name !== '' && $name !== '.' && $name !== '..' && strpbrk($name, '/\\') === false;
    }

    /**
     * A store view's media URL, ending in a slash.
     *
     * @param int $storeId
     * @return string
     */
    private function mediaUrl(int $storeId): string
    {
        return rtrim($this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/';
    }
}
