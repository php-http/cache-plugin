<?php

namespace Http\Client\Common\Plugin\Cache\Mutator;

use Psr\Http\Message\ResponseInterface;

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
    public function __construct($headerName = 'X-From-Php-Http-Cache')
    {
        $this->headerName = $headerName;
    }

    /**
     * Mutate the response depending on the cache status.
     *
     * @param ResponseInterface $response
     * @param bool              $cacheHit
     *
     * @return string
     */
    public function mutate(ResponseInterface $response, $cacheHit)
    {
        return $response->withHeader($this->headerName, $cacheHit);
    }
}
