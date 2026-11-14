<?php

namespace App\Modules\Payments\Domain;

use RuntimeException;

final class InvalidWebhookSignature extends RuntimeException {}
