<?php

namespace spec\Http\Client\Common\Plugin\Generator;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Psr\Http\Message\RequestInterface;

class RequestLineAndBodyGeneratorSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('Http\Client\Common\Plugin\Generator\RequestLineAndBodyGenerator');
    }

    function it_is_a_key_generator()
    {
        $this->shouldImplement('Http\Client\Common\Plugin\Generator\CacheKeyGenerator');
    }


    function it_generates_cache_from_request(RequestInterface $request)
    {
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn('http://example.com/foo');
        $request->getBody()->shouldBeCalled()->willReturn('bar');

        $this->generate($request)->shouldReturn('GET http://example.com/foo bar');
    }

    function it_generates_cache_from_request_with_no_body(RequestInterface $request)
    {
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn('http://example.com/foo');
        $request->getBody()->shouldBeCalled()->willReturn('');

        // No extra space after uri
        $this->generate($request)->shouldReturn('GET http://example.com/foo');
    }
}
