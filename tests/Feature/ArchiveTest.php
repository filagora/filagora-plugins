<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\ReleaseRefusal;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Release\ZipArchiveInspector;
use Filagora\Plugins\Release\ZipSeparatorRepair;
use Filagora\Plugins\Tests\Support\Zips;

function refusalFor(string $path): ?ReleaseRefusal
{
    try {
        (new ZipArchiveInspector)->assertSafe($path);
    } catch (ReleaseRefused $e) {
        return $e->reason;
    }

    return null;
}

it('lets an ordinary plugin archive through', function () {
    expect(refusalFor(Zips::make(['widget/a.php' => '<?php', 'widget/lang/en.php' => '<?php'])->getPathname()))->toBeNull();
});

it('refuses a file that is not a ZIP', function () {
    $path = tempnam(sys_get_temp_dir(), 'plugin-not-zip-');
    file_put_contents($path, 'plain text');

    expect(refusalFor($path))->toBe(ReleaseRefusal::NOT_AN_ARCHIVE)
        ->and(refusalFor('/nonexistent/file.zip'))->toBe(ReleaseRefusal::NOT_AN_ARCHIVE);
});

it('refuses an entry that would escape the extraction folder', function (string $entry) {
    expect(refusalFor(Zips::make([$entry => 'x'])->getPathname()))->toBe(ReleaseRefusal::UNSAFE_PATH);
})->with(['../evil.php', 'widget/../../evil.php', '/etc/passwd', 'C:/evil.php', 'widget\\..\\evil.php']);

it('refuses a path with a space in it', function () {
    expect(refusalFor(Zips::make(['my plugin/a.php' => 'x'])->getPathname()))->toBe(ReleaseRefusal::WHITESPACE_IN_PATH);
});

it('refuses an archive with too many files', function () {
    config(['filagora-plugins.archive.max_entries' => 2]);

    expect(refusalFor(Zips::make(['a' => '1', 'b' => '2', 'c' => '3'])->getPathname()))->toBe(ReleaseRefusal::TOO_MANY_FILES)
        ->and(refusalFor(Zips::make(['a' => '1', 'b' => '2'])->getPathname()))->toBeNull();
});

it('refuses an archive that expands past the size limit', function () {
    config(['filagora-plugins.archive.max_uncompressed_bytes' => 100]);

    expect(refusalFor(Zips::make(['a' => str_repeat('x', 101)])->getPathname()))->toBe(ReleaseRefusal::TOO_LARGE)
        ->and(refusalFor(Zips::make(['a' => str_repeat('x', 100)])->getPathname()))->toBeNull();
});

it('puts the offending path in the message', function () {
    try {
        (new ZipArchiveInspector)->assertSafe(Zips::make(['../evil.php' => 'x'])->getPathname());
    } catch (ReleaseRefused $e) {
        expect($e->getMessage())->toContain('../evil.php')->and($e->field())->toBe('zip_file');
    }
});

it('mends backslash path separators and keeps the contents', function () {
    $path = Zips::make(['widget\\lang\\en.php' => 'english', 'widget\\a.php' => 'a'])->getPathname();
    $repair = new ZipSeparatorRepair;

    expect($repair->findBackslashEntries($path))->toHaveCount(2);

    $result = $repair->repair($path);
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);

    expect($result['renamed'])->toBe(2)
        ->and($repair->findBackslashEntries($path))->toBe([])
        ->and($zip->getFromName('widget/lang/en.php'))->toBe('english');
});

it('leaves an empty or missing file alone', function () {
    $empty = tempnam(sys_get_temp_dir(), 'plugin-empty-');
    $repair = new ZipSeparatorRepair;

    expect($repair->repair($empty)['opened'])->toBeFalse()
        ->and(is_file($empty))->toBeTrue()
        ->and($repair->repair('/nonexistent.zip')['opened'])->toBeFalse();
});
