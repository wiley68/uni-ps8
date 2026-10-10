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

function expectPackageFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        return;
    }
    throw new RuntimeException($message);
}

/** Run fixture tooling without exposing command output on failure. */
function runPackageFixture(array $command, string $cwd): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);
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
$before = [];
foreach (array_merge($source, [
    'composer.lock' => $root . '/composer.lock',
    'vendor/composer/autoload_classmap.php' => $root . '/vendor/composer/autoload_classmap.php',
    'vendor/composer/autoload_psr4.php' => $root . '/vendor/composer/autoload_psr4.php',
]) as $relative => $absolute) {
    $before[$relative] = hash_file('sha256', $absolute);
}

// A private disposable checkout uses existing Git objects read-only. No commits
// are made, and neither the live checkout nor its Git index is mutated.
$testRoot = sys_get_temp_dir() . '/unipayment-package-test-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700);
$originalEpoch = getenv('SOURCE_DATE_EPOCH');
putenv('SOURCE_DATE_EPOCH=1700000000');
try {
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
    writePackageFixture($fixture, '.git/HEAD', trim(runPackageFixture(['git', 'rev-parse', 'HEAD'], $root)) . "\n");
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
    writePackageFixture($fixture, 'src/PackagingRuntimeFixture.php', '<?php // new, untracked runtime source');
    writePackageFixture($fixture, 'vendor/source-marker.txt', 'source vendor must remain untouched');
    $lockBefore = hash_file('sha256', $fixture . '/composer.lock');
    $builder = new DistributionPackage($fixture);
    $package = $builder->build();
    $firstHash = hash_file('sha256', $package);
    $builder->build();
    assertPackage(hash_file('sha256', $package) === $firstHash, 'Identical inputs produce identical ZIP bytes');
    assertPackage(hash_file('sha256', $fixture . '/composer.lock') === $lockBefore, 'Source lockfile unchanged');
    assertPackage(file_get_contents($fixture . '/vendor/source-marker.txt') === 'source vendor must remain untouched', 'Source vendor unchanged');
    assertPackage(!is_file($fixture . '/vendor/autoload.php'), 'Composer installs only in staging');
    assertPackage(glob($fixture . '/dist/.build-*') === [], 'Build stages cleaned');
    assertPackage(is_file($fixture . '/dist/.htaccess'), 'Local artifact directory protected');

    $zip = new ZipArchive();
    assertPackage($zip->open($package) === true, 'ZIP opens');
    $manifest = json_decode((string) $zip->getFromName('unipayment/package-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
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
    $extracted = $testRoot . '/extracted';
    mkdir($extracted, 0700);
    assertPackage($zip->extractTo($extracted), 'Verified ZIP extracts');
    $zip->close();
    runPackageFixture([PHP_BINARY, '-r',
        'require "vendor/autoload.php"; if (!class_exists("PrestaShop\\\\Module\\\\Unipayment\\\\Security\\\\SystemClock")'
        . ' || class_exists("PackagingDevFixture\\\\Marker")'
        . ' || class_exists("PrestaShop\\\\Module\\\\Unipayment\\\\Tests\\\\Support\\\\TestSuiteGuard")) { exit(1); }',
    ], $extracted . '/unipayment');

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
    expectPackageFailure(static function () use ($builder): void {
        $builder->build();
    }, 'Build accepted inconsistent module XML version');
    assertPackage(glob($fixture . '/dist/.build-*') === [], 'Failed verification cleans populated staging tree');
    assertPackage(hash_file('sha256', $package) === $firstHash, 'Failed verification preserves published ZIP');
    writePackageFixture($fixture, 'config.xml', $xmlBefore);
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
    $futureHash = hash_file('sha256', $package);
    $builder->build();
    assertPackage(hash_file('sha256', $package) === $futureHash, 'Changed destination build is reproducible');
    putenv('SOURCE_DATE_EPOCH=invalid');
    expectPackageFailure(static function () use ($builder): void {
        $builder->build();
    }, 'Invalid build timestamp accepted');
    assertPackage(glob($fixture . '/dist/.build-*') === [], 'Failed build stages cleaned');
} finally {
    if ($originalEpoch === false) {
        putenv('SOURCE_DATE_EPOCH');
    } else {
        putenv('SOURCE_DATE_EPOCH=' . $originalEpoch);
    }
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
    assertPackage(hash_file('sha256', $root . '/' . $relative) === $hash, 'Live source tree unchanged: ' . $relative);
}

fwrite(STDOUT, "OK (isolated repeatable PS8 ZIP, production dependency/autoload, source parity, 20 archive rejection cases and source guards)\n");
