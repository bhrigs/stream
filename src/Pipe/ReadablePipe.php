<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Pipe;

use Exception;
use Icicle\Loop;
use Icicle\Promise\Deferred;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Stream\Exception\BusyError;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\FailureException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\ReadableStreamInterface;
use Icicle\Stream\StreamResource;

class ReadablePipe extends StreamResource implements ReadableStreamInterface
{
    /**
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;

    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $poll;

    /**
     * @var int
     */
    private $length = 0;

    /**
     * @var string|null
     */
    private $byte;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @param resource $resource Stream resource.
     */
    public function __construct($resource)
    {
        parent::__construct($resource);

        stream_set_read_buffer($resource, 0);
        stream_set_chunk_size($resource, self::CHUNK_SIZE);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Frees all resources used by the writable stream.
     *
     * @param \Exception|null $exception
     */
    private function free(Exception $exception = null)
    {
        if (null !== $this->poll) {
            $this->poll->free();
        }

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception ?: new ClosedException('The stream was unexpectedly closed.')
            );
        }

        parent::close();
    }

    /**
     * {@inheritdoc}
     */
    public function read($length = 0, $byte = null, $timeout = 0)
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        $this->length = (int) $length;
        if (0 >= $this->length) {
            $this->length = self::CHUNK_SIZE;
        }

        $this->byte = (string) $byte;
        $this->byte = strlen($this->byte) ? $this->byte[0] : null;

        $resource = $this->getResource();
        $data = $this->fetch($resource);

        if ('' !== $data) {
            yield $data;
            return;
        }

        if ($this->eof($resource)) { // Close only if no data was read and at EOF.
            $this->close();
            yield $data; // Resolve with empty string on EOF.
            return;
        }

        if (null === $this->poll) {
            $this->poll = $this->createPoll();
        }

        $this->poll->listen($timeout);

        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }

    /**
     * @coroutine
     *
     * Returns a coroutine fulfilled when there is data available to read in the internal stream buffer. Note that
     * this method does not consider data that may be available in the internal buffer. This method should be used to
     * implement functionality that uses the stream socket resource directly.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use null for no timeout.
     *
     * @return \Icicle\Promise\PromiseInterface
     *
     * @resolve string Empty string.
     *
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\FailureException If the stream buffer is not empty.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    public function poll($timeout = 0)
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if ('' !== $this->buffer) {
            throw new FailureException('Stream buffer is not empty. Perform another read before polling.');
        }

        $this->length = 0;

        if (null === $this->poll) {
            $this->poll = $this->createPoll();
        }

        $this->poll->listen($timeout);

        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->isOpen();
    }

    /**
     * Reads data from the stream socket resource based on set length and read-to byte.
     *
     * @param resource $resource
     *
     * @return string
     */
    private function fetch($resource)
    {
        if ('' === $this->buffer) {
            $data = (string) fread($resource, $this->length);

            if (null === $this->byte || '' === $data) {
                return $data;
            }

            $this->buffer = $data;
        }

        if (null !== $this->byte && false !== ($position = strpos($this->buffer, $this->byte))) {
            ++$position; // Include byte in result.
        } else {
            $position = $this->length;
        }

        $data = (string) substr($this->buffer, 0, $position);
        $this->buffer = (string) substr($this->buffer, $position);
        return $data;
    }

    /**
     * @param resource $resource
     *
     * @return bool
     */
    private function eof($resource)
    {
        return feof($resource) && '' === $this->buffer;
    }

    /**
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll()
    {
        return Loop\poll($this->getResource(), function ($resource, $expired) {
            if ($expired) {
                $this->deferred->reject(new TimeoutException('The connection timed out.'));
                return;
            }

            if (0 === $this->length) {
                $this->deferred->resolve('');
                return;
            }

            $data = $this->fetch($resource);

            $this->deferred->resolve($data);

            if ('' === $data && $this->eof($resource)) { // Close only if no data was read and at EOF.
                $this->close();
            }
        });
    }
}