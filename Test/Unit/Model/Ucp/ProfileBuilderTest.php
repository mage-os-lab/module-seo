<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Ucp;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Model\Ucp\CapabilityPool;
use MageOS\Seo\Model\Ucp\ProfileBuilder;
use MageOS\Seo\Model\Ucp\ServicePool;
use MageOS\Seo\Model\Ucp\UcpConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The /.well-known/ucp business profile, per UCP 2026-08-25: a `ucp` object holding the version and
 * the three registries, and a `keys` JWK Set when a public signing key is stored.
 */
class ProfileBuilderTest extends TestCase
{
    private const EC_KEY = '{"kty":"EC","crv":"P-256","use":"sig","kid":"ucp-key-2026-09","x":"AAA","y":"BBB"}';

    /**
     * @return void
     */
    public function testWithNothingRegisteredTheProfileDeclaresNothing(): void
    {
        $this->assertSame(
            '{"ucp":{"version":"2026-08-25","services":{},"capabilities":{},"payment_handlers":{}}}',
            $this->json($this->builder())
        );
    }

    /**
     * @return void
     */
    public function testRegisteredServicesAndCapabilitiesAreListedUnderTheirNames(): void
    {
        $rest = [
            'version'   => '2026-08-25',
            'transport' => 'rest',
            'endpoint'  => 'https://shop.test/ucp/v1',
        ];
        $checkout = [
            'version' => '2026-08-25',
            'schema'  => 'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json',
        ];

        $profile = json_decode($this->json($this->builder(
            services: ['dev.ucp.shopping' => [$rest]],
            capabilities: ['dev.ucp.shopping.checkout' => [$checkout]]
        )), true);

        $this->assertSame(['ucp'], array_keys($profile));
        $this->assertSame([$rest], $profile['ucp']['services']['dev.ucp.shopping']);
        $this->assertSame([$checkout], $profile['ucp']['capabilities']['dev.ucp.shopping.checkout']);
        $this->assertSame([], $profile['ucp']['payment_handlers']);
    }

    /**
     * @return void
     */
    public function testAStoredPublicKeyIsPublishedUnderKeys(): void
    {
        $profile = json_decode($this->json($this->builder(self::EC_KEY)), true);

        $this->assertSame(['ucp', 'keys'], array_keys($profile));
        $this->assertSame([json_decode(self::EC_KEY, true)], $profile['keys']);
    }

    /**
     * @return void
     */
    public function testAnEd25519KeyIsPublished(): void
    {
        $key = '{"kty":"OKP","crv":"Ed25519","kid":"k1","x":"AAA","alg":"EdDSA"}';

        $this->assertSame([json_decode($key, true)], $this->builder($key)->build()['keys']);
    }

    /**
     * Every private JWK member the UCP profile schema forbids.
     *
     * @return array<string, array{0: string}>
     */
    public static function privateKeyMembers(): array
    {
        return array_combine(
            ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'],
            array_map(static fn (string $member): array => [$member], ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'])
        );
    }

    /**
     * A leaked private key must never be served, so the whole profile is refused.
     *
     * @dataProvider privateKeyMembers
     * @param string $member
     * @return void
     */
    #[DataProvider('privateKeyMembers')]
    public function testAKeyCarryingPrivateMaterialIsRefusedOutright(string $member): void
    {
        $key = json_decode(self::EC_KEY, true);
        $key[$member] = 'SECRET';

        $this->expectException(LocalizedException::class);
        $this->builder((string) json_encode($key))->build();
    }

    /**
     * A stored key the schema would reject, and the reason logged.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unusableKeys(): array
    {
        return [
            'not JSON'                  => ['not json', 'is not a JSON object'],
            'a JSON list'               => ['["a"]', 'is not a JSON object'],
            'no kid'                    => ['{"kty":"EC","crv":"P-256","x":"A","y":"B"}', 'lacks kid.'],
            'no kty'                    => ['{"kid":"k","crv":"P-256","x":"A","y":"B"}', 'lacks kty.'],
            'EC without y'              => ['{"kid":"k","kty":"EC","crv":"P-256","x":"A"}', 'lacks y.'],
            'EC without crv'            => ['{"kid":"k","kty":"EC","x":"A","y":"B"}', 'lacks crv.'],
            'OKP without x'             => ['{"kid":"k","kty":"OKP","crv":"Ed25519"}', 'lacks x.'],
            'an empty kid'              => ['{"kid":"","kty":"OKP","crv":"Ed25519","x":"A"}', 'lacks kid.'],
            'alg not matching P-256'    => [
                '{"kid":"k","kty":"EC","crv":"P-256","x":"A","y":"B","alg":"ES384"}',
                'alg ES384 does not match curve P-256',
            ],
            'alg not matching Ed25519'  => [
                '{"kid":"k","kty":"OKP","crv":"Ed25519","x":"A","alg":"ES256"}',
                'alg ES256 does not match curve Ed25519',
            ],
        ];
    }

    /**
     * @dataProvider unusableKeys
     * @param string $key
     * @param string $reason
     * @return void
     */
    #[DataProvider('unusableKeys')]
    public function testAnUnusableStoredKeyIsLoggedAndLeftOut(string $key, string $reason): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains($reason));

        $this->assertArrayNotHasKey('keys', $this->builder($key, logger: $logger)->build());
    }

    /**
     * The log line says what is wrong and how to replace the key.
     *
     * @return void
     */
    public function testTheLogSaysHowToReplaceTheKey(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'MageOS_Seo: UCP signing key left out of /.well-known/ucp: the stored public JWK lacks kid. '
            . 'Run bin/magento mageos:seo:ucp:keygen to replace it.'
        );

        $this->builder('{"kty":"OKP","crv":"Ed25519","x":"A"}', logger: $logger)->build();
    }

    /**
     * @param string $publicKeyJwk
     * @param array<string, list<array<string, mixed>>> $services
     * @param array<string, list<array<string, mixed>>> $capabilities
     * @param LoggerInterface|null $logger
     * @return ProfileBuilder
     */
    private function builder(
        string $publicKeyJwk = '',
        array $services = [],
        array $capabilities = [],
        ?LoggerInterface $logger = null
    ): ProfileBuilder {
        $config = $this->createStub(UcpConfig::class);
        $config->method('getPublicKeyJwk')->willReturn($publicKeyJwk);
        $servicePool = $this->createStub(ServicePool::class);
        $servicePool->method('getEnabledServices')->willReturn($services);
        $capabilityPool = $this->createStub(CapabilityPool::class);
        $capabilityPool->method('getEnabledCapabilities')->willReturn($capabilities);

        return new ProfileBuilder(
            $config,
            $capabilityPool,
            $servicePool,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * The profile as the endpoint serves it.
     *
     * @param ProfileBuilder $builder
     * @return string
     */
    private function json(ProfileBuilder $builder): string
    {
        return (string) json_encode($builder->build(), JSON_UNESCAPED_SLASHES);
    }
}
