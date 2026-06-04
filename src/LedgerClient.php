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
        private ?string $token,
        private IOInterface $io,
        private HttpDownloader $httpDownloader,
    ) {
    }

    /**
     * @param PackageInterface[] $packages
     */
    public function report(array $packages): void
    {
        $items = $this->buildItems($packages);

        if ($items === []) {
            return;
        }

        $body = json_encode(['packages' => array_values($items)]);

        if ($body === false) {
            return;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        try {
            $response = $this->httpDownloader->get($this->endpoint, [
                'http' => [
                    'method' => 'POST',
                    'header' => $headers,
                    'content' => $body,
                ],
                'retry-auth-failure' => false,
            ]);

            $this->io->writeError(sprintf(
                '<info>[composer-min-age] reported %d version(s) to ledger (HTTP %d)</info>',
                count($items),
                $response->getStatusCode(),
            ));
        } catch (Throwable $e) {
            $this->io->writeError('<warning>[composer-min-age] ledger report failed: ' . $e->getMessage() . '</warning>');
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
}
