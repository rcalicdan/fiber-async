<?php

namespace Rcalicdan\FiberAsync\Exceptions;

/**
 * Thrown when a socket operation exceeds its specified timeout.
 */
class TimeoutException extends SocketException {}
