<?php

namespace App\Modules\Suppliers\Domain\Connector;

use RuntimeException;

/** Permanent connector failure (bad request, unsupported operation). Not retried automatically. */
class ConnectorException extends RuntimeException {}
