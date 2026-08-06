<?php

declare(strict_types=1);

namespace Vortos\PayHere\Exception;

/**
 * A checkout was built without a field PayHere insists on.
 *
 * Thrown before the payer is redirected. PayHere would otherwise reject the
 * form on its own page, where we cannot see the error and the payer cannot do
 * anything about it — so the missing fields are named here, while there is
 * still somewhere to put them.
 */
final class MissingPayerDetailsException extends PayHereException
{
    /** @param list<string> $fields */
    public function __construct(public readonly array $fields, string $message)
    {
        parent::__construct($message);
    }

    /** @param list<string> $fields */
    public static function forFields(array $fields): self
    {
        return new self($fields, sprintf(
            'PayHere requires %s on every checkout. Collect them before offering this rail rather than sending a payer to a form that will refuse them.',
            implode(', ', $fields),
        ));
    }
}
