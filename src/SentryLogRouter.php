<?php

namespace Shyevsa\YiiSentry;

use CHttpRequest;
use CLogger;
use CLogRoute;
use CWebUser;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;
use Yii;
use Yiisoft\Arrays\ArrayHelper;
use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\withScope;

class SentryLogRouter extends CLogRoute
{

    /**
     * @var string Sentry Components
     */
    public $sentryComponent = 'sentry';

    /**
     * @var bool write the context information
     */
    public $context = true;

    /**
     * @var callable Callback function that modify extra's array
     */
    public $extraCallback;

    /**
     * @var callable Callback function that modify user's array
     */
    public $userCallback;

    /**
     * @var array list of the PHP predefined variables that should be logged in a message.
     * Note that a variable must be accessible via `$GLOBALS`. Otherwise it won't be logged.
     *
     * Defaults to `['_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_SERVER']`.
     *
     * Since version 2.0.9 additional syntax can be used:
     * Each element could be specified as one of the following:
     *
     * - `var` - `var` will be logged.
     * - `var.key` - only `var[key]` key will be logged.
     * - `!var.key` - `var[key]` key will be excluded.
     *
     * Note that if you need $_SESSION to logged regardless if session was used you have to open it right at
     * the start of your request.
     *
     * @see ArrayHelper::filter()
     */
    public $log_vars = [
        '_GET',
        '_POST',
        '_FILES',
        '_COOKIE',
        '_SESSION',
        '_SERVER',
    ];

    /**
     * @var array list of the PHP predefined variables that should NOT be logged "as is" and should always be replaced
     * with a mask `***` before logging, when exist.
     *
     * Defaults to `[ '_SERVER.HTTP_AUTHORIZATION', '_SERVER.PHP_AUTH_USER', '_SERVER.PHP_AUTH_PW']`
     *
     * Each element could be specified as one of the following:
     *
     * - `var` - `var` will be logged as `***`
     * - `var.key` - only `var[key]` will be logged as `***`
     *
     */
    public $mask_vars = [
        '_SERVER.HTTP_AUTHORIZATION',
        '_SERVER.PHP_AUTH_USER',
        '_SERVER.PHP_AUTH_PW',
    ];

    /**
     * @inheritdoc
     */
    protected function processLogs($logs)
    {
        if (count($logs) === 0) {
            return;
        }

        if(!$this->getSentry()){
            return;
        }

        foreach ($logs as $log) {
            [$message, $level, $category, $timestamp] = $log;

            $data = [
                'message' => '',
                'tags' => ['category' => $category],
                'extra' => ['timestamp' => $timestamp],
                'userData' => []
            ];

            /** @var CHttpRequest $request */
            $request = Yii::app()->getRequest();
            if ($request instanceof CHttpRequest) {
                $data['userData']['ip_address'] = $request->getUserHostAddress();
            }

            try {
                /** @var CWebUser $user */
                $user = Yii::app()->hasComponent('user') ? Yii::app()->getUser() : null;
                if ($user) {
                    $data['userData']['id'] = $user->id;
                    $data['userData']['name'] = $user->name;
                }
            } catch (\Throwable $e) {
            }

            withScope(function (Scope $scope) use ($message, $level, $data) {

                if (is_array($message)) {
                    if (isset($message['msg'])) {
                        $data['message'] = (string)$message['msg'];
                        unset($message['msg']);
                    }
                    if (isset($message['message'])) {
                        $data['message'] = (string)$message['message'];
                        unset($message['message']);
                    }

                    if (isset($message['tags'])) {
                        $data['tags'] = array_merge($data['tags'], $message['tags']);
                        unset($message['tags']);
                    }

                    if (isset($message['exception']) && $message['exception'] instanceof Throwable) {
                        $data['exception'] = $message['exception'];
                        unset($message['exception']);
                    }

                    $data['extra'] = $message;
                } else {
                    $data['message'] = preg_replace('#Stack trace:.+#s', '', (string)$message);
                }

                if ($this->context) {
                    $data['extra']['context'] = $this->getContextMessage();
                }

                if (isset($_SESSION['FPErrorRef'])) {
                    $data['extra']['error_ref'] = $_SESSION['FPErrorRef'];
                }

                $data = $this->runExtraCallback($message, $data);
                $data = $this->runUserCallback($message, $data);

                $scope->setUser($data['userData']);

                foreach ($data['extra'] as $key => $value) {
                    $scope->setExtra((string)$key, $value);
                }

                foreach ($data['tags'] as $key => $value) {
                    if ($value) {
                        $scope->setTag($key, $value);
                    }
                }

                if ($message instanceof Throwable) {
                    captureException($message);
                } else {
                    $event = Event::createEvent();
                    $event->setMessage($data['message']);
                    $event->setLevel($this->getLogLevel($level));

                    captureEvent($event,
                        EventHint::fromArray(array_filter(['exception' => $data['exception'] ?? null]))
                    );
                }

            });
        }
    }

    protected $_sentry;

    /**
     * @return false|SentryComponent
     */
    protected function getSentry()
    {
        if (!isset($this->_sentry)) {
            $this->_sentry = false;
            if (!Yii::app()->hasComponent($this->sentryComponent)) {
                Yii::log("'{$this->sentryComponent}' does not exists", CLogger::LEVEL_TRACE, __CLASS__);
            } else {
                /** @var SentryComponent $sentry */
                $this->_sentry = Yii::app()->{$this->sentryComponent};
                if (!$this->_sentry || !$this->_sentry->getIsInitialized()) {
                    Yii::log("'{$this->sentryComponent}' not initialized", CLogger::LEVEL_TRACE, __CLASS__);
                }
            }
        }

        return $this->_sentry;
    }


    /**
     * @return string
     */
    protected function getContextMessage()
    {
        $context = ArrayHelper::filter($GLOBALS, $this->log_vars);
        foreach ($this->mask_vars as $var){
            if(ArrayHelper::getValue($context, $var) !== null){
                ArrayHelper::setValue($context, $var, '***');
            }
        }

        $result = [];
        foreach ($context as $key => $value){
            $result[] = "\${$key} = " . \CVarDumper::dumpAsString($value);
        }

        return implode("\n\n", $result);
    }

    /**
     * @param $message
     * @param array $data
     * @return array
     */
    private function runExtraCallback($message, array $data): array
    {
        if (is_callable($this->extraCallback)) {
            $data['extra'] = call_user_func($this->extraCallback, $message, $data['extra'] ?? []);
        }

        return $data;
    }

    /**
     * @param $message
     * @param array $data
     * @return array
     */
    private function runUserCallback($message, array $data): array
    {
        if(is_callable($this->userCallback)){
            $data['userData'] = call_user_func($this->userCallback, $message, $data['userData'] ?? []);
        }

        return $data;
    }

    /**
     * Translates Yii log levels to Sentry Severity.
     *
     * @param $level
     * @return Severity
     */
    public function getLogLevel($level): Severity
    {
        switch ($level) {
            case CLogger::LEVEL_PROFILE:
            case CLogger::LEVEL_TRACE:
                return Severity::debug();
            case CLogger::LEVEL_WARNING:
                return Severity::warning();
            case CLogger::LEVEL_ERROR:
                return Severity::error();
            case CLogger::LEVEL_INFO:
            default:
                return Severity::info();
        }
    }


}