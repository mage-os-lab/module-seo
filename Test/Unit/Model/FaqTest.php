<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use MageOS\Seo\Api\Data\FaqInterface;
use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\ResourceModel\Faq as FaqResource;
use PHPUnit\Framework\TestCase;

/**
 * The FAQ model: typed accessors over its data, and the cache tags a save purges.
 */
class FaqTest extends TestCase
{
    public function testItTakesItsIdFieldFromItsResourceModel(): void
    {
        $this->assertSame('entity_id', $this->faq()->getIdFieldName());
    }

    public function testAnEmptyFaqReadsAsZeroEmptyAndInactive(): void
    {
        $faq = $this->faq();

        $this->assertSame(0, $faq->getEntityId());
        $this->assertSame('', $faq->getIdentifier());
        $this->assertSame(0, $faq->getStoreId());
        $this->assertSame('', $faq->getQuestion());
        $this->assertSame('', $faq->getAnswer());
        $this->assertSame(0, $faq->getSortOrder());
        $this->assertFalse($faq->getIsActive());
    }

    public function testStoredValuesAreReadBackTyped(): void
    {
        // As a collection or resource load leaves them: every column a string.
        $faq = $this->faq([
            FaqInterface::ENTITY_ID  => '7',
            FaqInterface::STORE_ID   => '2',
            FaqInterface::SORT_ORDER => '5',
            FaqInterface::IS_ACTIVE  => '1',
        ]);

        $this->assertSame(7, $faq->getEntityId());
        $this->assertSame(2, $faq->getStoreId());
        $this->assertSame(5, $faq->getSortOrder());
        $this->assertTrue($faq->getIsActive());
    }

    public function testTheSettersStoreTheirValueAndReturnTheModel(): void
    {
        $faq = $this->faq();

        $this->assertSame($faq, $faq->setIdentifier('shipping'));
        $this->assertSame($faq, $faq->setStoreId(2));
        $this->assertSame($faq, $faq->setQuestion('Do you ship worldwide?'));
        $this->assertSame($faq, $faq->setAnswer('Yes.'));
        $this->assertSame($faq, $faq->setSortOrder(4));
        $this->assertSame($faq, $faq->setIsActive(true));

        $this->assertSame('shipping', $faq->getIdentifier());
        $this->assertSame(2, $faq->getStoreId());
        $this->assertSame('Do you ship worldwide?', $faq->getQuestion());
        $this->assertSame('Yes.', $faq->getAnswer());
        $this->assertSame(4, $faq->getSortOrder());
        $this->assertTrue($faq->getIsActive());
    }

    public function testActiveIsStoredAsOneOrZero(): void
    {
        $faq = $this->faq();

        $faq->setIsActive(true);
        $this->assertSame(1, $faq->getData(FaqInterface::IS_ACTIVE));
        $faq->setIsActive(false);
        $this->assertSame(0, $faq->getData(FaqInterface::IS_ACTIVE));
    }

    public function testWithoutAGroupOnlyTheFaqTagIsPurged(): void
    {
        $this->assertSame([Faq::CACHE_TAG], $this->faq()->getIdentities());
    }

    public function testTheGroupsPagesArePurged(): void
    {
        $faq = $this->faq([FaqInterface::IDENTIFIER => 'shipping']);
        $faq->setOrigData(FaqInterface::IDENTIFIER, 'shipping');

        $this->assertSame([Faq::CACHE_TAG, Faq::CACHE_TAG . '_group_shipping'], $faq->getIdentities());
    }

    public function testMovingToAnotherGroupPurgesTheOldGroupsPagesToo(): void
    {
        $faq = $this->faq([FaqInterface::IDENTIFIER => 'returns']);
        $faq->setOrigData(FaqInterface::IDENTIFIER, 'shipping');

        $this->assertSame(
            [Faq::CACHE_TAG, Faq::CACHE_TAG . '_group_returns', Faq::CACHE_TAG . '_group_shipping'],
            $faq->getIdentities()
        );
    }

    public function testLeavingEveryGroupPurgesTheOldGroupsPages(): void
    {
        $faq = $this->faq([FaqInterface::IDENTIFIER => '']);
        $faq->setOrigData(FaqInterface::IDENTIFIER, 'shipping');

        $this->assertSame([Faq::CACHE_TAG, Faq::CACHE_TAG . '_group_shipping'], $faq->getIdentities());
    }

    /**
     * A FAQ model over the given data, built through its constructor.
     *
     * The resource model is passed in: without one, AbstractModel::_init() resolves it through the
     * global object manager, which a unit test does not have.
     *
     * @param array<string,mixed> $data
     * @return Faq
     */
    private function faq(array $data = []): Faq
    {
        $resource = $this->createStub(FaqResource::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new Faq(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $this->createStub(ExtensionAttributesFactory::class),
            $this->createStub(AttributeValueFactory::class),
            $resource,
            null,
            $data
        );
    }
}
