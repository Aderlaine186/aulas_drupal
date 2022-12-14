<?php

namespace Drush\Preflight;

use Symfony\Component\Console\Input\InputInterface;
use Composer\Autoload\ClassLoader;
use Drush\SiteAlias\SiteAliasFileLoader;
use Drush\Config\DrushConfig;
use Drush\Config\Environment;
use Drush\Config\ConfigLocator;
use Drush\Config\EnvironmentConfigLoader;
use Consolidation\SiteAlias\SiteAliasManager;
use DrupalFinder\DrupalFinder;
use RuntimeException;

/**
 * The Drush preflight determines what needs to be done for this request.
 * The preflight happens after Drush has loaded its autoload file, but
 * prior to loading Drupal's autoload file and setting up the DI container.
 *
 * - Pre-parse commandline arguments
 * - Read configuration .yml files
 * - Determine the site to use
 */
class Preflight
{
    /**
     * @var Environment $environment
     */
    protected $environment;

    /**
     * @var PreflightVerify
     */
    protected $verify;

    /**
     * @var ConfigLocator
     */
    protected $configLocator;

    /**
     * @var DrupalFinder
     */
    protected $drupalFinder;

    /**
     * @var PreflightArgs
     */
    protected $preflightArgs;

    /**
     * @var SiteAliasManager
     */
    protected $aliasManager;

    /**
     * @var PreflightLog $logger An early logger, just for Preflight.
     */
    protected $logger;

    /**
     * Preflight constructor
     */
    public function __construct(Environment $environment, $verify = null, $configLocator = null, $preflightLog = null)
    {
        $this->environment = $environment;
        $this->verify = $verify ?: new PreflightVerify();
        $this->configLocator = $configLocator ?: new ConfigLocator('DRUSH_', $environment->getConfigFileVariant());
        $this->drupalFinder = new DrupalFinder();
        $this->logger = $preflightLog ?: new PreflightLog();
    }

    public function logger(): PreflightLog
    {
        return $this->logger;
    }

    /**
     * @param PreflightLog $logger
     */
    public function setLogger(PreflightLog $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Perform preliminary initialization. This mostly involves setting up
     * legacy systems.
     */
    public function init(): void
    {
        // Define legacy constants, and include legacy files that Drush still needs
        LegacyPreflight::includeCode($this->environment->drushBasePath());
        LegacyPreflight::defineConstants($this->environment, $this->preflightArgs->applicationPath());
    }

    /**
     * Remapping table for arguments. Anything found in a key
     * here will be converted to the corresponding value entry.
     *
     * For example:
     *    --ssh-options='-i mysite_dsa'
     * will become:
     *    -Dssh.options='-i mysite_dsa'
     *
     * TODO: We could consider loading this from a file or some other
     * source. However, this table is needed very early -- even earlier
     * than config is loaded (since this is needed for preflighting the
     * arguments, which can select config files to load). Hardcoding
     * is probably best; we might want to move to another class, perhaps.
     * We also need this prior to Dependency Injection, though.
     *
     * Eventually, we might want to expose this table to some form of
     * 'help' output, so folks can see the available conversions.
     */
    protected function remapOptions(): array
    {
        return [
            '--ssh-options' => '-Dssh.options',
            '--php' => '-Druntime.php.path',
            '--php-options' => '-Druntime.php.options',
            '--php-notices' => '-Druntime.php.notices',
            '--halt-on-error' => '-Druntime.php.halt-on-error',
            '--output_charset' => '-Dio.output.charset',
            '--output-charset' => '-Dio.output.charset',
            '--notify' => '-Dnotify.duration',
            '--xh-link' => '-Dxh.link',
        ];
    }

    /**
     * Symfony Console dislikes certain command aliases, because
     * they are too similar to other Drush commands that contain
     * the same characters.  To avoid the "I don't know which
     * command you mean"-type errors, we will replace problematic
     * aliases with their longhand equivalents.
     *
     * This should be fixed in Symfony Console.
     */
    protected function remapCommandAliases(): array
    {
        return [
            'si' => 'site:install',
            'in' => 'pm:install',
            'install' => 'pm:install',
            'pm-install' => 'pm:install',
            'en' => 'pm:install',
            'pm-enable' => 'pm:install',
            // php was an alias for core-cli which got renamed to php-cli. See https://github.com/drush-ops/drush/issues/3091.
            'php' => 'php:cli',
        ];
    }

    /**
     * Preprocess the args, removing any @sitealias that may be present.
     * Arguments and options not used during preflight will be processed
     * with an ArgvInput.
     */
    public function preflightArgs($argv): PreflightArgs
    {
        $argProcessor = new ArgsPreprocessor();
        $remapper = new ArgsRemapper($this->remapOptions(), $this->remapCommandAliases());
        $preflightArgs = new PreflightArgs();
        $preflightArgs->setHomeDir($this->environment()->homeDir());
        $argProcessor->setArgsRemapper($remapper);

        $argProcessor->parse($argv, $preflightArgs);

        return $preflightArgs;
    }

    /**
     * Create the initial config locator object, and inject any needed
     * settings, paths and so on into it.
     */
    public function prepareConfig(Environment $environment): void
    {
        // Make our environment settings available as configuration items
        $this->configLocator->addEnvironment($environment);
        $this->configLocator->setLocal($this->preflightArgs->isLocal());
        $this->configLocator->addUserConfig($this->preflightArgs->configPaths(), $environment->systemConfigPath(), $environment->userConfigPath());
        $this->configLocator->addDrushConfig($environment->drushBasePath());
    }

    public function createInput(): InputInterface
    {
        return $this->preflightArgs->createInput();
    }

    public function getCommandFilePaths(): array
    {
        $commandlinePaths = $this->preflightArgs->commandPaths();
        $configPaths = $this->config()->get('drush.include', []);

        // Find all of the available commandfiles, save for those that are
        // provided by modules in the selected site; those will be added
        // during bootstrap.
        return $this->configLocator->getCommandFilePaths(array_merge($commandlinePaths, $configPaths), $this->drupalFinder()->getDrupalRoot());
    }

    public function loadSiteAutoloader(): ClassLoader
    {
        return $this->environment()->loadSiteAutoloader($this->drupalFinder()->getDrupalRoot());
    }

    public function loadSymfonyCompatabilityAutoloader(): ClassLoader
    {
        $symfonyMajorVersion = \Symfony\Component\HttpKernel\Kernel::MAJOR_VERSION;
        $compatibilityMap = [
            3 => false, // Drupal 8
            4 => 'v4',  // Drupal 9
            5 => 'v4',  // Early Drupal 10 (Symfony 5 works with Symfony 4 classes, so we don't keep an extra copy)
            6 => 'v6',  // Drupal 10
        ];

        if (empty($compatibilityMap[$symfonyMajorVersion])) {
            throw new RuntimeException("Fatal error: Drush does not work with Symfony $symfonyMajorVersion. (In theory, Composer should not allow you to get this far.)");
        }

        $compatibilityBaseDir = dirname(__DIR__, 2) . '/src-symfony-compatibility';
        $compatibilityDir = $compatibilityBaseDir . '/' . $compatibilityMap[$symfonyMajorVersion];

        // Next we will make a dynamic autoloader equivalent to an
        // entry in the autoload.php file similar to:
        //
        //    "psr-4": {
        //      "Drush\\": $compatibilityDir
        //    }
        $loader = new \Composer\Autoload\ClassLoader();
        // register classes with namespaces
        $loader->addPsr4('Drush\\', $compatibilityDir);
        // activate the autoloader
        $loader->register();

        return $loader;
    }

    public function config(): DrushConfig
    {
        return $this->configLocator->config();
    }

    /**
     * @param $argv
     *   True if the request was successfully redispatched remotely. False if the request should proceed.
     */
    public function preflight($argv): bool
    {
        // Fail fast if there is anything in our environment that does not check out
        $this->verify->verify($this->environment);

        // Get the preflight args and begin collecting configuration files.
        $this->preflightArgs = $this->preflightArgs($argv);
        $this->prepareConfig($this->environment);

        // Now that we know the value, set debug flag.
        $this->logger()->setDebug($this->preflightArgs->get(PreflightArgs::DEBUG, false));

        // Do legacy initialization (load static includes, define old constants, etc.)
        $this->init();

        // Get the config files provided by prepareConfig()
        $config = $this->config();

        // Copy items from the preflight args into configuration.
        // This will also load certain config values into the preflight args.
        $this->preflightArgs->applyToConfig($config);

        // Determine the local site targeted, if any.
        // Extend configuration and alias files to include files in
        // target site.
        $root = $this->findSelectedSite();
        $this->configLocator->addSitewideConfig($root);
        $this->configLocator->setComposerRoot($this->drupalFinder()->getComposerRoot());

        // Look up the locations where alias files may be found.
        $paths = $this->configLocator->getSiteAliasPaths($this->preflightArgs->aliasPaths(), $this->environment);

        // Configure alias manager.
        $aliasFileLoader = new SiteAliasFileLoader();
        $this->aliasManager = (new SiteAliasManager($aliasFileLoader))->addSearchLocations($paths);
        $this->aliasManager->setReferenceData($config->export());

        // Find the local site
        $siteLocator = new PreflightSiteLocator($this->aliasManager);
        $selfSiteAlias = $siteLocator->findSite($this->preflightArgs, $this->environment, $root);

        // If we did not find a local site, then we are destined to fail
        // UNLESS RedispatchToSiteLocal::redispatchIfSiteLocalDrush takes over.
        // Before we try to redispatch to the site-local Drush, though, we must
        // initialize the alias manager & c. based on any alias record we did find.
        if ($selfSiteAlias) {
            $this->aliasManager->setSelf($selfSiteAlias);
            $this->configLocator->addAliasConfig($selfSiteAlias->exportConfig());

            // Process the selected alias. This might change the selected site,
            // so we will add new site-wide config location for the new root.
            $root = $this->setSelectedSite($selfSiteAlias->localRoot(), false, $root);
        }

        // Now that we have our final Drupal root, check to see if there is
        // a site-local Drush. If there is, we will redispatch to it.
        // NOTE: termination handlers have not been set yet, so it is okay
        // to exit early without taking special action.
        $status = RedispatchToSiteLocal::redispatchIfSiteLocalDrush($argv, $root, $this->environment->vendorPath(), $this->logger());
        if ($status) {
            return $status;
        }

        // If the site locator couldn't find a local site, and we did not
        // redispatch to a site-local Drush, then we cannot continue.
        // This can happen when using Drush 9 to call a site-local Drush 8
        // using an alias record that is only defined in a Drush 8 format.
        if (!$selfSiteAlias) {
            // Note that PreflightSiteLocator::findSite only returns 'false'
            // when preflightArgs->alias() returns an alias name. In all other
            // instances we will get an alias record, even if it is only a
            // placeholder 'self' with the root holding the cwd.
            $aliasName = $this->preflightArgs->alias();
            throw new \Exception("The alias $aliasName could not be found.");
        }

        // If we did not redispatch, then add the site-wide config for the
        // new root (if the root did in fact change) and continue.
        $this->configLocator->addSitewideConfig($root);

        // Remember the paths to all the files we loaded, so that we can
        // report on it from Drush status or wherever else it may be needed.
        $configFilePaths = $this->configLocator->configFilePaths();
        $config->set('runtime.config.paths', $configFilePaths);
        $this->logger()->log(dt('Config paths: ' . implode(',', $configFilePaths)));
        $this->logger()->log(dt('Alias paths: ' . implode(',', $paths)));

        // We need to check the php minimum version again, in case anyone
        // has set it to something higher in one of the config files we loaded.
        $this->verify->confirmPhpVersion($config->get('drush.php.minimum-version'));

        return false;
    }

    /**
     * Find the site the user selected based on --root or cwd. If neither of
     * those result in a site, then we will fall back to the vendor path.
     */
    protected function findSelectedSite()
    {
        // TODO: If we want to support ONLY site-local Drush (which is
        // DIFFERENT than --local), then skip the call to `$preflightArgs->selectedSite`
        // and just assign `false` to $selectedRoot.

        // Try two approaches.
        $selectedRoot = $this->preflightArgs->selectedSite($this->environment->cwd());
        $fallBackPath = $this->preflightArgs->selectedSite(DRUSH_COMMAND);
        return $this->setSelectedSite($selectedRoot, $fallBackPath);
    }

    /**
     * Use the DrupalFinder to locate the Drupal Root + Composer Root at
     * the selected root, or, if nothing is found there, at a fallback path.
     *
     * @param string $selectedRoot The location to being searching for a site
     * @param string|bool $fallbackPath The secondary location to search (usualy the vendor director)
     */
    protected function setSelectedSite(string $selectedRoot, $fallbackPath = false, $originalSelection = null)
    {
        if ($selectedRoot || $fallbackPath) {
            $foundRoot = $this->drupalFinder->locateRoot($selectedRoot);
            // If we did not find a site at the selected root, check the
            // PARENT directory of the fallback path. This will find a site
            // that Drush is installed in while avoiding the SUT.
            if (!$foundRoot && $fallbackPath) {
                $foundRoot = $this->drupalFinder->locateRoot(dirname(dirname($fallbackPath)));
            }
            // If we can't find a site that Drush is installed in, and
            // Drush has been installed with a sut (git or composer dev install),
            // then look for the sut.
            if (!$foundRoot && $fallbackPath && is_dir($fallbackPath . '/sut') && is_dir($fallbackPath . '/vendor')) {
                $foundRoot = $this->drupalFinder->locateRoot($fallbackPath);
            }
            return $this->drupalFinder()->getDrupalRoot();
        }
        return $originalSelection;
    }

    /**
     * Return the Drupal Finder
     */
    public function drupalFinder(): DrupalFinder
    {
        return $this->drupalFinder;
    }

    /**
     * Return the alias manager
     */
    public function aliasManager(): SiteAliasManager
    {
        return $this->aliasManager;
    }

    /**
     * Return the environment
     */
    public function environment(): Environment
    {
        return $this->environment;
    }
}
