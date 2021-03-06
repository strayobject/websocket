<?php
namespace Icicle\WebSocket\Protocol;

use Icicle\Http\Message\{BasicRequest, BasicResponse, Request, Response, Uri};
use Icicle\Socket\Socket;
use Icicle\Stream\MemorySink;
use Icicle\WebSocket\Application;

class DefaultProtocolMatcher implements ProtocolMatcher
{
    /**
     * @var \Icicle\WebSocket\Protocol\Protocol
     */
    private $protocol;

    /**
     * @param mixed[] $options
     */
    public function __construct(array $options = [])
    {
        // Only RFC6455 supported at the moment, so keeping this simple.
        $this->protocol = new Rfc6455Protocol($options);
    }

    /**
     * {@inheritdoc}
     */
    public function createResponse(Application $application, Request $request, Socket $socket): Response
    {
        if ($request->getMethod() !== 'GET') {
            $sink = new MemorySink('Only GET requests allowed for WebSocket connections.');
            return new BasicResponse(Response::METHOD_NOT_ALLOWED, [
                'Connection' => 'close',
                'Upgrade' => 'websocket',
                'Content-Length' => $sink->getLength(),
                'Content-Type' => 'text/plain',
            ], $sink);
        }

        if (!in_array('upgrade', array_map('trim', explode(',', strtolower($request->getHeader('Connection')))), true)
            || strtolower($request->getHeader('Upgrade')) !== 'websocket'
        ) {
            $sink = new MemorySink('Must upgrade to WebSocket connection for requested resource.');
            return new BasicResponse(Response::UPGRADE_REQUIRED, [
                'Connection' => 'close',
                'Upgrade' => 'websocket',
                'Content-Length' => $sink->getLength(),
                'Content-Type' => 'text/plain',
            ], $sink);
        }

        if (!$this->protocol->isProtocol($request)) {
            $sink = new MemorySink('Unsupported protocol version.');
            return new BasicResponse(Response::UPGRADE_REQUIRED, [
                'Connection' => 'close',
                'Content-Length' => $sink->getLength(),
                'Content-Type' => 'text/plain',
                'Upgrade' => 'websocket',
                'Sec-WebSocket-Version' => $this->getSupportedVersions()
            ], $sink);
        }

        return $this->protocol->createResponse($application, $request, $socket);
    }

    /**
     * {@inheritdoc}
     */
    public function createRequest(Uri $uri, array $protocols = [], array $extensions = []): Request
    {
        $headers = [
            'Connection' => 'upgrade',
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Version' => $this->getSupportedVersions(),
            'Sec-WebSocket-Key' => $this->protocol->generateKey(),
        ];

        if (!empty($protocols)) {
            $headers['Sec-WebSocket-Protocol'] = implode(', ', $protocols);
        }

        if (!empty($extensions)) {
            $headers['Sec-WebSocket-Extension'] = implode(', ', $extensions);
        }

        return new BasicRequest('GET', $uri, $headers);
    }

    /**
     * {@inheritdoc}
     */
    public function validateResponse(Request $request, Response $response): bool
    {
        return $this->protocol->validateResponse($request, $response);
    }

    /**
     * @return string[]
     */
    public function getSupportedVersions(): array
    {
        return [$this->protocol->getVersionNumber()];
    }
}