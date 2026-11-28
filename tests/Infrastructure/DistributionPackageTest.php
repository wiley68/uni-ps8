<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/tools/DistributionPackage.php';

use PrestaShop\Module\Unipayment\Build\DistributionPackage;

function assertPackage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectPackageFailure(callable $operation, string $message, ?string $expectedError = null): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        if ($expectedError !== null && strpos($exception->getMessage(), $expectedError) !== 0) {
            throw $exception;
        }
        return;
    }
    throw new RuntimeException($message);
}

/** Run fixture tooling without exposing command output on failure. */
function runPackageFixture(array $command, string $cwd, ?array $environment = null): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    assertPackage(is_resource($process), 'Fixture process starts');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    assertPackage(proc_close($process) === 0, 'Fixture tooling succeeds: ' . $command[0]);

    return (string) $output;
}

function writePackageFixture(string $root, string $path, string $bytes): void
{
    if (!is_dir(dirname($root . '/' . $path))) {
        mkdir(dirname($root . '/' . $path), 0700, true);
    }
    assertPackage(file_put_contents($root . '/' . $path, $bytes) === strlen($bytes), 'Fixture write');
}

function setPackageFixtureEnv(string $name, $value): void
{
    if (!putenv($value === false ? $name : $name . '=' . $value)) {
        throw new RuntimeException('Could not set fixture environment: ' . $name);
    }
}

/** Inspect the classic ZIP headers emitted for this small, file-only fixture. */
function inspectPackageFixture(string $path, int $start, int $end): array
{
    $zip = new ZipArchive();
    assertPackage($zip->open($path) === true, 'Open archive for raw metadata comparison');
    try {
        $manifest = json_decode($zip->getFromName('unipayment/package-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $epoch = $manifest['source_date_epoch'];
        assertPackage(is_int($epoch) && $epoch % 2 === 0 && $epoch >= intdiv($start, 2) * 2
            && $epoch <= intdiv($end, 2) * 2, 'Manifest epoch is a current even second in the measured build interval');
        $dosTime = (int) gmdate('G', $epoch) * 2048 + (int) gmdate('i', $epoch) * 32 + intdiv((int) gmdate('s', $epoch), 2);
        $dosDate = ((int) gmdate('Y', $epoch) - 1980) * 512 + (int) gmdate('n', $epoch) * 32 + (int) gmdate('j', $epoch);
        $dos = pack('vv', $dosTime, $dosDate);
        $raw = file_get_contents($path);
        $endOffset = strlen($raw) - 22;
        $endRecord = unpack('a4signature/x6/vcount/Vsize/Voffset/vcommentLength', $raw, $endOffset);
        assertPackage($endRecord['signature'] === "PK\x05\x06" && $endRecord['count'] === $zip->numFiles
            && $endRecord['commentLength'] === 0 && $endRecord['offset'] + $endRecord['size'] === $endOffset,
            'Classic ZIP end record describes the complete central directory');

        $offset = $endRecord['offset'];
        $hashes = [];
        $names = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $central = unpack('a4signature/x4/vflags/vmethod/a4dos/Vcrc/x8/vname/vextra/vcomment/x4/Vattributes/Vlocal', $raw, $offset);
            $local = unpack('a4signature/x2/vflags/vmethod/a4dos/Vcrc/x8/vname/vextra', $raw, $central['local']);
            $name = $zip->getNameIndex($index);
            assertPackage($central['signature'] === "PK\x01\x02" && $local['signature'] === "PK\x03\x04"
                && substr($raw, $offset + 46, $central['name']) === $name
                && substr($raw, $central['local'] + 30, $local['name']) === $name,
                'Local and central headers identify the same entry: ' . $name);
            assertPackage($central['dos'] === $dos && $local['dos'] === $dos, 'Both DOS fields match the UTC manifest epoch: ' . $name);
            assertPackage(($central['flags'] & 8) === 0 && $local['flags'] === $central['flags']
                && $central['method'] === ZipArchive::CM_DEFLATE && $local['method'] === $central['method']
                && ($central['attributes'] >> 16) === 0100644, 'ZIP flags, compression and file mode: ' . $name);
            $bytes = $zip->getFromIndex($index);
            assertPackage(is_string($bytes) && $central['crc'] === $local['crc']
                && sprintf('%08x', $central['crc']) === hash('crc32b', $bytes),
                'Calculated CRC-32 and both ZIP header CRCs match: ' . $name);
            $names[] = $name;
            $relative = substr($name, strlen('unipayment/'));
            if ($relative !== 'package-manifest.json') {
                $hashes[$relative] = hash('sha256', $bytes);
            }
            $offset += 46 + $central['name'] + $central['extra'] + $central['comment'];
        }
        assertPackage($offset === $endOffset && $hashes === $manifest['files'], 'Ordered manifest hashes match the independent inventory');

        return [$manifest, $names];
    } finally {
        $zip->close();
    }
}

function buildPackageFixture(DistributionPackage $builder, $timezone): array
{
    setPackageFixtureEnv('TZ', $timezone);
    $start = time();
    $path = $builder->build();
    $end = time();
    assertPackage(getenv('TZ') === $timezone && date_default_timezone_get() === 'Pacific/Auckland',
        'Successful build preserves the exact process and PHP timezone states');
    assertPackage((fileperms($path) & 0777) === 0600, 'Published fixture ZIP has mode 0600');
    [$manifest, $names] = inspectPackageFixture($path, $start, $end);

    return [$path, $manifest, $names];
}

function waitForPackageEpoch(int $minimum): void
{
    $deadline = hrtime(true) + 5_000_000_000;
    while (time() < $minimum && hrtime(true) < $deadline) {
        usleep(20_000);
    }
    assertPackage(time() >= $minimum, 'Reached the requested DOS interval within five seconds');
}

function extractPackageFixture(string $archive, string $directory): void
{
    mkdir($directory, 0700);
    $environment = getenv();
    unset($environment['UNZIP'], $environment['UNZIPOPT']);
    $environment['TZ'] = 'UTC';
    runPackageFixture(['unzip', '-tq', $archive], $directory, $environment);
    runPackageFixture(['unzip', '-q', $archive, '-d', $directory], $directory, $environment);

    runPackageFixture([PHP_BINARY, '-r',
        'require "vendor/autoload.php"; if (!class_exists("PrestaShop\\\\Module\\\\Unipayment\\\\Security\\\\SystemClock")'
        . ' || class_exists("PackagingDevFixture\\\\Marker")'
        . ' || class_exists("PrestaShop\\\\Module\\\\Unipayment\\\\Tests\\\\Support\\\\TestSuiteGuard")) { exit(1); }',
    ], $directory . '/unipayment');
}

/** Fault injection for the private copy of the builder used only by failure tests. */
final class PackageFailureZipArchive extends ZipArchive
{
    public static string $failure;

    #[\ReturnTypeWillChange]
    public function open(string $filename, int $flags = 0)
    {
        assertPackage(getenv('TZ') === 'UTC', 'Process timezone is UTC during ZIP open');

        return parent::open($filename, $flags);
    }

    public function setMtimeName(string $name, int $timestamp, int $flags = 0): bool
    {
        assertPackage(getenv('TZ') === 'UTC', 'Process timezone is UTC during timestamp assignment');
        if (self::$failure === 'timestamp') {
            $name = 'unipayment/nonexistent-timestamp-fixture.php';
        }

        return parent::setMtimeName($name, $timestamp, $flags);
    }

    public function close(): bool
    {
        assertPackage(getenv('TZ') === 'UTC', 'Process timezone is UTC during ZIP close');
        if (self::$failure === 'close') {
            // libzip reads addFile() sources at close. Removing one forces a native error.
            assertPackage(unlink(dirname($this->filename) . '/unipayment/unipayment.php'), 'Remove a private staged file before ZIP close');
            $closed = @parent::close();
            assertPackage($closed === false, 'Native ZIP close fails with a missing staged input');

            return $closed;
        }

        return parent::close();
    }
}

foreach ([
    '<?php // $this->version = "9.9.9";' . "\n" . '$this->version = "2.0.3";',
    '<?php $this /* comment */ -> version = \'2.0.3\';',
] as $php) {
    assertPackage(DistributionPackage::resolveVersion($php) === '2.0.3', 'Literal version parsing');
}
foreach ([
    '<?php $this->version = getenv("VERSION");',
    '<?php $this->version = "2.0.3"; $this->version = "2.0.3";',
    '<?php $this->version = "2.0." . "3";',
    '<?php // $this->version = "2.0.3";',
    '<?php $this->version = "../../private";',
] as $php) {
    expectPackageFailure(static function () use ($php): void {
        DistributionPackage::resolveVersion($php);
    }, 'Ambiguous version accepted');
}
foreach ([
    'tests/Api/Test.php', 'docs/RELEASE.md', '.git/config', '.github/workflows/build.yml',
    'dist/previous.zip', 'keys/private.pem', 'keys/.htaccess', 'secrets/smartucf-key.php',
    'config/environment.php', 'config/environment.dist.php', 'config/credentials.php',
    'var/runtime.php', 'views/cache/private.php', 'src/.env', 'src/test/Fixture.php',
    'src/Service.tmp.php', 'tools/build-distribution.php', 'vendor/autoload.php',
] as $path) {
    assertPackage(!DistributionPackage::isRuntimePath($path), 'Non-runtime path allowed: ' . $path);
}
foreach ([
    'src/Security/SystemClock.php', 'controllers/front/shopcache.php', 'views/templates/hook/cart_calculator.tpl',
    'mails/bg/ordersend.html', 'config/services.yml', 'upgrade/index.php', 'translations/index.php',
    'views/fonts/roboto-condensed/LICENSE-Roboto-Condensed.txt',
] as $path) {
    assertPackage(DistributionPackage::isRuntimePath($path), 'Runtime path excluded: ' . $path);
}

$root = dirname(__DIR__, 2);
$source = (new DistributionPackage($root))->sourceFiles();
$sourceVendorFiles = ['vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_psr4.php'];
$before = [];
foreach ($source + ['composer.lock' => $root . '/composer.lock'] as $relative => $absolute) {
    $before[$relative] = hash_file('sha256', $absolute);
}
$sourceVendorBefore = [];
foreach ($sourceVendorFiles as $relative) {
    $sourceVendorBefore[$relative] = is_file($root . '/' . $relative) ? hash_file('sha256', $root . '/' . $relative) : null;
}

// A private disposable checkout uses existing Git objects read-only. No commits
// are made, and neither the live checkout nor its Git index is mutated.
$testRoot = sys_get_temp_dir() . '/unipayment-package-test-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
$originalEpoch = getenv('SOURCE_DATE_EPOCH');
$originalTimezone = getenv('TZ');
$originalPhpTimezone = date_default_timezone_get();
try {
    setPackageFixtureEnv('SOURCE_DATE_EPOCH', '1700000000');
    date_default_timezone_set('Pacific/Auckland');
    $fixture = $testRoot . '/source';
    mkdir($fixture, 0700);
    foreach ($source as $relative => $absolute) {
        writePackageFixture($fixture, $relative, (string) file_get_contents($absolute));
    }
    writePackageFixture($fixture, 'composer.lock', (string) file_get_contents($root . '/composer.lock'));
    writePackageFixture($fixture, '.gitignore', "/dist/\n/vendor/\n/keys/\n/secrets/\n/.packaging-fixture/\n");
    runPackageFixture(['git', 'init', '--quiet'], $fixture);
    $common = trim(runPackageFixture(['git', 'rev-parse', '--git-common-dir'], $root));
    if (substr($common, 0, 1) !== '/') {
        $common = $root . '/' . $common;
    }
    writePackageFixture($fixture, '.git/objects/info/alternates', realpath($common . '/objects') . "\n");
    $sourceHead = trim(runPackageFixture(['git', 'rev-parse', 'HEAD'], $root));
    $commitEpoch = (int) trim(runPackageFixture(['git', 'show', '-s', '--format=%ct', 'HEAD'], $root));
    $commitDosEpoch = intdiv($commitEpoch, 2) * 2;
    writePackageFixture($fixture, '.git/HEAD', $sourceHead . "\n");
    runPackageFixture(['git', 'read-tree', 'HEAD'], $fixture);

    // A real locked dev-only path dependency proves --no-dev removes packages,
    // as well as the PS8 test PSR-4 mapping. Resolution stays offline.
    writePackageFixture($fixture, '.packaging-fixture/dev/composer.json', json_encode([
        'name' => 'unipayment/packaging-dev-fixture', 'version' => '1.0.0',
        'autoload' => ['psr-4' => ['PackagingDevFixture\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));
    writePackageFixture($fixture, '.packaging-fixture/dev/src/Marker.php', '<?php namespace PackagingDevFixture; class Marker {}');
    $composer = json_decode((string) file_get_contents($fixture . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $composer['require-dev']['unipayment/packaging-dev-fixture'] = '1.0.0';
    $composer['repositories'] = [
        ['type' => 'path', 'url' => $fixture . '/.packaging-fixture/dev'], ['packagist.org' => false],
    ];
    writePackageFixture($fixture, 'composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    runPackageFixture(['composer', 'update', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-audit'], $fixture);
    writePackageFixture($fixture, 'keys/synthetic-private.pem', 'synthetic excluded fixture');
    writePackageFixture($fixture, 'secrets/smartucf-key.php', '<?php // synthetic excluded fixture');
    writePackageFixture($fixture, 'config/environment.php', '<?php // unused PS9 fixture');
    writePackageFixture($fixture, 'tests/ExcludedTest.php', '<?php');
    $runtimeFixture = 'src/PackagingRuntimeFixture.php';
    $firstBytes = '<?php return "first";';
    $secondBytes = '<?php return "second";';
    $firstSourceMtime = 1009843200;
    $secondSourceMtime = 1072915200;
    writePackageFixture($fixture, $runtimeFixture, $firstBytes);
    assertPackage(touch($fixture . '/' . $runtimeFixture, $firstSourceMtime), 'Set historical mtime on the first source fixture');
    writePackageFixture($fixture, 'vendor/source-marker.txt', 'source vendor must remain untouched');
    $lockBefore = hash_file('sha256', $fixture . '/composer.lock');
    $builder = new DistributionPackage($fixture);
    waitForPackageEpoch($commitDosEpoch + 2);
    [$package, $manifest, $firstNames] = buildPackageFixture($builder, false);
    $firstEpoch = $manifest['source_date_epoch'];
    assertPackage($manifest['source_git_sha'] === $sourceHead && $manifest['source_dirty'] === true,
        'Manifest retains HEAD and records dirty fixture source');
    assertPackage(!in_array($firstEpoch, [$commitDosEpoch, $firstSourceMtime, 1700000000], true),
        'First timestamp differs from HEAD, historical source mtime and inherited epoch');
    $firstHash = hash_file('sha256', $package);
    assertPackage(hash_file('sha256', $fixture . '/composer.lock') === $lockBefore, 'Source lockfile unchanged');
    assertPackage(file_get_contents($fixture . '/vendor/source-marker.txt') === 'source vendor must remain untouched', 'Source vendor unchanged');
    assertPackage(!is_file($fixture . '/vendor/autoload.php'), 'Composer installs only in staging');
    assertPackage(glob($fixture . '/dist/.build-*') === [], 'Build stages cleaned');
    assertPackage(is_file($fixture . '/dist/.htaccess'), 'Local artifact directory protected');

    $zip = new ZipArchive();
    assertPackage($zip->open($package) === true, 'ZIP opens');
    $version = DistributionPackage::resolveVersion((string) file_get_contents($fixture . '/unipayment.php'));
    assertPackage(basename($package) === 'ps8_uni_' . $version . '.zip', 'Exact filename and version');
    assertPackage(count($manifest['files']) + 1 === $zip->numFiles, 'Complete manifest inventory');
    assertPackage($builder->verify($package) === $zip->numFiles, 'Verifier file count');
    assertPackage($zip->getFromName('unipayment/src/Api/ControlPanelDestination.php')
        === file_get_contents($fixture . '/src/Api/ControlPanelDestination.php'), 'Canonical CP destination preserved unchanged');
    foreach ($builder->sourceFiles() as $relative => $absolute) {
        assertPackage(isset($manifest['files'][$relative]), 'Runtime source included: ' . $relative);
        if ($relative !== 'composer.json') {
            assertPackage($zip->getFromName('unipayment/' . $relative) === file_get_contents($absolute), 'Exact source parity: ' . $relative);
        }
    }
    for ($index = 0; $index < $zip->numFiles; ++$index) {
        assertPackage(strpos((string) $zip->getNameIndex($index), 'unipayment/') === 0, 'Single module root');
    }
    foreach (['config/environment.php', 'secrets/smartucf-key.php', 'keys/synthetic-private.pem',
        'composer.lock', 'tests/ExcludedTest.php', 'vendor/source-marker.txt',
        'vendor/unipayment/packaging-dev-fixture/src/Marker.php'] as $excluded) {
        assertPackage($zip->locateName('unipayment/' . $excluded) === false, 'Development/deployment content excluded: ' . $excluded);
    }
    $metadata = json_decode((string) $zip->getFromName('unipayment/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    assertPackage(!isset($metadata['autoload-dev']) && !isset($metadata['require-dev']) && !isset($metadata['scripts']), 'Production Composer metadata');
    $zip->close();
    $firstExtracted = $testRoot . '/first-extracted';
    extractPackageFixture($package, $firstExtracted);
    $firstPhp = $firstExtracted . '/unipayment/' . $runtimeFixture;
    assertPackage(file_get_contents($firstPhp) === $firstBytes && filemtime($firstPhp) === $firstEpoch,
        'First extracted PHP bytes and UTC mtime match the archive');
    $nativeZip = new ZipArchive();
    assertPackage($nativeZip->open($package) === true && $nativeZip->extractTo($testRoot . '/native-extracted')
        && $nativeZip->close(), 'Verified ZIP extracts through PHP');

    runPackageFixture(['cp', '-R', $firstExtracted . '/unipayment/vendor/.', $fixture . '/vendor'], $fixture);
    $localAutoload = 'vendor/composer/autoload_psr4.php';
    writePackageFixture($fixture, $localAutoload, file_get_contents($fixture . '/' . $localAutoload) . "\n// Local fixture marker.\n");
    $fixtureVendorHashes = [];
    foreach ($manifest['files'] as $relative => $packageHash) {
        if (strpos($relative, 'vendor/') === 0) {
            $hash = hash_file('sha256', $fixture . '/' . $relative);
            assertPackage(is_string($hash) && ($relative === $localAutoload ? $hash !== $packageHash : $hash === $packageHash),
                'Generated source vendor fixture exists: ' . $relative);
            $fixtureVendorHashes[$relative] = $hash;
        }
    }

    $cases = ['missing', 'tampered', 'extra-runtime', 'dev', 'git', 'nested-dist', 'temp', 'autoload',
        'metadata', 'installed', 'manifest', 'root', 'traversal', 'symlink', 'version', 'xml', 'ps9', 'sha'];
    foreach ($cases as $case) {
        $directory = $testRoot . '/' . $case;
        mkdir($directory, 0700);
        $candidate = $directory . '/' . basename($package);
        copy($package, $candidate);
        $bad = new ZipArchive();
        assertPackage($bad->open($candidate) === true, 'Negative ZIP opens');
        $changedManifest = $manifest;
        switch ($case) {
            case 'missing':
                $bad->deleteName('unipayment/src/Security/SystemClock.php');
                unset($changedManifest['files']['src/Security/SystemClock.php']);
                break;
            case 'tampered':
            case 'extra-runtime':
                $path = $case === 'tampered' ? 'src/Security/SystemClock.php' : 'src/Unexpected.php';
                $changed = '<?php // changed';
                $bad->addFromString('unipayment/' . $path, $changed);
                $changedManifest['files'][$path] = hash('sha256', $changed);
                break;
            case 'dev': $bad->addFromString('unipayment/tests/private.php', '<?php'); break;
            case 'git': $bad->addFromString('unipayment/.git/config', ''); break;
            case 'nested-dist': $bad->addFromString('unipayment/vendor/dist/previous.zip', ''); break;
            case 'temp': $bad->addFromString('unipayment/vendor/.build-temp/file.php', '<?php'); break;
            case 'autoload': $bad->addFromString('unipayment/vendor/composer/autoload_psr4.php', "<?php // '/tests/Support'"); break;
            case 'metadata': $bad->addFromString('unipayment/composer.json', json_encode($composer)); break;
            case 'installed': $bad->addFromString('unipayment/vendor/composer/installed.json', '{"dev":true,"packages":[],"dev-package-names":[]}'); break;
            case 'manifest': $bad->deleteName('unipayment/package-manifest.json'); break;
            case 'root': $bad->addFromString('unexpected/index.php', '<?php'); break;
            case 'traversal': $bad->addFromString('unipayment/vendor/../private.php', '<?php'); break;
            case 'symlink':
                $bad->addFromString('unipayment/src/Link.php', '/tmp/target');
                $bad->setExternalAttributesName('unipayment/src/Link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
                break;
            case 'version': $bad->addFromString('unipayment/unipayment.php', '<?php $this->version = "99.99.99";'); break;
            case 'xml': $bad->addFromString('unipayment/config.xml', '<module><version>99.99.99</version></module>'); break;
            case 'ps9': $bad->addFromString('unipayment/config/environment.php', '<?php'); break;
            case 'sha': $changedManifest['source_git_sha'] = str_repeat('0', 40); break;
        }
        if (in_array($case, ['missing', 'tampered', 'extra-runtime', 'sha'], true)) {
            // Recomputed unsigned checksums must not bypass current-source parity.
            $bad->addFromString('unipayment/package-manifest.json', json_encode($changedManifest, JSON_THROW_ON_ERROR));
        }
        $bad->close();
        expectPackageFailure(static function () use ($builder, $candidate): void {
            $builder->verify($candidate);
        }, 'Invalid archive accepted: ' . $case);
    }
    $wrongName = $testRoot . '/wrong.zip';
    copy($package, $wrongName);
    expectPackageFailure(static function () use ($builder, $wrongName): void {
        $builder->verify($wrongName);
    }, 'Wrong artifact filename accepted');
    $invalid = $testRoot . '/invalid.zip';
    file_put_contents($invalid, 'invalid archive');
    expectPackageFailure(static function () use ($builder, $invalid): void {
        $builder->verify($invalid);
    }, 'Invalid ZIP accepted');

    $runtime = $fixture . '/src/Security/SystemClock.php';
    $runtimeBytes = (string) file_get_contents($runtime);
    foreach (['missing', 'unreadable', 'symlink'] as $case) {
        if ($case === 'unreadable') {
            chmod($runtime, 0000);
        } else {
            unlink($runtime);
            if ($case === 'symlink') {
                symlink($root . '/src/Security/SystemClock.php', $runtime);
            }
        }
        expectPackageFailure(static function () use ($builder): void {
            $builder->build();
        }, 'Unsafe runtime source accepted: ' . $case);
        if ($case === 'unreadable') {
            chmod($runtime, 0600);
        } else {
            if (is_link($runtime)) {
                unlink($runtime);
            }
            file_put_contents($runtime, $runtimeBytes);
        }
        assertPackage(hash_file('sha256', $package) === $firstHash, 'Failed build preserves last successful ZIP');
    }
    writePackageFixture($fixture, '.gitignore', (string) file_get_contents($fixture . '/.gitignore') . '/src/HiddenRuntime.php' . "\n");
    writePackageFixture($fixture, 'src/HiddenRuntime.php', '<?php');
    expectPackageFailure(static function () use ($builder): void {
        $builder->sourceFiles();
    }, 'Ignored runtime file silently omitted');
    unlink($fixture . '/src/HiddenRuntime.php');
    $xmlBefore = (string) file_get_contents($fixture . '/config.xml');
    writePackageFixture($fixture, 'config.xml', '<module><version>99.99.99</version></module>');
    setPackageFixtureEnv('TZ', 'Europe/Sofia');
    expectPackageFailure(static function () use ($builder): void {
        $builder->build();
    }, 'Build accepted inconsistent module XML version', 'Packaged XML version does not match module version.');
    assertPackage(getenv('TZ') === 'Europe/Sofia' && date_default_timezone_get() === 'Pacific/Auckland',
        'Post-generation verification failure preserves caller timezones');
    assertPackage(glob($fixture . '/dist/.build-*') === [], 'Failed verification cleans populated staging tree');
    assertPackage(hash_file('sha256', $package) === $firstHash, 'Failed verification preserves published ZIP');
    writePackageFixture($fixture, 'config.xml', $xmlBefore);

    $faultBuilder = str_replace(
        ['namespace PrestaShop\\Module\\Unipayment\\Build;', 'use ZipArchive;'],
        ['namespace UnipaymentPackageFaultFixture;', 'use PackageFailureZipArchive as ZipArchive;'],
        file_get_contents($root . '/tools/DistributionPackage.php'),
        $replacements
    );
    assertPackage($replacements === 2, 'Only the namespace and ZIP class import change in the private fault fixture');
    writePackageFixture($testRoot, 'FaultDistributionPackage.php', $faultBuilder);
    require $testRoot . '/FaultDistributionPackage.php';
    $failingBuilder = new UnipaymentPackageFaultFixture\DistributionPackage($fixture);
    foreach ([
        ['timestamp', false, 'ZIP entry could not be written:'],
        ['close', '', 'ZIP could not be finalized.'],
    ] as [$failure, $timezone, $expectedError]) {
        PackageFailureZipArchive::$failure = $failure;
        setPackageFixtureEnv('TZ', $timezone);
        expectPackageFailure(static function () use ($failingBuilder): void {
            $failingBuilder->build();
        }, 'Build published despite ZIP ' . $failure . ' failure', $expectedError);
        assertPackage(getenv('TZ') === $timezone && date_default_timezone_get() === 'Pacific/Auckland',
            'ZIP ' . $failure . ' failure preserves caller timezones');
        assertPackage(hash_file('sha256', $package) === $firstHash, 'ZIP ' . $failure . ' failure preserves previous ZIP bytes');
        assertPackage(glob($fixture . '/dist/.build-*') === [], 'ZIP ' . $failure . ' failure cleans staging');
    }

    $historical = $testRoot . '/historical/' . basename($package);
    mkdir(dirname($historical), 0700);
    assertPackage(copy($package, $historical) && $zip->open($historical) === true, 'Open private historical-package fixture');
    $historicalManifest = $manifest;
    $historicalManifest['source_date_epoch'] = 1700000001;
    assertPackage($zip->addFromString('unipayment/package-manifest.json', json_encode($historicalManifest, JSON_THROW_ON_ERROR)),
        'Write historical manifest with an odd epoch');
    foreach ($firstNames as $name) {
        assertPackage($zip->setMtimeName($name, 1700000001), 'Set historical timestamp for ' . $name);
    }
    assertPackage($zip->close() && $builder->verify($historical) === count($firstNames),
        'Standalone verifier still accepts the historical timestamp policy');

    writePackageFixture($fixture, $runtimeFixture, $secondBytes);
    assertPackage(touch($fixture . '/' . $runtimeFixture, $secondSourceMtime), 'Set different historical mtime on the second source fixture');
    setPackageFixtureEnv('SOURCE_DATE_EPOCH', 'invalid');
    waitForPackageEpoch($firstEpoch + 2);
    [$package, $secondManifest, $secondNames] = buildPackageFixture($builder, '');
    $secondEpoch = $secondManifest['source_date_epoch'];
    assertPackage($secondEpoch > $firstEpoch && !in_array($secondEpoch, [$commitDosEpoch, $secondSourceMtime], true),
        'Second timestamp comes from a later build interval despite the invalid override');
    assertPackage($secondNames === $firstNames, 'Successive builds have the same ordered inventory');
    $expectedManifest = $manifest;
    $expectedManifest['source_date_epoch'] = $secondEpoch;
    $expectedManifest['files'][$runtimeFixture] = hash('sha256', $secondBytes);
    assertPackage($secondManifest === $expectedManifest, 'Only the changed PHP hash and build epoch differ in the manifest');

    $secondExtracted = $testRoot . '/second-extracted';
    extractPackageFixture($package, $secondExtracted);
    $secondPhp = $secondExtracted . '/unipayment/' . $runtimeFixture;
    assertPackage(file_get_contents($fixture . '/' . $runtimeFixture) === $secondBytes
        && file_get_contents($secondPhp) === $secondBytes && filemtime($secondPhp) === $secondEpoch,
        'Second source bytes and extracted bytes match with the second build mtime');

    $placed = $testRoot . '/placed-first.php';
    runPackageFixture(['cp', '-p', $firstPhp, $placed], $testRoot);
    assertPackage(file_get_contents($placed) === $firstBytes && filemtime($placed) === $firstEpoch,
        'Timestamp-preserving placement keeps the extracted mtime and PHP bytes');
    $copied = $testRoot . '/copied-first.php';
    $copyStart = time();
    assertPackage(copy($firstPhp, $copied), 'Ordinarily copy the extracted file');
    $copiedMtime = filemtime($copied);
    assertPackage(file_get_contents($copied) === $firstBytes && $copiedMtime >= $copyStart && $copiedMtime <= time()
        && $copiedMtime !== $firstEpoch, 'Ordinary copying keeps PHP bytes and assigns a new filesystem mtime');
    fwrite(STDOUT, sprintf(
        "UTC epoch and extracted mtime A=%d B=%d; placement preserved=%d ordinary copy=%d (%d entries per archive)\n",
        $firstEpoch, $secondEpoch, filemtime($placed), $copiedMtime, count($secondNames)
    ));

    foreach (['UTC', 'Europe/Sofia', 'America/New_York'] as $timezone) {
        [, $otherManifest, $otherNames] = buildPackageFixture($builder, $timezone);
        $expectedManifest['source_date_epoch'] = $otherManifest['source_date_epoch'];
        assertPackage($otherNames === $secondNames && $otherManifest === $expectedManifest,
            'Caller timezone affects neither inventory nor manifest content beyond the build epoch: ' . $timezone);
    }

    // Changing the one deployment value must survive packaging without substitution.
    $destinationSource = (string) file_get_contents($fixture . '/src/Api/ControlPanelDestination.php');
    $futureDestination = preg_replace('/public const CONTROL_PANEL_URL = [^;]+;/',
        "public const CONTROL_PANEL_URL = 'https://future-cp.example.org';", $destinationSource, 1);
    writePackageFixture($fixture, 'src/Api/ControlPanelDestination.php', $futureDestination);
    $builder->build();
    $zip = new ZipArchive();
    assertPackage($zip->open($package) === true, 'Future destination ZIP opens');
    assertPackage($zip->getFromName('unipayment/src/Api/ControlPanelDestination.php') === $futureDestination,
        'One-value future CP deployment packaged unchanged');
    $zip->close();
    assertPackage(hash_file('sha256', $fixture . '/composer.lock') === $lockBefore, 'Source lockfile unchanged after repeated builds');
    assertPackage(file_get_contents($fixture . '/vendor/source-marker.txt') === 'source vendor must remain untouched', 'Source vendor marker preserved');
    foreach ($fixtureVendorHashes as $relative => $hash) {
        assertPackage(hash_file('sha256', $fixture . '/' . $relative) === $hash, 'Generated source vendor preserved: ' . $relative);
    }
    assertPackage(glob($fixture . '/dist/.build-*') === [], 'All build stages cleaned');
} finally {
    setPackageFixtureEnv('SOURCE_DATE_EPOCH', $originalEpoch);
    setPackageFixtureEnv('TZ', $originalTimezone);
    date_default_timezone_set($originalPhpTimezone);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            // Some negative fixtures intentionally removed read permissions.
            if (!$entry->isLink()) {
                chmod($entry->getPathname(), 0600);
            }
            unlink($entry->getPathname());
        }
    }
    rmdir($testRoot);
}
foreach ($before as $relative => $hash) {
    assertPackage(is_string($hash) && hash_file('sha256', $root . '/' . $relative) === $hash, 'Live source tree unchanged: ' . $relative);
}
foreach ($sourceVendorBefore as $relative => $hash) {
    $path = $root . '/' . $relative;
    assertPackage($hash === null ? !file_exists($path) : is_string($hash) && hash_file('sha256', $path) === $hash,
        'Live source vendor file remains absent or byte-identical: ' . $relative);
}

fwrite(STDOUT, "OK (isolated PS8 ZIP timestamps, CRC, production autoload, source parity, 20 archive rejection cases and source guards)\n");
