<?php

namespace Rcalicdan\FiberAsync\ValueObjects;

/**
 * Value object for monitoring stream resources in the event loop.
 *
 * This class represents a stream resource that needs to be monitored for
 * readability or writability within the async event loop. It encapsulates
 * the stream resource and the callback that should be executed when the
 * stream becomes ready for the desired operation.
 *
 * StreamWatcher objects are used by the event loop to efficiently monitor
 * multiple streams using select() or similar mechanisms, enabling non-blocking
 * I/O operations that integrate seamlessly with fiber-based async programming.
 */
class StreamWatcher
{
    /**
     * @var resource The stream resource to monitor (file, socket, pipe, etc.)
     */
    private $stream;

    /**
     * @var callable Callback to execute when the stream becomes ready
     */
    private $callback;

    /**
     * Create a new stream watcher for monitoring stream readiness.
     *
     * Associates a stream resource with a callback function that should be
     * executed when the stream becomes ready for I/O operations. The callback
     * will receive the stream resource as its parameter when invoked.
     *
     * @param  resource  $stream  The stream resource to monitor
     * @param  callable  $callback  Function to call when stream is ready
     */
    public function __construct($stream, callable $callback)
    {
        $this->stream = $stream;
        $this->callback = $callback;
    }

    /**
     * Get the monitored stream resource.
     *
     * Returns the stream resource that this watcher is monitoring.
     * The event loop uses this to include the stream in select() calls
     * and other stream monitoring operations.
     *
     * @return resource The monitored stream resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Get the callback function for this stream watcher.
     *
     * Returns the callback that should be executed when the monitored
     * stream becomes ready for I/O operations. The callback is responsible
     * for handling the actual I/O and any subsequent promise resolution.
     *
     * @return callable The callback function for stream readiness
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Execute the callback with the monitored stream.
     *
     * Called by the event loop when the monitored stream becomes ready
     * for I/O operations. Passes the stream resource to the callback
     * function for processing.
     */
    public function execute(): void
    {
        ($this->callback)($this->stream);
    }
}
