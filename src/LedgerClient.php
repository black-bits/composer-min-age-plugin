<?php

declare(strict_types=1);

namespace BlackBits\ComposerMinAge;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\HttpDownloader;
use Throwable;

final class LedgerClient
{
    public function __construct(
        private string $endpoint,
        private IOInterface $io,
        private HttpDownloader $httpDownloader,
    ) {
    }

    /**
     * @param PackageInterface[] $packages
     * @return array<string, int> "name@version_normalized@source_reference" => first-seen unix timestamp
     */
    public function lookup(array $packages): array
    {
        $items = $this->buildItems($packages);

        if ($items === []) {
            return [];
        }

        $body = json_encode(['packages' => array_values($items)]);

        if ($body === false) {
            return [];
        }

        // Authentication is Composer-native: a bearer entry for the endpoint's host in
        // auth.json is attached automatically by the HttpDownloader.
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        try {
            $response = $this->httpDownloader->get($this->endpoint, [
                'http' => [
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => $body,
                ],
                'retry-auth-failure' => false,
            ]);

            $firstSeen = $this->parseFirstSeen($response->getBody());

            $this->io->writeError(sprintf(
                '<info>[composer-min-age] ledger: %d of %d version(s) known</info>',
                count($firstSeen),
                count($items),
            ));

            return $firstSeen;
        } catch (Throwable $e) {
            $this->io->writeError('<warning>[composer-min-age] ledger lookup failed: ' . $e->getMessage() . '</warning>');

            return [];
        }
    }

    /**
     * @param PackageInterface[] $packages
     * @return array<string, array<string, string|null>>
     */
    private function buildItems(array $packages): array
    {
        $items = [];

        foreach ($packages as $package) {
            $sourceReference = $package->getSourceReference();

            if ($sourceReference === null || $sourceReference === '') {
                continue;
            }

            $items[$package->getName() . '@' . $package->getVersion() . '@' . $sourceReference] = [
                'name' => $package->getName(),
                'version' => $package->getPrettyVersion(),
                'version_normalized' => $package->getVersion(),
                'source_reference' => $sourceReference,
                'dist_reference' => $package->getDistReference(),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, int>
     */
    private function parseFirstSeen(?string $body): array
    {
        if ($body === null) {
            return [];
        }

        $decoded = json_decode($body, true);
        $results = is_array($decoded) ? ($decoded['results'] ?? []) : [];

        if (!is_array($results)) {
            return [];
        }

        $firstSeen = [];

        foreach ($results as $result) {
            if (!is_array($result) || !is_string($result['first_seen_at'] ?? null)) {
                continue;
            }

            $timestamp = strtotime($result['first_seen_at']);

            if ($timestamp === false) {
                continue;
            }

            $firstSeen[$result['name'] . '@' . $result['version_normalized'] . '@' . $result['source_reference']] = $timestamp;
        }

        return $firstSeen;
    }
}
