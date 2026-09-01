<?php
/**
 * A deterministic contradiction in an Unreal redirect archive's own bytes.
 *
 * These errors are safe to show directly to an operator. They are not worker,
 * storage or PHP failures, so retrying the same immutable source cannot fix
 * them and the persisted message must not be prefixed with an exception class.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Redirect;

final class CatalogRedirectArchiveValidationException extends \RuntimeException
{
    /** @param array<string,int|string> $validationArguments */
    public function __construct(
        string $message,
        private readonly string $validationCode,
        private readonly array $validationArguments = []
    ) {
        parent::__construct($message);
    }

    public function validationCode(): string
    {
        return $this->validationCode;
    }

    /** @return array<string,int|string> */
    public function validationArguments(): array
    {
        return $this->validationArguments;
    }
}
