<?php

namespace App\Modules\Suppliers\Domain\Connector;

/** The request may have been processed (timeout after sending, 5xx). Never retry blindly: reconcile first. */
final class UnknownOutcomeException extends ConnectorException {}
