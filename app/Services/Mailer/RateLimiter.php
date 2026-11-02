<?php

namespace FluentMail\App\Services\Mailer;

use FluentMail\App\Models\Logger;
use FluentMail\App\Models\Settings;

/**
 * Sliding-window send limits.
 *
 * Each configured window counts an email once, however many times it was
 * attempted. A queued email that has not been tried yet does not count; a
 * failed one does. Rows written before this feature existed have no
 * updated_at, so they are intentionally outside every window - the count
 * starts from the moment the limit is switched on.
 *
 * A window longer than the log retention is skipped. The retention job has
 * already deleted the rows that window would need, so it could never measure
 * a full window and would stop the queue on a limit the site cannot observe.
 */
class RateLimiter
{
    const WINDOWS = [
        'rate_limit_per_minute' => 60,
        'rate_limit_per_hour'   => 3600,
        'rate_limit_per_day'    => 86400,
        'rate_limit_per_week'   => 604800,
        'rate_limit_per_month'  => 2592000,
    ];

    /**
     * Snapshot counts once per queue run; the queue keeps them up to date as it
     * sends. A concurrent test email can be a few sends ahead until the next
     * run, which is acceptable for a reputation guard.
     *
     * @return array window key => ['limit' => int, 'count' => int]
     */
    public function counts()
    {
        $misc = (new Settings())->getMisc();

        if (!isset($misc['email_rate_limit']) || $misc['email_rate_limit'] !== 'yes') {
            return [];
        }

        $retentionDays = isset($misc['log_saved_interval_days']) ? (int)$misc['log_saved_interval_days'] : 14;
        $retentionSeconds = $retentionDays > 0 ? $retentionDays * 86400 : 0;

        $logger = new Logger();
        $counts = [];

        foreach (self::WINDOWS as $key => $seconds) {
            if ($retentionSeconds > 0 && $seconds > $retentionSeconds) {
                continue;
            }

            $limit = isset($misc[$key]) ? (int)$misc[$key] : 0;

            if ($limit <= 0) {
                continue;
            }

            $since = gmdate('Y-m-d H:i:s', current_time('timestamp') - $seconds);

            $counts[$key] = [
                'limit' => $limit,
                'count' => $logger->getAttemptCountSince($since)
            ];
        }

        return $counts;
    }

    /**
     * @param array $counts
     * @return bool
     */
    public function isReached(array $counts)
    {
        foreach ($counts as $window) {
            if ($window['count'] >= $window['limit']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $counts
     * @return void
     */
    public function increment(array &$counts)
    {
        foreach ($counts as $key => $window) {
            $counts[$key]['count'] = $window['count'] + 1;
        }
    }
}
