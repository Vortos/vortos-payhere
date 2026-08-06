<?php

declare(strict_types=1);

namespace Vortos\PayHere\Exception;

/**
 * PayHere could not be reached, or answered with a server error, or the
 * circuit is open.
 *
 * The request never reached a decision, so the outcome is *unknown* — not
 * failed. A caller that treats this as failure after a refund call risks
 * issuing a second refund for a first one that succeeded.
 */
final class PayHereUnavailableException extends PayHereException
{
}
