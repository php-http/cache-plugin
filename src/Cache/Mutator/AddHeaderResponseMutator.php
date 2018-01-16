<?php

namespace Http\Client\Common\Plugin\Cache\Mutator;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Adds a header if the response came from cache.
 *
 * @author Iain Connor <iain.connor@priceline.com>
 */
class AddHeaderResponseMutator implements ResponseMutator
{
    /** @var string */
    private $headerName;

    /**
     * @param string $headerName
     */
    public function __construct($headerName = 'X-Cache')
    {
        $this->headerName = $headerName;
    }

    /**
     * Mutate the response depending on the cache status.
     *
     * @param RequestInterface        $request
     * @param ResponseInterface       $response
     * @param bool                    $cacheHit
     * @param CacheItemInterface|null $cacheItem
     *
     * @return string
     */
    public function mutate(RequestInterface $request, ResponseInterface $response, $cacheHit, CacheItemInterface $cacheItem)
    {
        return $response->withHeader($this->headerName, $cacheHit ? 'HIT' : 'MISS');
    }
}
