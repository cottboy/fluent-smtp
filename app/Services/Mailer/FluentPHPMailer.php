<?php

namespace FluentMail\App\Services\Mailer;

use FluentMail\App\Hooks\Handlers\BulkSendSessionHandler;
use FluentMail\App\Models\Logger;
use FluentMail\App\Services\Mailer\Providers\Factory;
use FluentMail\App\Services\Mailer\Providers\DefaultMail\Handler as PHPMailer;

class FluentPHPMailer
{
    protected $app = null;

    protected $phpMailer = null;

    public function __construct($phpMailer)
    {
        $this->app = fluentMail();

        $this->phpMailer = $phpMailer;
    }

    public function send()
    {
        /*
         * Consumed once, at the start of the replay, so a wp_mail() call made
         * by a hook during the send cannot write that other email into the
         * queued row.
         */
        $queueRowId = EmailQueue::isSending() ? EmailQueue::takeReplayRowId() : 0;

        if ($driver = fluentMailGetProvider($this->phpMailer->From)) {
            if ($forceFromEmail = $driver->getSetting('force_from_email_id')) {
                $this->phpMailer->From = $forceFromEmail;
            }

            $driver->setPhpMailer($this->phpMailer);

            if ($queueRowId && $driver->writesQueueRow()) {
                /*
                 * The provider cache is shared by every send from this address.
                 * The clone keeps the row id on the one replay that owns it.
                 */
                $driver = clone $driver;
                $driver->setQueueRowId($queueRowId);
            }

            /*
             * A queued email has not been sent, so the caller gets true as soon
             * as the log row is written. If the copy or the insert fails,
             * queueForAsync() returns false and this request sends normally
             * instead of dropping the message.
             */
            if ($driver->shouldQueueForAsync() && $driver->queueForAsync()) {
                return true;
            }

            return $driver->send();
        }

        // No connection routes this address, so PHPMailer sends it however the
        // request left it configured. That is not a send this plugin is
        // routing, so it must not go out over a relay socket a bulk session
        // left open.
        BulkSendSessionHandler::releaseForeignSend();

        return $this->phpMailer->send();
    }

    public function sendViaFallback($rowId)
    {
        $driver = fluentMailGetProvider($this->phpMailer->From);
        if($driver) {
            $driver = clone $driver;
            $driver->setRowId($rowId);
            return $driver->setPhpMailer($this->phpMailer)->send();
        }
        return false;
    }

    public function __get($key)
    {
        return $this->phpMailer->{$key};
    }

    public function __set($key, $value)
    {
        $this->phpMailer->{$key} = $value;
    }

    public function __call($method, $params)
    {
        return call_user_func_array([$this->phpMailer, $method], $params);
    }
}
