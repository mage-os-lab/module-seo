<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Router;

use MageOS\Seo\Model\Router\PublicPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The paths the modules serving public documents register: exact paths and path prefixes.
 */
class PublicPathsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function paths(): array
    {
        return [
            'a registered path'                  => ['/feed.txt', true],
            'without its leading slash'          => ['feed.txt', true],
            'with a trailing slash'              => ['/feed.txt/', true],
            'under a registered prefix'          => ['/docs/anything', true],
            // Nothing is served at the bare prefix: an ordinary request, as before.
            'the prefix alone'                   => ['/docs/', false],
            'a longer name starting the same way' => ['/feed.txt.html', false],
            'a prefix without its slash'         => ['/docsearch', false],
            'anything else'                      => ['/checkout/cart', false],
            'the home page'                      => ['/', false],
        ];
    }

    /**
     * @dataProvider paths
     * @param string $path
     * @param bool $public
     * @return void
     */
    #[DataProvider('paths')]
    public function testOnlyRegisteredPathsArePublic(string $path, bool $public): void
    {
        $this->assertSame($public, (new PublicPaths(['feed.txt'], ['docs/']))->isPublicPath($path));
    }

    /**
     * @return void
     */
    public function testNothingIsPublicUntilRegistered(): void
    {
        $this->assertFalse((new PublicPaths())->isPublicPath('/llms.txt'));
        $this->assertFalse((new PublicPaths())->isPublicPath('/.well-known/ucp'));
    }
}
