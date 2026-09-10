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

$subscriptionDomains = array_values(array_filter(array_map(
    static fn (string $domain): string => strtolower(trim($domain)),
    explode(',', (string) (getenv('CALENDAR_SUBSCRIPTION_ALLOWED_DOMAINS') ?: ''))
)));

return [
    'app.base_path' => rtrim((string) (getenv('AGENDAV_BASE_PATH') ?: ''), '/'),
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
    'calendar.sharing' => true,
    'calendar.subscriptions' => true,
    'calendar.subscriptions.allowed_domains' => $subscriptionDomains,
    'calendar.subscriptions.connect_timeout' => 5,
    'calendar.subscriptions.timeout' => 10,
    'calendar.subscriptions.cache_ttl' => max(30, min(3600, (int) (getenv('CALENDAR_SUBSCRIPTION_CACHE_TTL') ?: 900))),
    'calendar.subscriptions.max_bytes' => max(65536, min(10485760, (int) (getenv('CALENDAR_SUBSCRIPTION_MAX_BYTES') ?: 2097152))),
    'calendar.subscriptions.max_redirects' => max(0, min(5, (int) (getenv('CALENDAR_SUBSCRIPTION_MAX_REDIRECTS') ?: 3))),
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
    'davyro.mail_internal_url' => $requiredEnvironment('DAVYRO_MAIL_INTERNAL_URL'),
    'davyro.bridge_shared_secret' => $requiredEnvironment('MAIL_BRIDGE_SHARED_SECRET'),
    'davyro.principal_secret' => $requiredEnvironment('CALENDAR_PRINCIPAL_SECRET'),
    'davyro.baikal_db' => [
        'host' => $requiredEnvironment('BAIKAL_DB_HOST'),
        'name' => $requiredEnvironment('BAIKAL_DB_NAME'),
        'user' => $requiredEnvironment('BAIKAL_DB_USER'),
        'password' => $requiredEnvironment('BAIKAL_DB_PASSWORD'),
    ],
];
