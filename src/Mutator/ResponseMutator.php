<?php

namespace Http\Client\Common\Plugin\Cache\Mutator;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An interface for a mutator of a possibly cached response.
 *
 * @author Iain Connor <iain.connor@priceline.com>
 */
interface ResponseMutator
{
    /**
     * Mutate the response depending on the cache status.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param bool              $cacheHit
     *
     * @return string
     */
    public function mutate(RequestInterface $request, ResponseInterface $response, $cacheHit);
}
