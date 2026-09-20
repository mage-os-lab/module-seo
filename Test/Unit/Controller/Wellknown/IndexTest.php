<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Controller\Wellknown;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use MageOS\Seo\Api\WellKnownEndpointInterface;
use MageOS\Seo\Controller\Wellknown\Index;
use MageOS\Seo\Model\Feed\CanonicalPathRedirect;
use MageOS\Seo\Model\WellKnown\EndpointPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Review finding S6: /.well-known/ answered every URL variant that reached it, while the feeds
 * collapse theirs onto the canonical path.
 */
class IndexTest extends TestCase
{
    /**
     * The canonical path the controller asked the redirect service about.
     *
     * @var string|null
     */
    private ?string $askedFor = null;

    protected function setUp(): void
    {
        $this->askedFor = null;
    }

    public function testAnUncanonicalUrlIsRedirectedToTheWellKnownPath(): void
    {
        $redirect = $this->createStub(Redirect::class);

        $result = $this->controller('security.txt', $redirect)->execute();

        $this->assertSame($redirect, $result);
        $this->assertSame('.well-known/security.txt', $this->askedFor);
    }

    public function testTheCanonicalUrlIsServedRatherThanRedirected(): void
    {
        $result = $this->controller('security.txt', null)->execute();

        $this->assertInstanceOf(Raw::class, $result);
        $this->assertSame('.well-known/security.txt', $this->askedFor);
    }

    public function testAnEmptyEndpointNameIsNotSentRoundTheRedirect(): void
    {
        // Nothing to be canonical about, and asking would send the visitor to /.well-known/.
        $result = $this->controller('', $this->createStub(Redirect::class))->execute();

        $this->assertInstanceOf(Raw::class, $result);
        $this->assertNull($this->askedFor);
    }

    /**
     * The controller with a redirect service that returns $redirect and records what it was asked.
     *
     * @param string $endpointName
     * @param Redirect|null $redirect
     * @return Index
     */
    private function controller(string $endpointName, ?Redirect $redirect): Index
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($endpointName);

        $canonicalPathRedirect = $this->createStub(CanonicalPathRedirect::class);
        $canonicalPathRedirect->method('check')->willReturnCallback(
            function (string $path) use ($redirect): ?Redirect {
                $this->askedFor = $path;
                return $redirect;
            }
        );

        $endpoint = $this->createStub(WellKnownEndpointInterface::class);
        $endpoint->method('isEnabled')->willReturn(true);
        $endpoint->method('render')->willReturn('Contact: mailto:security@example.com');
        $endpoint->method('getContentType')->willReturn('text/plain');
        $endpoint->method('getCacheControl')->willReturn('public, max-age=86400');

        $endpointPool = $this->createStub(EndpointPool::class);
        $endpointPool->method('get')->willReturn($endpoint);

        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturnCallback(fn (): Raw => $this->createStub(Raw::class));

        return new Index(
            $endpointPool,
            $rawFactory,
            $request,
            $canonicalPathRedirect,
            $this->createStub(LoggerInterface::class)
        );
    }
}
