<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Stream\Exception\InvalidArgumentError;
use Icicle\Stream\Exception\UnwritableException;

if (!function_exists(__NAMESPACE__ . '\pipe')) {
    /**
     * @coroutine
     *
     * @param \Icicle\Stream\ReadableStreamInterface $from
     * @param \Icicle\Stream\WritableStreamInterface $to
     * @param bool $end
     * @param int $length
     * @param string|null $byte
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve int
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    function pipe(
        ReadableStreamInterface $from,
        WritableStreamInterface $to,
        $end = true,
        $length = 0,
        $byte = null,
        $timeout = 0
    ) {
        if (!$to->isWritable()) {
            throw new UnwritableException('The stream is not writable.');
        }

        $length = (int) $length;
        if (0 > $length) {
            throw new InvalidArgumentError('The length should be a non-negative integer.');
        }

        $byte = (string) $byte;
        $byte = strlen($byte) ? $byte[0] : null;

        $bytes = 0;

        try {
            do {
                $data = (yield $from->read($length, $byte, $timeout));

                $count = strlen($data);
                $bytes += $count;

                yield $to->write($data, $timeout);
            } while ($from->isReadable()
                && $to->isWritable()
                && (null === $byte || $data[$count - 1] !== $byte)
                && (0 === $length || 0 < $length -= $count)
            );
        } catch (\Exception $exception) {
            if ($end && $to->isWritable()) {
                yield $to->end();
            }
            throw $exception;
        }

        if ($end && $to->isWritable()) {
            yield $to->end();
        }

        yield $bytes;
    }

    /**
     * @coroutine
     *
     * @param ReadableStreamInterface $stream
     * @param int $length
     * @param string|null $needle
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve string
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    function readTo(ReadableStreamInterface $stream, $length, $needle = null, $timeout = 0)
    {
        $length = (int) $length;
        if (0 > $length) {
            throw new InvalidArgumentError('The length should be a non-negative integer.');
        }

        $needle = (string) $needle;
        $nlength = strlen($needle);
        $byte = $nlength ? $needle[$nlength - 1] : null;

        $buffer = '';
        $remaining = $length;

        if (0 === $length && null === $byte) {
            throw new InvalidArgumentError('The needle cannot be empty if the length is 0.');
        }

        do {
            $buffer .= (yield $stream->read($remaining, $byte, $timeout));
        } while ((0 === $length || 0 < ($remaining = $length - strlen($buffer)))
            && (null === $byte || (substr($buffer, -$nlength) !== $needle))
        );

        yield $buffer;
    }

    /**
     * @coroutine
     *
     * @param ReadableStreamInterface $stream
     * @param int $maxlength
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve string
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    function readAll(ReadableStreamInterface $stream, $maxlength = 0, $timeout = 0)
    {
        $buffer = '';

        $maxlength = (int) $maxlength;
        if (0 > $maxlength) {
            throw new InvalidArgumentError('The max length should be a non-negative integer.');
        }

        $remaining = $maxlength;

        while ($stream->isReadable() && (0 === $maxlength || 0 < ($remaining = $maxlength - strlen($buffer)))) {
            $buffer .= (yield $stream->read($remaining, null, $timeout));
        }

        yield $buffer;
    }
}