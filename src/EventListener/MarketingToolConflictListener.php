<?php

declare(strict_types=1);

namespace Nowo\MarketingKitBundle\EventListener;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

use function is_array;
use function is_object;
use function is_string;
use function ltrim;
use function str_starts_with;

/**
 * 1.13 (REQ-RUNTIME-003 rule 6): a lock conflict on a page of the bundle's marketing tool admin answers a conflict
 * instead of a 500.
 *
 * - Lock conflict (a deadlock, lock wait timeout or serialization failure anywhere down the chain, a
 *   {@see TransientStorageFailure}, or a lost savepoint of unknown outcome): the status of the
 *   {@see ConflictResponsePolicy} (**409** by default, 503 when the host configures it) with a {@see ConflictCode} in the
 *   `X-Conflict-Code` header:
 *   - `CONFLICT_001` with `Retry-After` when nothing of the request was committed;
 *   - `CONFLICT_002` without `Retry-After` when part of it may be: anything committed since the request started
 *     (transaction-doctrine's request mark, {@see ConflictCode::forRequest()}, when its bundle is registered with
 *     `commit_detection`; and the bundle's own mark on the {@see CommitLedger} the commit-tracking middleware feeds),
 *     a committed `ConcurrencyConflict`, or a lost savepoint with no deadlock behind it.
 * - Domain refusals of an admin write, never a `CONFLICT_*` code (they are not lock conflicts): 409 with the domain code
 *   in `X-Error-Code`:
 *   - {@see self::DUPLICATE_CODE}: the unique (profile, code) key refused the row (two admins creating the same code,
 *     or a catalog seed / YAML import creating a code another admin created at the same time);
 *   - {@see self::STALE_TOOL}: the row changed since it was read (optimistic `version`).
 *   The admin form catches both itself and shows them on the form; this covers the toggle, the seed and the import.
 * - A lost connection or any other storage error is not mapped: a real error.
 *
 * The pages are rendered by the application's error page: the answer is an HttpException with a fixed, client-safe
 * message and the storage error as previous (for the logs; no SQL or value reaches the client).
 *
 * Only the bundle's controllers: the host's routes (and the script rendering, which only reads) keep the host's error
 * handling. Stateless apart from the shared ledger, a monotonic counter read by difference only (FrankenPHP worker
 * mode, kernel reset off).
 */
final readonly class MarketingToolConflictListener
{
    /**
     * kernel.exception priority: above the framework's ErrorListener::logKernelException() (0), so an expected 409 is
     * logged as the 409 it becomes, not as a CRITICAL storage error (audit US-3); only the bundle's routes are mapped.
     */
    public const PRIORITY = 8;

    /** kernel.request priority: before the router (32) and the firewall (8), so no controller wrote yet. */
    public const REQUEST_PRIORITY = 2048;

    /** Request attribute holding the bundle's own commit mark. */
    public const MARK_ATTRIBUTE = '_nowo_marketing_kit_commit_mark';

    /** Response header carrying a domain error code (never a conflict code). */
    public const ERROR_CODE_HEADER = 'X-Error-Code';

    /** Domain code of a tool code already used in its profile (unique key). */
    public const DUPLICATE_CODE = 'MARKETING_TOOL_DUPLICATE_CODE';

    /** Domain code of a tool changed since it was read (optimistic version). */
    public const STALE_TOOL = 'MARKETING_TOOL_STALE';

    /** Message of a CONFLICT_001 answer (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'Another change to the marketing services is being saved at the same time. Retry in a moment.';

    /** Message of a CONFLICT_002 answer (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload the page before trying again.';

    /** Message of the duplicate-code answer. */
    public const DUPLICATE_CODE_MESSAGE = 'A marketing service with this code already exists in this profile, possibly saved at the same time. Reload the page and apply your change again.';

    /** Message of the stale-tool answer. */
    public const STALE_TOOL_MESSAGE = 'The marketing service was changed by someone else since it was read. Reload the page and apply your change again.';

    /** Controller namespace of the bundle. */
    public const CONTROLLER_NAMESPACE = 'Nowo\\MarketingKitBundle\\Controller\\';

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger real commits counted by the bundle's commit-tracking middleware (null = none)
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        private ?CommitLedger $ledger = null,
        private ?RequestStack $requestStack = null,
    ) {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Takes the bundle's commit mark on the main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger && $event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::MARK_ATTRIBUTE, new CommitMark($this->ledger));
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleController($request->attributes->get('_controller'))) {
            return;
        }

        $failure = $event->getThrowable();
        if ($this->isLockConflict($failure)) {
            $code = $this->code($request, $failure);
            $event->setThrowable($this->policy->httpExceptionFor(
                $code,
                $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE,
                $failure,
                $this->hint($failure),
            ));

            return;
        }

        $domain = self::domainCode($failure);
        if ($domain !== null) {
            $event->setThrowable(new ConflictHttpException(
                $domain === self::DUPLICATE_CODE ? self::DUPLICATE_CODE_MESSAGE : self::STALE_TOOL_MESSAGE,
                $failure,
                0,
                [self::ERROR_CODE_HEADER => $domain],
            ));
        }
    }

    /**
     * The domain code of an expected admin race ({@see self::DUPLICATE_CODE} or {@see self::STALE_TOOL}), else null.
     */
    public static function domainCode(Throwable $failure): ?string
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return self::DUPLICATE_CODE;
            }
            if ($current instanceof OptimisticLockException) {
                return self::STALE_TOOL;
            }
        }

        return null;
    }

    private function isLockConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure) || $this->classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * CONFLICT_002 when transaction-doctrine's request mark, or the bundle's own one, saw a commit; else the failure decides.
     */
    private function code(Request $request, Throwable $failure): ConflictCode
    {
        $code = ConflictCode::forRequest($request, $failure);
        if ($code->retrySafe() && $this->ownMark($request)?->committedSince() === true) {
            return ConflictCode::Committed;
        }

        return $code;
    }

    private function ownMark(Request $request): ?CommitMark
    {
        $mark = $request->attributes->get(self::MARK_ATTRIBUTE) ?? $this->requestStack?->getMainRequest()?->attributes->get(self::MARK_ATTRIBUTE);

        return $mark instanceof CommitMark ? $mark : null;
    }

    /** The first Retry-After hint of the chain (a transient failure of a runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }
        if (is_object($controller)) {
            $controller = $controller::class;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), self::CONTROLLER_NAMESPACE);
    }
}
