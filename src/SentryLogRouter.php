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

    public $maskVars = [
        'HTTP_AUTHORIZATION',
        'PHP_AUTH_USER',
        'PHP_AUTH_PW',
    ];

    /**
     * @inheritdoc
     */
    protected function processLogs($logs)
    {
        if (count($logs) === 0) {
            return;
        }

        foreach ($logs as $log) {
            [$message, $level, $category, $timestamp] = $log;

            $data = [
                'message' => '',
                'tags' => ['category' => $category],
                'extra' => [],
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
                    $data['message'] = (string)$message;
                }

                if ($this->context) {
                    $data['extra']['context'] = $this->getContextMessage();
                }

                if (isset($_SESSION['FPErrorRef'])) {
                    $data['extra']['error_ref'] = $_SESSION['FPErrorRef'];
                }

                $data = $this->runExtraCallback($message, $data);

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

    protected function getSentry()
    {
        if (!isset($this->_sentry)) {
            $this->_sentry = false;
            if (!Yii::app()->hasComponent($this->sentryComponent)) {
                Yii::log("'{$this->sentryComponent}' does not exists", CLogger::LEVEL_TRACE, __CLASS__);
            } else {
                $sentry = Yii::app()->{$this->sentryComponent};
                if (!$sentry || !$sentry->getIsInitialized()) {
                    Yii::log("'{$this->sentryComponent}' not initialized", CLogger::LEVEL_TRACE, __CLASS__);
                } else {
                    $sentry->getSentry();
                }
            }
        }

        return $this->_sentry;
    }

    protected function getContextMessage()
    {
        return '';
    }

    /**
     * @param $message
     * @param array $data
     * @return array
     */
    public function runExtraCallback($message, array $data): array
    {
        if (is_callable($this->extraCallback)) {
            $data['extra'] = call_user_func($this->extraCallback, $message, $data['extra'] ?? []);
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
        switch($level){
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