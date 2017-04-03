<?php

namespace spec\Http\Client\Common\Plugin\Cache\Generator;

use PhpSpec\ObjectBehavior;
use Psr\Http\Message\RequestInterface;

class SimpleGeneratorSpec extends ObjectBehavior
{
    public function it_is_initializable()
    {
        $this->shouldHaveType('Http\Client\Common\Plugin\Cache\Generator\SimpleGenerator');
    }

    public function it_is_a_key_generator()
    {
        $this->shouldImplement('Http\Client\Common\Plugin\Cache\Generator\CacheKeyGenerator');
    }

    public function it_generates_cache_from_request(RequestInterface $request)
    {
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn('http://example.com/foo');
        $request->getBody()->shouldBeCalled()->willReturn('bar');

        $this->generate($request)->shouldReturn('GET http://example.com/foo bar');
    }

    public function it_generates_cache_from_request_with_no_body(RequestInterface $request)
    {
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn('http://example.com/foo');
        $request->getBody()->shouldBeCalled()->willReturn('');

        // No extra space after uri
        $this->generate($request)->shouldReturn('GET http://example.com/foo');
    }
}
