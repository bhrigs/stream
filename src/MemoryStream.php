<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Exception;
use Icicle\Awaitable\Delayed;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Stream\Exception\BusyError;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnwritableException;

/**
 * Serves as buffer that implements the stream interface, allowing consumers to be notified when data is available in
 * the buffer. This class by itself is not particularly useful, but it can be extended to add functionality upon reading 
 * or writing, as well as acting as an example of how stream classes can be implemented.
 */
class MemoryStream implements DuplexStream
{
    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;
    
    /**
     * @var bool
     */
    private $open = true;
    
    /**
     * @var bool
     */
    private $writable = true;
    
    /**
     * @var \Icicle\Awaitable\Delayed|null
     */
    private $delayed;
    
    /**
     * @var int
     */
    private $length;
    
    /**
     * @var string|null
     */
    private $byte;

    /**
     * @var int
     */
    private $hwm;

    /**
     * @var \SplQueue|null
     */
    private $queue;
    
    /**
     * @param int $hwm High water mark. If the internal buffer has more than $hwm bytes, writes to the stream will
     *     return pending promises until the data is consumed.
     * @param string $data
     */
    public function __construct($hwm = 0, $data = '')
    {
        $this->buffer = new Structures\Buffer($data);
        $this->hwm = (int) $hwm;
        if (0 > $this->hwm) {
            $this->hwm = 0;
        }

        if (0 !== $this->hwm) {
            $this->queue = new \SplQueue();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen()
    {
        return $this->open;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Closes the stream and rejects any pending promises.
     *
     * @param \Exception|null $exception
     */
    protected function free(Exception $exception = null)
    {
        $this->open = false;
        $this->writable = false;

        if (null !== $this->delayed && $this->delayed->isPending()) {
            $this->delayed->resolve('');
        }

        if (0 !== $this->hwm) {
            while (!$this->queue->isEmpty()) {
                /** @var \Icicle\Awaitable\Delayed $delayed */
                $delayed = $this->queue->shift();
                $delayed->cancel(
                    $exception = $exception ?: new ClosedException('The stream was unexpectedly closed.')
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($length = 0, $byte = null, $timeout = 0)
    {
        if (null !== $this->delayed) {
            throw new BusyError('Already waiting to read from stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        $this->length = (int) $length;
        if (0 > $this->length) {
            throw new InvalidArgumentError('The length should be a positive integer.');
        }

        $this->byte = (string) $byte;
        $this->byte = strlen($this->byte) ? $this->byte[0] : null;

        if (!$this->buffer->isEmpty()) {
            $data = $this->remove();

            if (0 !== $this->hwm && $this->buffer->getLength() <= $this->hwm) {
                while (!$this->queue->isEmpty()) {
                    /** @var \Icicle\Awaitable\Delayed $delayed */
                    $delayed = $this->queue->shift();
                    $delayed->resolve();
                }
            }

            if (!$this->writable && $this->buffer->isEmpty()) {
                $this->close();
            }

            yield $data;
            return;
        }

        $awaitable = new Delayed();
        $this->delayed = $awaitable;

        if (0 !== $timeout) {
            $awaitable = $this->delayed->timeout($timeout);
        }

        try {
            yield $awaitable;
        } finally {
            $this->delayed = null;
        }
    }

    /**
     * Returns bytes from the buffer based on the current length or current search byte.
     *
     * @return string
     */
    private function remove()
    {
        if (null !== $this->byte && false !== ($position = $this->buffer->search($this->byte))) {
            if (0 === $this->length || $position < $this->length) {
                return $this->buffer->shift($position + 1);
            }

            return $this->buffer->shift($this->length);
        }

        if (0 === $this->length) {
            return $this->buffer->drain();
        }

        return $this->buffer->shift($this->length);
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->isOpen();
    }

    /**
     * {@inheritdoc}
     */
    public function write($data, $timeout = 0)
    {
        return $this->send($data, $timeout, false);
    }

    /**
     * {@inheritdoc}
     */
    public function end($data = '', $timeout = 0)
    {
        return $this->send($data, $timeout, true);
    }

    /**
     * @coroutine
     *
     * @param string $data
     * @param float|int $timeout
     * @param bool $end
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is not longer writable.
     */
    protected function send($data, $timeout = 0, $end = false)
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $this->buffer->push($data);

        if (null !== $this->delayed && !$this->buffer->isEmpty()) {
            $this->delayed->resolve($this->remove());
        }

        if ($end) {
            if ($this->buffer->isEmpty()) {
                $this->close();
            } else {
                $this->writable = false;
            }
        }

        if (0 !== $this->hwm && $this->buffer->getLength() > $this->hwm) {
            $awaitable = new Delayed();
            $this->queue->push($awaitable);

            if (0 !== $timeout) {
                $awaitable = $awaitable->timeout($timeout);
            }

            try {
                yield $awaitable;
            } catch (Exception $exception) {
                if ($this->isOpen()) {
                    $this->free($exception);
                }
                throw $exception;
            }
        }

        yield strlen($data);
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable()
    {
        return $this->writable;
    }
}
