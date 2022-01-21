<?php

namespace Shyevsa\YiiSentry;

use CApplicationComponent;
use Sentry\ClientBuilder;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentrySdk;

class SentryComponent extends CApplicationComponent
{
    public $dsn;

    public $clientOptions = [];

    public $useJs = false;

    public $clientJsOptions = [];

    public $sentry;

    public function init()
    {
        parent::init();
        $this->initSentry();
    }

    private function initSentry()
    {
        $userOptions = array_merge(['dsn' => $this->dsn], $this->clientOptions);
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

        SentrySdk::init()->bindClient($builder->getClient());
    }


}