<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Ucp;

use MageOS\Seo\Model\Organisation\ContactEmail;

/**
 * Builds the /.well-known/ai-plugin.json manifest (OpenAI plugin discovery format).
 *
 * Data is sourced from UCP config and store identity; the OpenAPI url points at Magento's
 * built-in REST schema endpoint, so no new API surface is introduced. `contact_email` is the
 * contact /llms.txt publishes (Organisation\ContactEmail), and '' when there is none: the manifest
 * format requires the key.
 */
class AiPluginBuilder
{
    /**
     * @param UcpConfig $config
     * @param ContactEmail $contactEmail
     */
    public function __construct(
        private readonly UcpConfig    $config,
        private readonly ContactEmail $contactEmail
    ) {
    }

    /**
     * Build the ai-plugin.json manifest as an associative array.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $name    = $this->config->getMerchantName();
        $baseUrl = $this->config->getBaseUrl();

        return [
            'schema_version'        => 'v1',
            'name_for_model'        => $this->modelName($name),
            'name_for_human'        => $name,
            'description_for_model' => $this->description(),
            'description_for_human' => $this->description(),
            'auth'                  => ['type' => 'none'],
            'api'                   => [
                'type'                 => 'openapi',
                'url'                  => $baseUrl . '/rest/all/schema?services=catalogProductRepositoryV1',
                'is_user_authenticated' => false,
            ],
            'contact_email'  => $this->contactEmail->get(),
            'legal_info_url' => $this->config->getAiPluginLegalUrl(),
        ];
    }

    /**
     * Sanitise a display name into a model-safe identifier (lowercase alphanumeric + underscores).
     *
     * @param string $name
     * @return string
     */
    private function modelName(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name));

        return trim($slug, '_');
    }

    /**
     * The agent-facing description, truncated for legibility.
     *
     * @return string
     */
    private function description(): string
    {
        $description = $this->config->getAiPluginDescription();
        if ($description === '') {
            $description = $this->config->getMerchantName();
        }

        if (mb_strlen($description) > 200) {
            $description = rtrim(mb_substr($description, 0, 200));
        }

        return $description;
    }
}
