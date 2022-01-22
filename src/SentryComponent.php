<?php

namespace Shyevsa\YiiSentry;

use CApplicationComponent;
use Sentry\Client;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;
use Yiisoft\Arrays\ArrayHelper;

class SentryComponent extends CApplicationComponent
{
    public $dsn;

    public $clientOptions = [];

    public $useJs = false;

    public $clientJsOptions = [];

    public $sentry;

    private $_client;

    public function init()
    {
        parent::init();
        $this->initSentry();
    }

    /**
     * Init Sentry Listener
     */
    private function initSentry()
    {
        SentrySdk::init()->bindClient($this->getClient());
    }

    /**
     * @return Client|ClientInterface
     */
    public function getClient()
    {
        if (!isset($this->_client)) {
            $userOptions = ArrayHelper::merge([
                'dsn' => $this->dsn,
                'in_app_exclude' => [
                    'CLogger',
                    'CLogRoute',
                    'SentryLogRouter',
                ]
            ], $this->clientOptions);
            $builder = ClientBuilder::create($userOptions);

            $options = $builder->getOptions();
            $options->setIntegrations(static function (array $integrations) {
                // Remove the default error and fatal exception listeners to let us handle those
                return array_filter($integrations, static function (IntegrationInterface $integration): bool {
                    if ($integration instanceof ErrorListenerIntegration) {
                        return false;
                    }
                    if ($integration instanceof ExceptionListenerIntegration) {
                        return false;
                    }
                    if ($integration instanceof FatalErrorListenerIntegration) {
                        return false;
                    }

                    return true;
                });
            });

            $this->_client = $builder->getClient();
        }
        return $this->_client;
    }

    /**
     * @param Client|ClientInterface $client
     */
    public function setClient($client): SentryComponent
    {
        $this->_client = $client;
        return $this;
    }
}