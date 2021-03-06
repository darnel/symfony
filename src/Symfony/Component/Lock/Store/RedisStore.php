<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Lock\Store;

use Symfony\Component\Lock\Exception\InvalidArgumentException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\StoreInterface;

/**
 * RedisStore is a StoreInterface implementation using Redis as store engine.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
class RedisStore implements StoreInterface
{
    private static $defaultConnectionOptions = array(
        'class' => null,
        'persistent' => 0,
        'persistent_id' => null,
        'timeout' => 30,
        'read_timeout' => 0,
        'retry_interval' => 0,
    );
    private $redis;
    private $initialTtl;

    /**
     * @param \Redis|\RedisArray|\RedisCluster|\Predis\Client $redisClient
     * @param float                                           $initialTtl  the expiration delay of locks in seconds
     */
    public function __construct($redisClient, $initialTtl = 300.0)
    {
        if (!$redisClient instanceof \Redis && !$redisClient instanceof \RedisArray && !$redisClient instanceof \RedisCluster && !$redisClient instanceof \Predis\Client) {
            throw new InvalidArgumentException(sprintf('%s() expects parameter 1 to be Redis, RedisArray, RedisCluster or Predis\Client, %s given', __METHOD__, is_object($redisClient) ? get_class($redisClient) : gettype($redisClient)));
        }

        if ($initialTtl <= 0) {
            throw new InvalidArgumentException(sprintf('%s() expects a strictly positive TTL. Got %d.', __METHOD__, $initialTtl));
        }

        $this->redis = $redisClient;
        $this->initialTtl = $initialTtl;
    }

    /**
     * Creates a Redis connection using a DSN configuration.
     *
     * Example DSN:
     *   - redis://localhost
     *   - redis://example.com:1234
     *   - redis://secret@example.com/13
     *   - redis:///var/run/redis.sock
     *   - redis://secret@/var/run/redis.sock/13
     *
     * @param string $dsn
     * @param array  $options See self::$defaultConnectionOptions
     *
     * @throws InvalidArgumentException When the DSN is invalid
     *
     * @return \Redis|\Predis\Client According to the "class" option
     */
    public static function createConnection($dsn, array $options = array())
    {
        if (0 !== strpos($dsn, 'redis://')) {
            throw new InvalidArgumentException(sprintf('Invalid Redis DSN: %s does not start with "redis://"', $dsn));
        }
        $params = preg_replace_callback('#^redis://(?:(?:[^:@]*+:)?([^@]*+)@)?#', function ($m) use (&$auth) {
            if (isset($m[1])) {
                $auth = $m[1];
            }

            return 'file://';
        }, $dsn);
        if (false === $params = parse_url($params)) {
            throw new InvalidArgumentException(sprintf('Invalid Redis DSN: %s', $dsn));
        }
        if (!isset($params['host']) && !isset($params['path'])) {
            throw new InvalidArgumentException(sprintf('Invalid Redis DSN: %s', $dsn));
        }
        if (isset($params['path']) && preg_match('#/(\d+)$#', $params['path'], $m)) {
            $params['dbindex'] = $m[1];
            $params['path'] = substr($params['path'], 0, -strlen($m[0]));
        }
        $params += array(
            'host' => isset($params['host']) ? $params['host'] : $params['path'],
            'port' => isset($params['host']) ? 6379 : null,
            'dbindex' => 0,
        );
        if (isset($params['query'])) {
            parse_str($params['query'], $query);
            $params += $query;
        }
        $params += $options + self::$defaultConnectionOptions;
        $class = null === $params['class'] ? (extension_loaded('redis') ? \Redis::class : \Predis\Client::class) : $params['class'];

        if (is_a($class, \Redis::class, true)) {
            $connect = $params['persistent'] || $params['persistent_id'] ? 'pconnect' : 'connect';
            $redis = new $class();
            @$redis->{$connect}($params['host'], $params['port'], $params['timeout'], $params['persistent_id'], $params['retry_interval']);

            if (@!$redis->isConnected()) {
                $e = ($e = error_get_last()) && preg_match('/^Redis::p?connect\(\): (.*)/', $e['message'], $e) ? sprintf(' (%s)', $e[1]) : '';
                throw new InvalidArgumentException(sprintf('Redis connection failed%s: %s', $e, $dsn));
            }

            if ((null !== $auth && !$redis->auth($auth))
                || ($params['dbindex'] && !$redis->select($params['dbindex']))
                || ($params['read_timeout'] && !$redis->setOption(\Redis::OPT_READ_TIMEOUT, $params['read_timeout']))
            ) {
                $e = preg_replace('/^ERR /', '', $redis->getLastError());
                throw new InvalidArgumentException(sprintf('Redis connection failed (%s): %s', $e, $dsn));
            }
        } elseif (is_a($class, \Predis\Client::class, true)) {
            $params['scheme'] = isset($params['host']) ? 'tcp' : 'unix';
            $params['database'] = $params['dbindex'] ?: null;
            $params['password'] = $auth;
            $redis = new $class((new Factory())->create($params));
        } elseif (class_exists($class, false)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a subclass of "Redis" or "Predis\Client"', $class));
        } else {
            throw new InvalidArgumentException(sprintf('Class "%s" does not exist', $class));
        }

        return $redis;
    }

    /**
     * {@inheritdoc}
     */
    public function save(Key $key)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("PEXPIRE", KEYS[1], ARGV[2])
            else
                return redis.call("set", KEYS[1], ARGV[1], "NX", "PX", ARGV[2])
            end
        ';

        $key->reduceLifetime($this->initialTtl);
        if (!$this->evaluate($script, (string) $key, array($this->getToken($key), (int) ceil($this->initialTtl * 1000)))) {
            throw new LockConflictedException();
        }

        if ($key->isExpired()) {
            throw new LockExpiredException(sprintf('Failed to store the "%s" lock.', $key));
        }
    }

    public function waitAndSave(Key $key)
    {
        throw new InvalidArgumentException(sprintf('The store "%s" does not supports blocking locks.', get_class($this)));
    }

    /**
     * {@inheritdoc}
     */
    public function putOffExpiration(Key $key, $ttl)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("PEXPIRE", KEYS[1], ARGV[2])
            else
                return 0
            end
        ';

        $key->reduceLifetime($ttl);
        if (!$this->evaluate($script, (string) $key, array($this->getToken($key), (int) ceil($ttl * 1000)))) {
            throw new LockConflictedException();
        }

        if ($key->isExpired()) {
            throw new LockExpiredException(sprintf('Failed to put off the expiration of the "%s" lock within the specified time.', $key));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(Key $key)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        $this->evaluate($script, (string) $key, array($this->getToken($key)));
    }

    /**
     * {@inheritdoc}
     */
    public function exists(Key $key)
    {
        return $this->redis->get((string) $key) === $this->getToken($key);
    }

    /**
     * Evaluates a script in the corresponding redis client.
     *
     * @param string $script
     * @param string $resource
     * @param array  $args
     *
     * @return mixed
     */
    private function evaluate($script, $resource, array $args)
    {
        if ($this->redis instanceof \Redis || $this->redis instanceof \RedisCluster) {
            return $this->redis->eval($script, array_merge(array($resource), $args), 1);
        }

        if ($this->redis instanceof \RedisArray) {
            return $this->redis->_instance($this->redis->_target($resource))->eval($script, array_merge(array($resource), $args), 1);
        }

        if ($this->redis instanceof \Predis\Client) {
            return call_user_func_array(array($this->redis, 'eval'), array_merge(array($script, 1, $resource), $args));
        }

        throw new InvalidArgumentException(sprintf('%s() expects been initialized with a Redis, RedisArray, RedisCluster or Predis\Client, %s given', __METHOD__, is_object($this->redis) ? get_class($this->redis) : gettype($this->redis)));
    }

    /**
     * Retrieves an unique token for the given key.
     *
     * @param Key $key
     *
     * @return string
     */
    private function getToken(Key $key)
    {
        if (!$key->hasState(__CLASS__)) {
            $token = base64_encode(random_bytes(32));
            $key->setState(__CLASS__, $token);
        }

        return $key->getState(__CLASS__);
    }
}
