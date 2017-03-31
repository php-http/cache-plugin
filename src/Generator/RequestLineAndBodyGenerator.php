<?php

namespace Http\Client\Common\Plugin\Generator;

use Psr\Http\Message\RequestInterface;

/**
 * Generate a cache key from the request method, URI and body.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class RequestLineAndBodyGenerator implements CacheKeyGenerator
{
    public function generate(RequestInterface $request)
    {
        $body = (string) $request->getBody();
        if (!empty($body)) {
            $body = ' '.$body;
        }

        return $request->getMethod().' '.$request->getUri().$body;
    }
}
