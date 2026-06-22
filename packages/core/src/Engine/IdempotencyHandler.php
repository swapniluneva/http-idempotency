<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

use HttpIdempotency\Config\IdempotencyConfig;
use HttpIdempotency\Exception\InvalidKeyException;
use HttpIdempotency\Fingerprint\FingerprintGenerator;
use HttpIdempotency\Key\IdempotencyKey;
use HttpIdempotency\Problem\ErrorCode;
use HttpIdempotency\Problem\ProblemDetail;
use HttpIdempotency\Record\IdempotencyRecord;
use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\BeginResult;
use HttpIdempotency\Store\StoreInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The single, framework-agnostic decision engine for idempotent request
 * handling. Every adapter reuses this: it reads a PSR-7 request, decides what
 * should happen ({@see evaluate()}), and — once the app has run — persists or
 * discards the result ({@see finalize()} / {@see abort()}).
 *
 * The flow mirrors the IETF idempotency-key draft:
 *  - non-enforced method or optional+absent key  -> PassThrough
 *  - required key missing/invalid/too long        -> Problem (400)
 *  - body over the limit                          -> Problem (413)
 *  - key reused with a different fingerprint      -> Problem (422)
 *  - original still in flight                     -> Problem (409)
 *  - original completed                           -> Replay
 *  - first time                                   -> Proceed (lock acquired)
 */
final readonly class IdempotencyHandler
{
    public function __construct(
        private StoreInterface $store,
        private FingerprintGenerator $fingerprintGenerator,
        private IdempotencyConfig $config,
        private ScopeResolver $scopeResolver = new NullScopeResolver,
    ) {}

    /**
     * Decide what to do with the request. Does not run the application.
     *
     * @param  bool|null  $requireKey  overrides the config default for this route
     *                                 (e.g. a `idempotency:required` middleware arg)
     */
    public function evaluate(ServerRequestInterface $request, ?bool $requireKey = null): Outcome
    {
        if (! $this->config->enforcesMethod($request->getMethod())) {
            return new PassThrough;
        }

        $required = $requireKey ?? $this->config->keyRequired;
        $rawKey = $this->readKeyHeader($request);

        if ($rawKey === null && ! $required) {
            return new PassThrough;
        }

        try {
            $key = IdempotencyKey::fromHeader($rawKey, $this->config->maxKeyLength);
        } catch (InvalidKeyException $e) {
            return $this->problem($e->errorCode);
        }

        if ($this->bodyExceedsLimit($request)) {
            return $this->problem(ErrorCode::BodyTooLarge);
        }

        $fingerprint = $this->fingerprintGenerator->generate($request);
        $lookupKey = $this->lookupKey($key, $request);

        $outcome = $this->store->begin(
            $lookupKey,
            $key->value,
            $fingerprint,
            $this->config->ttlSeconds,
        );

        return match ($outcome->result) {
            BeginResult::Acquired => new ProceedOutcome($lookupKey, (string) $outcome->lockToken),
            BeginResult::Replay => $this->replay($outcome->record),
            BeginResult::InProgress => $this->problem(ErrorCode::Conflict),
            BeginResult::Mismatch => $this->problem(ErrorCode::FingerprintMismatch),
        };
    }

    /**
     * Persist the application's response for replay — unless it is a server
     * error and caching those is disabled, in which case the lock is released
     * so the client can retry. Safe to call exactly once per ProceedOutcome.
     */
    public function finalize(string $lookupKey, string $lockToken, StoredResponse $response): void
    {
        if ($response->status >= 500 && ! $this->config->cacheServerErrors) {
            $this->store->release($lookupKey, $lockToken);

            return;
        }

        // A false return means the lock was lost (taken over after expiry); the
        // response we computed is still returned to the client, just not stored.
        $this->store->complete($lookupKey, $lockToken, $response);
    }

    /**
     * Release the lock because the application threw, so a legitimate retry is
     * not blocked until the TTL elapses.
     */
    public function abort(string $lookupKey, string $lockToken): void
    {
        $this->store->release($lookupKey, $lockToken);
    }

    public function config(): IdempotencyConfig
    {
        return $this->config;
    }

    private function readKeyHeader(ServerRequestInterface $request): ?string
    {
        if (! $request->hasHeader($this->config->headerName)) {
            return null;
        }

        return $request->getHeaderLine($this->config->headerName);
    }

    private function bodyExceedsLimit(ServerRequestInterface $request): bool
    {
        $size = $request->getBody()->getSize();

        // Unknown size: fall back to the declared Content-Length when present.
        if ($size === null) {
            $declared = $request->getHeaderLine('Content-Length');
            if ($declared === '') {
                return false;
            }
            $size = (int) $declared;
        }

        return $size > $this->config->maxBodyBytes;
    }

    private function lookupKey(IdempotencyKey $key, ServerRequestInterface $request): string
    {
        $scope = $this->scopeResolver->resolve($request);

        return hash('sha256', $scope."\x00".$key->value);
    }

    private function problem(ErrorCode $code): ProblemOutcome
    {
        return new ProblemOutcome(
            ProblemDetail::fromCode($code, $this->config->problemTypeBaseUri),
        );
    }

    private function replay(?IdempotencyRecord $record): Outcome
    {
        // Defensive: a completed record should always carry a response, but if
        // somehow it doesn't, treat the key as still in progress rather than
        // returning an empty body.
        if ($record?->response === null) {
            return $this->problem(ErrorCode::Conflict);
        }

        return new ReplayOutcome($record->response);
    }
}
