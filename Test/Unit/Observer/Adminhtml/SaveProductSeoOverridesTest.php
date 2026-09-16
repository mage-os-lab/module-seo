<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer\Adminhtml;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Observer\Adminhtml\SaveProductSeoOverrides;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SaveProductSeoOverridesTest extends TestCase
{
    public function testIgnoresEventWithoutAProduct(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->never())->method('save');

        $this->observer(['mageos_seo_robots_meta' => 'NOINDEX,FOLLOW'], '0', $repository)
            ->execute($this->eventFor(null));
    }

    public function testIgnoresProductWithoutAnId(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->never())->method('save');

        $this->observer(['mageos_seo_robots_meta' => 'NOINDEX,FOLLOW'], '0', $repository)
            ->execute($this->eventFor($this->product(0)));
    }

    public function testIgnoresRequestsWithoutTheSeoFieldset(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->never())->method('save');

        $this->observer(['product' => ['name' => 'Shirt']], '0', $repository)
            ->execute($this->eventFor($this->product(42)));
    }

    public function testSavesOverridesAndRobotsForTheStoreViewInTheRequest(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->once())->method('save')->with(
            42,
            3,
            ['override_fields' => ['gtin13' => '0123456789012'], 'robots_meta' => 'NOINDEX,FOLLOW']
        );
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->never())->method('addWarningMessage');

        $this->observer(
            [
                'mageos_seo_override_fields' => '{"gtin13":"0123456789012"}',
                'mageos_seo_robots_meta'     => 'NOINDEX,FOLLOW',
            ],
            '3',
            $repository,
            $messages
        )->execute($this->eventFor($this->product(42)));
    }

    public function testEmptyValuesClearOverridesAndRobotsMeta(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->once())->method('save')
            ->with(42, 0, ['override_fields' => [], 'robots_meta' => null]);

        $this->observer(['mageos_seo_override_fields' => '', 'mageos_seo_robots_meta' => ''], '0', $repository)
            ->execute($this->eventFor($this->product(42)));
    }

    public function testNegativeStoreParameterFallsBackToDefaultScope(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->once())->method('save')
            ->with(42, 0, ['robots_meta' => 'INDEX,FOLLOW']);

        $this->observer(['mageos_seo_robots_meta' => 'INDEX,FOLLOW'], '-5', $repository)
            ->execute($this->eventFor($this->product(42)));
    }

    public function testInvalidOverrideJsonIsAWarningAndOtherFieldsAreStillSaved(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->once())->method('save')
            ->with(42, 0, ['robots_meta' => 'NOINDEX,FOLLOW']);
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage')
            ->with('SEO override fields were not saved: the value is not valid JSON.');
        $messages->expects($this->never())->method('addErrorMessage');

        $this->observer(
            ['mageos_seo_override_fields' => '{nope', 'mageos_seo_robots_meta' => 'NOINDEX,FOLLOW'],
            '0',
            $repository,
            $messages
        )->execute($this->eventFor($this->product(42)));
    }

    public function testInvalidOverrideJsonAloneSavesNothing(): void
    {
        $repository = $this->createMock(ProductOverrideRepository::class);
        $repository->expects($this->never())->method('save');
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage');

        $this->observer(['mageos_seo_override_fields' => '{nope'], '0', $repository, $messages)
            ->execute($this->eventFor($this->product(42)));
    }

    public function testRepositoryFailureIsLoggedAndReportedAsAWarning(): void
    {
        $repository = $this->createStub(ProductOverrideRepository::class);
        $repository->method('save')->willThrowException(new \RuntimeException('db down'));
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects($this->once())->method('addWarningMessage')
            ->with('The product was saved, but its SEO overrides could not be saved.');
        $messages->expects($this->never())->method('addErrorMessage');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('db down'));

        $this->observer(['mageos_seo_robots_meta' => 'NOINDEX,FOLLOW'], '0', $repository, $messages, $logger)
            ->execute($this->eventFor($this->product(42)));
    }

    /**
     * Build the observer around a request carrying the given POST data and store parameter.
     *
     * Collaborators a test does not pass are stubs.
     *
     * @param array<string, mixed> $post
     * @param string $storeParam
     * @param ProductOverrideRepository|null $repository
     * @param ManagerInterface|null $messages
     * @param LoggerInterface|null $logger
     * @return SaveProductSeoOverrides
     */
    private function observer(
        array $post,
        string $storeParam,
        ?ProductOverrideRepository $repository = null,
        ?ManagerInterface $messages = null,
        ?LoggerInterface $logger = null
    ): SaveProductSeoOverrides {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getPostValue')->willReturn($post);
        $request->method('getParam')->willReturnMap([['store', 0, $storeParam]]);

        return new SaveProductSeoOverrides(
            $request,
            $repository ?? $this->createStub(ProductOverrideRepository::class),
            $messages ?? $this->createStub(ManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * @param int $id
     * @return ProductInterface
     */
    private function product(int $id): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }

    /**
     * @param ProductInterface|null $product
     * @return Observer
     */
    private function eventFor(?ProductInterface $product): Observer
    {
        return new Observer(['event' => new Event(['product' => $product])]);
    }
}
