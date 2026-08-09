<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the stable SMTP settings/address/send helper API for existing callers.
 * Why: SMTP settings/secrets and socket/message transport now have focused namespaced owners.
 * Role: Thin compatibility facade; do not add SMTP protocol or persistence implementation here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Email\CatalogSmtpSettingsStore;
use UnrealDb\Catalog\Infrastructure\Email\CatalogSmtpTransport;

/** @return array<string,mixed> */
function catalog_smtp_settings(PDO $db): array
{
    return (new CatalogSmtpSettingsStore($db))->settings();
}

function catalog_smtp_address(string $email, string $label): string
{
    return CatalogSmtpTransport::address($email, $label);
}

/**
 * @param array{reply_to_email?:string,reply_to_name?:string,headers?:array<string,string>} $options
 */
function catalog_smtp_send(PDO $db, string $recipient, string $subject, string $body, array $options = []): void
{
    (new CatalogSmtpTransport($db))->send($recipient, $subject, $body, $options);
}
