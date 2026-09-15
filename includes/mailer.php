<?php
declare(strict_types=1);

// A small, dependency-free SMTP client. Shared hosts frequently don't
// offer Composer/shell access to install PHPMailer, so rather than vendor
// a large third-party library wholesale (and one this environment had no
// way to actually test against), this talks SMTP directly over PHP
// streams. Supports STARTTLS (port 587, the common case) and implicit
// TLS (port 465), with optional AUTH LOGIN.

class SmtpException extends RuntimeException {}

function mailer_is_configured(): bool
{
    return (bool) env('SMTP_HOST');
}

// Every outgoing email's subject should reflect the configured site
// name — this is the one place that happens, instead of the name being
// repeated as a literal string in every api/*.php file that sends mail.
function mail_subject(string $suffix): string
{
    return "$suffix — " . site_name();
}

/**
 * @param string $to Plain email address.
 * @param string $subject
 * @param string $text Plain-text body.
 * @param string $html HTML body.
 * @return array{skipped: bool}
 */
function send_mail(string $to, string $subject, string $text, string $html): array
{
    if (!mailer_is_configured()) {
        error_log("SMTP not configured — would have sent \"$subject\" to $to");
        return ['skipped' => true];
    }

    smtp_send_with_config([
        'host' => env('SMTP_HOST'),
        'port' => (int) env('SMTP_PORT', '587'),
        'secure' => strtolower((string) env('SMTP_SECURE', 'false')) === 'true',
        'user' => env('SMTP_USER'),
        'pass' => env('SMTP_PASS'),
        'from' => (string) env('MAIL_FROM', env('SMTP_USER') ?: 'no-reply@localhost'),
    ], $to, $subject, $text, $html);

    return ['skipped' => false];
}

/**
 * The actual SMTP conversation, parameterized rather than reading
 * `.env` directly — split out of send_mail() so the setup wizard's
 * "Send test email" button can try connection values the admin has
 * only typed so far, before they're saved to `.env` at all (see
 * api/setup/test_smtp.php). Throws SmtpException on any failure;
 * send_mail() is the only caller that should treat "not configured" as
 * a silent no-op — this function always either sends or throws.
 *
 * @param array{host: ?string, port: int, secure: bool, user: ?string, pass: ?string, from: string} $config
 */
function smtp_send_with_config(array $config, string $to, string $subject, string $text, string $html): void
{
    $host = $config['host'];
    $port = $config['port'];
    $secure = $config['secure'];
    $user = $config['user'];
    $pass = $config['pass'];
    $from = $config['from'];

    $transport = $secure ? "tls://$host:$port" : "tcp://$host:$port";
    $stream = @stream_socket_client($transport, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$stream) {
        throw new SmtpException("Could not connect to $host:$port ($errstr)");
    }
    stream_set_timeout($stream, 15);

    try {
        smtp_expect($stream, 220);
        smtp_command($stream, 'EHLO ' . smtp_local_hostname(), 250);

        if (!$secure) {
            smtp_command($stream, 'STARTTLS', 220);
            $ok = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) {
                throw new SmtpException('STARTTLS negotiation failed.');
            }
            // RFC 3207: must re-issue EHLO after upgrading to TLS.
            smtp_command($stream, 'EHLO ' . smtp_local_hostname(), 250);
        }

        if ($user) {
            smtp_command($stream, 'AUTH LOGIN', 334);
            smtp_command($stream, base64_encode($user), 334);
            smtp_command($stream, base64_encode((string) $pass), 235);
        }

        $fromAddr = smtp_extract_address($from);
        $toAddr = smtp_extract_address($to);

        smtp_command($stream, "MAIL FROM:<$fromAddr>", 250);
        smtp_command($stream, "RCPT TO:<$toAddr>", 250);
        smtp_command($stream, 'DATA', 354);

        $boundary = 'boundary-' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_encode_header($from),
            'To: ' . smtp_encode_header($to),
            'Subject: ' . smtp_encode_header($subject),
            'MIME-Version: 1.0',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . smtp_local_hostname() . '>',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . smtp_dot_stuff($text) . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . smtp_dot_stuff($html) . "\r\n"
            . "--$boundary--\r\n";

        smtp_write($stream, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
        smtp_expect($stream, 250);
        smtp_command($stream, 'QUIT', 221);
    } finally {
        fclose($stream);
    }
}

function smtp_local_hostname(): string
{
    return $_SERVER['SERVER_NAME'] ?? 'localhost';
}

// Naive but sufficient "Name <addr>" -> "addr" extraction for envelope
// commands, which must be a bare address with no display name.
function smtp_extract_address(string $addr): string
{
    if (preg_match('/<([^>]+)>/', $addr, $m)) {
        return trim($m[1]);
    }
    return trim($addr);
}

function smtp_encode_header(string $value): string
{
    // Only MIME-encode if there's anything outside printable ASCII;
    // otherwise leave it alone for readability in mail logs.
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

// Per RFC 5321 §4.5.2: any line starting with "." in the message body
// must have an extra "." prepended, so it isn't mistaken for the
// end-of-data marker.
function smtp_dot_stuff(string $text): string
{
    $lines = preg_split('/\r\n|\n|\r/', $text);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }
    return implode("\r\n", $lines);
}

function smtp_write($stream, string $data): void
{
    fwrite($stream, $data . "\r\n");
}

function smtp_read_response($stream): array
{
    $lines = [];
    while (!feof($stream)) {
        $line = fgets($stream, 1024);
        if ($line === false) {
            break;
        }
        $lines[] = $line;
        // Multiline responses use "250-" for continuation, "250 " for the
        // final line of that response block.
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    if (!$lines) {
        throw new SmtpException('No response from SMTP server (connection may have dropped).');
    }
    $code = (int) substr($lines[0], 0, 3);
    return [$code, implode('', $lines)];
}

function smtp_expect($stream, int $expectedCode): string
{
    [$code, $text] = smtp_read_response($stream);
    if ($code !== $expectedCode) {
        throw new SmtpException("Expected SMTP $expectedCode, got: " . trim($text));
    }
    return $text;
}

function smtp_command($stream, string $command, int $expectedCode): string
{
    smtp_write($stream, $command);
    return smtp_expect($stream, $expectedCode);
}
