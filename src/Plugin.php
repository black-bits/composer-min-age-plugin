<?php

declare(strict_types=1);

namespace BlackBits\ComposerMinAge;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Util\HttpDownloader;
use RuntimeException;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // During a solve: filter disallowed candidate versions from the pool (see below).
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',

            // Before operations run: enforce policy on everything that ends up installed (see below).
            InstallerEvents::PRE_OPERATIONS_EXEC => 'onPreOperationsExec',
        ];

        // How the two handlers divide the work.
        //
        // On any run a package is in one of three states, and each is covered exactly once:
        //   1. a candidate the solver is choosing from        -> onPrePoolCreate filters it out of the pool
        //   2. installed or updated by this run (an operation) -> onPreOperationsExec checks the target version
        //   3. already on disk, untouched by this run          -> onPreOperationsExec checks the installed version
        //
        // onPrePoolCreate runs ONLY during a solve (composer update, or install with a stale lock). It drops
        // disallowed candidate versions so the solver picks an allowed one instead of resolving to a blocked
        // version and then failing. It is the graceful path, not the guarantee — it never aborts the run.
        //
        // onPreOperationsExec runs before any operation executes, on EVERY install and update. It is the actual
        // guarantee, and checks two populations whose union is the full resulting on-disk set:
        //   - the operations: the versions being installed/updated to (removals are skipped — a version that is
        //     leaving needn't be policed);
        //   - the installed repository minus those operations: versions already on disk that the run leaves in
        //     place. This is what catches a disallowed version no operation touches:
        //       * composer install from a lock — no solve happens, so onPrePoolCreate never runs;
        //       * partial update (composer update vendor/x) — everything else stays pinned and untouched.
        // Anything disallowed that would remain installed is blocked here.
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        $policy = Policy::fromComposer($this->composer);

        // Versions the solver has fixed (locked, or held back in a partial update) must stay in the pool —
        // removing a fixed version makes the solve fail hard. Those are left for onPreOperationsExec to block.
        $fixed = [];
        
        foreach ($event->getRequest()->getFixedOrLockedPackages() as $package) {
            $fixed[$package->getName() . '@' . $package->getVersion()] = true;
        }

        // Drop disallowed *choosable* candidates so the solver picks an allowed version instead. This is the
        // graceful path and never aborts; onPreOperationsExec guarantees nothing disallowed stays installed.
        $candidates = $event->getPackages();

        // Filter the pool on the same trusted age source as the enforcement point: the ledger's
        // first-seen. Null when no endpoint is configured, so this falls back to the package's
        // release date — identical to the pre-ledger behavior.
        $firstSeenByKey = $this->lookupFirstSeen($policy, $candidates);

        if ($firstSeenByKey !== null) {
            // Unknown tuples silently fall back to the release date here so the solver routes
            // around versions that would fail the age check; the user is asked for consent at
            // the enforcement point only, where it actually decides the run.
            [$firstSeenByKey] = $this->mergeReleaseDateFallback($policy, $candidates, $firstSeenByKey);
        }

        $kept = [];
        $prevented = [];

        foreach ($candidates as $package) {
            if (isset($fixed[$package->getName() . '@' . $package->getVersion()])) {
                $kept[] = $package;
                continue;
            }

            $violation = $policy->evaluatePackageVersion($package, 'candidate version', $firstSeenByKey);

            if ($violation === null) {
                $kept[] = $package;
                continue;
            }

            $prevented[] = $violation;
        }

        if ($prevented !== []) {
            $this->reportPrevented($prevented);
            $event->setPackages($kept);
        }
    }

    public function onPreOperationsExec(InstallerEvent $event): void
    {
        $policy = Policy::fromComposer($this->composer);

        $touched = [];
        $evaluations = [];

        // Packages this run installs, updates, or removes. Record the name so the installed-repo pass
        // below skips it (no double-check). Removals are recorded but not evaluated — a version that is
        // leaving needn't be policed.
        foreach ($event->getTransaction()->getOperations() as $operation) {
            $package = $this->getPackageFromOperation($operation);
            if ($package === null) {
                continue;
            }

            $touched[strtolower($package->getName())] = true;

            if ($operation->getOperationType() === 'uninstall') {
                continue;
            }

            $evaluations[] = [$package, $operation->getOperationType() . ' operation'];
        }

        // Packages already on disk that this run leaves untouched — catches a disallowed version no
        // operation touches (install from lock, or a partial update that does not reach it).
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            if (isset($touched[strtolower($package->getName())])) {
                continue;
            }

            $evaluations[] = [$package, 'installed package'];
        }

        // Replace the spoofable release date with the ledger's first-seen age. One batched lookup for
        // the whole resulting set; null means no endpoint configured, so each check falls back to the
        // package's release date (unchanged behavior).
        $evaluatedPackages = array_map(static fn (array $pair): PackageInterface => $pair[0], $evaluations);
        $firstSeenByKey = $this->lookupFirstSeen($policy, $evaluatedPackages);

        if ($firstSeenByKey !== null) {
            [$firstSeenByKey, $unknown] = $this->mergeReleaseDateFallback($policy, $evaluatedPackages, $firstSeenByKey);
            $this->confirmReleaseDateFallback($unknown);
        }

        $violations = [];

        foreach ($evaluations as [$package, $context]) {
            $violation = $policy->evaluatePackageVersion($package, $context, $firstSeenByKey);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        $this->enforceViolations($violations);
    }

    /**
     * @param PackageInterface[] $packages
     * @return array<string, int>|null
     */
    private function lookupFirstSeen(Policy $policy, array $packages): ?array
    {
        $endpoint = $policy->getEndpoint();

        if ($endpoint === null) {
            return null;
        }

        $client = new LedgerClient(
            $endpoint,
            $this->io,
            new HttpDownloader($this->io, $this->composer->getConfig()),
        );

        return $client->lookup($packages);
    }

    /**
     * The ledger answered, but some versions have no first-seen record (not crawled yet, lookup
     * failure, or no source reference). For those the only age signal left is the spoofable
     * release date — merge it into the map so the age check still applies, and report which
     * versions needed it.
     *
     * @param PackageInterface[] $packages
     * @param array<string, int> $firstSeenByKey
     * @return array{0: array<string, int>, 1: PackageInterface[]}
     */
    private function mergeReleaseDateFallback(Policy $policy, array $packages, array $firstSeenByKey): array
    {
        if ($policy->getMinimumAgeSeconds() <= 0) {
            return [$firstSeenByKey, []];
        }

        $unknown = [];

        foreach ($packages as $package) {
            if ($policy->isPackageVersionExempt($package)) {
                continue;
            }

            $key = $package->getName() . '@' . $package->getVersion() . '@' . $package->getSourceReference();

            if (isset($firstSeenByKey[$key])) {
                continue;
            }

            $unknown[$key] = $package;

            $releaseDate = $package->getReleaseDate();

            if ($releaseDate !== null) {
                $firstSeenByKey[$key] = $releaseDate->getTimestamp();
            }
        }

        return [$firstSeenByKey, array_values($unknown)];
    }

    /**
     * @param PackageInterface[] $unknown
     */
    private function confirmReleaseDateFallback(array $unknown): void
    {
        if ($unknown === []) {
            return;
        }

        $this->printList(
            'No trusted first-seen data for these versions; their (spoofable) self-reported release date is the only age signal left:',
            array_map(
                static fn (PackageInterface $package): string => $package->getPrettyName() . ' ' . $package->getPrettyVersion(),
                $unknown,
            ),
        );

        // Default yes: fail-open by design (prototype). Non-interactive runs take the default.
        if ($this->io->askConfirmation('<warning>[composer-min-age] Continue using the release-date fallback for them?</warning> [Y/n] ')) {
            return;
        }

        throw new RuntimeException(
            '[composer-min-age] Aborted: no trusted first-seen data for the listed versions and the release-date fallback was declined.'
        );
    }

    private function getPackageFromOperation(OperationInterface $operation): ?PackageInterface
    {
        if ($operation instanceof InstallOperation) {
            return $operation->getPackage();
        }

        if ($operation instanceof UpdateOperation) {
            return $operation->getTargetPackage();
        }

        if ($operation instanceof UninstallOperation) {
            return $operation->getPackage();
        }

        return null;
    }

    private function reportPrevented(array $prevented): void
    {
        // Informational only: these disallowed versions were filtered out of the solver pool, so it
        // resolves to allowed ones instead. Never fatal — onPreOperationsExec enforces the final result.
        $this->printList('Prevented disallowed versions during resolution:', $prevented);
    }

    private function enforceViolations(array $violations): void
    {
        if ($violations === []) {
            return;
        }

        $this->printList('Dependency policy violations:', $violations);

        throw new RuntimeException(
            '[composer-min-age] Blocked one or more disallowed package versions (see above). '
            . 'Update minimum-age or blocked-versions to allow them.'
        );
    }

    private function printList(string $header, array $messages): void
    {
        $this->io->writeError('<warning>[composer-min-age] ' . $header . '</warning>');

        $limit = 10;
        foreach (array_slice($messages, 0, $limit) as $message) {
            $this->io->writeError('  - ' . $message);
        }

        if (count($messages) > $limit) {
            $this->io->writeError(sprintf('  - ... and %d more', count($messages) - $limit));
        }
    }
}
