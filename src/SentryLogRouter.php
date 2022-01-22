<?php

namespace Shyevsa\YiiSentry;

use CHttpRequest;
use CLogger;
use CLogRoute;
use CWebUser;
use Sentry\Client;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Frame;
use Sentry\FrameBuilder;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\State\Scope;
use Throwable;
use Yii;
use Yiisoft\Arrays\ArrayHelper;
use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\withScope;

/**
 * @property Client|ClientInterface $sentry
 */
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

    /** @var callable Callback function that modify Tags Array */
    public $tagsCallback;

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
        //'_COOKIE',
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
        '_SERVER.HTTP_COOKIE'
    ];

    /**
     * @see self::getStackTrace().
     * @var string
     */
    public $tracePattern = '/(?J)in (?<file>.*)\:(?<line>\d+)|\((?<file>.*)\:(?<line>\d+)\)|in (?<file>[^(]+)\((?<line>\d+)\)|(?<number>\d+) (?<file>[^(]+)\((?<line>\d+)\): (?<cls>[^-]+)(->|::)(?<func>[^\(]+)/m';


    /**
     * @inheritdoc
     */
    protected function processLogs($logs)
    {
        if (count($logs) === 0) {
            return;
        }

        if (!$sentry = $this->getSentry()) {
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
                    $data['userData']['username'] = $user->name;
                }
            } catch (\Throwable $e) {
            }

            withScope(function (Scope $scope) use ($message, $level, $data, $timestamp) {

                $data['message'] = preg_replace('#(in |\().*?:\d+\)?#', '', explode("\n", ltrim($message), 2)[0]);
                $data['extra']['full_message'] = $message;

                if ($this->context) {
                    $context = ArrayHelper::filter($GLOBALS, $this->log_vars);
                    foreach ($this->mask_vars as $var) {
                        if (ArrayHelper::getValueByPath($context, $var) !== null) {
                            ArrayHelper::setValueByPath($context, $var, '***');
                        }
                    }

                    foreach ($context as $key => $value) {
                        if (empty($value)) {
                            continue;
                        }
                        $scope->setContext($key, $value);
                    }
                }

                $data = $this->runExtraCallback($message, $data);
                $data = $this->runUserCallback($message, $data);
                $data = $this->runTagsCallback($message, $data);

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
                    $event->setTimestamp($timestamp);
                    $event->setStacktrace($this->getStackTrace($message));

                    captureEvent($event,
                        EventHint::fromArray(array_filter(['exception' => $data['exception'] ?? null]))
                    );
                }

            });
        }
    }

    protected $_sentry;

    /**
     * @return false|Client|ClientInterface
     */
    protected function getSentry()
    {
        if (!isset($this->_sentry)) {
            $this->_sentry = false;
            if (!Yii::app()->hasComponent($this->sentryComponent)) {
                Yii::log("'{$this->sentryComponent}' does not exists", CLogger::LEVEL_TRACE, __CLASS__);
            } else {
                /** @var SentryComponent $sentry */
                $sentry = Yii::app()->{$this->sentryComponent};
                if (!$sentry || !$sentry->getIsInitialized()) {
                    Yii::log("'{$this->sentryComponent}' not initialized", CLogger::LEVEL_TRACE, __CLASS__);
                } else {
                    $this->_sentry = $sentry->getClient();
                }
            }
        }

        return $this->_sentry;
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
        if (is_callable($this->userCallback)) {
            $data['userData'] = call_user_func($this->userCallback, $message, $data['userData'] ?? []);
        }

        return $data;
    }

    /**
     * @param $message
     * @param array $data
     * @return array
     */
    private function runTagsCallback($message, array $data): array
    {
        if (is_callable($this->tagsCallback)) {
            $data['tags'] = call_user_func($this->tagsCallback, $message, $data['tags'] ?? []);
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

    /**
     * Parse yii stack trace for sentry.
     *
     * Example log string:
     * This is Warning in /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/CWebApplication.php:286
     * in /app/org.aevsa.yii1/protected/modules/debug/controllers/DebugController.php (99)
     * in /app/org.aevsa.yii1/index.php (21)
     *
     * #22 /var/www/example.is74.ru/vendor/yiisoft/yii/framework/web/CWebApplication.php(282): CController->run('index')
     *
     * (/app/org.aevsa.yii1/protected/modules/debug/controllers/DebugController.php:101)
     * Stack trace:
     * #0 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/actions/CInlineAction.php(49): DebugController->actionExp()
     * #1 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/CController.php(308): CInlineAction->runWithParams()
     * #2 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/CController.php(286): DebugController->runAction()
     * #3 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/CController.php(265): DebugController->runActionWithFilters()
     * #4 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/CWebApplication.php(282): DebugController->run()
     * #5 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/web/CWebApplication.php(141): CWebApplication->runController()
     * #6 /app/org.aevsa.yii1/protected/vendor/yiisoft/yii/framework/base/CApplication.php(185): CWebApplication->processRequest()
     * #7 /app/org.aevsa.yii1/index.php(21): CWebApplication->run()
     * @param string $log
     * @return Stacktrace|null
     *
     */
    protected function getStackTrace($log)
    {
       if (strpos($log, 'Stack trace:') !== false || strpos($log, 'in /') !== false) {
            if (preg_match_all($this->tracePattern, $log, $m, PREG_SET_ORDER)) {
                $frames = array();
                $frameBuilder = new FrameBuilder($this->sentry->getOptions(),
                    new RepresentationSerializer($this->sentry->getOptions()));
                foreach ($m as $row) {
                    $stack = array(
                        'file' => $row['file'] ?? Frame::INTERNAL_FRAME_FILENAME,
                        'line' => $row['line'] ?? 0,
                        'function' => $row['func'] ?? '',
                        'class' => $row['cls'] ?? '',
                    );
                    array_unshift($frames,
                        $frameBuilder->buildFromBacktraceFrame($stack['file'], $stack['line'], $stack));
                }

                return new Stacktrace($frames);
            }
        }

        return null;
    }
}