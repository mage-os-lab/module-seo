<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData\Provider;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Api\Data\OrganisationInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\StructuredData\Provider\OrganizationProvider;

class OrganizationProviderTest extends TestCase
{
    /**
     * @var OrganisationRepositoryInterface&MockObject
     */
    private OrganisationRepositoryInterface&MockObject $repository;

    /**
     * @var OrganizationProvider
     */
    private OrganizationProvider $provider;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrganisationRepositoryInterface::class);
        $this->provider   = new OrganizationProvider($this->repository);
    }

    /**
     * Create a fully configured Organisation mock.
     *
     * @param array<string, mixed> $config
     */
    private function makeOrg(array $config = []): OrganisationInterface&MockObject
    {
        $org = $this->createMock(OrganisationInterface::class);
        $org->method('getName')->willReturn($config['name'] ?? '');
        $org->method('getUrl')->willReturn($config['url'] ?? '');
        $org->method('getOrgType')->willReturn($config['org_type'] ?? 'Organization');
        $org->method('getLogoPath')->willReturn($config['logo_path'] ?? '');
        $org->method('getLogoWidth')->willReturn($config['logo_width'] ?? 0);
        $org->method('getLogoHeight')->willReturn($config['logo_height'] ?? 0);
        $org->method('getDescription')->willReturn($config['description'] ?? '');
        $org->method('getSocialProfiles')->willReturn($config['social_profiles'] ?? []);
        $org->method('getContactPoint')->willReturn($config['contact_point'] ?? []);
        return $org;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function acmeOrg(array $overrides = []): OrganisationInterface&MockObject
    {
        return $this->makeOrg(array_merge(['name' => 'Acme Ltd', 'url' => 'https://acme.com'], $overrides));
    }

    public function testGetHandlesReturnsWildcard(): void
    {
        $this->assertSame(['*'], $this->provider->getHandles());
    }

    public function testGetSchemasReturnsEmptyWhenOrganisationNameIsEmpty(): void
    {
        $this->repository->method('get')->willReturn($this->makeOrg());
        $this->assertSame([], $this->provider->getSchemas());
    }

    public function testGetSchemasReturnsTwoSchemasWhenNameIsSet(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $this->assertCount(2, $this->provider->getSchemas());
    }

    public function testOrganizationSchemaHasCorrectType(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg(['org_type' => 'Corporation']));
        $schemas = $this->provider->getSchemas();
        $this->assertSame('Corporation', $schemas[0]['@type']);
    }

    public function testOrganizationSchemaIdEndsWithHashOrganization(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertStringEndsWith('/#organization', $schemas[0]['@id']);
    }

    public function testTrailingSlashIsStrippedFromUrl(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg(['url' => 'https://acme.com/']));
        $schemas = $this->provider->getSchemas();
        $this->assertSame('https://acme.com', $schemas[0]['url']);
    }

    public function testLogoIncludedWhenPathIsSet(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg([
            'logo_path' => 'https://acme.com/logo.png',
        ]));
        $schemas = $this->provider->getSchemas();
        $this->assertArrayHasKey('logo', $schemas[0]);
        $this->assertSame('https://acme.com/logo.png', $schemas[0]['logo']['url']);
        $this->assertSame('ImageObject', $schemas[0]['logo']['@type']);
    }

    public function testLogoWidthAndHeightIncludedWhenPositive(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg([
            'logo_path'   => 'https://acme.com/logo.png',
            'logo_width'  => 200,
            'logo_height' => 60,
        ]));
        $schemas = $this->provider->getSchemas();
        $this->assertSame(200, $schemas[0]['logo']['width']);
        $this->assertSame(60, $schemas[0]['logo']['height']);
    }

    public function testLogoWidthAndHeightOmittedWhenZero(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg([
            'logo_path'   => 'https://acme.com/logo.png',
            'logo_width'  => 0,
            'logo_height' => 0,
        ]));
        $schemas = $this->provider->getSchemas();
        $this->assertArrayNotHasKey('width', $schemas[0]['logo']);
        $this->assertArrayNotHasKey('height', $schemas[0]['logo']);
    }

    public function testLogoNotIncludedWhenPathIsEmpty(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertArrayNotHasKey('logo', $schemas[0]);
    }

    public function testDescriptionIncludedWhenNotEmpty(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg(['description' => 'Quality widgets']));
        $schemas = $this->provider->getSchemas();
        $this->assertSame('Quality widgets', $schemas[0]['description']);
    }

    public function testDescriptionOmittedWhenEmpty(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertArrayNotHasKey('description', $schemas[0]);
    }

    public function testSocialProfilesAddedAsSameAs(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg([
            'social_profiles' => [
                'twitter'  => 'https://twitter.com/acme',
                'linkedin' => 'https://linkedin.com/company/acme',
            ],
        ]));
        $schemas = $this->provider->getSchemas();
        $this->assertArrayHasKey('sameAs', $schemas[0]);
        $this->assertContains('https://twitter.com/acme', $schemas[0]['sameAs']);
        $this->assertContains('https://linkedin.com/company/acme', $schemas[0]['sameAs']);
    }

    public function testSameAsOmittedWhenNoSocialProfiles(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertArrayNotHasKey('sameAs', $schemas[0]);
    }

    public function testContactPointIncludedWhenSet(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg([
            'contact_point' => [
                'contactType' => 'customer support',
                'email'       => 'support@acme.com',
            ],
        ]));
        $schemas = $this->provider->getSchemas();
        $this->assertArrayHasKey('contactPoint', $schemas[0]);
        $this->assertSame('ContactPoint', $schemas[0]['contactPoint']['@type']);
        $this->assertSame('customer support', $schemas[0]['contactPoint']['contactType']);
    }

    public function testContactPointOmittedWhenEmpty(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertArrayNotHasKey('contactPoint', $schemas[0]);
    }

    public function testWebSiteSchemaIsSecondElement(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertSame('WebSite', $schemas[1]['@type']);
    }

    public function testWebSiteSchemaContainsSearchAction(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertArrayHasKey('potentialAction', $schemas[1]);
        $this->assertSame('SearchAction', $schemas[1]['potentialAction']['@type']);
    }

    public function testWebSiteSchemaUrlTemplateContainsSearchParam(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas     = $this->provider->getSchemas();
        $urlTemplate = $schemas[1]['potentialAction']['target']['urlTemplate'];
        $this->assertStringContainsString('{search_term_string}', $urlTemplate);
        $this->assertStringContainsString('catalogsearch/result', $urlTemplate);
    }

    public function testWebSiteSchemaPublisherLinksToOrganization(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas     = $this->provider->getSchemas();
        $orgId       = $schemas[0]['@id'];
        $publisherId = $schemas[1]['publisher']['@id'];
        $this->assertSame($orgId, $publisherId);
    }

    public function testOrganizationSchemaContextIsSchemaOrg(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg());
        $schemas = $this->provider->getSchemas();
        $this->assertSame('https://schema.org', $schemas[0]['@context']);
    }

    public function testOrganizationNameIsPresentInSchema(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg(['name' => 'Widget Corp']));
        $schemas = $this->provider->getSchemas();
        $this->assertSame('Widget Corp', $schemas[0]['name']);
    }

    public function testSocialProfilesAreIndexedAsArrayValues(): void
    {
        $this->repository->method('get')->willReturn($this->acmeOrg([
            'social_profiles' => ['fb' => 'https://facebook.com/acme'],
        ]));
        $schemas = $this->provider->getSchemas();
        // sameAs should be re-indexed (not associative)
        $this->assertSame(['https://facebook.com/acme'], $schemas[1 - 1]['sameAs'] ?? $schemas[0]['sameAs']);
    }
}
