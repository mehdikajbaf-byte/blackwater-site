<?php
header('Content-Type: application/json; charset=utf-8');

function respond_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function read_smtp_config(): array
{
    $configFile = __DIR__ . '/smtp-config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
        if (is_array($config)) {
            return $config;
        }
    }

    return [
        'host' => getenv('BW_SMTP_HOST') ?: 'mail.blackwaterpumping.ca',
        'port' => (int) (getenv('BW_SMTP_PORT') ?: 465),
        'encryption' => getenv('BW_SMTP_ENCRYPTION') ?: 'ssl',
        'username' => getenv('BW_SMTP_USERNAME') ?: '',
        'password' => getenv('BW_SMTP_PASSWORD') ?: '',
        'from_email' => getenv('BW_SMTP_FROM_EMAIL') ?: '',
        'from_name' => getenv('BW_SMTP_FROM_NAME') ?: 'Blackwater Website',
        'to_email' => getenv('BW_SMTP_TO_EMAIL') ?: 'mehdi.kajbaf@gmail.com',
        'reply_to' => getenv('BW_SMTP_REPLY_TO') ?: '',
    ];
}

function smtp_command($socket, string $command, ?int $expectedCode = null): string
{
    fwrite($socket, $command . "\r\n");
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($expectedCode !== null && (int) substr($response, 0, 3) !== $expectedCode) {
        throw new RuntimeException(trim($response) ?: 'SMTP command failed.');
    }

    return $response;
}

function smtp_send_data($socket, string $message): void
{
    $message = preg_replace("/(?<!\r)\n/", "\r\n", $message);
    $message = preg_replace("/\r(?!\n)/", "\r\n", $message);
    $message = preg_replace('/^\./m', '..', $message);

    fwrite($socket, $message . "\r\n.\r\n");

    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ((int) substr($response, 0, 3) !== 250) {
        throw new RuntimeException(trim($response) ?: 'SMTP message send failed.');
    }
}

function connect_smtp_server(string $host, int $port, string $encryption)
{
    $transport = $encryption === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $context = stream_context_create([
        'ssl' => [
            'peer_name' => $host,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'capture_peer_cert' => false,
        ],
    ]);
    $socket = stream_socket_client(
        $transport,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket) {
        return $socket;
    }

    return [null, $errstr ?: 'Unable to connect to SMTP server.'];
}

function send_smtp_mail(array $config, string $subject, string $body, array $headers): void
{
    if (empty($config['username']) || empty($config['password']) || empty($config['from_email'])) {
        throw new RuntimeException('SMTP credentials are missing.');
    }

    $host = $config['host'] ?: 'mail.blackwaterpumping.ca';
    $port = (int) ($config['port'] ?: 465);
    $encryption = strtolower((string) ($config['encryption'] ?? 'ssl'));

    $socket = connect_smtp_server($host, $port, $encryption);
    if (is_array($socket)) {
        throw new RuntimeException($socket[1] ?: 'Unable to connect to SMTP server.');
    }

    try {
        stream_set_timeout($socket, 20);

        $greeting = '';
        while (($line = fgets($socket, 515)) !== false) {
            $greeting .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ((int) substr($greeting, 0, 3) !== 220) {
            throw new RuntimeException(trim($greeting) ?: 'SMTP server did not send a greeting.');
        }

        smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', 220);

            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to start TLS.');
            }

            smtp_command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        }
        smtp_command($socket, 'AUTH LOGIN', 334);
        smtp_command($socket, base64_encode($config['username']), 334);
        smtp_command($socket, base64_encode($config['password']), 235);

        $messageHeaders = $headers;
        $messageHeaders[] = 'To: ' . $config['to_email'];
        $messageHeaders[] = 'Subject: ' . $subject;
        $messageHeaders[] = 'MIME-Version: 1.0';
        $messageHeaders[] = 'Content-Type: text/plain; charset=UTF-8';

        $message = implode("\r\n", $messageHeaders) . "\r\n\r\n" . $body;

        smtp_command($socket, 'MAIL FROM: <' . $config['from_email'] . '>', 250);
        smtp_command($socket, 'RCPT TO: <' . $config['to_email'] . '>', 250);
        smtp_command($socket, 'DATA', 354);
        smtp_send_data($socket, $message);
        smtp_command($socket, 'QUIT', 221);
    } finally {
        fclose($socket);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

$name = trim($_POST['name'] ?? '');
$contact = trim($_POST['contact'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $contact === '' || $message === '') {
    respond_json(400, ['ok' => false, 'message' => 'Please fill out all fields.']);
}

$config = read_smtp_config();

$subject = 'New Blackwater quote request';
$body =
    "Name: {$name}\n" .
    "Email or phone: {$contact}\n\n" .
    "Service details:\n{$message}\n";

$headers = [
    'From: ' . ($config['from_name'] ?: 'Blackwater Website') . ' <' . ($config['from_email'] ?: $config['username']) . '>',
];

if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
    $headers[] = 'Reply-To: ' . $contact;
} elseif (!empty($config['reply_to'])) {
    $headers[] = 'Reply-To: ' . $config['reply_to'];
}

try {
    send_smtp_mail($config, $subject, $body, $headers);
    respond_json(200, ['ok' => true]);
} catch (Throwable $e) {
    respond_json(500, ['ok' => false, 'message' => $e->getMessage()]);
}
