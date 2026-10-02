<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Controller\Llms;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Controller\Llms\Index;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Router\CanonicalPathRedirect;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Doubles Magento's generated RawFactory, so it runs in the unit job inside an
 * installation, not under Infection.
 */
#[Group('magento-generated')]
class IndexTest extends TestCase
{
    /**
     * @var Raw&Stub
     */
    private Raw&Stub $result;

    /**
     * @var FeedStorage&Stub
     */
    private FeedStorage&Stub $storage;

    /**
     * @var RegenerationRequester&MockObject
     */
    private RegenerationRequester&MockObject $requester;

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    private ?int $code = null;

    protected function setUp(): void
    {
        $this->result = $this->createStub(Raw::class);
        $this->result->method('setHttpResponseCode')->willReturnCallback(function (int $code) {
            $this->code = $code;
            return $this->result;
        });
        $this->result->method('setHeader')->willReturnCallback(function (string $name, string $value) {
            $this->headers[$name] = $value;
            return $this->result;
        });
        $this->storage   = $this->createStub(FeedStorage::class);
        $this->requester = $this->createMock(RegenerationRequester::class);
    }

    private function controller(): Index
    {
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($this->result);

        $config = $this->createStub(Config::class);
        $config->method('isLlmsTxtEnabled')->willReturn(true);

        $redirect = $this->createStub(CanonicalPathRedirect::class);
        $redirect->method('check')->willReturn(null);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Index($rawFactory, $config, $this->storage, $redirect, $this->requester, $storeManager);
    }

    public function testMissingFileQueuesRebuildAndAnswers404NotServerError(): void
    {
        // Lighthouse scores a 5xx llms.txt as a failure, a 4xx as not applicable.
        $this->storage->method('read')->willReturn(null);
        $this->requester->expects($this->once())->method('request');

        $this->controller()->execute();

        $this->assertSame(404, $this->code);
        $this->assertSame('120', $this->headers['Retry-After'] ?? null);
        $this->assertSame('no-store', $this->headers['Cache-Control'] ?? null);
    }

    public function testStoredFileIsServedAsPlainText(): void
    {
        $this->storage->method('read')->willReturn("# Shop\n");
        $this->requester->expects($this->never())->method('request');

        $this->controller()->execute();

        $this->assertSame(200, $this->code);
        $this->assertSame('text/plain; charset=utf-8', $this->headers['Content-Type'] ?? null);
    }
}
