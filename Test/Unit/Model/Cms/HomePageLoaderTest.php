<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Cms;

use Magento\Cms\Model\Page;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Seo\Model\Cms\HomePageLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The home page is loaded with core's own call: the configured value, its `|…` suffix stripped,
 * handed to the CMS page resource model with the store set — numeric by page ID, anything else by
 * identifier.
 *
 * The page factory is Magento's generated class, so this test needs an installation to have
 * generated it. The mutation-testing run works from the module directory alone and excludes this
 * group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class HomePageLoaderTest extends TestCase
{
    public function testAnIdentifierIsLoadedAsItIs(): void
    {
        $this->assertLoadsWith('home', 'home');
    }

    public function testTheLayoutSuffixIsStripped(): void
    {
        $this->assertLoadsWith('home|2columns-left', 'home');
    }

    public function testAPageIdIsHandedOnForTheResourceModelToLoadById(): void
    {
        $this->assertLoadsWith('5', '5');
    }

    public function testThePageIsLoadedInTheStoreView(): void
    {
        $storeIdAtLoad = null;
        $page          = $this->page(1, $storeIdAtLoad);

        $resource = $this->createMock(PageResource::class);
        $resource->expects($this->once())->method('load')->willReturnCallback(
            function () use (&$storeIdAtLoad, $resource) {
                $this->assertSame(3, $storeIdAtLoad, 'The store is set before the page is loaded.');

                return $resource;
            }
        );

        $this->loader('home', $page, $resource)->load(3);
    }

    public function testNoHomePageConfiguredIsNull(): void
    {
        foreach ([null, ''] as $value) {
            $factory = $this->createMock(PageFactory::class);
            $factory->expects($this->never())->method('create');
            $scopeConfig = $this->createStub(ScopeConfigInterface::class);
            $scopeConfig->method('getValue')->willReturn($value);

            $loader = new HomePageLoader($scopeConfig, $factory, $this->createStub(PageResource::class));

            $this->assertNull($loader->load(1));
        }
    }

    public function testAPageThatDoesNotLoadIsNull(): void
    {
        $storeId = null;

        $this->assertNull($this->loader('missing', $this->page(null, $storeId))->load(1));
    }

    /**
     * Assert the configured value reaches the resource model as expected, and the page comes back.
     *
     * @param string $configured
     * @param string $loadedWith
     * @return void
     */
    private function assertLoadsWith(string $configured, string $loadedWith): void
    {
        $storeId = null;
        $page    = $this->page(7, $storeId);

        $resource = $this->createMock(PageResource::class);
        $resource->expects($this->once())->method('load')->with($page, $loadedWith)->willReturnSelf();

        $this->assertSame($page, $this->loader($configured, $page, $resource)->load(1));
    }

    /**
     * @param string $configured
     * @param Page $page
     * @param PageResource|null $resource
     * @return HomePageLoader
     */
    private function loader(string $configured, Page $page, ?PageResource $resource = null): HomePageLoader
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($configured);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        return new HomePageLoader($scopeConfig, $factory, $resource ?? $this->createStub(PageResource::class));
    }

    /**
     * A page whose magic setStoreId() records the store it was given.
     *
     * @param int|null $id The ID it has once loaded; null for a page that did not load
     * @param int|null $storeId Receives the store ID set on it
     * @return Page
     */
    private function page(?int $id, ?int &$storeId): Page
    {
        $page = $this->createStub(Page::class);
        $page->method('getId')->willReturn($id);
        $page->method('__call')->willReturnCallback(
            function (string $method, array $arguments) use (&$storeId, $page) {
                if ($method === 'setStoreId') {
                    $storeId = $arguments[0];
                }

                return $page;
            }
        );

        return $page;
    }
}
