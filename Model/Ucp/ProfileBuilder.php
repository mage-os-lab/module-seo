<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Builds the /.well-known/ucp business profile (Universal Commerce Protocol 2026-08-25).
 *
 * The profile declares only what is registered: services from ServicePool and capabilities from
 * CapabilityPool, each already checked against the UCP schema. Nothing registers by default, so out
 * of the box the profile is valid and declares nothing: this module serves the discovery document,
 * not a UCP implementation. `payment_handlers` is required by the schema and has no registration
 * point, so it is always empty. Each registry is emitted as a JSON object, `{}` when empty.
 *
 * A stored public signing key is published in `keys` (a JWK Set). A key carrying private material
 * refuses the whole profile rather than risk serving it; a key the schema would otherwise reject is
 * logged and left out.
 */
class ProfileBuilder
{
    public const UCP_VERSION = '2026-08-25';

    /**
     * JWK members holding private key material, which a UCP profile MUST NOT carry.
     */
    private const PRIVATE_MEMBERS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * Members each well-known key type requires beyond `kid` and `kty`.
     */
    private const TYPE_MEMBERS = ['EC' => ['crv', 'x', 'y'], 'OKP' => ['crv', 'x']];

    /**
     * The algorithm each well-known curve pairs with, when a key names one.
     */
    private const CURVE_ALGORITHMS = ['P-256' => 'ES256', 'P-384' => 'ES384', 'Ed25519' => 'EdDSA'];

    /**
     * @param UcpConfig $config
     * @param CapabilityPool $capabilityPool
     * @param ServicePool $servicePool
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly UcpConfig $config,
        private readonly CapabilityPool $capabilityPool,
        private readonly ServicePool $servicePool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Build the UCP business profile; the registries are objects so they encode as JSON objects.
     *
     * @throws LocalizedException When the stored public JWK leaks private key material.
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $profile = [
            'ucp' => [
                'version'          => self::UCP_VERSION,
                'services'         => (object) $this->servicePool->getEnabledServices(),
                'capabilities'     => (object) $this->capabilityPool->getEnabledCapabilities(),
                'payment_handlers' => new \stdClass(),
            ],
        ];

        $key = $this->buildKey();
        if ($key !== null) {
            $profile['keys'] = [$key];
        }

        return $profile;
    }

    /**
     * The stored public signing key, or null when there is none or it is unusable.
     *
     * @throws LocalizedException When the JWK contains private key material.
     * @return array<string, mixed>|null
     */
    private function buildKey(): ?array
    {
        $raw = $this->config->getPublicKeyJwk();
        if ($raw === '') {
            return null;
        }

        $jwk = json_decode($raw, true);
        if (!\is_array($jwk) || array_is_list($jwk)) {
            $this->logKeyLeftOut('is not a JSON object');
            return null;
        }

        if (array_intersect(self::PRIVATE_MEMBERS, array_keys($jwk)) !== []) {
            throw new LocalizedException(
                new Phrase('UCP signing key is misconfigured: the stored public JWK contains private key material.')
            );
        }

        $kty = \is_string($jwk['kty'] ?? null) ? $jwk['kty'] : '';
        $missing = array_filter(
            ['kid', 'kty', ...(self::TYPE_MEMBERS[$kty] ?? [])],
            static fn (string $member): bool => !\is_string($jwk[$member] ?? null) || $jwk[$member] === ''
        );
        if ($missing !== []) {
            $this->logKeyLeftOut('lacks ' . implode(', ', $missing));
            return null;
        }

        $crv = \is_string($jwk['crv'] ?? null) ? $jwk['crv'] : '';
        $alg = $jwk['alg'] ?? null;
        $expected = self::CURVE_ALGORITHMS[$crv] ?? null;
        if ($alg !== null && $expected !== null && $alg !== $expected) {
            $this->logKeyLeftOut(\sprintf(
                'alg %s does not match curve %s',
                \is_string($alg) ? $alg : (string) json_encode($alg),
                $crv
            ));
            return null;
        }

        return $jwk;
    }

    /**
     * Log why the stored key is not published.
     *
     * @param string $reason
     * @return void
     */
    private function logKeyLeftOut(string $reason): void
    {
        $this->logger->error(\sprintf(
            'MageOS_Seo: UCP signing key left out of /.well-known/ucp: the stored public JWK %s. '
            . 'Run bin/magento mageos:seo:ucp:keygen to replace it.',
            $reason
        ));
    }
}
