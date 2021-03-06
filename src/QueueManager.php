<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org/)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org/)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.1.0
 * @license       https://opensource.org/licenses/MIT MIT License
 */
namespace Queue;

use BadMethodCallException;
use Cake\Event\Event;
use Cake\Log\Log;
use Cake\Utility\Hash;
use Enqueue\Client\Message as ClientMessage;
use Enqueue\SimpleClient\SimpleClient;
use LogicException;
use Queue\Job\EventJob;

class QueueManager
{
    /**
     * Configuration sets.
     *
     * @var array
     */
    protected static $_config = [];

    /**
     * Queue clients
     *
     * @var array
     */
    protected static $_clients = [];

    /**
     * This method can be used to define configuration adapters for an application.
     *
     * To change an adapter's configuration at runtime, first drop the adapter and then
     * reconfigure it.
     *
     * Adapters will not be constructed until the first operation is done.
     *
     * ### Usage
     *
     * Assuming that the class' name is `QueueManager` the following scenarios
     * are supported:
     *
     * Setting a queue engine up.
     *
     * ```
     * QueueManager::setConfig('default', $settings);
     * ```
     *
     * Injecting a constructed adapter in:
     *
     * ```
     * QueueManager::setConfig('default', $instance);
     * ```
     *
     * Configure multiple adapters at once:
     *
     * ```
     * QueueManager::setConfig($arrayOfConfig);
     * ```
     *
     * @param string|array $key The name of the configuration, or an array of multiple configs.
     * @param array $config An array of name => configuration data for adapter.
     * @throws \BadMethodCallException When trying to modify an existing config.
     * @throws \LogicException When trying to store an invalid structured config array.
     * @return void
     */
    public static function setConfig($key, $config = null): void
    {
        if ($config === null) {
            if (!is_array($key)) {
                throw new LogicException('If config is null, key must be an array.');
            }
            foreach ($key as $name => $settings) {
                static::setConfig($name, $settings);
            }

            return;
        }

        if (isset(static::$_config[$key])) {
            /** @psalm-suppress PossiblyInvalidArgument */
            throw new BadMethodCallException(sprintf('Cannot reconfigure existing key "%s"', $key));
        }

        if (empty($config['url'])) {
            throw new BadMethodCallException('Must specify "url" key');
        }

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        static::$_config[$key] = $config;
    }

    /**
     * Reads existing configuration.
     *
     * @param string $key The name of the configuration.
     * @return mixed Configuration data at the named key or null if the key does not exist.
     */
    public static function getConfig(string $key)
    {
        return static::$_config[$key] ?? null;
    }

    /**
     * Get a queueing engine
     *
     * @param string $name Key name of a configured adapter to get.
     * @return \Enqueue\SimpleClient\SimpleClient
     */
    public static function engine(string $name): SimpleClient
    {
        if (isset(static::$_clients[$name])) {
            return static::$_clients[$name];
        }

        $config = static::getConfig($name);
        $url = Hash::get($config, 'url');

        $logger = null;
        $loggerName = Hash::get($config, 'logger', null);
        if ($loggerName) {
            $logger = Log::engine($loggerName);
        }

        static::$_clients[$name] = new SimpleClient($url, $logger);
        static::$_clients[$name]->setupBroker();

        return static::$_clients[$name];
    }

    /**
     * Push a single job onto the queue.
     *
     * @param callable $callable  a job callable
     * @param array $args         an array of data to set for the job
     * @param array $options      an array of options for publishing the job
     * @return void
     */
    public static function push(callable $callable, array $args = [], array $options = []): void
    {
        $name = Hash::get($options, 'config', 'default');
        $config = static::getConfig($name);
        $queue = Hash::get($config, 'queue', 'default');

        $message = new ClientMessage([
            'queue' => $queue,
            'class' => $callable,
            'args' => [$args],
        ]);

        $delay = Hash::get($options, 'delay', null);
        if ($delay !== null) {
            $message->setDelay($delay);
        }

        $expires_at = Hash::get($options, 'expires_at', null);
        if ($expires_at !== null) {
            $message->setExpire($expires_at);
        }

        $priority = Hash::get($options, 'priority', null);
        if ($priority !== null) {
            $message->setPriority($priority);
        }

        $client = static::engine($name);
        $client->sendEvent($queue, $message);
    }

    /**
     * Places an event in the job queue
     *
     * @param string $eventName  name of the event
     * @param array $data        an array of data to set for the event
     * @param array $options     an array of options for publishing the job
     * @return void
     */
    public static function pushEvent(string $eventName, array $data = [], array $options = []): void
    {
        $eventClass = Hash::get($options, 'eventClass', Event::class);

        /** @psalm-suppress InvalidArgument */
        static::push([EventJob::class, 'execute'], [
            'className' => $eventClass,
            'eventName' => $eventName,
            'data' => $data,
        ], $options);
    }
}
