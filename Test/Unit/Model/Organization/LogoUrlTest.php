<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Organization;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Model\Organization\LogoUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #37: the Organization's logo is worked out for each store view when the page is rendered.
 */
class LogoUrlTest extends TestCase
{
    /**
     * Stored configuration, by path, then scope and code.
     *
     * @var array<string, array<string, string>>
     */
    private array $config = [];

    /**
     * Files present in the media directory.
     *
     * @var string[]
     */
    private array $mediaFiles = [];

    /**
     * @return void
     */
    public function testADesignLogoIsEachStoreViewsOwnUnderItsMediaUrl(): void
    {
        $this->designLogo('store', '1', 'stores/1/one.png');
        $this->designLogo('store', '2', 'stores/2/two.png');
        // A value with a leading slash is the same file.
        $this->config['design/header/logo_src']['store|2'] = '/stores/2/two.png';

        $this->assertSame('https://one.example/media/logo/stores/1/one.png', $this->logoUrl()->designLogo(1));
        $this->assertSame('https://two.example/media/logo/stores/2/two.png', $this->logoUrl()->designLogo(2));
    }

    /**
     * In single-store mode the design logo is the website's, as core's header reads it.
     *
     * @return void
     */
    public function testInSingleStoreModeTheWebsitesDesignLogoIsUsed(): void
    {
        $this->config['general/single_store_mode/enabled']['default|'] = '1';
        $this->designLogo('store', '1', 'stores/1/stale.png');
        $this->designLogo('website', '7', 'websites/7/brand.png');

        $this->assertSame('https://one.example/media/logo/websites/7/brand.png', $this->logoUrl()->designLogo(1));
    }

    /**
     * @return void
     */
    public function testNoDesignLogoWhereNoneIsSetOrItsFileIsMissing(): void
    {
        $this->assertNull($this->logoUrl()->designLogo(1));

        $this->config['design/header/logo_src']['store|1'] = 'stores/1/gone.png';
        $this->assertNull($this->logoUrl()->designLogo(1), 'The file is not in the media directory.');
    }

    /**
     * @return void
     */
    public function testACustomLogoIsUnderEachStoreViewsMediaUrl(): void
    {
        $this->assertSame(
            'https://one.example/media/mage-os/seo/logo/square.png',
            $this->logoUrl()->customLogo('mage-os/seo/logo/square.png', 1)
        );
        $this->assertSame(
            'https://two.example/media/mage-os/seo/logo/square.png',
            $this->logoUrl()->customLogo('/mage-os/seo/logo/square.png', 2)
        );
        $this->assertSame(
            'https://one.example/media/mage-os/seo/logo/https://x.png',
            $this->logoUrl()->customLogo('mage-os/seo/logo/https://x.png', 1),
            'Only a URL that starts with its scheme is absolute.'
        );
    }

    /**
     * An older record's absolute URL is used as it is; an empty path is no logo.
     *
     * @return void
     */
    public function testAnAbsoluteCustomLogoIsUsedAsItIs(): void
    {
        $this->assertSame('https://cdn.example/a.png', $this->logoUrl()->customLogo('https://cdn.example/a.png', 1));
        $this->assertSame('HTTP://cdn.example/a.png', $this->logoUrl()->customLogo('HTTP://cdn.example/a.png', 2));
        $this->assertNull($this->logoUrl()->customLogo('', 1));
    }

    /**
     * @return void
     */
    public function testTheOrganizationsSourceDecidesWhichLogo(): void
    {
        $this->designLogo('store', '1', 'stores/1/one.png');

        $design = $this->organization(OrganizationInterface::LOGO_SOURCE_DESIGN, 'mage-os/seo/logo/x.png');
        $custom = $this->organization(OrganizationInterface::LOGO_SOURCE_CUSTOM, 'mage-os/seo/logo/x.png');

        $this->assertSame('https://one.example/media/logo/stores/1/one.png', $this->logoUrl()->forStore($design, 1));
        $this->assertSame('https://one.example/media/mage-os/seo/logo/x.png', $this->logoUrl()->forStore($custom, 1));
    }

    /**
     * @dataProvider uploadPaths
     * @param string $path
     * @param bool $isUpload
     * @return void
     */
    #[DataProvider('uploadPaths')]
    public function testOnlyAFileDirectlyInTheUploadFolderIsAnUpload(string $path, bool $isUpload): void
    {
        $this->assertSame($isUpload, $this->logoUrl()->isUpload($path));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function uploadPaths(): array
    {
        return [
            'an upload'                 => ['mage-os/seo/logo/square.png', true],
            'two dots in a name'        => ['mage-os/seo/logo/square..png', true],
            'a subfolder'               => ['mage-os/seo/logo/sub/square.png', false],
            'a way out'                 => ['mage-os/seo/logo/../../../app/etc/env.php', false],
            'the parent itself'         => ['mage-os/seo/logo/..', false],
            'the folder itself'         => ['mage-os/seo/logo/.', false],
            'no file'                   => ['mage-os/seo/logo/', false],
            'another folder'            => ['logo/stores/1/one.png', false],
            'the folder further down'   => ['x/mage-os/seo/logo/square.png', false],
            'a backslash'               => ['mage-os/seo/logo/a\\b.png', false],
            'an absolute URL'           => ['https://cdn.example/mage-os/seo/logo/a.png', false],
        ];
    }

    /**
     * Set a design logo at a scope, with its file in the media directory.
     *
     * @param string $scope
     * @param string $code
     * @param string $file
     * @return void
     */
    private function designLogo(string $scope, string $code, string $file): void
    {
        $this->config['design/header/logo_src'][$scope . '|' . $code] = $file;
        $this->mediaFiles[] = 'logo/' . $file;
    }

    /**
     * An Organization with the given logo source and path.
     *
     * @param string $source
     * @param string $path
     * @return OrganizationInterface
     */
    private function organization(string $source, string $path): OrganizationInterface
    {
        $organization = $this->createStub(OrganizationInterface::class);
        $organization->method('getLogoSource')->willReturn($source);
        $organization->method('getLogoPath')->willReturn($path);

        return $organization;
    }

    /**
     * LogoUrl over two store views in website 7, with media URLs one with a trailing slash and one
     * without.
     *
     * @return LogoUrl
     */
    private function logoUrl(): LogoUrl
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path, string $scope = 'default', mixed $code = null): ?string
                => $this->config[$path][$scope . '|' . (string) $code] ?? null
        );

        $stores = [];
        foreach ([1 => 'https://one.example/media/', 2 => 'https://two.example/media'] as $storeId => $mediaUrl) {
            $store = $this->createStub(Store::class);
            $store->method('getWebsiteId')->willReturn(7);
            $store->method('getBaseUrl')->willReturnCallback(
                static fn (string $type = UrlInterface::URL_TYPE_LINK): string
                    => $type === UrlInterface::URL_TYPE_MEDIA ? $mediaUrl : 'https://wrong.example/'
            );
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static fn (int $storeId): Store => $stores[$storeId]);

        $media = $this->createStub(ReadInterface::class);
        $media->method('isFile')->willReturnCallback(
            fn (string $path): bool => \in_array($path, $this->mediaFiles, true)
        );
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturnCallback(
            static fn (string $code): ReadInterface
                => $code === DirectoryList::MEDIA ? $media : throw new \LogicException($code)
        );

        return new LogoUrl($scopeConfig, $storeManager, $filesystem);
    }
}
