<?php

namespace Slides\Saml2;

use OneLogin\Saml2\Auth as OneLoginAuth;
use OneLogin\Saml2\Utils as OneLoginUtils;
use Illuminate\Support\Facades\URL;

/**
 * Class ServiceProvider
 *
 * @package Slides\Saml2
 */
class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Identity Provider resolver.
     *
     * @var IdpResolver
     */
    protected $idpResolver;

    /**
     * The resolved IdP key.
     *
     * @var string
     */
    protected $resolvedIdpKey;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->bootRoutes();
        $this->bootPublishes();
        $this->bootCommands();
        $this->loadMigrations();

        if ($this->app['config']->get('saml2.proxyVars', false)) {
            OneLoginUtils::setProxyVars(true);
        }
    }

    /**
     * Bootstrap the routes.
     *
     * @return void
     */
    protected function bootRoutes()
    {
        if($this->app['config']['saml2.useRoutes'] == true) {
            include __DIR__ . '/Http/routes.php';
        }
    }

    /**
     * Bootstrap the publishable files.
     *
     * @return void
     */
    protected function bootPublishes()
    {
        $this->publishes([
            __DIR__ . '../config/saml2.php' => config_path('saml2.php'),
        ]);
    }

    /**
     * Bootstrap the console commands.
     *
     * @return void
     */
    protected function bootCommands()
    {
        $this->commands([
            \Slides\Saml2\Commands\CreateTenant::class,
            \Slides\Saml2\Commands\DeleteTenant::class,
            \Slides\Saml2\Commands\RestoreTenant::class,
            \Slides\Saml2\Commands\ListTenants::class,
        ]);
    }

    /**
     * Load the package migrations.
     *
     * @return void
     */
    protected function loadMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if(!$this->urlMatchesNeededPrefix() || !$this->registerAuthenticationHandler()) {
            return;
        }

        $this->app->singleton('Slides\Saml2\Auth', function ($app) {
            return new \Slides\Saml2\Auth(
                $app['OneLogin_Saml2_Auth'],
                $this->idpResolver->getLastResolvedKey()
            );
        });
    }

    /**
     * Register the authentication handler.
     *
     * @return bool
     */
    protected function registerAuthenticationHandler()
    {
        if(!$idpConfig = $this->resolveIdentityProvider($this->app['config']['saml2']['idp'])) {
            \Illuminate\Support\Facades\Log::debug('[saml2] IdP is not resolved, skipping initialization');
            return false;
        }

        $this->app->singleton('OneLogin_Saml2_Auth', function ($app) use ($idpConfig) {
            $config = $app['config']['saml2'];

            $this->setConfigDefaultValues($config);

            $oneLoginConfig = $config;
            $oneLoginConfig['idp'] = $idpConfig;

            return new OneLoginAuth($this->normalizeConfigParameters($oneLoginConfig));
        });

        return true;
    }

    /**
     * Check whether current URL is matching needed prefix.
     *
     * @return bool
     */
    protected function urlMatchesNeededPrefix(): bool
    {
        return !$this->app->runningInConsole()
            && \Illuminate\Support\Str::startsWith(
                $this->app['request']->path(),
                ltrim($this->app['config']['saml2']['routesPrefix'], '/')
            );
    }

    /**
     * Configuration default values that must be replaced with custiom ones.
     *
     * @return array
     */
    protected function configDefaultValues()
    {
        return [
            'sp.entityId' => URL::route('saml.metadata', ['idpKey' => $this->resolvedIdpKey]),
            'sp.assertionConsumerService.url' => URL::route('saml.acs', ['idpKey' => $this->resolvedIdpKey]),
            'sp.singleLogoutService.url' => URL::route('saml.sls', ['idpKey' => $this->resolvedIdpKey])
        ];
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    /**
     * Normalize config parameters for OneLogin authentication handler.
     *
     * @param array $config
     *
     * @return array
     */
    protected function normalizeConfigParameters(array $config)
    {
        $config['idp']['x509cert'] = array_get($config['idp'], 'certs.x509');
        $config['idp']['certFingerprint'] = array_get($config['idp'], 'certs.fingerprint');

        return $config;
    }

    /**
     * Set default config values if they weren't set.
     *
     * @param array $config
     *
     * @return void
     */
    protected function setConfigDefaultValues(array &$config)
    {
        foreach ($this->configDefaultValues() as $key => $default) {
            if(!array_get($config, $key)) {
                array_set($config, $key, $default);
            }
        }
    }

    /**
     * Assign a default value to variable if its empty.
     *
     * @param mixed $var
     * @param mixed $default
     *
     * @return void
     */
    protected function setDefaultValue(&$var, $default)
    {
        if (empty($var)) {
            $var = $default;
        }
    }

    /**
     * Resolve an Identity Provider.
     *
     * @param array $config The IdPs config.
     *
     * @return array|null
     */
    protected function resolveIdentityProvider(array $config)
    {
        $config = ($this->idpResolver = new IdpResolver($config))->resolve();

        $this->resolvedIdpKey = $this->idpResolver->getLastResolvedKey();

        session()->flash('saml2.idpKey', $this->resolvedIdpKey);

        return $config;
    }
}