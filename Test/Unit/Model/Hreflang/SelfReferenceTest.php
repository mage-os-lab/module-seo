<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use MageOS\Seo\Model\Hreflang\SelfReference;
use PHPUnit\Framework\TestCase;

class SelfReferenceTest extends TestCase
{
    /**
     * @var array<int,array{hreflang:string,url:string,store_id:int}>
     */
    private const LINKS = [
        ['hreflang' => 'en-GB', 'url' => 'https://uk/about-us', 'store_id' => 1],
        ['hreflang' => 'de-DE', 'url' => 'https://de/ueber-uns', 'store_id' => 2],
    ];

    public function testASetWithTheStoreViewsLinkIncludesIt(): void
    {
        $this->assertTrue((new SelfReference())->includes(self::LINKS, 2));
    }

    public function testASetWithoutTheStoreViewsLinkDoesNotIncludeIt(): void
    {
        $this->assertFalse((new SelfReference())->includes(self::LINKS, 3));
    }

    public function testAGivenUrlMustBeTheStoreViewsLink(): void
    {
        $selfReference = new SelfReference();

        $this->assertTrue($selfReference->includes(self::LINKS, 1, 'https://uk/about-us'));
        $this->assertFalse($selfReference->includes(self::LINKS, 1, 'https://uk/about-us-2'));
    }

    /**
     * Another store view's link at the URL does not count: it is that store view's page.
     */
    public function testTheUrlInAnotherStoreViewDoesNotCount(): void
    {
        $this->assertFalse((new SelfReference())->includes(self::LINKS, 1, 'https://de/ueber-uns'));
    }

    public function testAnEmptySetIncludesNothing(): void
    {
        $this->assertFalse((new SelfReference())->includes([], 1));
    }
}
