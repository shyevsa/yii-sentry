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
    /**
     * @var string $dsn The Sentry DSN
     */
    public string $dsn;

    /**
     * @var bool true to enable and false to disable sentry log
     */
    public bool $enable = true;

    /**
     * @var array \Sentry Options
     */
    public array $clientOptions = [];

    /**
     * @var bool enable or disable sentry-browser
     */
    public bool $useJs = false;

    /**
     * @var array sentry-browser Options
     */
    public array $clientJsOptions = [];

    /**
     * @var Client|ClientInterface|null
     */
    private $_client;

    public function init()
    {
        parent::init();
        if($this->enable) {
            $this->initSentry();
        }

        if($this->useJs){
            $this->registerJs();
        }
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
        if(!$this->enable){
            return null;
        }

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

    public function registerJs(array $options = null, array $plugins = null, array $context = null)
    {
        if(!\Yii::app() instanceof \CWebApplication){
            return;
        }

        /** @var \CAssetManager $assetManager */
        $assetManager = \Yii::app()->getComponent('assetManager');

        /** @var \CClientScript $clientScript */
        $clientScript = \Yii::app()->getComponent('clientScript');

        if(!$assetManager || !$clientScript){
            return;
        }

        $jsOptions = $options ?? $this->clientJsOptions;
        $user = $this->getUserContext();
        
        $assetUrl = $assetManager->publish(\Yii::getPathOfAlias('application.vendor.npm-asset.sentry--browser.build'));
        $clientScript->registerScriptFile($assetUrl . '/bundle.min.js', \CClientScript::POS_HEAD);
        $config = ArrayHelper::merge(['dsn' => $this->dsn], $jsOptions);
        $clientScript->registerScript('sentry--browser'
            , 'Sentry.init('.\CJavaScript::encode($config).')', \CClientScript::POS_BEGIN);

        foreach ((array) $context as $key=>$value){
            $clientScript->registerScript('sentry-context', 'Sentry.setContext('.\CJavaScript::encode($key).','.\CJavaScript::encode($value).')', \CClientScript::POS_BEGIN);
        }

        if($user){
            $clientScript->registerScript('sentry-context', 'Sentry.setUser('.\CJavaScript::encode($user).')', \CClientScript::POS_BEGIN);
        }
    }

    /*
     * Return User Context
     */
    protected function getUserContext(): ?array
    {
        /** @var \CWebUser $user */
        $user = \Yii::app()->getComponent('user');
        if($user && !$user->isGuest){
            return [
                'id' => $user->getId(),
                'username' => \CHtml::encode($user->getName())
            ];
        }

        return null;
    }


}