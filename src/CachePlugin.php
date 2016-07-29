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
     *     @var int $default_ttl If we do not respect cache headers or can't calculate a good ttl, use this value
     *     @var string $hash_algo The hashing algorithm to use when generating cache keys.
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
            if (isset($data['expiresAt']) && time() > $data['expiresAt']) {
                // This item is still valid according to previous cache headers
                return new FulfilledPromise($this->createResponseFromCacheItem($cacheItem));
            }

            // Add headers to ask the server if this cache is still valid
            if ($modifiedAt = $this->getModifiedAt($cacheItem)) {
                $modifiedAt = new \DateTime('@'.$modifiedAt);
                $modifiedAt->setTimezone(new \DateTimeZone('GMT'));
                $request = $request->withHeader(
                    'If-Modified-Since',
                    sprintf('%s GMT', $modifiedAt->format('l, d-M-y H:i:s'))
                );
            }

            if ($etag = $this->getETag($cacheItem)) {
                $request = $request->withHeader(
                    'If-None-Match',
                    $etag
                );
            }
        }


        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {
            if (304 === $response->getStatusCode()) {
                // The cached response we have is still valid
                $data = $cacheItem->get();
                $data['expiresAt'] = time() + $this->getMaxAge($response);
                $cacheItem->set($data)->expiresAfter($this->config['cache_lifetime']);
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

                $cacheItem
                    ->expiresAfter($this->config['cache_lifetime'])
                    ->set([
                    'response' => $response,
                    'body' => $body,
                    'expiresAt' => time() + $this->getMaxAge($response),
                    'createdAt' => time(),
                    'etag' => $response->getHeader('ETag'),
                ]);
                $this->pool->save($cacheItem);
            }

            return $response;
        });
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
            'cache_lifetime' => 2592000, // 30 days
            'default_ttl' => null,
            'respect_cache_headers' => true,
            'hash_algo' => 'sha1',
        ]);

        $resolver->setAllowedTypes('cache_lifetime', 'int');
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
     * Get the timestamp when the cached response was stored.
     *
     * @param CacheItemInterface $cacheItem
     *
     * @return int|null
     */
    private function getModifiedAt(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();
        if (!isset($data['createdAt'])) {
            return null;
        }

        return $data['createdAt'];
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
        if (!isset($data['etag'])) {
            return null;
        }

        if (is_array($data['etag'])) {
            foreach($data['etag'] as $etag) {
                if (!empty($etag)) {
                    return $etag;
                }
            }

            return null;
        }

        return $data['etag'];
    }
}
