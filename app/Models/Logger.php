<?php

namespace FluentMail\App\Models;

use Exception;
use InvalidArgumentException;
use FluentMail\Includes\Support\Arr;
use FluentMail\App\Services\Mailer\AttachmentStore;

class Logger extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_FAILED  = 'failed';
    const STATUS_SENT    = 'sent';

    /*
     * `auto_retries` is the automatic retry stage, not just a count:
     * 0 means the row is not waiting on a retry, 1 to 3 mean the retry due
     * after 2, 20 or 60 minutes, and 4 means no further automatic attempt.
     * Keeping the stage in a numeric column lets the queue find waiting rows
     * through an index; the first-failure time still lives in `extra` for
     * timing and alert suppression.
     */
    const AUTO_RETRY_MAX = 3;
    const AUTO_RETRY_FINAL = 4;

    /*
     * Inline images are sent to the log viewer as data URIs. One image may
     * use the whole byte budget; the total and count caps only stop a single
     * log detail from becoming an unbounded download. Anything over a cap is
     * left out and the body keeps its broken image reference, as it did
     * before this existed.
     */
    const MAX_INLINE_IMAGE_TOTAL = 10485760;
    const MAX_INLINE_IMAGES      = 30;

    protected $fillables = [
        'to',
        'from',
        'subject',
        'body',
        'status',
        'response',
        'extra',
        'created_at'
    ];

    protected $searchables = [
        'to',
        'from',
        'subject'
    ];

    /*
     * Columns log navigation may filter on. `filter_by` arrives from the
     * request and lands in the query as an identifier, which prepare() cannot
     * bind, so a value outside this list is dropped instead of interpolated.
     * Every key is re-checked against it in buildWhere()'s loop, which is the
     * one place an identifier is written into SQL.
     */
    protected $filterables = [
        'status',
        'created_at',
        'to',
        'from',
        'subject'
    ];

    protected $table = null;

    public function __construct()
    {
        parent::__construct();

        $this->table = $this->db->prefix . FLUENT_MAIL_DB_PREFIX . 'email_logs';
    }

    public function get($data)
    {
        $db      = $this->getDb();
        $page    = isset($data['page']) ? (int)$data['page'] : 1;
        $perPage = isset($data['per_page']) ? (int)$data['per_page'] : 15;
        $offset  = ($page - 1) * $perPage;

        $query = $db->table(FLUENT_MAIL_DB_PREFIX . 'email_logs')
            ->limit($perPage)
            ->offset($offset)
            ->orderBy('id', 'DESC');

        if (!empty($data['status'])) {
            $query->where('status', sanitize_text_field($data['status']));
        }

        if (!empty($data['date_range']) && is_array($data['date_range']) && count($data['date_range']) == 2) {
            $dateRange = $data['date_range'];
            $from      = $dateRange[0] . ' 00:00:01';
            $to        = $dateRange[1] . ' 23:59:59';
            $query->whereBetween('created_at', $from, $to);
        }

        if (!empty($data['search'])) {
            $search = trim(sanitize_text_field($data['search']));
            $query->where(function ($q) use ($search) {
                $searchColumns = $this->searchables;

                $columnSearch = false;
                if (strpos($search, ':')) {
                    $searchArray = explode(':', $search);
                    $column = array_shift($searchArray);
                    if (in_array($column, $this->fillables)) {
                        $columnSearch = true;
                        $q->where($column, 'LIKE', '%' . trim(implode(':', $searchArray)) . '%');
                    }
                }

                if (!$columnSearch) {
                    $firstColumn = array_shift($searchColumns);
                    $q->where($firstColumn, 'LIKE', '%' . $search . '%');
                    foreach ($searchColumns as $column) {
                        $q->orWhere($column, 'LIKE', '%' . $search . '%');
                    }
                }

            });
        }

        $result = $query->paginate();
        $result['data'] = $this->formatResult($result['data']);

        return $result;
    }

    protected function buildWhere($data)
    {
        $where = [];

        $filterBy = Arr::get($data, 'filter_by');

        if (isset($data['filter_by_value']) && in_array($filterBy, $this->filterables, true)) {
            $value = $this->normalizeFilterValue($filterBy, $data['filter_by_value']);

            if (!is_null($value)) {
                $where[$filterBy] = $value;
            }
        }

        /*
         * The date range travels beside filter_by rather than through it.
         *
         * There is only one filter_by slot and the logs screen already spends it on
         * status, so a viewer opened from a date-filtered list had no range at all and
         * its Prev/Next walked straight out of the result set the user was looking at.
         * Normalized through the same helper so the pair reaching the loop is scalar.
         */
        $dateRange = $this->normalizeFilterValue('created_at', Arr::get($data, 'date_range'));

        if (!isset($where['created_at']) && is_array($dateRange)) {
            $where['created_at'] = $dateRange;
        }

        if (isset($data['query']) && is_scalar($data['query']) && trim($data['query']) !== '') {
            $query = trim($data['query']);
            $columns = $this->searchables;

            /*
             * The same reading get() gives the search box: `subject:invoice` is a
             * search of that one column, not of every column for the literal text.
             * The viewer's Prev and Next walk the list the search produced, so a
             * column search that the list understood and the navigation did not
             * left Next with nothing to land on.
             */
            if (strpos($query, ':')) {
                $parts = explode(':', $query);
                $column = array_shift($parts);

                if (in_array($column, $this->filterables, true)) {
                    $columns = [$column];
                    $query = trim(implode(':', $parts));
                }
            }

            foreach ($columns as $column) {
                if (isset($where[$column])) {
                    $where[$column] .= '|' . $query;
                } else {
                    $where[$column] = $query;
                }
            }
        }

        $args = [1];
        $andWhere = $orWhere = '';
        $whereClause = "WHERE 1 = '%d'";

        foreach ($where as $key => $value) {
            if (!in_array($key, $this->filterables, true)) {
                continue;
            }

            if (in_array($key, ['status', 'created_at'])) {
                if ($key == 'created_at') {
                    if (is_array($value)) {
                        $args[] = $value[0];
                        $args[] = $value[1];
                    } else {
                        $args[] = $value;
                        $args[] = $value;
                    }
                    $andWhere .= " AND `{$key}` >= '%s' AND `{$key}` < '%s' + INTERVAL 1 DAY";
                } else {
                    $args[] = $value;
                    $andWhere .= " AND `{$key}` = '%s'";
                }
            } else {
                if (strpos($value, '|')) {
                    $nestedOr = '';
                    $values = explode('|', $value);
                    foreach ($values as $itemValue) {
                        $args[] = '%' . $this->db->esc_like($itemValue) . '%';
                        $nestedOr .= " OR `{$key}` LIKE '%s'";
                    }
                    $orWhere .= ' OR (' . trim($nestedOr, 'OR ') . ')';
                } else {
                    $args[] = '%' . $this->db->esc_like($value) . '%';
                    $orWhere .= " OR `{$key}` LIKE '%s'";
                }
            }
        }

        if ($orWhere) {
            $orWhere = 'AND (' . trim($orWhere, 'OR ') . ')';
        }

        $whereClause = implode(' ', [$whereClause, trim($andWhere), $orWhere]);

        return [$whereClause, $args];
    }

    /**
     * `created_at` is the one filter carried as a [from, to] pair; every other
     * column takes a single value. The request can send either as an array, so
     * anything that would reach the string operations in buildWhere() as a
     * non-scalar is normalized here or dropped. Returns null when there is
     * nothing usable left.
     */
    protected function normalizeFilterValue($column, $value)
    {
        if (is_scalar($value)) {
            return $value;
        }

        if ($column != 'created_at' || !is_array($value)) {
            return null;
        }

        $value = array_values(array_filter($value, 'is_scalar'));

        if (count($value) > 1) {
            return [$value[0], $value[1]];
        }

        return count($value) ? $value[0] : null;
    }

    protected function formatResult($result)
    {
        $result = is_array($result) ? $result : func_get_args();
        foreach ($result as $key => $row) {
            $result[$key]            = $this->maybeUnserialize((array)$row);
            $result[$key]['id']      = (int)$result[$key]['id'];
            $result[$key]['retries'] = (int)$result[$key]['retries'];
            $result[$key]['from']    = htmlspecialchars($result[$key]['from']);
            /*
             * No wp_kses_post() here. Both consumers render the subject as text
             * — {{ }} in Logs.vue and LogViewer.vue, which escapes on its own —
             * so kses adds no safety, and its entity normalization rewrites a
             * subject reading "Tom & Jerry" to "Tom &amp; Jerry", which the
             * page then shows verbatim. Anything that puts a subject into
             * HTML has to escape it at that point, as digest_email.php does.
             */
            $result[$key]['subject'] = wp_unslash($result[$key]['subject']);

            if (isset($result[$key]['headers']) && is_array($result[$key]['headers'])) {
                foreach (array_keys($result[$key]['headers']) as $headerName) {
                    if (strpos((string)$headerName, '__') === 0) {
                        unset($result[$key]['headers'][$headerName]);
                    }
                }
            }
        }

        return $result;
    }

    protected function maybeUnserialize(array $data)
    {
        foreach ($data as $key => $value) {
            if ($this->isUnserializable($key)) {
                $data[$key] = $this->unserialize($value);
            }
        }

        if (array_key_exists('to', $data)) {
            $data['to'] = $this->normalizeLoggedRecipients($data['to']);
        }
        
        return $data;
    }

    protected function isUnserializable($key)
    {
        $allowedFields = [
            'to',
            'headers',
            'attachments',
            'response',
            'extra'
        ];
        
        return in_array($key, $allowedFields);
    }

    protected function unserialize($data)
    {
        if (is_serialized($data)) {
            if (preg_match('/(^|;)O:[0-9]+:/', $data)) {
                return $data;
            }
            return unserialize(trim($data), ['allowed_classes' => false]);
        }

        return $data;
    }

    /**
     * Turn a stored `to` value into the `[{email, name?}, ...]` shape the log
     * UI and resend path expect.
     *
     * Rows written while `to` was VARCHAR(255) can hold a clipped serialize
     * blob that `is_serialized()` rejects. Pull complete addresses out of
     * that fragment so the viewer does not print `a:7:{i:0;a:1:{s:5:"email"`
     * and resend does not foreach a string. A trailing half-written address
     * is dropped because it is not a valid mailbox.
     *
     * @param mixed $to
     * @return array|mixed
     */
    protected function normalizeLoggedRecipients($to)
    {
        if (is_array($to)) {
            return $to;
        }

        if (!is_string($to) || $to === '') {
            return $to;
        }

        $recipients = [];

        if (strpos(ltrim($to), 'a:') === 0) {
            if (preg_match_all('/s:\d+:"([^"]+)"/', $to, $matches)) {
                foreach ($matches[1] as $value) {
                    if (is_email($value)) {
                        $recipients[] = ['email' => $value];
                    }
                }
            }

            return $recipients;
        }

        foreach (preg_split('/[\s,;]+/', $to) as $address) {
            $address = trim($address, " \t\n\r\0\x0B<>");
            if (is_email($address)) {
                $recipients[] = ['email' => $address];
            }
        }

        return $recipients ?: $to;
    }

    protected function formatHeaders($headers)
    {
        foreach ((array)$headers as $key => $header) {
            if (is_array($header)) {
                $header = $this->formatHeaders($header);
            } else {
                $header = htmlspecialchars($header);
            }

            $headers[$key] = $header;
        }

        return $headers;
    }

    public function add($data)
    {
        try {
            if (!isset($data['created_at'])) {
                $data['created_at'] = current_time('mysql');
            }

            /*
             * Every new row gets a last-attempt time. The opening send counts as
             * the first attempt, so the rate limiter can count it later without
             * walking a separate ledger.
             */
            if (!isset($data['updated_at'])) {
                $data['updated_at'] = $data['created_at'];
            }

            return $this->getDb()->table(FLUENT_MAIL_DB_PREFIX . 'email_logs')
                ->insert($data);

        } catch (Exception $e) {
            return $e;
        }
    }

    public function delete(array $id)
    {
        $attachmentStore = new \FluentMail\App\Services\Mailer\AttachmentStore();

        if (isset($id[0]) && $id[0] === 'all') {
            // TRUNCATE doesn't support parameterization
            // Table name is safe - constructed from constants in __construct()
            $result = $this->db->query("TRUNCATE TABLE {$this->table}");

            if ($result !== false) {
                /*
                 * Only remove the copies once the rows that reference them are
                 * gone. A failed TRUNCATE must not cost the site its only copy
                 * of a queued email's attachments.
                 */
                $attachmentStore->deleteAll();

                // The table is empty now, so widening an old VARCHAR `to` column is instant.
                require_once FLUENTMAIL_PLUGIN_PATH . 'database/migrations/EmailLogs.php';
                \FluentMailMigrations\EmailLogs::maybeWidenToColumn($this->table);
            }

            return $result;
        }

        $ids = array_filter($id, 'intval');

        if (!$ids) {
            return false;
        }

        $idList = implode(',', array_map('intval', $ids));
        $rows = $this->db->get_results(
            "SELECT id, attachments FROM {$this->table} WHERE id IN ({$idList})"
        );

        foreach ($rows ?: [] as $row) {
            $attachmentStore->deleteForAttachmentList($row->attachments);
        }

        return $this->getDb()->table(FLUENT_MAIL_DB_PREFIX . 'email_logs')
            ->whereIn('id', $ids)
            ->delete();
    }

    public function navigate($data)
    {
        $filterBy = Arr::get($data, 'filter_by');
        foreach (['date', 'daterange', 'datetime', 'datetimerange'] as $field) {
            if ($filterBy == $field) {
                $data['filter_by'] = 'created_at';
            }
        }

        /*
         * The cursor is bound as '%d', so it cannot carry SQL either way, but
         * an array-shaped `id` from the request reaches prepare() as an
         * unsupported argument type and trips _doing_it_wrong(), which prints
         * a notice into the AJAX response body on a debug site. Narrowed to an
         * integer here instead.
         */
        $id = isset($data['id']) && is_scalar($data['id']) ? (int) $data['id'] : 0;

        $dir = isset($data['dir']) ? $data['dir'] : null;

        list($where, $args) = $this->buildWhere($data);

        $args = array_merge($args, [$id]);

        $sqlNext = "SELECT * FROM {$this->table} {$where} AND `id` > '%d' ORDER BY id LIMIT 2";
        $sqlPrev = "SELECT * FROM {$this->table} {$where} AND `id` < '%d' ORDER BY id DESC LIMIT 2";

        if ($dir == 'next') {
            $query = $this->db->prepare($sqlNext, $args);
        } else if ($dir == 'prev') {
            $query = $this->db->prepare($sqlPrev, $args);
        } else {
            foreach (['next' => $sqlNext, 'prev' => $sqlPrev] as $key => $sql) {

                $keyResult = $this->db->get_results(
                    $this->db->prepare($sql, $args)
                );

                $result[$key] = $this->formatResult($keyResult);
            }

            $current = $this->db->get_results(
                $this->db->prepare("SELECT * FROM {$this->table} WHERE `id` = '%d' LIMIT 1", [$id])
            );

            $log = $current ? $this->formatResult($current)[0] : null;

            $result['log'] = $log ? $this->attachInlineImages($log) : null;

            return $result;
        }

        $result = $this->db->get_results($query);

        if (count($result) > 1) {
            $next = true;
            $prev = true;
        } else {
            if ($dir == 'next') {
                $next = false;
                $prev = true;
            } else {
                $next = true;
                $prev = false;
            }
        }

        $log = $result ? $this->formatResult($result[0])[0] : null;

        return [
            'log'  => $log ? $this->attachInlineImages($log) : null,
            'next' => $next,
            'prev' => $prev
        ];
    }

    /**
     * Add the inline images an HTML body refers to as data URIs.
     *
     * The body only stores `cid:` references; the picture itself is an inline
     * attachment row. Embedding the bytes here lets the admin preview render
     * the message without exposing the stored copy under a public URL, and
     * without touching the raw body used when the email is resent.
     *
     * @param array $log A row already passed through formatResult().
     * @return array
     */
    protected function attachInlineImages(array $log)
    {
        $log['inline_images'] = [];

        $headers = isset($log['headers']) && is_array($log['headers']) ? $log['headers'] : [];
        $contentType = isset($headers['content-type']) && is_string($headers['content-type'])
            ? strtolower(trim(strtok($headers['content-type'], ';')))
            : '';

        if (!in_array($contentType, ['text/html', 'multipart/alternative'], true)) {
            return $log;
        }

        $body = isset($log['body']) && is_string($log['body']) ? $log['body'] : '';

        if ($body === '' || stripos($body, 'cid:') === false) {
            return $log;
        }

        $attachments = isset($log['attachments']) && is_array($log['attachments']) ? $log['attachments'] : [];
        $totalBytes = 0;

        foreach ($attachments as $attachment) {
            if (
                count($log['inline_images']) >= self::MAX_INLINE_IMAGES
                || $totalBytes >= self::MAX_INLINE_IMAGE_TOTAL
            ) {
                break;
            }

            if (!is_array($attachment)) {
                continue;
            }

            $disposition = isset($attachment[6]) && is_string($attachment[6])
                ? strtolower($attachment[6])
                : '';

            if ($disposition !== 'inline') {
                continue;
            }

            $cid = isset($attachment[7]) && is_string($attachment[7])
                ? trim($attachment[7], " \t\n\r\0\x0B<>")
                : '';

            if (
                $cid === ''
                || isset($log['inline_images'][$cid])
                || !$this->bodyReferencesCid($body, $cid)
            ) {
                continue;
            }

            $image = $this->inlineImageData($attachment, self::MAX_INLINE_IMAGE_TOTAL - $totalBytes);

            if (!$image) {
                continue;
            }

            $log['inline_images'][$cid] = 'data:' . $image['mime'] . ';base64,' . base64_encode($image['bytes']);
            $totalBytes += strlen($image['bytes']);
        }

        return $log;
    }

    /**
     * Whether the HTML body points at this Content-ID.
     *
     * @param string $body
     * @param string $cid
     * @return bool
     */
    protected function bodyReferencesCid($body, $cid)
    {
        if (stripos($body, 'cid:' . $cid) !== false) {
            return true;
        }

        $encoded = rawurlencode($cid);

        return $encoded !== $cid && stripos($body, 'cid:' . $encoded) !== false;
    }

    /**
     * Read one inline image, if it is actually an image and small enough.
     *
     * Only files inside wp-content are read. The real path must stay under
     * that directory, so a path pointing at wp-config.php or through a
     * symlink out of the tree is refused even if the row asks for it.
     *
     * @param array $attachment One row of PHPMailer::getAttachments().
     * @param int   $maxBytes
     * @return array{mime: string, bytes: string}|array
     */
    protected function inlineImageData(array $attachment, $maxBytes)
    {
        $maxBytes = (int)$maxBytes;

        if ($maxBytes <= 0) {
            return [];
        }

        if (!empty($attachment[5])) {
            $bytes = isset($attachment[0]) && is_string($attachment[0]) ? $attachment[0] : '';
        } else {
            $path = isset($attachment[0]) && is_string($attachment[0]) ? $attachment[0] : '';

            if ($path === '') {
                return [];
            }

            $real = realpath($path);

            if ($real === false || !is_file($real) || !is_readable($real)) {
                return [];
            }

            $real = wp_normalize_path($real);
            $contentDir = trailingslashit(wp_normalize_path(WP_CONTENT_DIR));
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? stripos($real, $contentDir) === 0
                : strpos($real, $contentDir) === 0;

            if (!$inside) {
                return [];
            }

            $size = @filesize($real);

            if ($size === false || $size <= 0 || $size > $maxBytes) {
                return [];
            }

            $bytes = @file_get_contents($real);

            if ($bytes === false) {
                return [];
            }
        }

        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            return [];
        }

        $info = @getimagesizefromstring($bytes);

        if (!$info || empty($info['mime']) || stripos($info['mime'], 'image/') !== 0) {
            return [];
        }

        return ['mime' => $info['mime'], 'bytes' => $bytes];
    }

    public function find($id)
    {
        $row = $this->getDb()->table(FLUENT_MAIL_DB_PREFIX . 'email_logs')
            ->where('id', $id)
            ->first();

        if (!$row) {
            return [];
        }

        $row->extra = $this->unserialize($row->extra);

        $row->response = $this->unserialize($row->response);

        return (array)$row;
    }

    /**
     * A log row in the shape the queue and the resend path need.
     *
     * find() deliberately leaves the three large columns serialized, while
     * resendEmailFromLog() used to unpack them itself. Both callers now share
     * this method so there is one definition of "a sendable log row".
     *
     * @param int $id
     * @return array
     */
    public function findForQueue($id)
    {
        $row = $this->find($id);

        if (!$row) {
            return [];
        }

        return $this->prepareForSend($row);
    }

    /**
     * @param array $row Raw find() result.
     * @return array
     */
    protected function prepareForSend(array $row)
    {
        foreach (['to', 'headers', 'attachments'] as $key) {
            $row[$key] = $this->unserialize(isset($row[$key]) ? $row[$key] : '');
        }

        $row['to'] = $this->normalizeLoggedRecipients($row['to']);

        if (!is_array($row['extra'])) {
            $row['extra'] = [];
        }

        return $row;
    }

    public function resendEmailFromLog($id, $type = 'retry', $recipients = [])
    {
        $row = $this->find($id);

        if (!$row) {
            return false;
        }

        $email = $this->prepareForSend($row);

        try {
            if (!defined('FLUENTMAIL_LOG_OFF')) {
                define('FLUENTMAIL_LOG_OFF', true);
            }

            $replayer = new \FluentMail\App\Services\Mailer\LoggedEmailReplayer();
            $recipients = (array)$recipients;

            $startedAt = microtime(true);

            $replayed = $replayer->send($email, $recipients, '', !(new AttachmentStore())->shouldPreserve());

            $durationMs = round((microtime(true) - $startedAt) * 1000, 1);

            if (!$replayed['success']) {
                // A bulk resend keeps the original log status, while the reason
                // for this failure is still handed to the page statistics.
                if ($type === 'check_realtime') {
                    $this->updateLog([
                        'status'     => $email['status'] == 'failed' ? 'failed' : 'sent',
                        'updated_at' => current_time('mysql'),
                        'retries'    => intval($email['retries']) + 1
                    ], ['id' => $id]);
                }

                $error = $replayed['error'];

                throw new \Exception(
                    is_wp_error($error)
                        ? $error->get_error_message()
                        : __('The email could not be sent.', 'fluent-smtp'),
                    400
                );
            }

            $updateData = [
                'status'     => 'sent',
                'updated_at' => current_time('mysql'),
            ];

            if ($type == 'resend') {
                $updateData['resent_count'] = intval($email['resent_count']) + 1;
                $updateData['extra'] = maybe_serialize(
                    $this->appendResendRecord(
                        $email['extra'],
                        $replayer->recipients($email, $recipients),
                        true,
                        $durationMs
                    )
                );
            } else {
                $updateData['retries'] = intval($email['retries']) + 1;
            }

            if ($this->updateLog($updateData, ['id' => $id])) {
                $this->purgeTemporaryAttachmentsAfterSend(
                    $id,
                    isset($email['attachments']) ? $email['attachments'] : []
                );

                return $this->findForQueue($id);
            }
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            throw $e;
        }
    }

    /**
     * Remove the copies made for a queued email once a manual send succeeded.
     *
     * A manual retry can run while the email is still waiting for its
     * automatic retry; when it succeeds, the copies are no longer needed. A
     * final automatic failure removes them itself, so by then this is a no-op.
     *
     * @param int   $id
     * @param mixed $attachments The row's unserialized attachment list.
     * @return void
     */
    protected function purgeTemporaryAttachmentsAfterSend($id, $attachments)
    {
        $fresh = $this->find($id);
        $extra = isset($fresh['extra']) && is_array($fresh['extra']) ? $fresh['extra'] : [];

        if (empty($extra['attachments_temporary'])) {
            return;
        }

        (new AttachmentStore())->deleteForAttachmentList((array)$attachments);

        $extra['attachments_temporary'] = false;
        $extra['attachments_removed'] = true;

        $this->updateLog(['extra' => maybe_serialize($extra)], ['id' => $id]);
    }

    /**
     * Record where a resend actually went.
     *
     * A resend can now be redirected to an address other than the original
     * recipient, and resent_count alone only says that it happened, not where
     * it landed - so a log row could show three resends with nothing to say
     * that two of them went to somebody else entirely. The trail lives in the
     * existing `extra` column, which needs no schema change, and the row's own
     * `to` stays untouched as the record of the original send.
     *
     * @param mixed $extra The unserialized `extra` column.
     * @param array $to    Recipients this resend was addressed to.
     * @param bool  $sent  Whether the send itself reported success.
     * @param float $ms    How long the send took, in milliseconds.
     * @return array
     */
    protected function appendResendRecord($extra, $to, $sent, $ms = null)
    {
        $extra = is_array($extra) ? $extra : [];

        $resends = [];

        if (!empty($extra['resends']) && is_array($extra['resends'])) {
            $resends = $extra['resends'];
        }

        $user = wp_get_current_user();

        $resends[] = [
            'at'   => current_time('mysql'),
            'to'   => array_values(array_map('strval', (array)$to)),
            // The display name as it stood at the time. Storing an ID would
            // leave the trail unreadable once the account is deleted, which is
            // exactly when it matters.
            'by'   => ($user && $user->exists()) ? $user->display_name : '',
            'sent' => $sent,
            'ms'   => $ms
        ];

        // Keep the tail. A row that gets resent all day should not grow its
        // extra column without bound.
        $limit = apply_filters('fluentsmtp_resend_history_limit', 20);

        if (count($resends) > $limit) {
            $resends = array_slice($resends, -$limit);
        }

        $extra['resends'] = array_values($resends);

        return $extra;
    }

    /**
     * Queued rows that have never been attempted.
     *
     * Only the columns the scheduler needs are read; the body and attachments
     * of a queued email can be large and are fetched once it is actually due.
     *
     * @param int $limit
     * @param int $afterId Last row inspected in this queue run.
     * @return array
     */
    public function getNewQueuedEmails($limit = 50, $afterId = 0)
    {
        $limit = max(1, (int)$limit);

        $query = $this->db->prepare(
            "SELECT id, response, auto_retries, updated_at, extra
             FROM {$this->table}
             WHERE status = %s
               AND id > %d
               AND auto_retries = 0
               AND (response IS NULL OR response = '')
             ORDER BY id ASC
             LIMIT %d",
            self::STATUS_PENDING,
            (int)$afterId,
            $limit
        );

        return $this->db->get_results($query) ?: [];
    }

    /**
     * Rows that have failed and are waiting for an automatic retry.
     *
     * The `auto_retries` stage says which retry is due: 1 to 3 are waiting,
     * while 0 is never queued for a retry and 4 is already final. Filtering
     * on that numeric stage is what makes the lookup indexable.
     *
     * The final due check depends on the first-failure timestamp kept in
     * `extra`, so the SQL only narrows to rows that are old enough for the
     * shortest retry delay.
     *
     * @param int $limit
     * @param string $retryBefore Site-local date-time string.
     * @param int $afterId Last retry inspected in this queue run.
     * @return array
     */
    public function getRetryQueuedEmails($limit = 50, $retryBefore = '', $afterId = 0)
    {
        $limit = max(1, (int)$limit);

        if (!$retryBefore) {
            $retryBefore = gmdate('Y-m-d H:i:s', current_time('timestamp') - 120);
        }

        $query = $this->db->prepare(
            "SELECT id, response, auto_retries, updated_at, extra
             FROM {$this->table}
             WHERE status = %s
               AND id > %d
               AND auto_retries BETWEEN 1 AND %d
               AND updated_at <= %s
             ORDER BY id ASC
             LIMIT %d",
            self::STATUS_FAILED,
            (int)$afterId,
            self::AUTO_RETRY_MAX,
            $retryBefore,
            $limit
        );

        return $this->db->get_results($query) ?: [];
    }

    /**
     * Whether any queued email is still unfinished: one that has never been
     * attempted, or one waiting for an automatic retry.
     *
     * @return bool
     */
    public function hasPendingEmails()
    {
        $query = $this->db->prepare(
            "SELECT id FROM {$this->table}
             WHERE status = %s
                OR (status = %s AND auto_retries BETWEEN 1 AND %d)
             LIMIT 1",
            self::STATUS_PENDING,
            self::STATUS_FAILED,
            self::AUTO_RETRY_MAX
        );

        return (bool)$this->db->get_var($query);
    }

    /**
     * Count emails attempted in the rate-limit window that starts at $date.
     *
     * A row counts once: a failed password, a retry and a later success are
     * still one email. A queued row that has never been tried does not count.
     * Rows written before this feature have no updated_at and are outside the
     * window by design.
     *
     * @param string $date Site-local date-time string.
     * @return int
     */
    public function getAttemptCountSince($date)
    {
        $query = $this->db->prepare(
            "SELECT COUNT(*)
             FROM {$this->table}
             WHERE status IN (%s, %s)
               AND updated_at >= %s",
            self::STATUS_SENT,
            self::STATUS_FAILED,
            $date
        );

        return (int)$this->db->get_var($query);
    }

    public function updateLog($data, $where)
    {
        return $this->db->update($this->table, $data, $where);
    }

    public function getStats()
    {
        // Status values are hardcoded, no need for prepare()
        $succeeded = $this->db->get_var("SELECT COUNT(id) FROM {$this->table} WHERE status = 'sent'");
        $failed = $this->db->get_var("SELECT COUNT(id) FROM {$this->table} WHERE status = 'failed'");

        return [
            'sent'   => $succeeded,
            'failed' => $failed
        ];
    }

    public function deleteLogsOlderThan($days)
    {
        try {

            $date = gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);

            /*
             * Deleted in batches rather than in one statement. A site that has
             * been logging for months can have this cron pass hit hundreds of
             * thousands of rows, and a single unbounded DELETE holds locks for
             * the whole scan - long enough to time out and leave the backlog
             * permanently uncleared, since the next run faces the same pile.
             * Batches let each statement commit and release.
             *
             * Rows are selected before they are deleted so their attachment
             * copies can be removed too. Every status ages out on the same
             * schedule, including a queued email and one waiting for a retry.
             */
            $batchSize = (int)apply_filters('fluentmail_log_delete_batch_size', 2000);
            $batchSize = max(1, $batchSize);

            $deleted = 0;
            $attachmentStore = new \FluentMail\App\Services\Mailer\AttachmentStore();

            do {
                /*
                 * Only delete the records this batch selected. Even with a normal
                 * timeline, the same filters do not guarantee that query and
                 * delete pick the same rows.
                 */
                $select = $this->db->prepare(
                    "SELECT id, attachments
                     FROM {$this->table}
                     WHERE `created_at` < %s
                     ORDER BY id ASC
                     LIMIT %d",
                    $date,
                    $batchSize
                );

                $rows = $this->db->get_results($select);

                if (!$rows) {
                    break;
                }

                $ids = implode(',', array_map('intval', wp_list_pluck($rows, 'id')));

                $query = $this->db->prepare(
                    "DELETE FROM {$this->table}
                     WHERE id IN ({$ids})
                       AND `created_at` < %s
                     LIMIT %d",
                    $date,
                    $batchSize
                );

                $result = $this->db->query($query);

                if (!$result) {
                    break;
                }

                $deleted += $result;

                foreach ($rows as $row) {
                    $attachmentStore->deleteForAttachmentList($row->attachments);
                }
            } while (count($rows) >= $batchSize);

            return $deleted;

        } catch (Exception $e) {
            fluentMailDebugLog('Failed to delete old email logs - ' . $e->getMessage());
        }
    }

    public function getTotalCountStat($status, $startDate, $endDate = false)
    {
        if ($endDate) {
            $query = $this->db->prepare(
                "SELECT COUNT(*)
                FROM {$this->table}
                WHERE status = %s
                AND created_at >= %s
                AND created_at <= %s",
                $status,
                $startDate,
                $endDate
            );
        } else {
            $query = $this->db->prepare(
                "SELECT COUNT(*)
                FROM {$this->table}
                WHERE status = %s
                AND created_at >= %s",
                $status,
                $startDate
            );
        }

        return (int)$this->db->get_var($query);
    }

    public function getSubjectCountStat($status, $startDate, $endDate)
    {
        $query = $this->db->prepare(
            "SELECT COUNT(DISTINCT(subject))
			FROM {$this->table}
			WHERE status = %s
			AND created_at >= %s
			AND created_at <= %s",
            $status,
            $startDate,
            $endDate
        );

        return (int)$this->db->get_var($query);
    }

    public function getSubjectStat($status, $statDate, $endDate, $limit = 5)
    {
        // Sanitize and validate limit as positive integer
        $limit = max(1, absint($limit)) ?: 5;

        $query = $this->db->prepare(
            "SELECT subject,
			COUNT(DISTINCT id) AS emails_sent
			FROM {$this->table}
			WHERE created_at >= %s
			AND created_at <= %s
			AND status = %s
			GROUP BY subject
			ORDER BY emails_sent DESC
			LIMIT %d",
            $statDate,
            $endDate,
            $status,
            $limit
        );

        return $this->db->get_results($query, ARRAY_A);
    }

}
