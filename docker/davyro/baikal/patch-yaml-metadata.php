<?php
// The Baikal release omits composer.json/lock. Replace only its embedded YAML
// package and preserve the complete upstream dependency/autoload inventory.
$base = '/var/www/baikal/vendor/composer/';
$patchedVersion = 'v7.4.23';
$patchedReference = '1e601207ffe627a2e621faa9d661f1d32dcf1cec';
$current = json_decode(file_get_contents($base.'installed.json'), true, flags: JSON_THROW_ON_ERROR);
$replacement = json_decode(file_get_contents('/tmp/yaml-installed.json'), true, flags: JSON_THROW_ON_ERROR);
$package = array_values(array_filter($replacement['packages'], fn ($p) => $p['name'] === 'symfony/yaml'));
if (count($package) !== 1 || $package[0]['version'] !== $patchedVersion
    || $package[0]['source']['reference'] !== $patchedReference) {
    throw new RuntimeException('Unexpected replacement YAML package');
}
$found = 0;
foreach ($current['packages'] as &$entry) {
    if ($entry['name'] !== 'symfony/yaml') {
        continue;
    }
    if ($entry['version'] !== 'v7.4.15') {
        throw new RuntimeException('Unexpected upstream YAML version');
    }
    $oldReference = $entry['source']['reference'];
    $entry = $package[0];
    ++$found;
}
unset($entry);
if ($found !== 1) {
    throw new RuntimeException('Missing/duplicate upstream YAML package');
}
file_put_contents($base.'installed.json', json_encode($current, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
$php = file_get_contents($base.'installed.php');
$pattern = "/('symfony\\/yaml' => array\\(.*?\\n        \\),)/s";
$php = preg_replace_callback($pattern, function ($m) use ($oldReference, $patchedVersion, $patchedReference) {
    if (!str_contains($m[0], "'pretty_version' => 'v7.4.15'")) {
        throw new RuntimeException('Unexpected installed.php YAML entry');
    }
    return str_replace(
        ['v7.4.15', '7.4.15.0', $oldReference],
        [$patchedVersion, substr($patchedVersion, 1).'.0', $patchedReference],
        $m[0],
    );
}, $php, count: $count);
if ($count !== 1) {
    throw new RuntimeException('Could not update installed.php YAML inventory');
}
file_put_contents($base.'installed.php', $php);
require '/var/www/baikal/vendor/autoload.php';
if (Composer\InstalledVersions::getPrettyVersion('symfony/yaml') !== $patchedVersion
    || Symfony\Component\Yaml\Yaml::parse('patched: true') !== ['patched' => true]) {
    throw new RuntimeException('Patched YAML runtime verification failed');
}
