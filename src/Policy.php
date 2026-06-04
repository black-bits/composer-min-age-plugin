<?php

declare(strict_types=1);

namespace BlackBits\ComposerMinAge;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use UnexpectedValueException;

final class Policy
{
    public const CONFIG_KEY = 'composer-min-age';
    private const DEFAULT_EXEMPT_PACKAGES = ['black-bits/composer-min-age-plugin'];

    private function __construct(
        private int $minimumAgeSeconds,
        private array $blockedPackageVersions,
        private array $exemptedPackages,
        private ?string $endpoint,
        private ?string $token,
    ) {
    }

    public static function fromComposer(Composer $composer): self
    {
        $global = self::getGlobalConfig($composer);
        $local = self::normalizeConfig($composer->getPackage()->getExtra()[self::CONFIG_KEY] ?? []);
        $merged = array_merge($global, $local);

        // Global config is a floor: block lists combine and the stricter minimum age wins,
        // so a project cannot drop an org-wide block. Exemptions from both configs apply.
        // endpoint/token are single values, so local overrides global when both are set.
        return new self(
            minimumAgeSeconds: max(
                self::getMinimumAgeInSeconds($global['minimum-age'] ?? '0'),
                self::getMinimumAgeInSeconds($local['minimum-age'] ?? '0'),
            ),
            blockedPackageVersions: array_merge_recursive(
                self::getBlockedPackageVersions($global['blocked-versions'] ?? []),
                self::getBlockedPackageVersions($local['blocked-versions'] ?? []),
            ),
            exemptedPackages: self::getExemptedPackages(array_merge(
                (array) ($global['exempt-packages'] ?? []),
                (array) ($local['exempt-packages'] ?? []),
            )),
            endpoint: self::getStringConfig($merged['endpoint'] ?? null),
            token: self::getStringConfig($merged['token'] ?? null),
        );
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * @param array<string, int>|null $firstSeenByKey first-seen timestamps from the ledger, keyed by
     *                                                 "name@version_normalized@source_reference". Null means
     *                                                 the ledger is not in play, so age falls back to the
     *                                                 package's (spoofable) release date.
     */
    public function evaluatePackageVersion(PackageInterface $package, string $context, ?array $firstSeenByKey = null): ?string
    {
        if ($this->isPackageVersionExempt($package)) {
            return null;
        }

        if ($this->isPackageVersionBlocked($package)) {
            return $this->describePolicyViolation($package, $context, 'is on the block list');
        }

        if ($this->minimumAgeSeconds <= 0) {
            return null;
        }

        if ($this->isPackageVersionTooNew($package, $firstSeenByKey)) {
            return $this->describePolicyViolation($package, $context, sprintf(
                'is too new (%s old, minimum age is %s)',
                self::formatDuration($this->getPackageVersionAgeInSeconds($package, $firstSeenByKey) ?? 0),
                self::formatDuration($this->minimumAgeSeconds),
            ));
        }

        return null;
    }

    private function isPackageVersionExempt(PackageInterface $package): bool
    {
        return in_array(strtolower($package->getName()), $this->exemptedPackages, true);
    }

    private function isPackageVersionBlocked(PackageInterface $package): bool
    {
        $packageConstraint = new Constraint('==', $package->getVersion());

        foreach ($this->blockedPackageVersions[strtolower($package->getName())] ?? [] as $constraint) {
            if ($constraint->matches($packageConstraint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int>|null $firstSeenByKey
     */
    private function isPackageVersionTooNew(PackageInterface $package, ?array $firstSeenByKey): bool
    {
        $ageSeconds = $this->getPackageVersionAgeInSeconds($package, $firstSeenByKey);

        return $ageSeconds !== null && $ageSeconds < $this->minimumAgeSeconds;
    }

    /**
     * @param array<string, int>|null $firstSeenByKey
     */
    private function getPackageVersionAgeInSeconds(PackageInterface $package, ?array $firstSeenByKey): ?int
    {
        if ($firstSeenByKey !== null) {
            $key = $package->getName() . '@' . $package->getVersion() . '@' . $package->getSourceReference();

            return isset($firstSeenByKey[$key]) ? time() - $firstSeenByKey[$key] : null;
        }

        $releaseDate = $package->getReleaseDate();

        if ($releaseDate === null) {
            return null;
        }

        return time() - $releaseDate->getTimestamp();
    }

    private function describePolicyViolation(PackageInterface $package, string $context, string $reason): string
    {
        return sprintf('%s %s [%s]: %s', $package->getPrettyName(), $package->getPrettyVersion(), $context, $reason);
    }

    private static function getGlobalConfig(Composer $composer): array
    {
        $home = $composer->getConfig()->get('home');

        if (!is_string($home) || $home === '') {
            return [];
        }

        $path = rtrim($home, '/\\') . '/composer.json';

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);
        $extra = is_array($json) ? ($json['extra'] ?? []) : [];

        return is_array($extra) 
            ? self::normalizeConfig($extra[self::CONFIG_KEY] ?? []) 
            : [];
    }

    private static function normalizeConfig(mixed $config): array
    {
        return is_array($config) ? $config : [];
    }

    private static function getStringConfig(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function getExemptedPackages(mixed $configured): array
    {
        $names = array_merge(self::DEFAULT_EXEMPT_PACKAGES, is_array($configured) ? $configured : []);

        return array_map('strtolower', array_filter($names, 'is_string'));
    }

    private static function getBlockedPackageVersions(mixed $blockedVersions): array
    {
        if (!is_array($blockedVersions)) {
            return [];
        }

        $versionParser = new VersionParser();

        $result = [];

        foreach ($blockedVersions as $packageName => $constraints) {
            foreach ((array) $constraints as $constraint) {
                $constraint = trim((string) $constraint);

                if ($constraint === '') {
                    continue;
                }

                $result[strtolower((string) $packageName)][] = $versionParser->parseConstraints($constraint);
            }
        }

        return $result;
    }

    private static function getMinimumAgeInSeconds(mixed $duration): int
    {
        $value = trim((string) $duration);

        if ($value === '' || $value === '0') {
            return 0;
        }

        if (!preg_match('/^(\d+)([hd])$/i', $value, $matches)) {
            throw new UnexpectedValueException(
                "Invalid composer-min-age minimum-age '$duration'; use e.g. 12h, 7d, or 0 to disable."
            );
        }

        return (int) $matches[1] * (strtolower($matches[2]) === 'h' ? 3600 : 86400);
    }

    private static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        return $parts === [] ? '<1h' : implode(' ', $parts);
    }
}
