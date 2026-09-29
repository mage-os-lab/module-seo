<?php

declare(strict_types=1);

namespace MageOS\Seo\Console\Command;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\Ucp\UcpConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates an ECDSA P-256 (ES256) signing keypair for the UCP profile.
 *
 * The private key PEM is stored encrypted in config; only the public JWK (x/y, no "d") is stored
 * in plain text and printed. The private key is never printed.
 */
class UcpKeygenCommand extends Command
{
    private const OPTION_WEBSITE = 'website';

    /**
     * @param EncryptorInterface $encryptor
     * @param WriterInterface $configWriter
     * @param string|null $name
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly WriterInterface $configWriter,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('ucp:keygen')
            ->setDescription('Generate an ECDSA P-256 signing keypair for the UCP /.well-known/ucp profile')
            ->addOption(
                self::OPTION_WEBSITE,
                null,
                InputOption::VALUE_REQUIRED,
                'Website id to store the keypair against',
                '0'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $websiteId = (int) $input->getOption(self::OPTION_WEBSITE);

        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if ($resource === false) {
            $output->writeln('<error>Failed to generate EC keypair (OpenSSL EC support unavailable).</error>');
            return Command::FAILURE;
        }

        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) {
            $output->writeln('<error>Failed to export private key.</error>');
            return Command::FAILURE;
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            $output->writeln('<error>Failed to read EC key coordinates.</error>');
            return Command::FAILURE;
        }

        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'use' => 'sig',
            'kid' => 'ucp-key-' . date('Y-m'),
            'x'   => $this->base64Url($details['ec']['x']),
            'y'   => $this->base64Url($details['ec']['y']),
        ];

        $scope = $websiteId > 0 ? ScopeInterface::SCOPE_WEBSITES : 'default';

        $this->configWriter->save(
            UcpConfig::XML_UCP_SIGNING_PRIVATE,
            $this->encryptor->encrypt($privatePem),
            $scope,
            $websiteId
        );

        $publicJwkJson = (string) json_encode($jwk, JSON_UNESCAPED_SLASHES);
        $this->configWriter->save(
            UcpConfig::XML_UCP_SIGNING_JWK,
            $publicJwkJson,
            $scope,
            $websiteId
        );

        $output->writeln('<info>UCP signing keypair generated for website ' . $websiteId . '.</info>');
        $output->writeln('Public JWK (also stored in config):');
        $output->writeln((string) json_encode($jwk, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $output->writeln('<comment>Run "bin/magento cache:flush config" for the change to take effect.</comment>');

        return Command::SUCCESS;
    }

    /**
     * Base64url-encode binary data (no padding), per JWK encoding rules.
     *
     * @param string $binary
     * @return string
     */
    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
