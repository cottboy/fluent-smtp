<?php

namespace FluentMail\App\Services\Mailer;

use Exception;
use InvalidArgumentException;
use FluentMail\App\Models\Logger;
use FluentMail\App\Hooks\Handlers\BulkSendSessionHandler;
use FluentMail\Includes\Support\Arr;
use FluentMail\Includes\Core\Application;
use FluentMail\App\Services\Mailer\Manager;
use FluentMail\App\Services\Mailer\ValidatorTrait;

class BaseHandler
{
    use ValidatorTrait;

    protected $app = null;
    
    protected $params = [];

    protected $manager = null;
    
    protected $phpMailer = null;

    protected $settings = [];

    protected $attributes = [];

    protected $response = null;

    protected $existing_row_id = null;

    /**
     * Log row a queue replay owns.
     *
     * Distinct from $existing_row_id, which marks the fallback attempt of a
     * synchronous send and merges its response into that row. A queue replay
     * is a primary attempt that happens to already have a row.
     *
     * @var int|null
     */
    protected $queue_row_id = null;

    protected $sendStartedAt = null;

    protected $sendDurationMs = null;

    /**
     * Set once this plugin's SMTP provider has pointed the shared PHPMailer
     * instance at a relay, and cleared again at the start of the next send.
     *
     * The instance persists across sends now, so a PHP mail() send that follows
     * an SMTP one — a fallback, most of all — would otherwise inherit
     * Mailer='smtp' along with that relay's host and credentials. Only that
     * carry-over may be undone: a phpmailer_init listener selecting a transport
     * for the send in progress has to be honored, so the reset is not a blanket
     * one. @see Providers\DefaultMail\Handler::postSend()
     *
     * @var bool
     */
    protected static $smtpTransportClaimed = false;

    /**
     * Record that the SMTP provider has taken over the shared PHPMailer.
     *
     * @return void
     */
    public static function markSmtpTransportClaimed()
    {
        self::$smtpTransportClaimed = true;
    }

    /**
     * Forget any earlier claim, because the transport has just been reset.
     *
     * Called from the wp_mail() replacement alongside the isMail() reset, which
     * runs before phpmailer_init, so listeners get the final word again.
     *
     * @return void
     */
    public static function forgetSmtpTransportClaim()
    {
        self::$smtpTransportClaimed = false;
    }

    public function __construct(?Application $app = null, ?Manager $manager = null)
    {
        $this->app = $app ?: fluentMail();
        $this->manager = $manager ?: fluentMail(Manager::class);
    }

    public function setPhpMailer($phpMailer)
    {
        $this->phpMailer = $phpMailer;

        if(!$this->phpMailer->CharSet) {
            $this->phpMailer->CharSet = 'UTF-8';
        }

        return $this;
    }

    public function setSettings($settings)
    {

        $this->settings = $settings;

        return $this;
    }

    /**
     * Whether this send should be written to the queue instead of sent now.
     *
     * Test emails, manual resends, the queue's own replays, FluentCRM bulk
     * sessions and simulated sends all stay synchronous. FluentCRM already
     * sends in the background and reuses one SMTP connection across a run;
     * queueing each message would lose that reuse and slow a campaign down.
     *
     * @return bool
     */
    public function shouldQueueForAsync()
    {
        if (defined('FLUENTMAIL_EMAIL_TESTING') || defined('FLUENTMAIL_TEST_EMAIL')) {
            return false;
        }

        if (defined('FLUENTMAIL_LOG_OFF')) {
            return false;
        }

        if (EmailQueue::isSending()) {
            return false;
        }

        if (BulkSendSessionHandler::isActive()) {
            return false;
        }

        $misc = $this->manager->getConfig('misc', []);

        if (isset($misc['simulate_emails']) && $misc['simulate_emails'] === 'yes') {
            return false;
        }

        if (defined('FLUENTMAIL_SIMULATE_EMAILS') && FLUENTMAIL_SIMULATE_EMAILS) {
            return false;
        }

        if (!isset($misc['log_emails']) || $misc['log_emails'] !== 'yes') {
            return false;
        }

        return isset($misc['async_email_sending']) && $misc['async_email_sending'] === 'yes';
    }

    /**
     * Persist this email as a queued log row and let cron deliver it later.
     *
     * Attachments are copied before the request ends because uploads that
     * arrived with this request are normally deleted with it. A copy failure
     * returns false, and the caller sends synchronously so the email is not
     * lost.
     *
     * @return bool
     */
    public function queueForAsync()
    {
        $stored = false;
        $logId = 0;

        try {
            $rawAttachments = $this->phpMailer ? $this->phpMailer->getAttachments() : [];
            $attachmentStore = new AttachmentStore();
            $stored = $attachmentStore->storeForQueue((array)$rawAttachments);

            if ($stored === false) {
                return false;
            }

            if (!$this->preSend()) {
                $attachmentStore->deleteForAttachmentList($stored['attachments']);
                return false;
            }

            EmailQueue::ensureSchema();

            $extra = (array)$this->getExtraParams();
            $extra['queued_at'] = time();

            /*
             * A fallback attempt rewrites the row's sender to the fallback
             * connection. Keeping the address this email actually resolved
             * to lets every retry start from the primary connection again.
             * The PHPMailer address, not the connection's configured sender,
             * because one connection can serve several sender addresses.
             */
            if (!empty($this->phpMailer->From)) {
                $extra['original_sender_email'] = $this->phpMailer->From;
            }

            if (!empty($this->phpMailer->FromName)) {
                $extra['original_sender_name'] = $this->phpMailer->FromName;
            }

            if (!empty($stored['temporary'])) {
                $extra['attachments_temporary'] = true;
            }

            $customHeaders = isset($this->attributes['custom_headers']) && is_array($this->attributes['custom_headers'])
                ? $this->attributes['custom_headers']
                : [];

            if ($customHeaders) {
                $extra['custom_headers'] = $customHeaders;
            }

            $headers = $this->getParam('headers');

            if (!is_array($headers)) {
                $headers = [];
            }

            /*
             * There is no column for the plain-text alternative and `extra` is
             * TEXT, so the text part rides in the headers blob (LONGTEXT).
             * LoggedEmailReplayer strips it back out before wp_mail() sees it.
             */
            if (!empty($this->attributes['alt_body'])) {
                $headers['__fluentmail_alt_body'] = (string)$this->attributes['alt_body'];
            }

            $data = [
                'to'           => $this->serialize($this->attributes['to']),
                'from'         => $this->attributes['from'],
                'subject'      => sanitize_text_field($this->attributes['subject']),
                'body'         => $this->attributes['message'],
                'attachments'  => $this->serialize($stored['attachments']),
                'status'       => Logger::STATUS_PENDING,
                'response'     => null,
                'headers'      => $this->serialize($headers, ['__fluentmail_alt_body']),
                'extra'        => $this->serialize($extra, ['custom_headers']),
                'auto_retries' => 0,
                'created_at'   => current_time('mysql'),
                'updated_at'   => current_time('mysql')
            ];

            $logId = (new Logger())->add($data);

            if (!is_int($logId) || $logId <= 0) {
                $logId = 0;
                $attachmentStore->deleteForAttachmentList($stored['attachments']);
                return false;
            }

            EmailQueue::schedule();

            return true;
        } catch (\Throwable $e) {
            if ($stored !== false && !$logId) {
                (new AttachmentStore())->deleteForAttachmentList($stored['attachments']);
            }

            fluentMailDebugLog('Failed to queue email - ' . $e->getMessage());

            return false;
        }
    }

    protected function preSend()
    {
        /*
         * Every provider calls this first, so it is the one point that marks
         * the start of a send for all of them. What lands in the log is the
         * span from here to the provider's answer: assembling the MIME message
         * and then the round trip itself - connection, the API call or SMTP
         * conversation, and the response. It is not the time until the mail
         * reaches an inbox; that part is out of our hands.
         */
        $this->sendStartedAt = microtime(true);

        $this->attributes = [];
        
        if ($this->isForced('from_name')) {
            $this->phpMailer->FromName = $this->getSetting('sender_name');
        }

        /*
         * A display name is plain text — an entity in it has no meaning and
         * simply shows up as "Tom &amp; Jerry" in the recipient's inbox. Names
         * arrive escaped whenever they came from the site title, which
         * WordPress stores that way, whether that was our own sender_name
         * setting or a theme filtering wp_mail_from_name with the raw option.
         * Normalize once here, where the handler takes ownership of the
         * message and before any provider or the log reads the name.
         */
        if ($this->phpMailer->FromName) {
            $this->phpMailer->FromName = wp_specialchars_decode($this->phpMailer->FromName, ENT_QUOTES);
        }

        if ($this->getSetting('return_path') == 'yes') {
            $this->phpMailer->Sender = $this->phpMailer->From;
        }

        $this->attributes = $this->setAttributes();

        return true;
    }

    protected function isForced($key)
    {
        return $this->getSetting("force_{$key}") == 'yes';
    }

    public function isForcedEmail()
    {
        return $this->getSetting("force_from_email") != 'no';
    }

    public function isActive()
    {
        return $this->getSetting('is_active') == 'yes';
    }

    protected function getDefaultParams()
    {
        $timeout = (int)ini_get('max_execution_time');

        return [
            'timeout'     => $timeout ?: 30,
            'httpversion' => '1.1',
            'blocking'    => true,
        ];
    }

    protected function setAttributes()
    {
        $from = $this->setFrom();
        
        $replyTos = $this->setRecipientsArray(array_values(
            $this->phpMailer->getReplyToAddresses()
        ));
        
        /*
         * Expose the bare MIME type only. PHPMailer's ContentType can carry
         * parameters — a charset, or the multipart boundary that WP 6.9+ folds
         * into the Content-Type value instead of emitting a separate header.
         * Every provider and the logger compare this against bare types such as
         * 'text/html' and 'multipart/alternative', so a parameterized value would
         * silently fall through to the plain-text branch.
         */
        $contentType = trim(strtok((string)$this->phpMailer->ContentType, ';'));
        
        $customHeaders = $this->setFormattedCustomHeaders();

        $recipients = [
            'to' => $this->setRecipientsArray($this->phpMailer->getToAddresses()),
            'cc' => $this->setRecipientsArray($this->phpMailer->getCcAddresses()),
            'bcc' => $this->setRecipientsArray($this->phpMailer->getBccAddresses())
        ];

        return array_merge($this->attributes, [
            'from' => $from,
            'to' => $recipients['to'],
            'subject' => $this->phpMailer->Subject,
            'message' => $this->phpMailer->Body,
            'alt_body' => $this->phpMailer->AltBody,
            'attachments' => $this->phpMailer->getAttachments(),
            'custom_headers' => $customHeaders,
            'headers' => [
                'reply-to' => $replyTos,
                'cc' => $recipients['cc'],
                'bcc' => $recipients['bcc'],
                'content-type' => $contentType
            ]
        ]);
    }

    /**
     * Split a comma-separated address list, keeping a comma that sits inside a
     * quoted display name: `"Jewel, Shah" <jewel@example.com>` is one mailbox.
     *
     * A quote only opens a quoted name at the start of an entry, so a stray
     * `"` (an inch mark, a comment) never joins two entries. A list without a
     * double quote, or with an unclosed quoted name, is split on every comma
     * exactly as wp_mail() does.
     *
     * @param string $list
     * @return array
     */
    public static function splitAddressList($list)
    {
        $list = (string)$list;

        if (strpos($list, '"') === false) {
            return explode(',', $list);
        }

        $parts = [];
        $current = '';
        $inQuote = false;
        $length = strlen($list);

        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];

            if ($inQuote) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $char . $list[++$i];
                    continue;
                }
                if ($char === '"') {
                    $inQuote = false;
                }
            } elseif ($char === '"' && trim($current) === '') {
                $inQuote = true;
            } elseif ($char === ',') {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ($inQuote) {
            return explode(',', $list);
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Strip the quotes from a quoted display name that holds a comma:
     * `"Jewel, Shah"` → `Jewel, Shah`. PHPMailer quotes it again when it
     * writes the header.
     *
     * Any other name, quoted or not, is returned unchanged.
     *
     * @param string $name
     * @return string
     */
    public static function unquoteName($name)
    {
        $trimmed = trim((string)$name);

        if (!self::isQuotedString($trimmed) || strpos($trimmed, ',') === false) {
            return $name;
        }

        return preg_replace('/\\\\(.)/s', '$1', substr($trimmed, 1, -1));
    }

    /**
     * Build `name <email>`, quoting a name that holds a comma so a provider
     * that takes a comma-separated list reads it as one mailbox.
     *
     * A name without a comma, or one that is already quoted, is written
     * exactly as before.
     *
     * @param string $email
     * @param string $name
     * @return string
     */
    public static function formatAddress($email, $name = '')
    {
        $name = (string)$name;

        if ($name === '') {
            return $email;
        }

        if (strpos($name, ',') !== false && !self::isQuotedString(trim($name))) {
            $name = '"' . addcslashes($name, '"\\') . '"';
        }

        return $name . ' <' . $email . '>';
    }

    /**
     * Whether a value is one RFC 5322 quoted string: `"..."` with every inner
     * quote escaped.
     *
     * @param string $value
     * @return bool
     */
    private static function isQuotedString($value)
    {
        if (strlen($value) < 2 || $value[0] !== '"' || substr($value, -1) !== '"') {
            return false;
        }

        $inner = preg_replace('/\\\\./s', '', substr($value, 1, -1));

        return strpos($inner, '"') === false && substr($inner, -1) !== '\\';
    }

    /**
     * The `from` param for a provider that sends it as one `name <email>`
     * string. A sender name with a comma is quoted; any other From is the
     * logged `from` value, unchanged.
     *
     * @return string
     */
    protected function getFormattedFrom()
    {
        $name = (string)$this->getParam('sender_name');

        if (strpos($name, ',') === false) {
            return $this->getParam('from');
        }

        return self::formatAddress($this->getParam('sender_email'), $name);
    }

    protected function setFrom()
    {
        $name = $this->getSetting('sender_name');
        $email = $this->getSetting('sender_email');
        $overrideName = $this->getSetting('force_from_name');

        if ($name && ($overrideName == 'yes' || $this->phpMailer->FromName == 'WordPress')) {
            $this->attributes['sender_name'] = $name;
            $this->attributes['sender_email'] = $email;
            $from = $name . ' <' . $email . '>';
        } elseif ($this->phpMailer->FromName) {
            $this->attributes['sender_email'] = $email;
            $this->attributes['sender_name'] = $this->phpMailer->FromName;
            $from = $this->phpMailer->FromName . ' <' . $email . '>';
        } else {
            $from = $this->attributes['sender_email'] = $email;
        }

        return $from;
    }

    protected function setRecipientsArray($array)
    {
        $recipients = [];

        foreach ($array as $key => $recipient) {
            $recipient = array_filter($recipient);

            if (!$recipient) continue;
            
            $recipients[$key] = [
                'email' => array_shift($recipient)
            ];

            if ($recipient) {
                $recipients[$key]['name'] = array_shift($recipient);
            }
        }

        return $recipients;
    }

    protected function setFormattedCustomHeaders()
    {
        $headers = [];

        $customHeaders = $this->phpMailer->getCustomHeaders();

        foreach ($customHeaders as $key => $header) {
            if ($header[0] == 'Return-Path') {
                if ($this->getSetting('options.return_path') == 'no') {
                    if (!empty($header[1])) {
                        $this->phpMailer->Sender = $header[1];
                    }
                }
                unset($customHeaders[$key]);
            } else {
                $headers[] = [
                    'key' => $header[0],
                    'value' => $header[1]
                ];
            }
        }

        $this->phpMailer->clearCustomHeaders();

        foreach ($customHeaders as $customHeader) {
            $this->phpMailer->addCustomHeader($customHeader[0], $customHeader[1]);
        }

        return $headers;
    }

    /**
     * Repair List-Unsubscribe / List-Unsubscribe-Post headers in a raw MIME message.
     *
     * PHPMailer RFC-2047-encodes a custom header whose value is longer than it
     * can fit on one line. Our wp_mail() replacement leaves PHPMailer in mail()
     * mode, so that limit is MAIL_MAX_LINE_LENGTH (63) minus the encoded-word
     * overhead — only 47 characters. Every realistic unsubscribe URL is longer
     * than that and therefore ships encoded.
     *
     * Encoded-words are not valid inside structured headers such as
     * List-Unsubscribe, so Gmail ignores the header entirely when it arrives
     * encoded, silently breaking RFC 8058 one-click unsubscribe that Gmail and
     * Yahoo require from bulk senders.
     *
     * Providers that hand the fully built message to the API can repair the
     * headers here: unfold each target header, decode any encoded-words, and
     * re-emit it as a plain single line (re-folded at a URI boundary only if it
     * would otherwise exceed the RFC 5322 line limit).
     *
     * Any header we cannot rewrite safely is left exactly as PHPMailer built
     * it — a missing unsubscribe button is a far better outcome than a message
     * the provider rejects.
     *
     * @param string $rawMessage The message from PHPMailer::getSentMIMEMessage()
     * @return string
     */
    protected function normalizeListHeaders($rawMessage)
    {
        if (stripos($rawMessage, 'List-Unsubscribe') === false) {
            return $rawMessage;
        }

        // Work on the top-level header block only.
        $breakPos = strpos($rawMessage, "\r\n\r\n");

        if ($breakPos === false) {
            $breakPos = strpos($rawMessage, "\n\n");
        }

        if ($breakPos === false) {
            return $rawMessage;
        }

        $headerBlock = substr($rawMessage, 0, $breakPos);
        $rest = substr($rawMessage, $breakPos);

        $lineEnding = (strpos($headerBlock, "\r\n") !== false) ? "\r\n" : "\n";

        $fixedHeaderBlock = preg_replace_callback(
            '/^(List-Unsubscribe(?:-Post)?)[ \t]*:[ \t]*([^\r\n]*(?:\r?\n[ \t][^\r\n]*)*)/mi',
            function ($matches) use ($lineEnding) {
                $name = $matches[1];

                // Unfold continuation lines.
                $value = preg_replace('/\r?\n[ \t]+/', ' ', $matches[2]);

                // Decode RFC 2047 encoded-words if present.
                if (strpos($value, '=?') !== false) {
                    $decoded = false;

                    if (function_exists('iconv_mime_decode')) {
                        $decoded = @iconv_mime_decode(
                            $value,
                            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
                            'UTF-8'
                        );
                    }

                    if (($decoded === false || $decoded === null) && function_exists('mb_decode_mimeheader')) {
                        $decoded = mb_decode_mimeheader($value);
                    }

                    if ($decoded !== false && $decoded !== null && $decoded !== '') {
                        $value = $decoded;
                    }
                }

                $value = preg_replace('/[ \t]+/', ' ', trim($value));

                // Decoding is what makes an encoded-word unnecessary in the
                // first place, so it must produce a header that is legal
                // unencoded: printable US-ASCII, no control characters and no
                // line breaks. A percent-escaped URL always is. Anything else
                // (an IDN host, a unicode query value, a decode that went
                // wrong) has to stay encoded or we would emit raw 8-bit bytes
                // into the header block and risk the provider rejecting the
                // whole message.
                if (preg_match('/[^\x20-\x7E]/', $value)) {
                    return $matches[0];
                }

                $headerLine = $name . ': ' . $value;

                // RFC 5322 caps a line at 998 characters. Fold at the comma
                // between URIs, which is a legal fold point.
                if (strlen($headerLine) > 990) {
                    $headerLine = $name . ': ' . preg_replace(
                        '/>,\s*</',
                        '>,' . $lineEnding . ' <',
                        $value
                    );
                }

                // A single URI longer than the limit has no fold point, so the
                // rewrite would produce an over-long line. Keep the original.
                foreach (explode($lineEnding, $headerLine) as $line) {
                    if (strlen($line) > 998) {
                        return $matches[0];
                    }
                }

                return $headerLine;
            },
            $headerBlock
        );

        if ($fixedHeaderBlock === null) {
            return $rawMessage; // regex failure — send untouched
        }

        return $fixedHeaderBlock . $rest;
    }

    public function getSetting($key = null, $default = null)
    {
        try {
            return $key ? Arr::get($this->settings, $key, $default) : $this->settings;
        } catch (Exception $e) {
            return $default;
        }
    }

    protected function getParam($key = null, $default = null)
    {
        try {
            return $key ? Arr::get($this->attributes, $key, $default) : $this->attributes;
        } catch (Exception $e) {
            return $default;
        }
    }

    protected function getHeader($key, $default = null)
    {
        try {
            return Arr::get(
                $this->attributes['headers'], $key, $default
            );
        } catch (Exception $e) {
            return $default;
        }
    }

    public function getSubject()
    {
        $subject = '';

        if (isset($this->attributes['subject'])) {
            $subject = $this->attributes['subject'];
        }

        return $subject;
    }

    protected function getExtraParams()
    {
        $this->attributes['extra']['provider'] = $this->getSetting('provider');

        if ($this->sendDurationMs !== null) {
            $this->attributes['extra']['send_time_ms'] = $this->sendDurationMs;
        }

        return $this->attributes['extra'];
    }

    public function handleResponse($response)
    {
        // Stopped here rather than in writeLog() so the figure is the provider
        // round trip, not the round trip plus however long the database took.
        // Failed sends are timed too — a send that takes ten seconds to fail is
        // worth seeing.
        if ($this->sendStartedAt) {
            $this->sendDurationMs = round((microtime(true) - $this->sendStartedAt) * 1000, 1);
        }

        if ( is_wp_error($response) ) {
            $code = $response->get_error_code();

            if (!is_numeric($code)) {
                $code = 400;
            }

            $message = $response->get_error_message();

            $errorResponse = [
                'code'    => $code,
                'message' => $message,
                'errors'  => $response->get_error_data()
            ];

            $status = $this->processResponse($errorResponse, false);

            if ( !$status ) {
                throw new \PHPMailer\PHPMailer\Exception($message, $code); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            } else {
                return $status;
            }

        } else {
            return $this->processResponse($response, true);
        }
    }

    public function processResponse($response, $status)
    {
        if ($this->shouldBeLogged($status)) {
            try {
                return $this->writeLog($response, $status);
            } catch (\Throwable $e) {
                /*
                 * Logging runs after the provider has already accepted (or
                 * rejected) the message, so a failure here must never change the
                 * outcome of a send that already happened. Throwable rather than
                 * Exception: the database layer can raise engine-level \Errors —
                 * a missing PHP extension, for instance — which would otherwise
                 * escape and kill the request after the email had gone out,
                 * leaving the caller with no response at all.
                 */
                fluentMailDebugLog('Failed to write email log - ' . $e->getMessage());
            }
        }

        return $status;
    }

    /**
     * Persist the email log row for a completed send.
     *
     * @param mixed $response Provider response or error payload.
     * @param bool $status Whether the send succeeded.
     * @return bool
     */
    protected function writeLog($response, $status)
    {
        $attachments = isset($this->attributes['attachments']) && is_array($this->attributes['attachments'])
            ? $this->attributes['attachments']
            : [];

        $rowId = $this->existing_row_id ?: $this->queue_row_id;
        $existingRow = $rowId ? (new Logger())->find($rowId) : [];

        if ($attachments) {
            if ($rowId) {
                /*
                 * A fallback reuses the row written by the first attempt, and a
                 * queued replay reuses the copies made when the email entered
                 * the queue. Copying again would orphan the first set and make
                 * the row point at a second one.
                 */
                $existingAttachments = isset($existingRow['attachments']) ? $existingRow['attachments'] : '';

                if (is_serialized($existingAttachments)) {
                    $decoded = unserialize(trim($existingAttachments), ['allowed_classes' => false]);

                    if (is_array($decoded) && $decoded) {
                        $attachments = $decoded;
                    }
                }
            } else {
                /*
                 * Runs after the provider has answered, so a copy failure must
                 * leave the log pointing at the original files rather than fail a
                 * send that already happened.
                 */
                $attachments = (new AttachmentStore())->storeForLog($attachments);
            }
        }

        $extra = (array)$this->getExtraParams();

        if ($rowId && isset($existingRow['extra']) && is_array($existingRow['extra'])) {
            /*
             * Both callers write a row that already holds state of its own:
             * the fallback's own record, or the queue marker, retry anchor and
             * attachment flags. Provider and timing values are refreshed;
             * everything else survives the write.
             */
            $extra = array_merge($existingRow['extra'], $extra);
        }

        $headers = $this->getParam('headers');

        if (!is_array($headers)) {
            $headers = [];
        }

        if ($rowId && !empty($existingRow['headers']) && is_string($existingRow['headers']) && is_serialized($existingRow['headers'])) {
            $storedHeaders = unserialize(trim($existingRow['headers']), ['allowed_classes' => false]);

            if (is_array($storedHeaders) && isset($storedHeaders['__fluentmail_alt_body'])) {
                $headers['__fluentmail_alt_body'] = $storedHeaders['__fluentmail_alt_body'];
            }
        }

        $data = [
            'to' => $this->serialize($this->attributes['to']),
            'from' => $this->attributes['from'],
            'subject' => sanitize_text_field($this->attributes['subject']),
            'body' => $this->attributes['message'],
            'attachments' => $this->serialize($attachments),
            'status'   => $status ? 'sent' : 'failed',
            'response' => $this->serialize($response),
            'headers'  => $this->serialize($headers, ['__fluentmail_alt_body']),
            'extra'    => $this->serialize($extra, ['custom_headers'])
        ];

        if($this->existing_row_id) {
            if($existingRow) {
                $existingRow['response'] = (array) $existingRow['response'];
                if($status) {
                    $existingRow['response']['fallback'] = __('Sent using fallback connection ', 'fluent-smtp') . $this->attributes['from'];
                    $existingRow['response']['fallback_response'] = $response;
                } else {
                    $existingRow['response']['fallback'] = __('Tried to send using fallback but failed. ', 'fluent-smtp') . $this->attributes['from'];
                    $existingRow['response']['fallback_response'] = $response;
                }

                $data['response'] = $this->serialize( $existingRow['response']);
                $data['retries'] = $existingRow['retries'] + 1;
                $updated = (new Logger())->updateLog($data, ['id' => $existingRow['id']]);

                if ($updated !== false && EmailQueue::isActiveReplayRow($existingRow['id'])) {
                    EmailQueue::markReplayRowWritten();
                }

                if(!$status) {
                    do_action('fluentmail_email_sending_failed_no_fallback', $existingRow['id'], $this, $data);
                }
            }
        } elseif ($this->queue_row_id) {
            if ($existingRow) {
                $data['updated_at'] = current_time('mysql');

                $updated = (new Logger())->updateLog($data, ['id' => $this->queue_row_id]);

                if ($updated !== false) {
                    EmailQueue::markReplayRowWritten();
                }

                if (!$status) {
                    /*
                     * The same filter a synchronous send fires, so the fallback
                     * connection and the failure alerts handle a queued email
                     * exactly as they handle any other one.
                     */
                    $status = apply_filters('fluentmail_email_sending_failed', $status, $this->queue_row_id, $this, $data);
                }
            }
        } else {
            $logId = (new Logger)->add($data);
            if(!$status) {
                // We have to fire an action for this failed job
                $status = apply_filters('fluentmail_email_sending_failed', $status, $logId, $this, $data);
            }
        }

        return $status;
    }

    protected function shouldBeLogged($status)
    {
        if($this->existing_row_id) {
            return true;
        }

        /*
         * A queue replay owns a row already. Letting the normal send path
         * write it is what brings the fallback connection and the failure
         * alerts to queued email; the row is updated, not duplicated.
         */
        if ($this->queue_row_id) {
            return true;
        }

        // A send the queue did not route (no provider matched the sender) has
        // no row to update, so writing one here would duplicate its log entry.
        if (EmailQueue::isSending()) {
            return false;
        }

        if (defined('FLUENTMAIL_LOG_OFF') && FLUENTMAIL_LOG_OFF) {
            return false;
        }

        if (!$status) {
            return true;
        }

        $miscSettings = $this->manager->getConfig('misc');
        $isLogOn = $miscSettings['log_emails'] == 'yes';

        return apply_filters('fluentmail_will_log_email', $isLogOn, $miscSettings, $this);
    }

    /**
     * @param array $data
     * @param array $keepRaw Top-level keys stored byte-for-byte.
     * @return string
     */
    protected function serialize(array $data, array $keepRaw = [])
    {
        return serialize($this->recursiveSanitize($data, $keepRaw));
    }

    protected function recursiveSanitize(array $data, array $keepRaw = [])
    {
        foreach ($data as $key => $item) {

            if (in_array($key, $keepRaw, true)) {
                /*
                 * Replay payloads keep their exact bytes. The generic text
                 * sanitizer strips angle brackets and collapses line breaks,
                 * both of which are meaningful in a custom header value and in
                 * the plain-text alternative. Custom headers are validated
                 * again when they are replayed; the text part is email content,
                 * stored raw like `body` is.
                 */
                continue;
            }

            if (is_array($item)) {
                // Reassign the sanitized array back; without this, nested values
                // (e.g. recipient display names in `to`) bypass sanitization.
                $data[$key] = $this->recursiveSanitize($item);
                continue;
            }

            if (is_object($item) || is_resource($item)) {
                throw new InvalidArgumentException(
                    esc_html__('Invalid Data: Array cannot contain an object or resource.', 'fluent-smtp')
                );
            }

            if (is_string($item)) {
                if (is_serialized($item)) {
                    throw new InvalidArgumentException(
                        esc_html__('Invalid Data: Array cannot contain serialized data.', 'fluent-smtp')
                    );
                }

                if (filter_var($item, FILTER_VALIDATE_EMAIL)) {
                    $data[$key] = sanitize_email($item);
                } elseif (filter_var($item, FILTER_VALIDATE_URL)) {
                    $data[$key] = esc_url_raw($item);
                } else {
                    $data[$key] = sanitize_text_field($item);
                }
            }
        }

        return $data;
    }

    public function getValidSenders($connection)
    {
        return [$connection['sender_email']];
    }

    public function checkConnection($connection)
    {
        return true;
    }

    public function getConnectionInfo($connection)
    {
        return [
            'info' => (string) fluentMail('view')->make('admin.general_connection_info', [
                'connection' => $connection
            ])
        ];
    }

    public function getPhpMailer()
    {
        return $this->phpMailer;
    }

    public function setRowId($id)
    {
        $this->existing_row_id = $id;
    }

    /**
     * @param int $id
     * @return void
     */
    public function setQueueRowId($id)
    {
        $this->queue_row_id = (int)$id;
    }

    /**
     * Whether a queue replay may hand this handler the row to update.
     *
     * True for every provider that logs through processResponse(). The
     * Simulator writes its own log and returns without reaching writeLog(),
     * so the queue records that outcome itself.
     *
     * @return bool
     */
    public function writesQueueRow()
    {
        return true;
    }

    public function addNewSenderEmail($connection, $email)
    {
        return new \WP_Error('not_implemented', __('Not implemented', 'fluent-smtp'));
    }

    public function removeSenderEmail($connection, $email)
    {
        return new \WP_Error('not_implemented', __('Not implemented', 'fluent-smtp'));
    }

    /**
     * Record an attachment that could not be read, then let the send continue.
     *
     * @param string     $provider Provider label used in the log line.
     * @param \Exception $e        The failure from secureFileRead().
     * @return void
     */
    protected function logAttachmentFailure($provider, $e)
    {
        fluentMailDebugLog(sprintf(
            '%s: Failed to read attachment - %s',
            $provider,
            $e->getMessage()
        ));
    }

    /**
     * Resolve the file name an attachment should be delivered under.
     *
     * PHPMailer keeps the caller supplied name at index 2 — wp_mail() puts the
     * attachments array key there — and the file's own base name at index 1, so
     * a site that stores uploads under randomised names can still send a
     * readable one. The name is caller controlled, so it is reduced to a bare
     * file name before it reaches a Content-Disposition header or a provider
     * payload.
     *
     * @param array $attachment One row of PHPMailer::getAttachments()
     * @return string Empty only when the row carries neither a name nor a path.
     */
    protected function getAttachmentName($attachment)
    {
        $candidates = [
            isset($attachment[2]) ? $attachment[2] : '',
            isset($attachment[0]) ? $attachment[0] : ''
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            // wp_basename() drops any directory part for both separators;
            // the rest cannot be allowed to break out of a quoted header.
            $name = str_replace(["\r", "\n", "\0", '"'], '', wp_basename($candidate));
            $name = trim($name);

            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * Securely read file contents with path traversal protection
     *
     * @param string $filePath The file path to read
     * @return string File contents on success
     * @throws Exception If path validation fails or file cannot be read
     */
    protected function secureFileRead($filePath)
    {
        // Resolve the real path to prevent path traversal attacks
        $realPath = realpath($filePath);

        // Validate that realpath returned a valid path
        if ($realPath === false) {
            throw new Exception(
                sprintf(__('Invalid file path: %s', 'fluent-smtp'), esc_html($filePath))
            );
        }

        // Verify it's actually a file (not a directory)
        if (!is_file($realPath)) {
            throw new Exception(
                sprintf(__('Path is not a file: %s', 'fluent-smtp'), esc_html($realPath))
            );
        }

        // Verify the file is readable
        if (!is_readable($realPath)) {
            throw new Exception(
                sprintf(__('File is not readable: %s', 'fluent-smtp'), esc_html($realPath))
            );
        }

        // Security check: Block access to sensitive system directories
        // This prevents reading sensitive files while allowing plugin/theme attachments
        $blockedPaths = [
            '/etc/',
            '/proc/',
            '/sys/',
            '/dev/',
            '/root/',
            ABSPATH . 'wp-config.php',
            dirname(ABSPATH) . '/wp-config.php',  // WordPress often stores wp-config.php one level up
            ABSPATH . '.htaccess',
        ];

        // Allow developers to customize blocked paths via filter
        $blockedPaths = apply_filters('fluentsmtp_attachment_blocked_paths', $blockedPaths);

        // Validate filter return to prevent fatal errors
        if (!is_array($blockedPaths)) {
            $blockedPaths = [];
        } else {
            // Ensure all entries are strings
            $blockedPaths = array_values(array_filter($blockedPaths, 'is_string'));
        }

        foreach ($blockedPaths as $blockedPath) {
            $blockedRealPath = realpath($blockedPath);
            if ($blockedRealPath && strpos($realPath, $blockedRealPath) === 0) {
                throw new Exception(
                    __('Access to this file location is restricted for security reasons', 'fluent-smtp')
                );
            }
        }

        // Read and return file contents
        $contents = file_get_contents($realPath);

        if ($contents === false) {
            throw new Exception(
                sprintf(__('Failed to read file: %s', 'fluent-smtp'), esc_html($realPath))
            );
        }

        return $contents;
    }

}
