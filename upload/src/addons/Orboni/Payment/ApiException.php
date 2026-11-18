<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment;

/** Orboni said no, or did not answer (type `connection_error`, status 0). */
class ApiException extends \RuntimeException
{
    /** @var string */
    public $type;

    /** @var int */
    public $status;

    /** @var string */
    public $param;

    public function __construct(string $message, string $type, int $status, string $param = '')
    {
        parent::__construct($message);
        $this->type = $type;
        $this->status = $status;
        $this->param = $param;
    }
}
