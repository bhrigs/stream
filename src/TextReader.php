<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Stream\Exception\Error;
use Icicle\Stream\Exception\InvalidArgumentError;
use Icicle\Stream\Structures\Buffer;

/**
 * Reads text from a stream.
 *
 * Requires mbstring to be available to do proper chracter decoding.
 */
class TextReader implements StreamInterface
{
    const DEFAULT_CHUNK_SIZE = 4096;

    /**
     * @var \Icicle\Stream\ReadableStreamInterface The stream to read from.
     */
    private $stream;

    /**
     * @var string The name of the character encoding to use.
     */
    private $encoding;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;

    /**
     * @var string The string of bytes representing a newline in the configured encoding.
     */
    private $newLine;

    /**
     * Creates a new stream reader for a given stream.
     *
     * @param \Icicle\Stream\ReadableStreamInterface $stream The stream to read from.
     * @param string $encoding The character encoding to use.
     */
    public function __construct(ReadableStreamInterface $stream, $encoding = 'UTF-8')
    {
        if (!extension_loaded('mbstring')) {
            throw new Error('The mbstring extension is not loaded.');
        }

        if (!in_array($encoding, mb_list_encodings())) {
            throw new InvalidArgumentError("The encoding '$encoding' is not available.");
        }

        $this->stream = $stream;
        $this->encoding = $encoding;
        $this->buffer = new Buffer();
        $this->newLine = mb_convert_encoding("\n", $encoding, 'ASCII');
    }

    /**
     * Gets the underlying stream.
     *
     * @return \Icicle\Stream\ReadableStreamInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Determines if the stream is still open.
     *
     * @return bool
     */
    public function isOpen()
    {
        return $this->stream->isOpen();
    }

    /**
     * Closes the stream reader and the underlying stream.
     */
    public function close()
    {
        $this->stream->close();
    }

    /**
     * @coroutine
     *
     * Returns the next sequence of characters without consuming them.
     *
     * @param int $length The number of characters to peek.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string String of characters read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function peek($length = 1, $timeout = 0)
    {
        // Read chunks of bytes until we reach the desired length.
        while (mb_strlen((string)$this->buffer, $this->encoding) < $length && $this->stream->isReadable()) {
            $this->buffer->push(yield $this->stream->read(self::DEFAULT_CHUNK_SIZE, null, $timeout));
        }

        yield mb_substr((string)$this->buffer, 0, min($length, $this->buffer->getLength()), $this->encoding);
    }

    /**
     * @coroutine
     *
     * Reads a specific number of characters from the stream.
     *
     * @param int $length The number of characters to read.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string String of characters read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function read($length = 1, $timeout = 0)
    {
        $text = (yield $this->peek($length, $timeout));
        $this->buffer->shift(strlen($text));
    }

    /**
     * @coroutine
     *
     * Reads a single line from the stream.
     *
     * Reads from the stream until a newline is reached or the stream is closed.
     * The newline characters are included in the returned string.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string A line of text read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function readLine($timeout = 0)
    {
        $newLineTail = substr($this->newLine, -1);

        // Check if a new line is already in the buffer.
        if (($pos = $this->buffer->search($this->newLine)) !== false)
        {
            yield $this->buffer->shift($pos + 1);
            return;
        }

        while ($this->stream->isReadable()) {
            $buffer = (yield $this->stream->read(0, $newLineTail, $timeout));

            if (($pos = strpos($buffer, $this->newLine)) !== false) {
                yield $this->buffer->drain() . substr($buffer, 0, $pos + 1);
                $this->buffer->push(substr($buffer, $pos));
                return;
            }

            $this->buffer->push($buffer);
        }
    }

    /**
     * @coroutine
     *
     * Reads all characters from the stream until the end of the stream is reached.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string The contents of the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function readAll($timeout = 0)
    {
        while ($this->stream->isReadable()) {
            $this->buffer->push(yield $this->stream->read(0, null, $timeout));
        }

        yield $this->buffer->drain();
    }

    /**
     * @coroutine
     *
     * Reads and parses characters from the stream according to a format.
     *
     * The format string is of the same format as `sscanf()`.
     *
     * @param string $format The parse format.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve array An array of parsed values.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     *
     * @see http://php.net/sscanf
     */
    public function scan($format, $timeout = 0)
    {
        // Read from the stream chunk by chunk, attempting to satisfy the format
        // string each time until the format successfully parses or the end of
        // the stream is reached.
        while (true) {
            $result = sscanf((string)$this->buffer, $format . '%n');
            $length = $result ? array_pop($result) : null;

            // If the format string was satisfied, consume the used characters and
            // return the parsed results.
            if ($length !== null && $length < $this->buffer->getLength()) {
                $this->buffer->shift($length);
                yield $result;
                return;
            }

            // Read more into the buffer if possible.
            if ($this->stream->isReadable()) {
                $this->buffer->push(yield $this->stream->read(self::DEFAULT_CHUNK_SIZE, null, $timeout));
            } else {
                // Format string can't be satisfied.
                yield null;
                return;
            }
        }
    }
}