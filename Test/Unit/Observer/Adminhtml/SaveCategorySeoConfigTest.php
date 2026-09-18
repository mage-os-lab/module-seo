<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer\Adminhtml;

use Magento\Catalog\Model\Category;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Observer\Adminhtml\SaveCategorySeoConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaveCategorySeoConfigTest extends TestCase
{
    public function testIgnoresEventDataThatIsNotACategoryModel(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('save');
        $observer = $this->observer($repository);

        $observer->execute($this->eventFor(new DataObject([
            'entity_id'                  => 7,
            'mageos_seo_schema_template' => 'GenericProduct',
        ])));
        $observer->execute($this->eventFor(null));
    }

    public function testIgnoresCategoryWithoutAnId(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('save');

        $this->observer($repository)->execute($this->eventFor(
            $this->category(0, 0, ['mageos_seo_schema_template' => 'GenericProduct'])
        ));
    }

    public function testIgnoresCategoriesThatDoNotCarryTheSeoFieldset(): void
    {
        // Any other category saved during the admin request: no posted SEO fields on it.
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('save');

        $this->observer($repository)->execute($this->eventFor($this->category(7, 0, ['name' => 'Shirts'])));
    }

    public function testSavesEveryPostedFieldForTheCategoryStoreView(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(
            7,
            [
                'schema_template'   => 'GenericProduct',
                'enabled_fields'    => ['color', 'material'],
                'item_list_enabled' => 0,
                'robots_meta'       => 'NOINDEX,FOLLOW',
                'override_fields'   => ['gender' => 'Female'],
            ],
            2
        );
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addWarningMessage');

        $this->observer($repository, $messages)->execute($this->eventFor($this->category(7, 2, [
            'mageos_seo_schema_template'   => 'GenericProduct',
            'mageos_seo_enabled_fields'    => ['color', 'material'],
            'mageos_seo_item_list_enabled' => '0',
            'mageos_seo_robots_meta'       => 'NOINDEX,FOLLOW',
            'mageos_seo_override_fields'   => '{"gender":"Female"}',
        ])));
    }

    public function testEmptyValuesMapToTheirStoredDefaults(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(
            7,
            [
                'schema_template'   => '',
                'enabled_fields'    => [],
                'item_list_enabled' => null,
                'robots_meta'       => null,
                'override_fields'   => [],
            ],
            0
        );

        $this->observer($repository)->execute($this->eventFor($this->category(7, 0, [
            'mageos_seo_schema_template'   => '',
            'mageos_seo_enabled_fields'    => 'not-an-array',
            'mageos_seo_item_list_enabled' => '',
            'mageos_seo_robots_meta'       => '',
            'mageos_seo_override_fields'   => '',
        ])));
    }

    public function testOnlyPostedFieldsAreSaved(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(7, ['item_list_enabled' => 1], 0);

        $this->observer($repository)->execute($this->eventFor(
            $this->category(7, 0, ['mageos_seo_item_list_enabled' => '1'])
        ));
    }

    public function testNegativeStoreIdFallsBackToDefaultScope(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(7, ['robots_meta' => 'INDEX,FOLLOW'], 0);

        $this->observer($repository)->execute($this->eventFor(
            $this->category(7, -1, ['mageos_seo_robots_meta' => 'INDEX,FOLLOW'])
        ));
    }

    public function testInvalidOverrideJsonIsAWarningAndOtherFieldsAreStillSaved(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->once())->method('save')->with(7, ['robots_meta' => 'INDEX,NOFOLLOW'], 0);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage')
            ->with('SEO override fields were not saved: the value is not valid JSON.');
        $messages->expects($this->never())->method('addErrorMessage');

        $this->observer($repository, $messages)->execute($this->eventFor($this->category(7, 0, [
            'mageos_seo_robots_meta'     => 'INDEX,NOFOLLOW',
            'mageos_seo_override_fields' => '{nope',
        ])));
    }

    public function testInvalidOverrideJsonAloneSavesNothing(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->never())->method('save');
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage');

        $this->observer($repository, $messages)->execute($this->eventFor(
            $this->category(7, 0, ['mageos_seo_override_fields' => '{nope'])
        ));
    }

    public function testRepositoryFailureIsLoggedAndReportedAsAWarning(): void
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('save')->willThrowException(new \RuntimeException('db down'));
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage')
            ->with('The category was saved, but its SEO settings could not be saved.');
        $messages->expects($this->never())->method('addErrorMessage');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('db down'));

        $this->observer($repository, $messages, $logger)->execute($this->eventFor(
            $this->category(7, 0, ['mageos_seo_schema_template' => 'GenericProduct'])
        ));
    }

    /**
     * Build the observer; collaborators a test does not pass are stubs.
     *
     * @param ConfigRepository $repository
     * @param ManagerInterface|null $messages
     * @param LoggerInterface|null $logger
     * @return SaveCategorySeoConfig
     */
    private function observer(
        ConfigRepository $repository,
        ?ManagerInterface $messages = null,
        ?LoggerInterface $logger = null
    ): SaveCategorySeoConfig {
        return new SaveCategorySeoConfig(
            $repository,
            $messages ?? $this->createStub(ManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A saved category carrying the given data, as core's save controller leaves it.
     *
     * @param int $id
     * @param int $storeId
     * @param array<string, mixed> $data
     * @return Category
     */
    private function category(int $id, int $storeId, array $data): Category
    {
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getStoreId')->willReturn($storeId);
        $category->method('getData')->willReturnCallback(
            static fn ($key = '') => $data[$key] ?? null
        );

        return $category;
    }

    /**
     * @param object|null $category
     * @return Observer
     */
    private function eventFor(?object $category): Observer
    {
        return new Observer(['event' => new Event(['category' => $category])]);
    }
}
