<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Seo\Model\Category\PathResolver;
use PHPUnit\Framework\TestCase;

class PathResolverTest extends TestCase
{
    public function testSplitsALoadedCategorysPath(): void
    {
        $resolver = new PathResolver($this->createStub(CategoryRepositoryInterface::class));

        $this->assertSame(['1', '2', '5', '9'], $resolver->forCategory($this->category('1/2/5/9')));
    }

    public function testAnUnsetPathIsNoAncestorsRatherThanOneEmptyOne(): void
    {
        $resolver = new PathResolver($this->createStub(CategoryRepositoryInterface::class));

        $this->assertSame([], $resolver->forCategory($this->category('')));
    }

    public function testLoadsACategoryKnownOnlyByItsId(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('get')
            ->with(9, 3)
            ->willReturn($this->category('1/2/5/9'));

        $this->assertSame(['1', '2', '5', '9'], (new PathResolver($repository))->forCategoryId(9, 3));
    }

    public function testCategoryIdZeroIsNotLooked(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->expects($this->never())->method('get');

        $this->assertSame([], (new PathResolver($repository))->forCategoryId(0, 1));
    }

    public function testACategoryThatNoLongerExistsYieldsNoPath(): void
    {
        // A product can still carry the ID of a deleted category, and it has its own schema to
        // emit regardless.
        $repository = $this->createStub(CategoryRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException());

        $this->assertSame([], (new PathResolver($repository))->forCategoryId(9, 1));
    }

    /**
     * @param string $path
     * @return CategoryInterface
     */
    private function category(string $path): CategoryInterface
    {
        $category = $this->createStub(CategoryInterface::class);
        $category->method('getPath')->willReturn($path);

        return $category;
    }
}
