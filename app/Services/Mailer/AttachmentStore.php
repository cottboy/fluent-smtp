<?php

namespace FluentMail\App\Services\Mailer;

use FluentMail\App\Models\Settings;

/**
 * Keeps our own copies of email attachments.
 *
 * A queued email outlives the request that queued it, and uploads that arrive
 * with that request are usually deleted when it ends. Copying the files into
 * our own directory keeps the email sendable later and keeps a resend working
 * after the original file is gone.
 *
 * Files are stored under wp-content/uploads/fluentmail with random names and a
 * .dat suffix. The suffix is deliberate: a PHP file copied into uploads can be
 * executed on a misconfigured server, and a .dat file cannot. The original
 * name, MIME type, encoding, disposition and CID stay in the log row so the
 * recipient still sees report.pdf, not a random .dat file.
 */
class AttachmentStore
{
    const FILE_EXTENSION = '.dat';

    /**
     * Whether the admin asked us to keep attachments with every log.
     *
     * @return bool
     */
    public function shouldPreserve()
    {
        $misc = (new Settings())->getMisc();

        return isset($misc['keep_email_attachments']) && $misc['keep_email_attachments'] === 'yes';
    }

    /**
     * Copy attachments for an email that is about to enter the queue.
     *
     * The queue cannot send an email whose files have already been deleted, so
     * a copy failure returns false and the caller sends synchronously instead.
     * Without the retention setting these copies are temporary and are removed
     * once the email is sent or finally fails.
     *
     * @param array $attachments
     * @return array|false {attachments: array, temporary: bool}
     */
    public function storeForQueue(array $attachments)
    {
        return $this->store($attachments, !$this->shouldPreserve());
    }

    /**
     * Copy attachments for a log written after a synchronous send.
     *
     * The send has already happened by this point, so a failure here must not
     * fail the request: the original attachment rows are returned unchanged.
     *
     * @param array $attachments
     * @return array
     */
    public function storeForLog(array $attachments)
    {
        if (!$this->shouldPreserve()) {
            return $attachments;
        }

        $stored = $this->store($attachments, false);

        return $stored === false ? $attachments : $stored['attachments'];
    }

    /**
     * @param array $attachments PHPMailer::getAttachments() rows
     * @param bool $temporary
     * @return array|false
     */
    public function store(array $attachments, $temporary)
    {
        $attachments = array_values($attachments);

        if (!$attachments) {
            return ['attachments' => [], 'temporary' => false];
        }

        $base = $this->baseDir();

        if (!$base || !$this->prepareBaseDir($base)) {
            return false;
        }

        $dir = $base . '/' . $this->randomToken();

        if (!wp_mkdir_p($dir)) {
            return false;
        }

        $stored = [];
        $created = [];

        foreach ($attachments as $attachment) {
            $row = $this->copyAttachment($attachment, $dir);

            if ($row === false) {
                $this->deleteFiles($created);
                @rmdir($dir);
                return false;
            }

            $stored[] = $row;

            if (is_string($row[0]) && $row[0] !== '') {
                $created[] = $row[0];
            }
        }

        return [
            'attachments' => $stored,
            'temporary'   => (bool)$temporary
        ];
    }

    /**
     * Delete the copies referenced by a log row.
     *
     * Only files inside our own uploads directory are removed. Original
     * attachments supplied by another plugin or theme are never touched.
     *
     * @param mixed $attachments Serialized or unserialized attachment rows.
     * @return void
     */
    public function deleteForAttachmentList($attachments)
    {
        if (is_string($attachments) && is_serialized($attachments)) {
            $attachments = unserialize(trim($attachments), ['allowed_classes' => false]);
        }

        if (!is_array($attachments) || !$attachments) {
            return;
        }

        $base = $this->baseDir();

        if (!$base) {
            return;
        }

        $base = wp_normalize_path(realpath($base) ?: $base);

        foreach ($attachments as $attachment) {
            $path = $this->attachmentPath($attachment);

            if ($path === '') {
                continue;
            }

            $real = realpath($path);

            if ($real === false || !is_file($real)) {
                continue;
            }

            $real = wp_normalize_path($real);

            // A path that escaped our directory is not ours to delete.
            if (strpos($real, $base . '/') !== 0) {
                continue;
            }

            @unlink($real);

            $dir = dirname($real);

            while ($dir !== $base && strpos($dir, $base . '/') === 0) {
                if (!@rmdir($dir)) {
                    break;
                }
                $dir = dirname($dir);
            }
        }
    }

    /**
     * Remove every attachment copy this plugin owns.
     *
     * Used by "delete all logs", which also cancels queued emails. Deleting the
     * directory as a whole avoids loading every log row just to find paths.
     *
     * @return void
     */
    public function deleteAll()
    {
        $base = $this->baseDir();

        if (!$base || !is_dir($base)) {
            return;
        }

        $real = realpath($base);

        if ($real === false) {
            return;
        }

        $this->deleteDirectory(wp_normalize_path($real));
    }

    /**
     * @return string|false
     */
    public function baseDir()
    {
        $uploads = wp_upload_dir();

        if (!empty($uploads['error'])) {
            return false;
        }

        $base = wp_normalize_path(
            trailingslashit($uploads['basedir']) . ltrim(FLUENTMAIL_UPLOAD_DIR, '/')
        );

        return untrailingslashit($base);
    }

    /**
     * @param string $base
     * @return bool
     */
    protected function prepareBaseDir($base)
    {
        if (!wp_mkdir_p($base)) {
            return false;
        }

        /*
         * The .dat suffix already makes the files non-executable, and the
         * random name makes them unguessable. These two files are an extra
         * layer on Apache; servers that do not honour .htaccess are no worse
         * off than without them.
         */
        $index = $base . '/index.php';

        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
        }

        $htaccess = $base . '/.htaccess';

        if (!file_exists($htaccess)) {
            $rules = "# FluentSMTP attachment copies. Read from disk by the plugin only.\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

            @file_put_contents($htaccess, $rules, LOCK_EX);
        }

        return is_dir($base) && is_writable($base);
    }

    /**
     * @param mixed $attachment
     * @param string $dir
     * @return array|false
     */
    protected function copyAttachment($attachment, $dir)
    {
        $isString = false;
        $path = '';
        $filename = '';
        $name = '';
        $encoding = 'base64';
        $type = '';
        $disposition = 'attachment';
        $cid = '';

        if (is_string($attachment)) {
            $path = $attachment;
        } elseif (is_array($attachment)) {
            $path = isset($attachment[0]) && is_string($attachment[0]) ? $attachment[0] : '';
            $filename = isset($attachment[1]) && is_string($attachment[1]) ? $attachment[1] : '';
            $name = isset($attachment[2]) && is_string($attachment[2]) ? $attachment[2] : '';
            $encoding = !empty($attachment[3]) && is_string($attachment[3]) ? $attachment[3] : 'base64';
            $type = isset($attachment[4]) && is_string($attachment[4]) ? $attachment[4] : '';
            $isString = !empty($attachment[5]);
            $disposition = !empty($attachment[6]) && is_string($attachment[6]) ? $attachment[6] : 'attachment';
            $cid = isset($attachment[7]) && is_string($attachment[7]) ? $attachment[7] : '';
        } else {
            return false;
        }

        $file = $dir . '/' . $this->randomToken() . self::FILE_EXTENSION;

        if ($isString) {
            if (!is_string($path)) {
                return false;
            }

            if (file_put_contents($file, $path, LOCK_EX) === false) {
                return false;
            }

            $type = $type ?: 'application/octet-stream';
        } else {
            $real = realpath($path);

            if ($real === false || !is_file($real) || !is_readable($real)) {
                return false;
            }

            if ($this->isBlockedPath($real)) {
                return false;
            }

            if (!@copy($real, $file)) {
                return false;
            }

            if ($type === '') {
                $type = function_exists('mime_content_type') ? (string)mime_content_type($real) : '';
            }
        }

        $displaySource = $name ?: $filename;

        if ($displaySource === '' && !$isString) {
            $displaySource = wp_basename($path);
        }

        $displayName = $this->sanitizeName($displaySource ?: 'attachment');

        return [
            0 => $file,
            1 => $displayName,
            2 => $displayName,
            3 => $encoding,
            4 => $type,
            5 => false,
            6 => $disposition,
            7 => $cid
        ];
    }

    /**
     * Reject the same sensitive files the provider path readers reject.
     *
     * @param string $realPath
     * @return bool
     */
    protected function isBlockedPath($realPath)
    {
        $realPath = wp_normalize_path($realPath);

        $blockedPaths = [
            '/etc/',
            '/proc/',
            '/sys/',
            '/dev/',
            '/root/',
            ABSPATH . 'wp-config.php',
            dirname(ABSPATH) . '/wp-config.php',
            ABSPATH . '.htaccess',
        ];

        $blockedPaths = apply_filters('fluentsmtp_attachment_blocked_paths', $blockedPaths);

        if (!is_array($blockedPaths)) {
            return false;
        }

        foreach ($blockedPaths as $blockedPath) {
            if (!is_string($blockedPath)) {
                continue;
            }

            $blockedReal = realpath($blockedPath);

            if (!$blockedReal) {
                continue;
            }

            $blockedReal = wp_normalize_path($blockedReal);

            if (strpos($realPath, $blockedReal) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $attachment
     * @return string
     */
    protected function attachmentPath($attachment)
    {
        if (is_string($attachment)) {
            return $attachment;
        }

        if (!is_array($attachment) || empty($attachment[0]) || !is_string($attachment[0])) {
            return '';
        }

        // Index 5 marks an attachment whose "path" is the file content itself.
        if (!empty($attachment[5])) {
            return '';
        }

        return $attachment[0];
    }

    /**
     * @param array $files
     * @return void
     */
    protected function deleteFiles(array $files)
    {
        foreach ($files as $file) {
            if (is_string($file) && $file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param string $dir
     * @return void
     */
    protected function deleteDirectory($dir)
    {
        $items = @scandir($dir);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * @param string $name
     * @return string
     */
    protected function sanitizeName($name)
    {
        $name = str_replace(["\r", "\n", "\0", '"'], '', wp_basename((string)$name));
        $name = trim($name);

        return $name === '' ? 'attachment' : $name;
    }

    /**
     * @return string
     */
    protected function randomToken()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return wp_generate_password(32, false, false);
        }
    }
}
