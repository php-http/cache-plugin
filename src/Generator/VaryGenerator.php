<?php

namespace Http\Client\Common\Plugin\Generator;

use Psr\Http\Message\RequestInterface;

/**
 * Generate a cache key and specify what headers you want to vary on.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class VaryGenerator implements CacheKeyGenerator
{
    /**
     * The header names we should vary on.
     *
     * @var array
     */
    private $headerNames;

    /**
     * @param $headerNames
     */
    public function __construct(array $headerNames)
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
