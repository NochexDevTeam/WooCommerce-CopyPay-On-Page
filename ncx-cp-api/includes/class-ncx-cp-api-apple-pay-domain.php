<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hosts safe association files under the site's .well-known path.
 */
final class NCX_CP_API_Apple_Pay_Domain {

    public const OPTION_KEY = 'woocommerce_ncx_cp_api_settings';
    public const SETTING_KEY = 'apple_pay_domain_association';
    private const MANAGED_OPTION_KEY = 'ncx_cp_api_managed_well_known_files';
    public const RELATIVE_DIR = '.well-known';
    public const FILENAME = 'apple-developer-merchantid-domain-association';
    public const MAX_BYTES = 65536;
    public const MAX_TOTAL_BYTES = 262144;
    public const MAX_FILES = 10;
    public const CHECK_TIMEOUT = 5;

    public static function register(): void {
        add_action('parse_request', [self::class, 'maybe_serve'], 0);
        add_action('template_redirect', [self::class, 'maybe_serve'], 0);
    }

    public static function maybe_serve(): void {
        $filename = self::requested_filename();
        if (null === $filename) {
            return;
        }

        $body = self::get_stored_body($filename);
        if ('' === $body) {
            return;
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        status_header(200);

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated plain-text file bytes.
        exit;
    }

    /**
     * Legacy versions stored one string. Continue treating it as the Apple file.
     *
     * @return array<string, string>
     */
    public static function get_stored_files(): array {
        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            return [];
        }

        return self::normalize_stored_value($settings[self::SETTING_KEY] ?? []);
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    public static function normalize_stored_value($value): array {
        if (is_string($value)) {
            return '' === $value ? [] : [self::FILENAME => $value];
        }
        if (!is_array($value)) {
            return [];
        }

        $files = [];
        foreach ($value as $filename => $body) {
            if (!is_string($filename) || !is_string($body) || '' === $body || !self::is_safe_filename($filename)) {
                continue;
            }
            $files[$filename] = $body;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    public static function get_stored_body(string $filename = self::FILENAME): string {
        $files = self::get_stored_files();

        return $files[$filename] ?? '';
    }

    /**
     * Permit extensionless association files plus inert JSON/text files.
     */
    public static function is_safe_filename(string $filename): bool {
        if (strlen($filename) > 128 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $filename)) {
            return false;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return '' === $extension || in_array($extension, ['json', 'txt'], true);
    }

    /**
     * @return array{ok:bool, body:string, error:string}
     */
    public static function normalize_incoming(string $raw): array {
        $body = $raw;
        if (0 === strpos($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        if (strlen($body) > self::MAX_BYTES) {
            return [
                'ok'    => false,
                'body'  => '',
                'error' => sprintf(
                    /* translators: %d: maximum bytes */
                    __('Association file is too large (maximum %d bytes).', 'ncx-cp-api'),
                    self::MAX_BYTES
                ),
            ];
        }

        if ('' === $body || false !== strpos($body, "\0") || false !== stripos($body, '<?php') || false !== strpos($body, '<?=')) {
            return [
                'ok'    => false,
                'body'  => '',
                'error' => __('Association file looks invalid and was not saved.', 'ncx-cp-api'),
            ];
        }

        return [
            'ok'    => true,
            'body'  => $body,
            'error' => '',
        ];
    }

    public static function body_fingerprint(string $body): array {
        if ('' === $body) {
            return [
                'length'    => 0,
                'sha256_12' => '',
            ];
        }

        return [
            'length'    => strlen($body),
            'sha256_12' => substr(hash('sha256', $body), 0, 12),
        ];
    }

    /**
     * @return string[]
     */
    public static function public_urls(string $filename = self::FILENAME): array {
        if (!self::is_safe_filename($filename)) {
            return [];
        }

        $path = '/' . self::RELATIVE_DIR . '/' . rawurlencode($filename);
        $urls = [];

        foreach ([home_url($path), site_url($path)] as $url) {
            if (!in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public static function wordpress_not_at_domain_root(): bool {
        $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);

        return '' !== rtrim($home_path, '/');
    }

    public static function physical_file_path(string $filename = self::FILENAME): string {
        if (!self::is_safe_filename($filename)) {
            return '';
        }

        return ABSPATH . self::RELATIVE_DIR . '/' . $filename;
    }

    /**
     * Writes only safe files and removes only unchanged files recorded as
     * plugin-managed. Unknown or manually changed files are preserved.
     *
     * @param array<string, string> $files
     * @return array{ok:bool, message:string, wrote:int, deleted:int}
     */
    public static function sync_physical_files(array $files): array {
        $files = self::normalize_stored_value($files);
        $dir = ABSPATH . self::RELATIVE_DIR;
        $managed = get_option(self::MANAGED_OPTION_KEY, []);
        $managed = is_array($managed) ? $managed : [];
        $next_managed = $managed;
        $wrote = 0;
        $deleted = 0;
        $warnings = [];

        if (is_link($dir)) {
            return [
                'ok'      => false,
                'wrote'   => 0,
                'deleted' => 0,
                'message' => __('The .well-known path is a symbolic link, so the plugin preserved it and will serve stored files through WordPress only.', 'ncx-cp-api'),
            ];
        }

        if (!empty($files) && !is_dir($dir) && !wp_mkdir_p($dir) && !is_dir($dir)) {
            return [
                'ok'      => false,
                'wrote'   => 0,
                'deleted' => 0,
                'message' => __('Could not create the .well-known folder at the WordPress root. WordPress will still try to serve the stored files.', 'ncx-cp-api'),
            ];
        }

        foreach ($managed as $filename => $expected_hash) {
            $filename = (string) $filename;
            if (isset($files[$filename]) || !self::is_safe_filename($filename)) {
                continue;
            }

            $path = self::physical_file_path($filename);
            if (is_link($path)) {
                $warnings[] = sprintf(
                    /* translators: %s: filename */
                    __('Preserved symbolic link %s.', 'ncx-cp-api'),
                    $filename
                );
                unset($next_managed[$filename]);
                continue;
            }
            $current_hash = is_file($path) ? hash_file('sha256', $path) : false;
            if (false !== $current_hash && hash_equals((string) $expected_hash, $current_hash)) {
                if (@unlink($path)) {
                    $deleted++;
                } else {
                    $warnings[] = sprintf(
                        /* translators: %s: filename */
                        __('Could not remove managed file %s.', 'ncx-cp-api'),
                        $filename
                    );
                }
            } elseif (is_file($path)) {
                $warnings[] = sprintf(
                    /* translators: %s: filename */
                    __('Preserved manually changed file %s.', 'ncx-cp-api'),
                    $filename
                );
            }
            unset($next_managed[$filename]);
        }

        foreach ($files as $filename => $body) {
            $path = self::physical_file_path($filename);
            if (is_link($path)) {
                $warnings[] = sprintf(
                    /* translators: %s: filename */
                    __('Did not write through symbolic link %s.', 'ncx-cp-api'),
                    $filename
                );
                unset($next_managed[$filename]);
                continue;
            }
            $desired_hash = hash('sha256', $body);
            $current_hash = is_file($path) ? hash_file('sha256', $path) : false;
            $is_managed = false !== $current_hash
                && isset($managed[$filename])
                && hash_equals((string) $managed[$filename], $current_hash);

            if (false !== $current_hash && !$is_managed) {
                if (hash_equals($desired_hash, $current_hash)) {
                    // Safely adopt the legacy plugin file when its bytes exactly
                    // match the stored setting; unrelated files remain untouched.
                    $next_managed[$filename] = $desired_hash;
                } else {
                    $warnings[] = sprintf(
                        /* translators: %s: filename */
                        __('Did not overwrite unmanaged file %s.', 'ncx-cp-api'),
                        $filename
                    );
                }
                continue;
            }

            if (false === @file_put_contents($path, $body, LOCK_EX)) {
                $warnings[] = sprintf(
                    /* translators: %s: filename */
                    __('Could not write %s at the WordPress root.', 'ncx-cp-api'),
                    $filename
                );
                continue;
            }

            @chmod($path, 0644);
            $next_managed[$filename] = $desired_hash;
            $wrote++;
        }

        update_option(self::MANAGED_OPTION_KEY, $next_managed, false);

        $message = sprintf(
            /* translators: 1: files written, 2: files removed */
            __('Synced .well-known files: %1$d written, %2$d removed.', 'ncx-cp-api'),
            $wrote,
            $deleted
        );
        if (!empty($warnings)) {
            $message .= ' ' . implode(' ', $warnings);
        }
        if (self::wordpress_not_at_domain_root()) {
            $message .= ' ' . __('WordPress is not at the domain root, so a web-server alias for /.well-known/ may still be required.', 'ncx-cp-api');
        }

        return [
            'ok'      => empty($warnings),
            'wrote'   => $wrote,
            'deleted' => $deleted,
            'message' => $message,
        ];
    }

    public static function requested_filename(): ?string {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rawurldecode($path);
        if ('' !== $path && 0 !== strpos($path, '/')) {
            $path = '/' . $path;
        }
        $path = rtrim($path, '/');

        $prefixes = ['/' . self::RELATIVE_DIR . '/'];
        $home_path = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ('' !== $home_path) {
            $prefixes[] = $home_path . '/' . self::RELATIVE_DIR . '/';
        }

        foreach ($prefixes as $prefix) {
            if (0 !== strpos($path, $prefix)) {
                continue;
            }
            $filename = substr($path, strlen($prefix));
            if (self::is_safe_filename($filename)) {
                return $filename;
            }
        }

        return null;
    }

    public static function request_is_association_path(): bool {
        return self::FILENAME === self::requested_filename();
    }

    /**
     * GET a fixed stored file URL with redirects disabled.
     *
     * @return array<string, mixed>
     */
    public static function check_public_url(string $filename = self::FILENAME): array {
        if (!self::is_safe_filename($filename)) {
            return [
                'ok'      => false,
                'message' => __('Invalid association filename.', 'ncx-cp-api'),
            ];
        }

        $urls = self::public_urls($filename);
        $expected = $urls[0] ?? '';
        $stored = self::get_stored_body($filename);
        $stored_hash = '' === $stored ? '' : hash('sha256', $stored);

        if ('' === $expected) {
            return [
                'ok'      => false,
                'message' => __('Could not build the public association URL.', 'ncx-cp-api'),
            ];
        }

        if ('' === $stored) {
            return [
                'ok'      => false,
                'message' => __('Save the association file before checking.', 'ncx-cp-api'),
                'url'     => $expected,
            ];
        }

        $response = wp_remote_get(
            $expected,
            [
                'timeout'     => self::CHECK_TIMEOUT,
                'redirection' => 0,
                'sslverify'   => true,
            ]
        );

        if (is_wp_error($response)) {
            return [
                'ok'      => false,
                'url'     => $expected,
                'message' => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $location = (string) wp_remote_retrieve_header($response, 'location');
        $body = (string) wp_remote_retrieve_body($response);
        $body_hash = '' === $body ? '' : hash('sha256', $body);
        $redirect = $code >= 300 && $code < 400;
        $match = '' !== $stored_hash && $stored_hash === $body_hash;
        $ok = 200 === $code && !$redirect && $match;

        return [
            'ok'           => $ok,
            'url'          => $expected,
            'status'       => $code,
            'redirect'     => $redirect,
            'location'     => $location,
            'body_matches' => $match,
            'message'      => self::check_message($ok, $code, $redirect, $match),
        ];
    }

    private static function check_message(bool $ok, int $code, bool $redirect, bool $match): string {
        if ($ok) {
            return __('Reachable with HTTP 200, no redirect, and the body matches the saved file.', 'ncx-cp-api');
        }
        if ($redirect) {
            return __('Apple rejects redirects. The URL redirected instead of returning the file.', 'ncx-cp-api');
        }
        if (200 === $code && !$match) {
            return __('The URL responded 200 but the body does not match the saved file.', 'ncx-cp-api');
        }

        return sprintf(
            /* translators: %d: HTTP status code */
            __('Check failed (HTTP %d).', 'ncx-cp-api'),
            $code
        );
    }
}
