<?php

namespace FluentMail\App\Services\Mailer;

use FluentMail\App\Models\Logger;
use FluentMail\App\Models\Settings;

/**
 * Sends queued emails from the log table.
 *
 * There is no separate queue table: a row with status `pending` is a queued
 * message that has not been attempted yet, while a `failed` row whose
 * `auto_retries` stage is 1 to 3 is waiting for its next automatic attempt
 * and stage 4 is final. The next send time is derived from the first failure
 * time kept in `extra`. The queue runs from WP-Cron once a minute, takes a
 * lock so two runs cannot overlap, and stops after a short time budget so a
 * large backlog cannot hold a PHP request open indefinitely.
 */
class EmailQueue
{
    const ACTION = 'fluentmail_process_email_queue';
    const SCHEDULE = 'fluentmail_minute';
    const LOCK_OPTION = 'fluentmail_queue_lock';
    const LOCK_TTL = 300;
    const TIME_BUDGET = 25;
    const BATCH_SIZE = 50;
    const MAX_CONSECUTIVE_FAILURES = 5;

    /**
     * True while the queue is handing an email to wp_mail().
     *
     * A static flag rather than a global constant: WP-Cron runs every due hook
     * in one request, and a constant would leak into another plugin's mail sent
     * later in that same request.
     *
     * @var bool
     */
    protected static $sending = false;

    /**
     * Log row the replay that is starting now is allowed to update.
     *
     * @var int
     */
    protected static $replayRowId = 0;

    /**
     * The same row for the whole attempt. `replayRowId` is consumed as soon as
     * FluentPHPMailer starts, while this one stays until the attempt returns so
     * the fallback path can recognise the row it belongs to.
     *
     * @var int
     */
    protected static $activeReplayRowId = 0;

    /**
     * Whether the normal send path wrote this attempt's row.
     *
     * A failed row is now both the state before and after a failed retry, so
     * the status alone cannot say whether a provider was reached. BaseHandler
     * sets this when it writes the queue row, including through the fallback
     * connection.
     *
     * @var bool
     */
    protected static $replayRowWritten = false;

    /**
     * @return bool
     */
    public static function isSending()
    {
        return self::$sending;
    }

    /**
     * @param int $rowId
     * @return bool
     */
    public static function isActiveReplayRow($rowId)
    {
        return self::$activeReplayRowId && (int)$rowId === self::$activeReplayRowId;
    }

    /**
     * @return void
     */
    public static function markReplayRowWritten()
    {
        self::$replayRowWritten = true;
    }

    /**
     * @return bool
     */
    protected static function takeReplayRowWritten()
    {
        $written = self::$replayRowWritten;
        self::$replayRowWritten = false;

        return $written;
    }

    /**
     * Take the row id for the replay that is starting now.
     *
     * Consumed rather than read: a hook running during the send may itself
     * call wp_mail(), and that email must not be written into the queued row.
     *
     * @return int
     */
    public static function takeReplayRowId()
    {
        $rowId = self::$replayRowId;
        self::$replayRowId = 0;

        return $rowId;
    }

    /**
     * Retry delays in seconds, keyed by the `auto_retries` stage: 1 is the
     * first retry, 2 the second and 3 the third. Stage 0 is not waiting and
     * stage 4 is final.
     *
     * @var array
     */
    protected static $retryDelays = [
        1 => 120,
        2 => 1200,
        3 => 3600
    ];

    /**
     * Bring the log table up to the queue schema before it is used.
     *
     * Plugin updates do not fire activation, and the queue can be reached from
     * a cron request long after the update. Every step is idempotent.
     *
     * @return void
     */
    public static function ensureSchema()
    {
        require_once FLUENTMAIL_PLUGIN_PATH . 'database/migrations/EmailLogs.php';

        \FluentMailMigrations\EmailLogs::maybeUpgradeSchema();
    }

    /**
     * @param array $schedules
     * @return array
     */
    public static function addCronSchedule($schedules)
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = [
                'interval' => 60,
                'display'  => __('Every Minute (FluentSMTP)', 'fluent-smtp')
            ];
        }

        return $schedules;
    }

    /**
     * @return void
     */
    public static function schedule()
    {
        if (wp_next_scheduled(self::ACTION)) {
            return;
        }

        // Activation can run before the normal plugin hooks have registered the
        // one-minute schedule, so make sure wp_schedule_event() can resolve it.
        add_filter('cron_schedules', [self::class, 'addCronSchedule']);

        wp_schedule_event(time() + 60, self::SCHEDULE, self::ACTION);
    }

    /**
     * @return void
     */
    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::ACTION);
    }

    /**
     * Keep the cron event aligned with the setting and any backlog.
     *
     * Turning async off must not abandon emails that were already queued, so
     * the event stays scheduled while pending rows exist.
     *
     * @return void
     */
    public static function syncSchedule()
    {
        if (self::shouldRun()) {
            self::schedule();
            return;
        }

        if (wp_next_scheduled(self::ACTION)) {
            self::unschedule();
        }
    }

    /**
     * @return bool
     */
    public static function shouldRun()
    {
        if (self::isAsyncEnabled()) {
            return true;
        }

        return (new Logger())->hasPendingEmails();
    }

    /**
     * @return bool
     */
    public static function isAsyncEnabled()
    {
        $misc = (new Settings())->getMisc();

        return isset($misc['async_email_sending']) && $misc['async_email_sending'] === 'yes';
    }

    /**
     * @return bool
     */
    public static function isRetryEnabled()
    {
        if (!self::isAsyncEnabled()) {
            return false;
        }

        $misc = (new Settings())->getMisc();

        return isset($misc['retry_failed_emails']) && $misc['retry_failed_emails'] === 'yes';
    }

    /**
     * Cron entry point.
     *
     * @return void
     */
    public function process()
    {
        $logger = new Logger();

        if (!self::isAsyncEnabled() && !$logger->hasPendingEmails()) {
            self::syncSchedule();
            return;
        }

        self::ensureSchema();

        if (!$this->acquireLock()) {
            return;
        }

        try {
            $this->runQueue($logger);
        } finally {
            $this->releaseLock();
            self::syncSchedule();
        }
    }

    /**
     * @param Logger $logger
     * @return void
     */
    protected function runQueue(Logger $logger)
    {
        $replayer = new LoggedEmailReplayer();
        $rateLimiter = new RateLimiter();
        $counts = $rateLimiter->counts();
        $startedAt = microtime(true);
        $consecutiveFailures = 0;
        $stop = false;
        $lastNewId = 0;
        $lastRetryId = 0;

        while (!$stop && (microtime(true) - $startedAt) < self::TIME_BUDGET) {
            if ($rateLimiter->isReached($counts)) {
                break;
            }

            $candidates = $this->dueCandidates($logger, $lastNewId, $lastRetryId);

            if (!$candidates) {
                break;
            }

            foreach ($candidates as $candidate) {
                if ((microtime(true) - $startedAt) >= self::TIME_BUDGET) {
                    $stop = true;
                    break;
                }

                if ($rateLimiter->isReached($counts)) {
                    $stop = true;
                    break;
                }

                // Advance each scan independently, even when a retry is not due.
                if ((int)$candidate->auto_retries === 0) {
                    $lastNewId = (int)$candidate->id;
                } else {
                    $lastRetryId = (int)$candidate->id;
                }

                if (!$this->isDue($candidate)) {
                    continue;
                }

                $row = $logger->findForQueue($candidate->id);

                if (!$row || !isset($row['status'])) {
                    continue;
                }

                if ($row['status'] === Logger::STATUS_FAILED) {
                    /*
                     * Only a row in the waiting stages may be sent again.
                     * Stage 0 is ordinary failed mail and stage 4 is final.
                     */
                    $stage = (int)$row['auto_retries'];

                    if ($stage < 1 || $stage > Logger::AUTO_RETRY_MAX) {
                        continue;
                    }
                } elseif ($row['status'] !== Logger::STATUS_PENDING) {
                    continue;
                }

                $wasCounted = $this->wasAttempted($row);
                self::$replayRowWritten = false;
                self::$replayRowId = (int)$row['id'];
                self::$activeReplayRowId = (int)$row['id'];
                self::$sending = true;

                try {
                    /*
                     * The original sender, not the row's current `from`: a
                     * previous attempt may have ended on the fallback
                     * connection, and every retry has to start from the
                     * primary again.
                     */
                    $result = $replayer->send($row, [], $this->originalFrom($row));
                } catch (\Throwable $e) {
                    $result = [
                        'success' => false,
                        'error'   => new \WP_Error('fluentmail_replay_failed', $e->getMessage())
                    ];
                } finally {
                    self::$sending = false;
                    self::$replayRowId = 0;
                    self::$activeReplayRowId = 0;
                }

                $rowWritten = self::takeReplayRowWritten();

                /*
                 * When the replay reached a provider, the normal send path has
                 * already written this row - including the fallback attempt
                 * and the failure alerts. Read that outcome back instead of
                 * overwriting it. A replay that never reached a provider (a
                 * missing attachment, for one) leaves the row as it was, so
                 * the queue finalizes it from the replay result as before.
                 * The status cannot tell these apart once a retry is also
                 * stored as failed, hence the written flag.
                 */
                $fresh = $logger->find($row['id']);
                $freshStatus = isset($fresh['status']) ? $fresh['status'] : '';

                if ($freshStatus === Logger::STATUS_SENT) {
                    $this->finishSent($logger, $row);
                    $consecutiveFailures = 0;
                } elseif ($freshStatus === Logger::STATUS_FAILED && $rowWritten) {
                    $this->finishLoggedFailure($logger, $row);
                    $consecutiveFailures++;

                    if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                        $stop = true;
                    }
                } elseif ($result['success']) {
                    $this->markSent($logger, $row);
                    $consecutiveFailures = 0;
                } else {
                    $this->markFailed($logger, $row, $result['error']);
                    $consecutiveFailures++;

                    if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                        $stop = true;
                    }
                }

                if (!$wasCounted) {
                    $rateLimiter->increment($counts);
                }
            }
        }
    }

    /**
     * @param Logger $logger
     * @param int $lastNewId
     * @param int $lastRetryId
     * @return array
     */
    protected function dueCandidates(Logger $logger, $lastNewId = 0, $lastRetryId = 0)
    {
        $new = $logger->getNewQueuedEmails(self::BATCH_SIZE, $lastNewId);
        $retry = $logger->getRetryQueuedEmails(
            self::BATCH_SIZE,
            gmdate('Y-m-d H:i:s', current_time('timestamp') - min(self::$retryDelays)),
            $lastRetryId
        );

        $byId = [];

        foreach (array_merge($new, $retry) as $row) {
            $byId[(int)$row->id] = $row;
        }

        ksort($byId);

        return array_values($byId);
    }

    /**
     * @param object $candidate
     * @return bool
     */
    protected function isDue($candidate)
    {
        $autoRetries = (int)$candidate->auto_retries;
        $attempted = !empty($candidate->response) || $autoRetries > 0;

        if (!$attempted) {
            return true;
        }

        if (!isset(self::$retryDelays[$autoRetries])) {
            return false;
        }

        if (!self::isRetryEnabled()) {
            // Retrying was switched off while this row waited. Send it once
            // more now, and a failure will be final.
            return true;
        }

        $extra = $this->unserializeExtra($candidate->extra);
        $anchor = isset($extra['retry_started_at']) ? (int)$extra['retry_started_at'] : 0;

        if (!$anchor) {
            $anchor = strtotime((string)$candidate->updated_at);
        }

        if (!$anchor) {
            return true;
        }

        return (time() - $anchor) >= self::$retryDelays[$autoRetries];
    }

    /**
     * @param array $row
     * @return bool
     */
    protected function wasAttempted(array $row)
    {
        return !empty($row['response']) || (int)$row['auto_retries'] > 0;
    }

    /**
     * The sender this email was queued with.
     *
     * A fallback attempt rewrites the row's `from` to the fallback sender, so
     * reading that column on the next retry would quietly turn the fallback
     * into the primary. The sender recorded at queue time is returned here
     * instead. Rows queued before the marker existed return an empty string
     * and keep the previous behaviour of using the row's own sender.
     *
     * @param array $row
     * @return string
     */
    protected function originalFrom(array $row)
    {
        $extra = isset($row['extra']) && is_array($row['extra']) ? $row['extra'] : [];

        $email = isset($extra['original_sender_email']) && is_string($extra['original_sender_email'])
            ? sanitize_email($extra['original_sender_email'])
            : '';

        if (!$email || !is_email($email)) {
            return '';
        }

        $name = isset($extra['original_sender_name']) && is_string($extra['original_sender_name'])
            ? str_replace(["\r", "\n", "\0"], '', wp_strip_all_tags($extra['original_sender_name']))
            : '';
        $name = trim($name);

        return $name !== '' ? BaseHandler::formatAddress($email, $name) : $email;
    }

    /**
     * The send path already wrote this row as sent. Only the copies made for
     * a queue-only send still need removing.
     *
     * @param Logger $logger
     * @param array $row
     * @return void
     */
    protected function finishSent(Logger $logger, array $row)
    {
        $fresh = $logger->find($row['id']);
        $extra = isset($fresh['extra']) && is_array($fresh['extra']) ? $fresh['extra'] : [];

        if (empty($extra['attachments_temporary'])) {
            return;
        }

        $this->purgeTemporaryAttachments($row, $extra);
        $logger->updateLog(['extra' => maybe_serialize($extra)], ['id' => $row['id']]);
    }

    /**
     * The send path wrote the failure and already triggered the fallback and
     * the failure alerts. Decide whether the row gets another automatic
     * retry, keeping the response that was just recorded.
     *
     * @param Logger $logger
     * @param array $row The row as it was before this attempt.
     * @return void
     */
    protected function finishLoggedFailure(Logger $logger, array $row)
    {
        $autoRetries = (int)$row['auto_retries'];
        $wasAttempted = $this->wasAttempted($row);
        $final = true;

        if (self::isRetryEnabled()) {
            if (!$wasAttempted) {
                $autoRetries = 1;
                $final = false;
            } elseif ($autoRetries < Logger::AUTO_RETRY_MAX) {
                $autoRetries++;
                $final = false;
            } else {
                $autoRetries = Logger::AUTO_RETRY_FINAL;
            }
        } else {
            $autoRetries = Logger::AUTO_RETRY_FINAL;
        }

        $fresh = $logger->find($row['id']);
        $extra = isset($fresh['extra']) && is_array($fresh['extra']) ? $fresh['extra'] : [];

        if ($final) {
            /*
             * Stage 4 stops the candidate query, and clearing the marker stops
             * the retry-time and alert bookkeeping. There is no further attempt,
             * so the temporary copies have done their job and can go now.
             */
            unset($extra['retry_started_at']);

            $updated = $logger->updateLog([
                'status'       => Logger::STATUS_FAILED,
                'auto_retries' => $autoRetries,
                'extra'        => maybe_serialize($extra),
                'updated_at'   => current_time('mysql')
            ], ['id' => $row['id']]);

            if (!empty($extra['attachments_temporary']) && $updated !== false) {
                $this->purgeTemporaryAttachments($row, $extra);
                $logger->updateLog(['extra' => maybe_serialize($extra)], ['id' => $row['id']]);
            }

            return;
        }

        if (empty($extra['retry_started_at'])) {
            $extra['retry_started_at'] = time();
        }

        $logger->updateLog([
            'status'       => Logger::STATUS_FAILED,
            'auto_retries' => $autoRetries,
            'extra'        => maybe_serialize($extra),
            'updated_at'   => current_time('mysql')
        ], ['id' => $row['id']]);
    }

    /**
     * @param Logger $logger
     * @param array $row
     * @return void
     */
    protected function markSent(Logger $logger, array $row)
    {
        $extra = is_array($row['extra']) ? $row['extra'] : [];
        $hadTemporaryAttachments = !empty($extra['attachments_temporary']);

        $updated = $logger->updateLog([
            'status'     => Logger::STATUS_SENT,
            'response'   => maybe_serialize([
                'code'    => 200,
                'message' => __('Sent from the email queue.', 'fluent-smtp')
            ]),
            'extra'      => maybe_serialize($extra),
            'updated_at' => current_time('mysql')
        ], ['id' => $row['id']]);

        /*
         * Only remove the temporary copies after the row says "sent". If the
         * update failed, the row still points at the files and will be tried
         * again; deleting them first would turn a recoverable failure into a
         * missing-attachment failure.
         */
        if ($hadTemporaryAttachments && $updated !== false) {
            $this->purgeTemporaryAttachments($row, $extra);
            $logger->updateLog(['extra' => maybe_serialize($extra)], ['id' => $row['id']]);
        }
    }

    /**
     * @param Logger $logger
     * @param array $row
     * @param \WP_Error $error
     * @return void
     */
    protected function markFailed(Logger $logger, array $row, $error)
    {
        if (!is_wp_error($error)) {
            $error = new \WP_Error(
                'fluentmail_queue_failed',
                __('The email could not be sent.', 'fluent-smtp')
            );
        }

        $extra = is_array($row['extra']) ? $row['extra'] : [];
        $autoRetries = (int)$row['auto_retries'];
        $wasAttempted = $this->wasAttempted($row);
        $final = true;

        $canRetry = self::isRetryEnabled()
            && $error->get_error_code() !== 'fluentmail_attachment_missing';

        if ($canRetry) {
            if (!$wasAttempted) {
                $autoRetries = 1;
                $final = false;
            } elseif ($autoRetries < Logger::AUTO_RETRY_MAX) {
                $autoRetries++;
                $final = false;
            } else {
                $autoRetries = Logger::AUTO_RETRY_FINAL;
            }
        } else {
            $autoRetries = Logger::AUTO_RETRY_FINAL;
        }

        if (!$final && empty($extra['retry_started_at'])) {
            $extra['retry_started_at'] = time();
        }

        if ($final) {
            unset($extra['retry_started_at']);
        }

        $hadTemporaryAttachments = !empty($extra['attachments_temporary']);

        $code = $error->get_error_code();

        if (!is_numeric($code)) {
            $code = 400;
        }

        $updated = $logger->updateLog([
            'status'       => Logger::STATUS_FAILED,
            'auto_retries' => $autoRetries,
            'response'     => maybe_serialize([
                'code'    => (int)$code,
                'message' => $error->get_error_message(),
                'errors'  => $error->get_error_data()
            ]),
            'extra'        => maybe_serialize($extra),
            'updated_at'   => current_time('mysql')
        ], ['id' => $row['id']]);

        if ($final && $hadTemporaryAttachments && $updated !== false) {
            $this->purgeTemporaryAttachments($row, $extra);
            $logger->updateLog(['extra' => maybe_serialize($extra)], ['id' => $row['id']]);
        }
    }

    /**
     * Remove the copies that only existed so the queue could send the email.
     * A successful send and a final failure both end their life; a failure
     * with another attempt ahead keeps them for that attempt. Attachment rows
     * stay in the log for display.
     *
     * @param array $row
     * @param array $extra
     * @return void
     */
    protected function purgeTemporaryAttachments(array $row, array &$extra)
    {
        if (empty($extra['attachments_temporary'])) {
            return;
        }

        (new AttachmentStore())->deleteForAttachmentList(
            isset($row['attachments']) ? $row['attachments'] : []
        );

        $extra['attachments_temporary'] = false;
        $extra['attachments_removed'] = true;
    }

    /**
     * @param mixed $extra
     * @return array
     */
    protected function unserializeExtra($extra)
    {
        if (is_array($extra)) {
            return $extra;
        }

        if (is_string($extra) && is_serialized($extra)) {
            $decoded = unserialize(trim($extra), ['allowed_classes' => false]);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return bool
     */
    protected function acquireLock()
    {
        $now = time();

        if (add_option(self::LOCK_OPTION, $now, '', 'no')) {
            return true;
        }

        $lockedAt = (int)get_option(self::LOCK_OPTION, 0);

        if ($lockedAt && ($now - $lockedAt) > self::LOCK_TTL) {
            delete_option(self::LOCK_OPTION);

            return (bool)add_option(self::LOCK_OPTION, $now, '', 'no');
        }

        return false;
    }

    /**
     * @return void
     */
    protected function releaseLock()
    {
        delete_option(self::LOCK_OPTION);
    }
}
