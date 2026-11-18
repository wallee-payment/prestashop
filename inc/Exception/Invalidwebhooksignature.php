<?php
/**
 * wallee Prestashop
 *
 * This Prestashop module enables to process payments with wallee (https://www.wallee.com).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @copyright 2017 - 2026 customweb GmbH
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */

/**
 * This exception indicates that an incoming webhook request is missing its signature or has an invalid one.
 */
class WalleeExceptionInvalidwebhooksignature extends Exception
{
    /**
     * Constructs a WalleeExceptionInvalidwebhooksignature object.
     *
     * @param string $message
     *   The message that this exception will show.
     * @param integer $code
     *   Exception's code number.
     * @param Throwable|null $previous
     *   The previously thrown exception.
     */
    public function __construct($message = "The webhook signature is missing or invalid", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
