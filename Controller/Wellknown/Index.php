<?php

declare(strict_types=1);

namespace MageOS\Seo\Controller\Wellknown;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Model\Feed\CanonicalPathRedirect;
use MageOS\Seo\Model\Router\PublicPaths;
use MageOS\Seo\Model\WellKnown\EndpointPool;
use Psr\Log\LoggerInterface;

/**
 * Dispatches /.well-known/* requests to the matching registered endpoint.
 *
 * Returns 404 when the endpoint is unknown or disabled, and 500 (logged) when an endpoint fails
 * to render — for UCP this guards against ever serving a manifest with a leaked private key.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param EndpointPool $endpointPool
     * @param RawFactory $rawFactory
     * @param RequestInterface $request
     * @param CanonicalPathRedirect $canonicalPathRedirect
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EndpointPool $endpointPool,
        private readonly RawFactory $rawFactory,
        private readonly RequestInterface $request,
        private readonly CanonicalPathRedirect $canonicalPathRedirect,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render the requested well-known document.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $name = (string) $this->request->getParam('endpoint');

        // Query-string variants and the internal /mageos-seo/... URL collapse to the canonical
        // path, as the feeds do, so neither can be used to force cache-missing requests.
        if ($name !== '') {
            $redirect = $this->canonicalPathRedirect->check(PublicPaths::WELL_KNOWN_PREFIX . $name);
            if ($redirect !== null) {
                return $redirect;
            }
        }

        $result = $this->rawFactory->create();
        $endpoint = $this->endpointPool->get($name);

        if ($endpoint === null || !$endpoint->isEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        try {
            $body = $endpoint->render();
        } catch (LocalizedException $e) {
            $this->logger->error('MageOS_Seo well-known endpoint failed: ' . $e->getMessage());
            $result->setHttpResponseCode(500);
            $result->setContents('');
            return $result;
        }

        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', $endpoint->getContentType(), true);
        $result->setHeader('Cache-Control', $endpoint->getCacheControl(), true);
        $result->setContents($body);

        return $result;
    }
}
