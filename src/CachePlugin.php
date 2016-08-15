<?php

namespace Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Allow for caching a response.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class CachePlugin implements Plugin
{
    /**
     * @var CacheItemPoolInterface
     */
    private $pool;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @var array
     */
    private $config;

    /**
     * @param CacheItemPoolInterface $pool
     * @param StreamFactory          $streamFactory
     * @param array                  $config        {
     *
     *     @var bool $respect_cache_headers Whether to look at the cache directives or ignore them
     *     @var int $default_ttl (seconds) If we do not respect cache headers or can't calculate a good ttl, use this
     *              value
     *     @var string $hash_algo The hashing algorithm to use when generating cache keys
     *     @var int $cache_lifetime (seconds) To support serving a previous stale response when the server answers 304
     *              we have to store the cache for a longer time than the server originally says it is valid for.
     *              We store a cache item for $cache_lifetime + max age of the response.
     * }
     */
    public function __construct(CacheItemPoolInterface $pool, StreamFactory $streamFactory, array $config = [])
    {
        $this->pool = $pool;
        $this->streamFactory = $streamFactory;

        $optionsResolver = new OptionsResolver();
        $this->configureOptions($optionsResolver);
        $this->config = $optionsResolver->resolve($config);
    }

    /**
     * {@inheritdoc}
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $method = strtoupper($request->getMethod());
        // if the request not is cachable, move to $next
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $next($request);
        }

        // If we can cache the request
        $key = $this->createCacheKey($request);
        $cacheItem = $this->pool->getItem($key);

        if ($cacheItem->isHit()) {
            $data = $cacheItem->get();
            // The array_key_exists() is to be removed in 2.0.
            if (array_key_exists('expiresAt', $data) && ($data['expiresAt'] === null || time() < $data['expiresAt'])) {
                // This item is still valid according to previous cache headers
                return new FulfilledPromise($this->createResponseFromCacheItem($cacheItem));
            }

            // Add headers to ask the server if this cache is still valid
            if ($modifiedSinceValue = $this->getModifiedSinceHeaderValue($cacheItem)) {
                $request = $request->withHeader('If-Modified-Since', $modifiedSinceValue);
            }

            if ($etag = $this->getETag($cacheItem)) {
                $request = $request->withHeader('If-None-Match', $etag);
            }
        }

        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {
            if (304 === $response->getStatusCode()) {
                if (!$cacheItem->isHit()) {
                    /*
                     * We do not have the item in cache. This plugin did not add If-Modified-Since
                     * or If-None-Match headers. Return the response from server.
                     */
                    return $response;
                }

                // The cached response we have is still valid
                $data = $cacheItem->get();
                $maxAge = $this->getMaxAge($response);
                $data['expiresAt'] = $this->calculateResponseExpiresAt($maxAge);
                $cacheItem->set($data)->expiresAfter($this->calculateCacheItemExpiresAfter($maxAge));
                $this->pool->save($cacheItem);

                return $this->createResponseFromCacheItem($cacheItem);
            }

            if ($this->isCacheable($response)) {
                $bodyStream = $response->getBody();
                $body = $bodyStream->__toString();
                if ($bodyStream->isSeekable()) {
                    $bodyStream->rewind();
                } else {
                    $response = $response->withBody($this->streamFactory->createStream($body));
                }

                $maxAge = $this->getMaxAge($response);
                $cacheItem
                    ->expiresAfter($this->calculateCacheItemExpiresAfter($maxAge))
                    ->set([
                        'response' => $response,
                        'body' => $body,
                        'expiresAt' => $this->calculateResponseExpiresAt($maxAge),
                        'createdAt' => time(),
                        'etag' => $response->getHeader('ETag'),
                    ]);
                $this->pool->save($cacheItem);
            }

            return $response;
        });
    }

    /**
     * Calculate the timestamp when this cache item should be dropped from the cache. The lowest value that can be
     * returned is $maxAge.
     *
     * @param int|null $maxAge
     *
     * @return int|null Unix system time passed to the PSR-6 cache
     */
    private function calculateCacheItemExpiresAfter($maxAge)
    {
        if ($this->config['cache_lifetime'] === null && $maxAge === null) {
            return;
        }

        return $this->config['cache_lifetime'] + $maxAge;
    }

    /**
     * Calculate the timestamp when a response expires. After that timestamp, we need to send a
     * If-Modified-Since / If-None-Match request to validate the response.
     *
     * @param int|null $maxAge
     *
     * @return int|null Unix system time. A null value means that the response expires when the cache item expires
     */
    private function calculateResponseExpiresAt($maxAge)
    {
        if ($maxAge === null) {
            return;
        }

        return time() + $maxAge;
    }

    /**
     * Verify that we can cache this response.
     *
     * @param ResponseInterface $response
     *
     * @return bool
     */
    protected function isCacheable(ResponseInterface $response)
    {
        if (!in_array($response->getStatusCode(), [200, 203, 300, 301, 302, 404, 410])) {
            return false;
        }
        if (!$this->config['respect_cache_headers']) {
            return true;
        }
        if ($this->getCacheControlDirective($response, 'no-store') || $this->getCacheControlDirective($response, 'private')) {
            return false;
        }

        return true;
    }

    /**
     * Get the value of a parameter in the cache control header.
     *
     * @param ResponseInterface $response
     * @param string            $name     The field of Cache-Control to fetch
     *
     * @return bool|string The value of the directive, true if directive without value, false if directive not present
     */
    private function getCacheControlDirective(ResponseInterface $response, $name)
    {
        $headers = $response->getHeader('Cache-Control');
        foreach ($headers as $header) {
            if (preg_match(sprintf('|%s=?([0-9]+)?|i', $name), $header, $matches)) {

                // return the value for $name if it exists
                if (isset($matches[1])) {
                    return $matches[1];
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     *
     * @return string
     */
    private function createCacheKey(RequestInterface $request)
    {
        return hash($this->config['hash_algo'], $request->getMethod().' '.$request->getUri());
    }

    /**
     * Get a ttl in seconds. It could return null if we do not respect cache headers and got no defaultTtl.
     *
     * @param ResponseInterface $response
     *
     * @return int|null
     */
    private function getMaxAge(ResponseInterface $response)
    {
        if (!$this->config['respect_cache_headers']) {
            return $this->config['default_ttl'];
        }

        // check for max age in the Cache-Control header
        $maxAge = $this->getCacheControlDirective($response, 'max-age');
        if (!is_bool($maxAge)) {
            $ageHeaders = $response->getHeader('Age');
            foreach ($ageHeaders as $age) {
                return $maxAge - ((int) $age);
            }

            return (int) $maxAge;
        }

        // check for ttl in the Expires header
        $headers = $response->getHeader('Expires');
        foreach ($headers as $header) {
            return (new \DateTime($header))->getTimestamp() - (new \DateTime())->getTimestamp();
        }

        return $this->config['default_ttl'];
    }

    /**
     * Configure an options resolver.
     *
     * @param OptionsResolver $resolver
     */
    private function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'cache_lifetime' => 86400 * 30, // 30 days
            'default_ttl' => 0,
            'respect_cache_headers' => true,
            'hash_algo' => 'sha1',
        ]);

        $resolver->setAllowedTypes('cache_lifetime', ['int', 'null']);
        $resolver->setAllowedTypes('default_ttl', ['int', 'null']);
        $resolver->setAllowedTypes('respect_cache_headers', 'bool');
        $resolver->setAllowedValues('hash_algo', hash_algos());
    }

    /**
     * @param CacheItemInterface $cacheItem
     *
     * @return ResponseInterface
     */
    private function createResponseFromCacheItem(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();

        /** @var ResponseInterface $response */
        $response = $data['response'];
        $response = $response->withBody($this->streamFactory->createStream($data['body']));

        return $response;
    }

    /**
     * Get the value of the "If-Modified-Since" header.
     *
     * @param CacheItemInterface $cacheItem
     *
     * @return string|null
     */
    private function getModifiedSinceHeaderValue(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();
        // The isset() is to be removed in 2.0.
        if (!isset($data['createdAt'])) {
            return;
        }

        $modified = new \DateTime('@'.$data['createdAt']);
        $modified->setTimezone(new \DateTimeZone('GMT'));

        return sprintf('%s GMT', $modified->format('l, d-M-y H:i:s'));
    }

    /**
     * Get the ETag from the cached response.
     *
     * @param CacheItemInterface $cacheItem
     *
     * @return string|null
     */
    private function getETag(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();
        // The isset() is to be removed in 2.0.
        if (!isset($data['etag'])) {
            return;
        }

        if (!is_array($data['etag'])) {
            return $data['etag'];
        }

        foreach ($data['etag'] as $etag) {
            if (!empty($etag)) {
                return $etag;
            }
        }
    }
}
