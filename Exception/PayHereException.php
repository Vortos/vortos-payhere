<?php

declare(strict_types=1);

namespace Vortos\PayHere\Exception;

/**
 * Base for PayHere-specific failures.
 *
 * The gateway adapter translates these into the rail-agnostic exceptions from
 * vortos-payments before they leave the package, so nothing outside ever has to
 * know PayHere's vocabulary.
 */
class PayHereException extends \RuntimeException
{
}
