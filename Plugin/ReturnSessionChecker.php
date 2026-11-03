<?php

declare(strict_types=1);

namespace Shubo\Flitt\Plugin;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Keeps the customer logged in across the Flitt hosted-page return:
 * the cross-site POST to response_url arrives WITHOUT the SameSite=Lax
 * session cookie, and letting it start a session would Set-Cookie a fresh
 * guest id over the customer's real one and log them out. So no session
 * may start on that POST at all; the Complete controller trampolines it to
 * a GET, which Lax cookies DO ride along with. PayPal's
 * TransparentSessionChecker pattern, narrowed to POST only — the GET leg
 * needs the session (restoreQuote, messages, success keys).
 */
class ReturnSessionChecker
{
    private const string RETURN_PATH = 'flitt/redirect/complete';

    /**
     * @param Http $request
     */
    public function __construct(
        private readonly Http $request,
    ) {
    }

    /**
     * Veto session start for the cross-site POST return leg.
     *
     * @param SessionStartChecker $subject
     * @param bool $result
     * @return bool
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if ($result === false) {
            return false;
        }

        return !($this->request->isPost()
            && str_contains((string)$this->request->getPathInfo(), self::RETURN_PATH));
    }
}
