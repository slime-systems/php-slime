# Token Infrastructure & Identity (`SlimeSystems\Tokens`)

The `Tokens` module provides cryptographic token issuance and validation utilities designed for zero-trust API authentication, federated identity, and stateless flow continuation.

---

## 1. High-Assurance API Tokens (`ApiTokenIssuer` & `ApiTokenVerifier`)

Designed for secure server-to-server or client-to-API communication with anti-tamper payload verification and replay protection.

### Anti-Tamper Request Binding (`api:hash`)
The token embeds an `api:hash` claim containing a cryptographic digest (default `sha256`) of the raw HTTP request body. If the body is modified in transit, verification fails automatically.

### Issuing an API Token
```php
use SlimeSystems\Tokens\Api\ApiTokenIssuer;
use Illuminate\Http\Request;

// Issue bound to a Laravel Request instance
$token = ApiTokenIssuer::issueForRequest(
    keyId: 'client-key-01',
    secret: config('services.api.secret'),
    request: $request,
    extraClaims: ['sub' => 'user_123'],
);

// Or issue from a raw body string
$token = ApiTokenIssuer::issue(
    keyId: 'client-key-01',
    secret: config('services.api.secret'),
    rawBody: json_encode(['action' => 'sync', 'timestamp' => 1700000000]),
    extraClaims: ['role' => 'admin'],
);
```

### Verifying an API Token
```php
use SlimeSystems\Tokens\Api\ApiTokenVerifier;
use Illuminate\Http\Request;

$verifier = new ApiTokenVerifier(
    fetchSecret: fn (string $kid) => ApiKey::find($kid)?->secret,
    maxAgeSeconds: 300,        // Replay window: rejects tokens older than 5 minutes
    futureSkewSeconds: 30,     // Clock drift tolerance
    requireApiHash: true,      // Enforces body hash verification
);

// Verify directly against an incoming Request
$claims = $verifier->verifyRequest($token, $request);

// Or verify against raw body
$claims = $verifier->verify($token, rawBody: $rawPayload);
```

---

## 2. Federated Auth: Firebase Verification (`FirebaseVerifier`)

Validates Google Firebase Auth ID tokens with automated public key caching and rotation.

### Usage Pattern
```php
use SlimeSystems\Tokens\Firebase\FirebaseVerifier;

$verifier = new FirebaseVerifier(
    projectId: config('services.firebase.project_id'),
    leewaySeconds: 10,
);

// Returns verified token claims array
$claims = $verifier->verify($jwtToken);

$firebaseUid = $claims['sub'];
$email = $claims['email'] ?? null;
```

---

## 3. Stateless Continuation & State Tokens (`OpaqueToken` & `ContinuationToken`)

Encrypted using modern **PASETO v4.local** (authenticated encryption with AEAD using XChaCha20-Poly1305). Useful for stateless pagination cursors, multi-step transaction tokens, or temporary workflow state without storing ephemeral session records in the database.

### OpaqueToken
```php
use SlimeSystems\Tokens\State\OpaqueToken;

$tokenManager = new OpaqueToken(
    secret: config('app.key'),
    defaultKeyId: 'v1',
);

// Encrypt payload into a secure PASETO v4 string
$token = $tokenManager->encrypt([
    'userId' => 42,
    'step' => 2,
    'expiresAt' => now()->addMinutes(15)->timestamp,
]);

// Decrypt and extract payload
$payload = $tokenManager->decrypt($token);
```

### ContinuationToken (Stateless Pagination Cursor)
```php
use SlimeSystems\Tokens\State\ContinuationToken;

$cursorManager = new ContinuationToken(secret: config('app.key'));

// Generate cursor for next page
$nextCursor = $cursorManager->encode([
    'last_id' => 'rec_98765',
    'sort_order' => 'desc',
]);

// Decode cursor from request
$cursorData = $cursorManager->decode($request->query('cursor'));
```

---

## 4. Standardized API Responses (`HandlesApiResponse`)

A lightweight controller concern for standard JSON response formatting:

```php
namespace App\Http\Controllers;

use SlimeSystems\Tokens\Http\Concerns\HandlesApiResponse;

class ApiController extends Controller {
    use HandlesApiResponse;

    public function show($id) {
        $data = ['id' => $id, 'title' => 'Example'];
        return $this->renderData($data);
    }

    public function fail() {
        return $this->renderException(
            code: 'RESOURCE_NOT_FOUND',
            message: 'The requested resource does not exist.',
            status: 404
        );
    }
}
```
