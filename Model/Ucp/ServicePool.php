<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

use MageOS\Seo\Api\UcpServiceProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Collect-all pool of the UCP service bindings the /.well-known/ucp profile declares.
 *
 * Empty by default: MageOS_Seo serves the discovery document, and a module that serves a UCP
 * service appends a provider per transport binding via di.xml. Each binding is checked against the
 * UCP business schema; one that fails is logged with its provider and the reasons, and left out.
 */
class ServicePool
{
    /**
     * @param EntryValidator $validator
     * @param LoggerInterface $logger
     * @param UcpServiceProviderInterface[] $providers
     */
    public function __construct(
        private readonly EntryValidator $validator,
        private readonly LoggerInterface $logger,
        private readonly array $providers = []
    ) {
    }

    /**
     * The profile's service registry: each enabled service name maps to its list of bindings.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getEnabledServices(): array
    {
        $services = [];
        foreach ($this->providers as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }

            $name    = $provider->getServiceKey();
            $entry   = $provider->getServiceData();
            $reasons = $this->validator->validateService($name, $entry);
            if ($reasons !== []) {
                $this->logger->error(\sprintf(
                    'MageOS_Seo: UCP service "%s" from %s left out of /.well-known/ucp: %s.',
                    $name,
                    $provider::class,
                    implode('; ', $reasons)
                ));
                continue;
            }

            $services[$name][] = $entry;
        }

        return $services;
    }
}
