<?php

namespace Http\Client\Common\Plugin\Cache\Generator;

use Psr\Http\Message\RequestInterface;

/**
 * Generate a cache key by vary on Cookie and Authorization header.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class SharedCacheKeyGenerator implements CacheKeyGenerator
{
    /**
     * The header names we should take into account when creating the cache key.
     *
     * @var array
     */
    private $headerNames;

    /**
     * @param array $headerNames defaults to Authorization and Cookie
     */
    public function __construct(array $headerNames = ['Authorization', 'Cookie'])
    {
        $this->headerNames = $headerNames;
    }

    public function generate(RequestInterface $request)
    {
        $concatenatedHeaders = [];
        foreach ($this->headerNames as $headerName) {
            $concatenatedHeaders[] = sprintf(' %s:"%s"', $headerName, $request->getHeaderLine($headerName));
        }

        return $request->getMethod().' '.$request->getUri().implode('', $concatenatedHeaders);
    }
}
