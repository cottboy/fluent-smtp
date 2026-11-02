<?php

namespace FluentMail\App\Services\Mailer;

/**
 * Rebuilds an email from a log row and sends it again.
 *
 * The queued sender and the log screen's Resend button both need the same
 * reconstruction, including attachments that keep their original name, MIME
 * type and inline CID. Passing paths through wp_mail() alone cannot do that:
 * the stored file is named <random>.dat and an inline image would arrive as a
 * normal attachment. A one-shot phpmailer_init listener adds each attachment
 * with its recorded metadata instead.
 */
class LoggedEmailReplayer
{
    /**
     * @param array $email Log row with `to`, `headers`, `attachments` and `extra` unserialized.
     * @param array $customRecipients Optional replacement recipients.
     * @param string $from Optional sender that overrides the row's stored `from`.
     * @param bool $skipMissingAttachments Whether a manual resend skips
     * attachments that are no longer available.
     * @return array {success: bool, error: \WP_Error|null}
     */
    public function send(array $email, array $customRecipients = [], $from = '', $skipMissingAttachments = false)
    {
        $attachments = $this->prepareAttachments(
            isset($email['attachments']) ? $email['attachments'] : [],
            $skipMissingAttachments
        );

        if (is_wp_error($attachments)) {
            return ['success' => false, 'error' => $attachments];
        }

        $hasCustomRecipients = !empty($customRecipients);
        $to = $this->recipients($email, $customRecipients);
        $hasRecipients = !empty($to);

        // Cc and Bcc can be the only destinations, unless recipients were replaced.
        if (!$hasRecipients && !$hasCustomRecipients) {
            foreach ($this->storedHeaders($email) as $key => $value) {
                if (in_array(strtolower((string)$key), ['cc', 'bcc'], true)
                    && $this->recipients(['to' => $value], [])) {
                    $hasRecipients = true;
                    break;
                }
            }
        }

        if (!$hasRecipients) {
            return [
                'success' => false,
                'error'   => new \WP_Error(
                    'fluentmail_no_recipients',
                    __('The logged email has no valid recipients.', 'fluent-smtp')
                )
            ];
        }

        $headers = $this->headers($email, $hasCustomRecipients, $from);
        $subject = isset($email['subject']) ? (string)$email['subject'] : '';
        $body = isset($email['body']) ? (string)$email['body'] : '';
        $customHeaders = $this->storedCustomHeaders($email);
        $altBody = $this->storedAltBody($email);

        $phpmailerInit = function ($phpMailer) use (&$phpmailerInit, $attachments, $customHeaders, $altBody) {
            // Runs once per wp_mail(), and removes itself so a later send in the
            // same process cannot inherit these files.
            remove_action('phpmailer_init', $phpmailerInit, 10);

            if ($customHeaders) {
                $present = [];

                foreach ((array)$phpMailer->getCustomHeaders() as $header) {
                    if (isset($header[0]) && is_string($header[0])) {
                        $present[strtolower($header[0])] = true;
                    }
                }

                foreach ($customHeaders as $header) {
                    $name = strtolower($header['key']);

                    /*
                     * A listener that adds this header on every send wins: its
                     * value may be fresher than the one saved at queue time, and
                     * adding ours too would stack a duplicate header.
                     */
                    if (isset($present[$name])) {
                        continue;
                    }

                    $phpMailer->addCustomHeader($header['key'], $header['value']);
                    $present[$name] = true;
                }
            }

            if ($altBody !== '') {
                $phpMailer->isHTML(true);
                $phpMailer->AltBody = $altBody;
                $phpMailer->ContentType = 'multipart/alternative';
            }

            foreach ($attachments as $attachment) {
                if ($attachment['is_string']) {
                    if ($attachment['cid'] !== '') {
                        $phpMailer->addStringEmbeddedImage(
                            $attachment['content'],
                            $attachment['cid'],
                            $attachment['name'],
                            $attachment['encoding'],
                            $attachment['type'],
                            $attachment['disposition']
                        );
                    } else {
                        $phpMailer->addStringAttachment(
                            $attachment['content'],
                            $attachment['name'],
                            $attachment['encoding'],
                            $attachment['type'],
                            $attachment['disposition']
                        );
                    }
                    continue;
                }

                if ($attachment['cid'] !== '') {
                    $phpMailer->addEmbeddedImage(
                        $attachment['path'],
                        $attachment['cid'],
                        $attachment['name'],
                        $attachment['encoding'],
                        $attachment['type']
                    );
                    continue;
                }

                $phpMailer->addAttachment(
                    $attachment['path'],
                    $attachment['name'],
                    $attachment['encoding'],
                    $attachment['type'],
                    $attachment['disposition']
                );
            }
        };

        add_action('phpmailer_init', $phpmailerInit, 10, 1);

        $failure = null;
        $onFailure = function ($error) use (&$failure) {
            if (is_wp_error($error)) {
                $failure = $error;
            }
        };

        add_action('wp_mail_failed', $onFailure, 10, 1);

        try {
            // Attachments travel through phpmailer_init, not this argument, so
            // their original names and inline CIDs survive the rebuild.
            $sent = wp_mail($to, $subject, $body, $headers, []);
        } finally {
            remove_action('phpmailer_init', $phpmailerInit, 10);
            remove_action('wp_mail_failed', $onFailure, 10);
        }

        if ($sent) {
            return ['success' => true, 'error' => null];
        }

        if (!$failure) {
            $failure = new \WP_Error(
                'fluentmail_replay_failed',
                __('The email could not be sent.', 'fluent-smtp')
            );
        }

        return ['success' => false, 'error' => $failure];
    }

    /**
     * @param array $email
     * @param array $customRecipients
     * @return array
     */
    public function recipients(array $email, array $customRecipients)
    {
        $to = [];

        if ($customRecipients) {
            foreach ($customRecipients as $recipient) {
                if (is_string($recipient) && is_email($recipient)) {
                    $to[] = $recipient;
                }
            }

            return $to;
        }

        $logged = isset($email['to']) ? $email['to'] : [];

        if (is_string($logged)) {
            foreach (preg_split('/[\s,;]+/', $logged) as $address) {
                $address = trim($address, " \t\n\r\0\x0B<>");
                if (is_email($address)) {
                    $to[] = $address;
                }
            }

            return $to;
        }

        if (!is_array($logged)) {
            return $to;
        }

        foreach ($logged as $recipient) {
            if (!is_array($recipient)) {
                if (is_string($recipient) && is_email($recipient)) {
                    $to[] = $recipient;
                }
                continue;
            }

            if (empty($recipient['email']) || !is_email($recipient['email'])) {
                continue;
            }

            if (!empty($recipient['name'])) {
                $to[] = BaseHandler::formatAddress($recipient['email'], $recipient['name']);
            } else {
                $to[] = $recipient['email'];
            }
        }

        return $to;
    }

    /**
     * @param array $email
     * @param bool $skipCcBcc
     * @param string $from
     * @return array
     */
    protected function headers(array $email, $skipCcBcc, $from = '')
    {
        $logged = $this->storedHeaders($email);

        $skip = $skipCcBcc ? ['cc', 'bcc'] : [];
        $headers = [];

        foreach ($logged as $key => $value) {
            // Internal markers are carried in this blob but are not headers.
            if (strpos((string)$key, '__') === 0) {
                continue;
            }

            if (in_array(strtolower((string)$key), $skip, true)) {
                continue;
            }

            if ($key == 'content-type' && $value == 'multipart/alternative') {
                $value = 'text/html';
            }

            if (is_array($value)) {
                $values = [];

                foreach (array_filter($value) as $item) {
                    if (is_array($item) && isset($item['email'])) {
                        $item = BaseHandler::formatAddress(
                            $item['email'],
                            isset($item['name']) ? $item['name'] : ''
                        );
                    }

                    if (is_scalar($item) && (string)$item !== '') {
                        $values[] = (string)$item;
                    }
                }

                if ($values) {
                    $headers[] = "{$key}: " . implode(', ', $values);
                }
                continue;
            }

            if (is_scalar($value) && (string)$value !== '') {
                $headers[] = "{$key}: {$value}";
            }
        }

        if ($from === '' && !empty($email['from']) && is_string($email['from'])) {
            $from = $email['from'];
        }

        if ($from !== '') {
            $headers[] = 'From: ' . $from;
        }

        return $headers;
    }

    /**
     * @param array $email
     * @return array
     */
    protected function storedHeaders(array $email)
    {
        $logged = isset($email['headers']) ? $email['headers'] : [];

        if (is_string($logged) && is_serialized($logged)) {
            $logged = unserialize(trim($logged), ['allowed_classes' => false]);
        }

        return is_array($logged) ? $logged : [];
    }

    /**
     * Custom headers saved when the email entered the queue.
     *
     * The row is data, not code: a header name has to stay a valid token and
     * neither part may carry a line break.
     *
     * @param array $email
     * @return array<int, array{key: string, value: string}>
     */
    protected function storedCustomHeaders(array $email)
    {
        $extra = isset($email['extra']) && is_array($email['extra']) ? $email['extra'] : [];
        $stored = isset($extra['custom_headers']) && is_array($extra['custom_headers'])
            ? $extra['custom_headers']
            : [];

        $reserved = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'content-type',
            'return-path', 'subject', 'date', 'message-id', 'mime-version'
        ];

        $headers = [];

        foreach ($stored as $header) {
            if (!is_array($header) || empty($header['key']) || !isset($header['value'])) {
                continue;
            }

            $key = trim(str_replace(["\r", "\n", "\0"], '', (string)$header['key']));
            $value = trim(str_replace(["\r", "\n", "\0"], ' ', (string)$header['value']));

            if ($key === '' || $value === '' || !preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $key)) {
                continue;
            }

            if (in_array(strtolower($key), $reserved, true)) {
                continue;
            }

            $headers[] = ['key' => $key, 'value' => $value];
        }

        return $headers;
    }

    /**
     * The plain-text alternative saved when the email entered the queue.
     *
     * Only HTML mail carries one; a stored text body is left alone.
     *
     * @param array $email
     * @return string
     */
    protected function storedAltBody(array $email)
    {
        $headers = $this->storedHeaders($email);
        $contentType = isset($headers['content-type']) ? $headers['content-type'] : '';

        if (!is_string($contentType)) {
            return '';
        }

        $contentType = strtolower(trim(strtok($contentType, ';')));

        if (!in_array($contentType, ['text/html', 'multipart/alternative'], true)) {
            return '';
        }

        $altBody = isset($headers['__fluentmail_alt_body']) ? $headers['__fluentmail_alt_body'] : '';

        return is_string($altBody) ? $altBody : '';
    }

    /**
     * @param mixed $attachments
     * @param bool $skipMissingAttachments
     * @return array|\WP_Error
     */
    protected function prepareAttachments($attachments, $skipMissingAttachments)
    {
        if (is_string($attachments) && is_serialized($attachments)) {
            $attachments = unserialize(trim($attachments), ['allowed_classes' => false]);
        }

        if (!is_array($attachments) || !$attachments) {
            return [];
        }

        $prepared = [];

        foreach ($attachments as $attachment) {
            if (is_string($attachment)) {
                $attachment = [0 => $attachment];
            }

            if (!is_array($attachment) || !isset($attachment[0]) || !is_string($attachment[0])) {
                continue;
            }

            $isString = !empty($attachment[5]);
            $name = $this->attachmentName($attachment, $isString);
            $encoding = !empty($attachment[3]) && is_string($attachment[3]) ? $attachment[3] : 'base64';
            $type = isset($attachment[4]) && is_string($attachment[4]) ? $attachment[4] : '';
            $disposition = !empty($attachment[6]) && is_string($attachment[6]) ? $attachment[6] : 'attachment';

            /*
             * Index 7 is the Content-ID only for an inline image. For a normal
             * attachment WordPress's bundled PHPMailer stores the display name
             * there, so treating it as a CID would turn every file attachment
             * into an inline part.
             */
            $cid = $disposition === 'inline' && isset($attachment[7]) && is_string($attachment[7])
                ? trim($attachment[7])
                : '';

            if (!$isString) {
                $path = realpath($attachment[0]);

                if ($path === false || !is_file($path) || !is_readable($path)) {
                    if ($skipMissingAttachments) {
                        continue;
                    }

                    return new \WP_Error(
                        'fluentmail_attachment_missing',
                        sprintf(
                            /* translators: %s: attachment file name */
                            __('The attachment is no longer available: %s', 'fluent-smtp'),
                            $name
                        )
                    );
                }

                $attachment[0] = $path;
                $type = $type ?: (function_exists('mime_content_type') ? (string)mime_content_type($path) : '');
            }

            $prepared[] = [
                'path'         => $isString ? '' : $attachment[0],
                'content'      => $isString ? $attachment[0] : '',
                'name'         => $name,
                'encoding'     => $encoding,
                'type'         => $type,
                'disposition'  => $disposition,
                'cid'          => $cid,
                'is_string'    => $isString
            ];
        }

        return $prepared;
    }

    /**
     * @param array $attachment
     * @param bool $isString
     * @return string
     */
    protected function attachmentName(array $attachment, $isString = false)
    {
        $candidates = [
            isset($attachment[2]) ? $attachment[2] : '',
            isset($attachment[1]) ? $attachment[1] : ''
        ];

        if (!$isString) {
            $candidates[] = isset($attachment[0]) ? $attachment[0] : '';
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $name = str_replace(["\r", "\n", "\0", '"'], '', wp_basename($candidate));
            $name = trim($name);

            if ($name !== '') {
                return $name;
            }
        }

        return 'attachment';
    }
}
