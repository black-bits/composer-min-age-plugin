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
        $kept = [];
        $prevented = [];

        foreach ($event->getPackages() as $package) {
            if (isset($fixed[$package->getName() . '@' . $package->getVersion()])) {
                $kept[] = $package;
                continue;
            }

            $violation = $policy->evaluatePackageVersion($package, 'candidate version');

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

        $violations = [];
        $touched = [];

        // Packages this run installs, updates, or removes. Evaluate the version being installed/updated
        // to, and record the name so the installed-repo pass below skips it (no double-check). Removals
        // are recorded but not evaluated — a version that is leaving needn't be policed.
        foreach ($event->getTransaction()->getOperations() as $operation) {
            $package = $this->getPackageFromOperation($operation);
            if ($package === null) {
                continue;
            }

            $touched[strtolower($package->getName())] = true;

            if ($operation->getOperationType() === 'uninstall') {
                continue;
            }

            $violation = $policy->evaluatePackageVersion($package, $operation->getOperationType() . ' operation');
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        // Packages already on disk that this run leaves untouched — catches a disallowed version no
        // operation touches (install from lock, or a partial update that does not reach it).
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            if (isset($touched[strtolower($package->getName())])) {
                continue;
            }

            $violation = $policy->evaluatePackageVersion($package, 'installed package');
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        $this->enforceViolations($violations);
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
