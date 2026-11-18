<?php

/**
 * Orboni for XenForo.
 *
 * @author    Orboni <support@orboni.com>
 * @copyright 2026 Orboni
 * @license   MIT
 */

namespace Orboni\Payment\Pub\Controller;

use Orboni\Payment\Payment\Orboni;
use Orboni\Payment\Records;
use XF\Entity\PaymentProvider;
use XF\Pub\Controller\AbstractController;

/**
 * index.php?orboni/return&request_key=…, the session's success_url: the
 * member back from the payment page. The session is read back and the
 * purchase granted at once when it is paid, so a lost webhook keeps no one
 * waiting; then the purchase's own page (an upgrade's "thank you"). XenForo's
 * purchase/process is not used: it refuses an upgrade the webhook granted a
 * second earlier as one that can no longer be bought.
 */
class PaymentReturn extends AbstractController
{
    public function actionReturn()
    {
        $row = Records::get($this->filter('request_key', 'str'));
        if ($row === null) {
            return $this->redirect($this->buildLink('index'));
        }

        $provider = $this->em()->find(PaymentProvider::class, Orboni::PROVIDER_ID);
        $handler = $provider ? $provider->handler : null;
        if ($handler instanceof Orboni && (int) $row['granted'] !== Records::GRANTED) {
            $handler->recheck($row);
        }

        $back = (string) $row['return_url'];

        return $this->redirect($back !== '' ? $back : $this->buildLink('index'));
    }
}
