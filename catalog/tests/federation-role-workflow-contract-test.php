<?php
declare(strict_types=1);

function federation_role_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$join = file_get_contents($root . '/federation/join.php');
$joinMain = file_get_contents($root . '/federation/join-main-parent.php');
$settings = file_get_contents($root . '/federation/settings.php');
$claim = file_get_contents($root . '/federation/claim-parent.php');
$transfer = file_get_contents($root . '/federation/transfer-run.php');
$httpClient = file_get_contents($root . '/lib/TrustedHttpSourceClient.php');

foreach ([$join, $joinMain, $settings, $claim, $transfer, $httpClient] as $content) {
    federation_role_expect(is_string($content), 'A required federation workflow file is missing.');
}

federation_role_expect(str_contains($join, "header('Location: join-main-parent.php')"), 'Administrator join page does not use the easy join workflow.');
federation_role_expect(str_contains($joinMain, "const JMP_OFFICIAL_PARENT_URL = 'https://unrealdb.com';"), 'Official unrealdb.com parent option is missing.');
federation_role_expect(str_contains($joinMain, 'name="parent_mode" value="manual"'), 'Manual parent URL option is missing.');
federation_role_expect(str_contains($joinMain, 'site role to child'), 'Parent-to-child role warning is missing.');
foreach ([
    "fed_set_setting(\$db, 'site_role', 'child')",
    "fed_set_setting(\$db, 'child_enabled', '1')",
    "fed_set_setting(\$db, 'parent_enabled', '0')",
    "fed_set_setting(\$db, 'join_requests_enabled', '0')",
] as $required) {
    federation_role_expect(str_contains($joinMain, $required), 'Join workflow does not enforce child role setting: ' . $required);
    federation_role_expect(str_contains($claim, $required), 'Claim workflow does not enforce child role setting: ' . $required);
}
federation_role_expect(str_contains($settings, 'data-parent-only'), 'Settings UI does not mark parent-only controls.');
federation_role_expect(str_contains($settings, 'settings_apply_role($db, $siteRole)'), 'Settings save does not enforce role defaults.');
federation_role_expect(str_contains($settings, 'field.disabled=child'), 'Settings UI does not disable parent controls for child role.');

$settingName = 'allow_self_signed_federation_certificates';
federation_role_expect(str_contains($settings, $settingName), 'Self-signed federation certificate setting is missing.');
federation_role_expect(str_contains($settings, "\$settings[\$key] ?? '0'"), 'Self-signed federation certificate setting is not disabled by default.');
federation_role_expect(str_contains($joinMain, $settingName), 'Join requests do not honor the self-signed certificate setting.');
federation_role_expect(str_contains($claim, $settingName), 'Parent claims do not honor the self-signed certificate setting.');
federation_role_expect(str_contains($transfer, $settingName), 'Manual transfers do not honor the self-signed certificate setting.');
federation_role_expect(str_contains($httpClient, 'configureFromFederationSetting'), 'Background federation HTTP requests do not load the TLS test setting.');
federation_role_expect(str_contains($httpClient, 'CURLOPT_SSL_VERIFYPEER => !self::$allowUntrustedTls'), 'Federation cURL verification cannot be relaxed in testing mode.');
federation_role_expect(str_contains($httpClient, 'if (!self::$allowPrivateNetwork)'), 'Federation test mode does not support private-network endpoints.');

echo "Federation role and TLS workflow contract tests passed.\n";
