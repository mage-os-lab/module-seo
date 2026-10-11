<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\RobotsMeta;

use MageOS\Seo\Model\Category\PathResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\PageDirectives;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\ProductRobotsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The robots directive a page gets, for whole sets of products and categories at once: this
 * module's value (the entity's own, an ancestor's, or the page type's default), else core's Design →
 * Search Engine Robots default, as on the page itself.
 *
 * One copy of the rule, for the sitemap, which leaves NOINDEX pages out, and for MageOS_Aeo's feeds
 * (review M4), which now do the same.
 */
class PageDirectivesTest extends TestCase
{
    private const CORE_DEFAULT = 'INDEX,FOLLOW';

    /**
     * @return void
     */
    public function testAProductWithoutAModuleDirectiveGetsCoresDefault(): void
    {
        $products = $this->createStub(ProductRobotsProvider::class);
        $products->method('forProducts')->willReturn([1 => 'NOINDEX,FOLLOW', 2 => null]);

        $this->assertSame(
            [1 => 'NOINDEX,FOLLOW', 2 => self::CORE_DEFAULT],
            $this->pageDirectives(products: $products)->forProducts([1, 2], 1)
        );
    }

    /**
     * @return void
     */
    public function testACategoryIsAskedWithItsPath(): void
    {
        $paths = $this->createStub(PathResolver::class);
        $paths->method('forCategoryIds')->willReturn([5 => ['1', '2', '5'], 6 => ['1', '2', '6']]);
        $categories = $this->createMock(CategoryRobotsProvider::class);
        $categories->expects($this->exactly(2))
            ->method('forCategory')
            ->willReturnCallback(
                static fn (int $id, array $path, int $storeId): ?string
                    => $id === 5 && $path === ['1', '2', '5'] && $storeId === 1 ? 'NOINDEX,FOLLOW' : null
            );

        $this->assertSame(
            [5 => 'NOINDEX,FOLLOW', 6 => self::CORE_DEFAULT],
            $this->pageDirectives(categories: $categories, paths: $paths)->forCategories([5, 6], 1)
        );
    }

    /**
     * A category that no longer exists has no page, so no directive.
     *
     * @return void
     */
    public function testACategoryThatNoLongerExistsIsLeftOut(): void
    {
        $paths = $this->createStub(PathResolver::class);
        $paths->method('forCategoryIds')->willReturn([5 => ['1', '2', '5']]);

        $this->assertSame([5], array_keys($this->pageDirectives(paths: $paths)->forCategories([5, 99], 1)));
    }

    /**
     * @return void
     */
    public function testNoIdsAskNothing(): void
    {
        $products = $this->createMock(ProductRobotsProvider::class);
        $products->expects($this->never())->method('forProducts');
        $paths = $this->createMock(PathResolver::class);
        $paths->expects($this->never())->method('forCategoryIds');
        $model = $this->pageDirectives(products: $products, paths: $paths);

        $this->assertSame([], $model->forProducts([], 1));
        $this->assertSame([], $model->forCategories([], 1));
    }

    /**
     * @dataProvider directives
     * @param string $directive
     * @param bool $noindex
     * @return void
     */
    #[DataProvider('directives')]
    public function testWhichDirectivesKeepAPageOutOfTheIndex(string $directive, bool $noindex): void
    {
        $this->assertSame($noindex, $this->pageDirectives()->isNoindex($directive));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function directives(): array
    {
        return [
            'NOINDEX,FOLLOW'      => ['NOINDEX,FOLLOW', true],
            'lower case'          => ['noindex, nofollow', true],
            'none'                => ['none', true],
            'INDEX,FOLLOW'        => ['INDEX,FOLLOW', false],
            // Restricts images, not the page.
            'noimageindex'        => ['INDEX,FOLLOW,noimageindex', false],
            'empty'               => ['', false],
        ];
    }

    /**
     * The class over the given collaborators, or stubs that say nothing.
     *
     * @param ProductRobotsProvider|null $products
     * @param CategoryRobotsProvider|null $categories
     * @param PathResolver|null $paths
     * @return PageDirectives
     */
    private function pageDirectives(
        ?ProductRobotsProvider $products = null,
        ?CategoryRobotsProvider $categories = null,
        ?PathResolver $paths = null
    ): PageDirectives {
        $config = $this->createStub(Config::class);
        $config->method('getRobotsCoreDefault')->willReturn(self::CORE_DEFAULT);

        return new PageDirectives(
            $products ?? $this->createStub(ProductRobotsProvider::class),
            $categories ?? $this->createStub(CategoryRobotsProvider::class),
            $paths ?? $this->createStub(PathResolver::class),
            $config
        );
    }
}
