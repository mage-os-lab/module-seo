<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Seo\Model\Category\PathResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The category collection factory is one of Magento's generated classes, so this test needs an
 * installation to have generated it. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class PathResolverTest extends TestCase
{
    public function testSplitsALoadedCategorysPath(): void
    {
        $resolver = $this->resolver($this->createStub(CategoryRepositoryInterface::class));

        $this->assertSame(['1', '2', '5', '9'], $resolver->forCategory($this->category('1/2/5/9')));
    }

    public function testAnUnsetPathIsNoAncestorsRatherThanOneEmptyOne(): void
    {
        $resolver = $this->resolver($this->createStub(CategoryRepositoryInterface::class));

        $this->assertSame([], $resolver->forCategory($this->category('')));
    }

    public function testLoadsACategoryKnownOnlyByItsId(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('get')
            ->with(9, 3)
            ->willReturn($this->category('1/2/5/9'));

        $this->assertSame(['1', '2', '5', '9'], $this->resolver($repository)->forCategoryId(9, 3));
    }

    public function testCategoryIdZeroIsNotLooked(): void
    {
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->expects($this->never())->method('get');

        $this->assertSame([], $this->resolver($repository)->forCategoryId(0, 1));
    }

    public function testACategoryThatNoLongerExistsYieldsNoPath(): void
    {
        // A product can still carry the ID of a deleted category, and it has its own schema to
        // emit regardless.
        $repository = $this->createStub(CategoryRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException());

        $this->assertSame([], $this->resolver($repository)->forCategoryId(9, 1));
    }

    public function testReadsSeveralCategoriesPathsInOneQuery(): void
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('entity_id', ['in' => [9, 12]])
            ->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->loaded(9, '1/2/5/9'),
            $this->loaded(12, '1/2/12'),
        ]));

        $factory = $this->createMock(CategoryCollectionFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($collection);

        $resolver = new PathResolver($this->createStub(CategoryRepositoryInterface::class), $factory);

        $this->assertSame(
            [9 => ['1', '2', '5', '9'], 12 => ['1', '2', '12']],
            $resolver->forCategoryIds([9, 12, 9])
        );
    }

    public function testNoIdsReadNothing(): void
    {
        $factory = $this->createMock(CategoryCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $resolver = new PathResolver($this->createStub(CategoryRepositoryInterface::class), $factory);

        $this->assertSame([], $resolver->forCategoryIds([]));
    }

    /**
     * @param CategoryRepositoryInterface $repository
     * @return PathResolver
     */
    private function resolver(CategoryRepositoryInterface $repository): PathResolver
    {
        return new PathResolver($repository, $this->createStub(CategoryCollectionFactory::class));
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

    /**
     * A category as a collection hands it over.
     *
     * @param int $id
     * @param string $path
     * @return Category
     */
    private function loaded(int $id, string $path): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getPath')->willReturn($path);

        return $category;
    }
}
