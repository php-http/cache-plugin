<?php

namespace spec\Http\Client\Common\Plugin\Cache\Generator;

use PhpSpec\ObjectBehavior;
use Psr\Http\Message\RequestInterface;

class SharedCacheKeyGeneratorSpec extends ObjectBehavior
{
    public function let()
    {
        $this->beConstructedWith(['Authorization', 'Content-Type']);
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType('Http\Client\Common\Plugin\Cache\Generator\SharedCacheKeyGenerator');
    }

    public function it_is_a_key_generator()
    {
        $this->shouldImplement('Http\Client\Common\Plugin\Cache\Generator\CacheKeyGenerator');
    }

    public function it_generates_cache_from_request(RequestInterface $request)
    {
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn('http://example.com/foo');
        $request->getHeaderLine('Authorization')->shouldBeCalled()->willReturn('bar');
        $request->getHeaderLine('Content-Type')->shouldBeCalled()->willReturn('application/baz');

        $this->generate($request)->shouldReturn('GET http://example.com/foo Authorization:"bar" Content-Type:"application/baz"');
    }
}
