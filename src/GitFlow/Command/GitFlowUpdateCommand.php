<?php
    namespace IchHabRecht\GitFlow\Command;

    use Symfony\Component\Console\Output\OutputInterface;
    use Symfony\Component\Console\Input\InputInterface;
    use Symfony\Component\Console\Input\InputOption;
    use Composer\Semver\Constraint\Constraint;
    use Composer\Package\PackageInterface;
    use Composer\Command\UpdateCommand;
    use Composer\Semver\VersionParser;
    use Composer\Package\Link;

    class GitFlowUpdateCommand extends UpdateCommand
    {
        /**
         * @var string|string[]
         */
        private $stability = 'dev-master';
        /**
         * @var bool
         */
        private $verbose = false;

        /**
         * Sets the name of this command
         */
        protected function configure()
        {
            parent::configure();
            $this->setName('git-flow-update');
            $this->addOption('stability', '', InputOption::VALUE_OPTIONAL, 'Define the branch prefixes which should be used to checkout your repositories (comma separated values)');
        }

        /**
         * Execute command, adjust constraints and start update
         *
         * @param InputInterface $input
         * @param OutputInterface $output
         * @return int
         */
        protected function execute(InputInterface $input, OutputInterface $output)
        {
            $io = $this->getIO();
            $io->writeError('> dynamics-unlimited/composer-git-flow-plugin');

            $this->verbose = (bool)$input->getOption('verbose') ?: false;
            $this->stability = (string)$input->getOption('stability');

            $stability = trim((string)getenv('STABILITY'));
            if (!empty($stability)) {
                $io->writeError('Warning: You are using the deprecated environment variable `STABILITY`. Please use cli option --stability ' . $stability);
                $this->stability = $stability;
            }

            if ($this->verbose) {
                $io->writeError('  - using STABILITY=' . $this->stability);
                $io->writeError('');
            }

            $composer = $this->getComposer(true, $input->getOption('no-plugins'));

            $requires = $composer->getPackage()->getRequires();
            $newRequires = $this->adjustGitFlowPackages($requires);
            $packages = array_keys($newRequires);
            $composer->getPackage()->setRequires(array_merge($requires, $newRequires));

            if (!$input->getOption('no-dev')) {
                $requires = $this->adjustGitFlowPackages($composer->getPackage()->getDevRequires());
                $newRequires = $this->adjustGitFlowPackages($requires);
                $packages += array_keys($newRequires);
                $composer->getPackage()->setDevRequires(array_merge($requires, $newRequires));
            }

            $input->setArgument('packages', $packages);
            $io->writeError('');

            return parent::execute($input, $output);
        }

        /**
         * Loops over packages and adjusts the dependency constraints
         *
         * @param array $packages
         * @return array
         */
        protected function adjustGitFlowPackages(array $packages)
        {
            $newRequires = [];
            $versionParser = new VersionParser();
            foreach ($packages as $packageName => $package) {
                if ('dev-master' === $package->getPrettyConstraint()) {
                    $branch = $this->findStabilityBranch($packageName);
                    $this->getIO()->writeError('      ➜ Adjusting ' . $packageName . ' to ' . $branch);
                    $link = new Link(
                        $package->getSource(),
                        $package->getTarget(),
                        $versionParser->parseConstraints($branch),
                        $package->getDescription(),
                        $branch
                    );
                    $newRequires[$packageName] = $link;
                }
            }

            return $newRequires;
        }

        /**
         * Returns package branch to use according to the desired stability
         *
         * @param string $packageName
         * @return string
         */
        protected function findStabilityBranch($packageName)
        {
            $repositoryManager = $this->getComposer()->getRepositoryManager();

            if (is_string($this->stability)) {
                $this->stability = explode(',', $this->stability) ?: [];
            }

            foreach($this->stability as $stability) {
                $stability ='dev-' . trim($stability);

                if ($this->verbose) {
                    $this->getIO()->writeError("    - Looking for $stability in $packageName");
                }

                $package = $repositoryManager->findPackage($packageName, new Constraint('==', $stability));

                if ($package instanceof PackageInterface) {
                    $prettyVersion = $package->getPrettyVersion();

                    if ($this->verbose) {
                        $this->getIO()->writeError("    - Found $stability in $packageName ($prettyVersion)");
                    }

                    return $prettyVersion;
                }
            }

            if ($this->verbose) {
                $this->getIO()->writeError("    - Defaulting to dev-master for $packageName");
            }

            return 'dev-master';
        }
    }
