<?php

declare(strict_types=1);

namespace Vortos\PayHere\Exception;

/**
 * PayHere's merchant API understood the request and refused it, or answered
 * with something unusable.
 *
 * Terminal for this attempt — retrying an identical request gets an identical
 * refusal.
 */
final class PayHereApiException extends PayHereException
{
}
