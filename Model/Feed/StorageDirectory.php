<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Phrase;

/**
 * Decides whether a configured feed storage directory may be written to.
 *
 * The setting exists for multi-server deployments, where web servers and the cron host need a
 * shared mount, so it has to accept an absolute path outside the installation. Left unguarded,
 * that is an administrator writing files anywhere PHP can reach.
 *
 * Two rules, applied in this order:
 *
 *  - inside the installation, only var/ — every other standard directory (app, bin, dev,
 *    generated, lib, pub, setup, update, vendor) and the root itself are refused, so no value can
 *    reach the codebase, and no dot directory (.git, .ssh, .magento) is reachable anywhere;
 *  - outside the installation, only a root listed in env.php under mageos_seo/feed_storage_roots,
 *    which is deployment configuration an administrator cannot edit from the admin panel.
 *
 * Paths are resolved before they are judged, so a symlink inside var/ cannot stand for a target
 * outside it.
 */
class StorageDirectory
{
    /**
     * The deployment-config key holding the roots an installation permits.
     */
    public const DEPLOYMENT_CONFIG_PATH = 'mageos_seo/feed_storage_roots';

    /**
     * @param DirectoryList $directoryList
     * @param DeploymentConfig $deploymentConfig
     * @param FileDriver $fileDriver
     */
    public function __construct(
        private readonly DirectoryList    $directoryList,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly FileDriver       $fileDriver
    ) {
    }

    /**
     * Whether a configured value may be used.
     *
     * An empty value is the default: var/mageos_seo, which needs no configuration at all.
     *
     * @param string $path
     * @return bool
     */
    public function isAllowed(string $path): bool
    {
        return $this->reject(trim($path)) === null;
    }

    /**
     * Check a value and explain the refusal, for the admin save path.
     *
     * @param string $path
     * @throws LocalizedException
     * @return void
     */
    public function validate(string $path): void
    {
        $reason = $this->reject(trim($path));
        if ($reason !== null) {
            throw new LocalizedException($reason);
        }
    }

    /**
     * Why this path may not be used, or null when it may.
     *
     * @param string $path
     * @return Phrase|null
     */
    private function reject(string $path): ?Phrase
    {
        if ($path === '') {
            return null;
        }

        if (!str_starts_with($path, '/')) {
            return new Phrase('The feed storage directory must be an absolute path.');
        }
        if (str_contains($path, '..')) {
            return new Phrase('The feed storage directory must not contain "..".');
        }
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (str_starts_with($segment, '.')) {
                return new Phrase('The feed storage directory must not contain hidden directories.');
            }
        }

        // Resolve before judging: a symlink under var/ must not stand for a target outside it.
        $resolved = $this->resolve($path);
        if ($resolved === '') {
            return new Phrase('The feed storage directory does not exist: %1', [$path]);
        }
        if (!$this->fileDriver->isDirectory($resolved) || !$this->fileDriver->isWritable($resolved)) {
            return new Phrase('The feed storage directory is not a writable directory: %1', [$path]);
        }

        if ($this->isWithin($resolved, $this->realRoot(DirectoryList::VAR_DIR))) {
            return null;
        }

        // The installation rule is checked before the declared roots, not after: a root declared
        // in env.php extends where feeds may go, it does not open up the codebase. Entries copied
        // between environments or left behind by a template are exactly how one would come to
        // point at pub/ — which is web-served — so such a root is ignored rather than obeyed.
        if ($this->isWithin($resolved, $this->realRoot(DirectoryList::ROOT))) {
            return new Phrase(
                'Inside the installation only var/ may be used. To store feeds elsewhere, add the'
                . ' directory to "%1" in app/etc/env.php.',
                [self::DEPLOYMENT_CONFIG_PATH]
            );
        }

        foreach ($this->permittedRoots() as $root) {
            if ($this->isWithin($resolved, $root)) {
                return null;
            }
        }

        return new Phrase(
            'The feed storage directory must be inside var/, or a directory listed under "%1" in'
            . ' app/etc/env.php.',
            [self::DEPLOYMENT_CONFIG_PATH]
        );
    }

    /**
     * Roots this installation has declared in deployment configuration.
     *
     * @return string[] Resolved absolute paths
     */
    private function permittedRoots(): array
    {
        $configured = $this->deploymentConfig->get(self::DEPLOYMENT_CONFIG_PATH);
        if (!\is_array($configured)) {
            $configured = $configured === null || $configured === '' ? [] : [$configured];
        }

        $roots = [];
        foreach ($configured as $root) {
            $resolved = $this->resolve((string) $root);
            if ($resolved !== '') {
                $roots[] = $resolved;
            }
        }

        return $roots;
    }

    /**
     * A path with symlinks and relative segments resolved, or an empty string if it does not exist.
     *
     * @param string $path
     * @return string
     */
    private function resolve(string $path): string
    {
        try {
            $resolved = $this->fileDriver->getRealPath($path);
        } catch (\Exception) {
            return '';
        }

        return \is_string($resolved) ? $resolved : '';
    }

    /**
     * Whether a resolved path is the given directory or sits inside it.
     *
     * @param string $path
     * @param string $directory
     * @return bool
     */
    private function isWithin(string $path, string $directory): bool
    {
        if ($directory === '') {
            return false;
        }

        return $path === $directory || str_starts_with($path, rtrim($directory, '/') . '/');
    }

    /**
     * A resolved installation directory, or an empty string when it cannot be resolved.
     *
     * @param string $code
     * @return string
     */
    private function realRoot(string $code): string
    {
        try {
            return $this->resolve($this->directoryList->getPath($code));
        } catch (\Exception) {
            return '';
        }
    }
}
