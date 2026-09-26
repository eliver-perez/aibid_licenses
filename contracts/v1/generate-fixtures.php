<?php

declare(strict_types=1);

// Public, deterministic test material. Never load these keys in production.
if (!extension_loaded('sodium')) {
    throw new RuntimeException('The Sodium extension is required.');
}

function encodeBase64Url(string $bytes): string
{
    return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function encodeJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function testKey(string $name, string $kind): array
{
    $seed = hash('sha256', 'AIBIDLICENSE PUBLIC TEST ONLY V1 ' . $name, true);
    $keyPair = sodium_crypto_sign_seed_keypair($seed);

    return [
        'name' => $name,
        'kind' => $kind,
        'seed_hex' => bin2hex($seed),
        'public_key_b64u' => encodeBase64Url(sodium_crypto_sign_publickey($keyPair)),
    ];
}

function signTestMessage(array $key, string $message): string
{
    $keyPair = sodium_crypto_sign_seed_keypair(hex2bin($key['seed_hex']));
    $signature = sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($keyPair));
    if (!sodium_crypto_sign_verify_detached($signature, $message, sodium_crypto_sign_publickey($keyPair))) {
        throw new RuntimeException('Sodium self-verification failed.');
    }

    return encodeBase64Url($signature);
}

function licenseVector(string $name, array $payload, array $key): array
{
    $headerJson = encodeJson(['alg' => 'EdDSA', 'kid' => $key['name'], 'typ' => 'lic+jws']);
    $payloadJson = encodeJson($payload);
    $signingInput = encodeBase64Url($headerJson) . '.' . encodeBase64Url($payloadJson);
    $licenseJws = $signingInput . '.' . signTestMessage($key, $signingInput);

    return [
        'name' => $name, 'key_name' => $key['name'], 'header_json' => $headerJson,
        'payload_json' => $payloadJson, 'signing_input' => $signingInput,
        'jws' => $licenseJws, 'sha256' => hash('sha256', $licenseJws),
    ];
}

function writeArtifact(string $path, string $bytes): void
{
    if (in_array('--check', $GLOBALS['argv'] ?? [], true)) {
        if (!is_file($path) || file_get_contents($path) !== $bytes) {
            throw new RuntimeException('Fixture mismatch: ' . basename($path));
        }
        return;
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Could not write fixture: ' . $path);
    }
}

$serverKey = testKey('lic-test-v1-a', 'server');
$rotationKey = testKey('lic-test-v1-b', 'server');
$installationKey = testKey('installation-test-v1-a', 'installation');
$otherInstallationKey = testKey('installation-test-v1-b', 'installation');
$installationId = '22222222-2222-4222-8222-222222222222';
$licenseId = '11111111-1111-4111-8111-111111111111';
$activationId = '33333333-3333-4333-8333-333333333333';
$fingerprintHash = 'sha256:' . hash('sha256', 'PUBLIC TEST MACHINE A');
$payload = [
    'schema_version' => '1.0', 'product_id' => 'gestor_documental',
    'license_id' => $licenseId, 'activation_id' => $activationId,
    'installation_id' => $installationId,
    'installation_public_key' => $installationKey['public_key_b64u'],
    'fingerprint_version' => '1', 'fingerprint_hash' => $fingerprintHash,
    'license_type' => 'perpetual', 'license_status' => 'active', 'license_revision' => 1,
    'issued_at' => '2026-09-22T18:30:00Z', 'expires_at' => null, 'grace_days' => 0,
    'maintenance_until' => null, 'entitled_release_until' => '2026-09-22T18:30:00Z',
    'features' => [
        'linked_libraries' => true, 'managed_libraries' => true, 'ocr' => true,
        'expedientes' => true, 'review_workflow' => true,
    ],
    'limits' => [
        'max_installations' => 1, 'max_users' => null, 'max_libraries' => null, 'max_documents' => null,
    ],
];
$subscriptionPayload = array_replace($payload, [
    'license_id' => '77777777-7777-4777-8777-777777777777',
    'activation_id' => '88888888-8888-4888-8888-888888888888',
    'license_type' => 'subscription', 'expires_at' => '2026-10-01T00:00:00Z',
    'grace_days' => 15, 'entitled_release_until' => '2026-10-01T00:00:00Z',
]);
$licenseVectors = [
    licenseVector('perpetual', $payload, $serverKey),
    licenseVector('subscription', $subscriptionPayload, $serverKey),
    licenseVector('revoked', array_replace($payload, [
        'license_status' => 'revoked', 'license_revision' => 2, 'issued_at' => '2026-09-23T18:30:00Z',
    ]), $serverKey),
    licenseVector('rotated', array_replace($payload, [
        'license_revision' => 3, 'issued_at' => '2026-09-24T18:30:00Z',
    ]), $rotationKey),
    licenseVector('renewed', array_replace($subscriptionPayload, [
        'license_revision' => 2, 'issued_at' => '2026-09-25T18:30:00Z',
        'expires_at' => '2026-11-01T00:00:00Z', 'entitled_release_until' => '2026-11-01T00:00:00Z',
    ]), $serverKey),
];

$proofVectors = [];
foreach (['activate', 'refresh', 'deactivate'] as $action) {
    $challengeId = '55555555-5555-4555-8555-555555555555';
    $nonce = encodeBase64Url(hash('sha256', 'PUBLIC TEST NONCE ' . $action, true));
    $proofActivationId = $action === 'activate' ? null : $activationId;
    $message = implode("\n", ['LIC-V1', $action, $challengeId, $nonce,
        'gestor_documental', $installationId, $proofActivationId ?? '-']);
    $proofVectors[] = [
        'name' => $action, 'key_name' => $installationKey['name'], 'action' => $action,
        'challenge_id' => $challengeId, 'nonce' => $nonce, 'product_id' => 'gestor_documental',
        'installation_id' => $installationId, 'activation_id' => $proofActivationId,
        'message_utf8' => $message, 'proof' => signTestMessage($installationKey, $message),
    ];
}

$offlineVectors = [];
foreach (['activate', 'renew', 'deactivate'] as $index => $action) {
    $offlinePayload = [
        'action' => $action,
        'request_id' => '44444444-4444-4444-8444-44444444444' . ($index + 1),
        'created_at' => '2026-09-25T18:30:00Z', 'product_id' => 'gestor_documental',
        'installation_id' => $installationId,
        'installation_public_key' => $installationKey['public_key_b64u'],
        'fingerprint_version' => '1', 'fingerprint_hash' => $fingerprintHash,
    ];
    if ($action !== 'activate') {
        $offlinePayload['license_id'] = $action === 'renew' ? $subscriptionPayload['license_id'] : $licenseId;
        $offlinePayload['activation_id'] = $action === 'renew' ? $subscriptionPayload['activation_id'] : $activationId;
    }
    $payloadJson = encodeJson($offlinePayload);
    $payloadSegment = encodeBase64Url($payloadJson);
    $message = "LICREQ-V1\n" . $payloadSegment;
    $envelope = [
        'schema_version' => '1.0', 'payload_b64u' => $payloadSegment,
        'signature_b64u' => signTestMessage($installationKey, $message),
    ];
    $offlineVectors[] = [
        'name' => $action, 'key_name' => $installationKey['name'], 'payload_json' => $payloadJson,
        'message_utf8' => $message, 'envelope_json' => encodeJson($envelope),
    ];
}

$originalSegments = explode('.', $licenseVectors[0]['jws']);
$badSignature = sodium_base642bin($originalSegments[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$badSignature[0] = chr(ord($badSignature[0]) ^ 1);
$changedPayload = array_replace($payload, ['license_revision' => 999]);
$unknownHeader = encodeJson(['alg' => 'EdDSA', 'kid' => 'untrusted-test-key', 'typ' => 'lic+jws']);
$unknownInput = encodeBase64Url($unknownHeader) . '.' . $originalSegments[1];
$negativeVectors = [
    ['name' => 'altered-payload', 'jws' => $originalSegments[0] . '.' . encodeBase64Url(encodeJson($changedPayload)) . '.' . $originalSegments[2], 'expected_error' => 'invalid_signature'],
    ['name' => 'altered-signature', 'jws' => $licenseVectors[0]['signing_input'] . '.' . encodeBase64Url($badSignature), 'expected_error' => 'invalid_signature'],
    ['name' => 'unknown-kid', 'jws' => $unknownInput . '.' . signTestMessage($serverKey, $unknownInput), 'expected_error' => 'unknown_kid'],
];

$vectors = [
    'fixture_version' => '1.0', 'warning' => 'PUBLIC TEST ONLY: never trust these keys in production.',
    'keys' => [$serverKey, $rotationKey, $installationKey, $otherInstallationKey],
    'licenses' => $licenseVectors, 'proofs' => $proofVectors,
    'offline_requests' => $offlineVectors, 'negative_jws' => $negativeVectors,
    'expected_client_cases' => [
        ['name' => 'before-expiry', 'license' => 'subscription', 'now' => '2026-09-30T23:59:59.999999Z', 'state' => 'active'],
        ['name' => 'at-expiry', 'license' => 'subscription', 'now' => '2026-10-01T00:00:00Z', 'state' => 'grace'],
        ['name' => 'before-grace-end', 'license' => 'subscription', 'now' => '2026-10-15T23:59:59.999999Z', 'state' => 'grace'],
        ['name' => 'at-grace-end', 'license' => 'subscription', 'now' => '2026-10-16T00:00:00Z', 'state' => 'expired'],
        ['name' => 'perpetual-later', 'license' => 'perpetual', 'now' => '2036-09-22T18:30:00Z', 'state' => 'active'],
        ['name' => 'other-installation-key', 'license' => 'perpetual', 'local_key_name' => $otherInstallationKey['name'], 'state' => 'invalid'],
    ],
];
$fixturesDirectory = __DIR__ . '/fixtures';
if (!is_dir($fixturesDirectory) && !mkdir($fixturesDirectory, 0755, true)) {
    throw new RuntimeException('Could not create fixture directory.');
}
foreach ($licenseVectors as $vector) {
    writeArtifact($fixturesDirectory . '/' . $vector['name'] . '.lic', $vector['jws']);
}
foreach ($offlineVectors as $vector) {
    writeArtifact($fixturesDirectory . '/' . $vector['name'] . '.licreq', $vector['envelope_json']);
}
writeArtifact($fixturesDirectory . '/vectors.json', json_encode($vectors,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
writeArtifact($fixturesDirectory . '/deactivate-response.json', encodeJson(['activation_id'=>'33333333-3333-4333-8333-333333333333','deactivated_at'=>'2026-09-25T18:30:00.000000Z','status'=>'deactivated']) . "\n");
echo (in_array('--check', $argv, true) ? 'Verified' : 'Generated') . " 5 JWS, 3 proofs, 3 offline requests and 3 negative JWS fixtures.\n";
