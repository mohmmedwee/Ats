<?php

namespace App\Modules\Suppliers\Domain\Connector;

/** The request was NOT processed by the supplier (connection refused, 429, explicit "not processed"). Safe to retry. */
final class TransientConnectorException extends ConnectorException {}
