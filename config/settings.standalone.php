<?php

declare(strict_types=1);

$requiredEnvironment = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: $name");
    }

    return $value;
};

$trustedBrowserUrl = static function (string $name): ?string {
    $value = trim((string) (getenv($name) ?: ''));
    if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
        return null;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return null;
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower(rtrim((string) $parts['host'], '.'));
    $port = $parts['port'] ?? null;
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }

    if ($scheme === 'https') {
        return $value;
    }

    $allowLocalHttp = filter_var(
        getenv('DAVYRO_ALLOW_INSECURE_LOCAL_LINKS') ?: 'false',
        FILTER_VALIDATE_BOOLEAN
    );

    if ($scheme === 'http' && $allowLocalHttp && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return $value;
    }

    return null;
};

$demoCredentials = null;
$demoUserEnabled = getenv('AGENDAV_ENVIRONMENT') === 'dev'
    && filter_var(getenv('DAVYRO_CREATE_DEMO_USER') ?: 'false', FILTER_VALIDATE_BOOLEAN) === true;

if ($demoUserEnabled) {
    $demoUsernameValue = getenv('DAVYRO_DEMO_USERNAME');
    $demoPasswordValue = getenv('DAVYRO_DEMO_PASSWORD');
    $demoUsername = $demoUsernameValue === false ? '' : (string) $demoUsernameValue;
    $demoPassword = $demoPasswordValue === false ? '' : (string) $demoPasswordValue;

    $isSafeDemoValue = static function (string $value, int $maximumLength): bool {
        return $value !== ''
            && strlen($value) <= $maximumLength
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    };

    if (
        $demoUsername === trim($demoUsername)
        && $isSafeDemoValue($demoUsername, 320)
        && $isSafeDemoValue($demoPassword, 1024)
    ) {
        $demoCredentials = [
            'username' => $demoUsername,
            'password' => $demoPassword,
        ];
    }
}

return [
    'site.title' => 'Davyro Kalender',
    'site.footer' => 'Davyro Kalender · basierend auf AgenDAV',
    'db.options' => [
        'driver' => 'pdo_mysql',
        'host' => $requiredEnvironment('AGENDAV_DB_HOST'),
        'dbname' => $requiredEnvironment('AGENDAV_DB_NAME'),
        'user' => $requiredEnvironment('AGENDAV_DB_USER'),
        'password' => $requiredEnvironment('AGENDAV_DB_PASSWORD'),
        'charset' => 'utf8mb4',
    ],
    'csrf.secret' => $requiredEnvironment('AGENDAV_CSRF_SECRET'),
    'session.encryption.key' => $requiredEnvironment('AGENDAV_SESSION_KEY'),
    'caldav.baseurl' => $requiredEnvironment('BAIKAL_INTERNAL_BASE_URL'),
    'caldav.authmethod' => 'basic',
    'caldav.publicurls' => false,
    'caldav.baseurl.public' => '',
    'caldav.connect.timeout' => 5,
    'caldav.response.timeout' => 15,
    'caldav.certificate.verify' => true,
    'calendar.sharing' => false,
    // Remains disabled until the SSRF-safe fetcher and cache issues are done.
    'calendar.subscriptions' => false,
    'defaults.timezone' => 'Europe/Berlin',
    'defaults.language' => 'de_DE',
    'defaults.time_format' => '24',
    'defaults.date_format' => 'dmy',
    'defaults.weekstart' => 1,
    'defaults.default_view' => 'week',
    'defaults.show_week_nb' => true,
    'log.file' => 'php://stdout',
    'log.level' => 'INFO',
    'davyro.calendar_url' => $trustedBrowserUrl('DAVYRO_CALENDAR_URL'),
    'davyro.mail_url' => $trustedBrowserUrl('DAVYRO_MAIL_URL'),
    'davyro.hub_url' => $trustedBrowserUrl('DAVYRO_HUB_URL'),
    'davyro.demo_credentials' => $demoCredentials,
];
