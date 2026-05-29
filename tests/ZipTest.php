<?php

declare(strict_types=1);

use GNfsys\Helpers\Zip;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Zip::class)]
class ZipTest extends TestCase
{
    private string $tempDir;
    private string $sourceDir;
    private string $zipPath;

    protected function setUp(): void
    {
        $this->tempDir   = sys_get_temp_dir() . '/zip_test_' . uniqid();
        $this->sourceDir = $this->tempDir . '/source';
        $this->zipPath   = $this->tempDir . '/out.zip';

        mkdir($this->sourceDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -- helpers --

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $handle = opendir($path);

        if ($handle === false) {
            return;
        }

        while (false !== $entry = readdir($handle)) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        closedir($handle);
        rmdir($path);
    }

    /**
     * @return array<string>
     */
    private function zipEntries(string $zipPath): array
    {
        $archive = new ZipArchive();
        $archive->open($zipPath);

        $entries = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entry = $archive->getNameIndex($i);

            if ($entry !== false) {
                $entries[] = $entry;
            }
        }

        $archive->close();

        sort($entries);

        return $entries;
    }

    private function zipEntryContent(string $zipPath, string $entry): ?string
    {
        $archive  = new ZipArchive();
        $archive->open($zipPath);
        $contents = $archive->getFromName($entry);
        $archive->close();

        if ($contents === false) {
            return null;
        }

        return $contents;
    }

    // -- tests --

    public function test_zips_single_file(): void
    {
        file_put_contents($this->sourceDir . '/file.txt', 'hello');

        $result  = Zip::zipDirectory($this->sourceDir, $this->zipPath);
        $entries = $this->zipEntries($this->zipPath);

        self::assertTrue($result);
        self::assertContains('source/', $entries);
        self::assertContains('source/file.txt', $entries);
    }

    public function test_file_content_is_preserved(): void
    {
        file_put_contents($this->sourceDir . '/data.txt', 'expected content');

        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        self::assertSame('expected content', $this->zipEntryContent($this->zipPath, 'source/data.txt'));
    }

    public function test_zips_nested_structure(): void
    {
        mkdir($this->sourceDir . '/sub', 0755);
        file_put_contents($this->sourceDir . '/root.txt', '');
        file_put_contents($this->sourceDir . '/sub/nested.txt', '');

        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('source/', $entries);
        self::assertContains('source/root.txt', $entries);
        self::assertContains('source/sub/', $entries);
        self::assertContains('source/sub/nested.txt', $entries);
    }

    public function test_zips_deeply_nested_structure(): void
    {
        mkdir($this->sourceDir . '/a/b/c', 0755, true);
        file_put_contents($this->sourceDir . '/a/b/c/deep.txt', 'deep');

        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('source/a/', $entries);
        self::assertContains('source/a/b/', $entries);
        self::assertContains('source/a/b/c/', $entries);
        self::assertContains('source/a/b/c/deep.txt', $entries);
    }

    public function test_zips_empty_directory(): void
    {
        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        $entries = $this->zipEntries($this->zipPath);

        self::assertSame(['source/'], $entries);
    }

    public function test_empty_subdirectory_is_included(): void
    {
        mkdir($this->sourceDir . '/empty', 0755);

        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        self::assertContains('source/empty/', $this->zipEntries($this->zipPath));
    }

    public function test_returns_false_for_invalid_destination(): void
    {
        $result = Zip::zipDirectory($this->sourceDir, '/no/such/path/out.zip');

        self::assertFalse($result);
    }

    public function test_does_not_overwrite_by_default(): void
    {
        file_put_contents($this->sourceDir . '/file.txt', '');
        $first = Zip::zipDirectory($this->sourceDir, $this->zipPath);
        self::assertTrue($first);

        // Second call without overwrite -- ZipArchive::CREATE on existing file
        // still succeeds (appends), so just assert no exception is thrown
        // and the zip remains valid.
        $result = Zip::zipDirectory($this->sourceDir, $this->zipPath);
        self::assertFalse($result);
    }

    public function test_overwrites_existing_zip(): void
    {
        file_put_contents($this->sourceDir . '/old.txt', '');
        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        unlink($this->sourceDir . '/old.txt');
        file_put_contents($this->sourceDir . '/new.txt', '');
        Zip::zipDirectory($this->sourceDir, $this->zipPath, overwrite: true);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('source/new.txt', $entries);
        self::assertNotContains('source/old.txt', $entries);
    }

    public function test_root_entry_uses_source_basename(): void
    {
        $namedDir = $this->tempDir . '/my_folder';
        mkdir($namedDir);

        Zip::zipDirectory($namedDir, $this->zipPath);

        self::assertContains('my_folder/', $this->zipEntries($this->zipPath));
    }

    public function test_multiple_files_at_root(): void
    {
        file_put_contents($this->sourceDir . '/a.txt', '');
        file_put_contents($this->sourceDir . '/b.txt', '');
        file_put_contents($this->sourceDir . '/c.txt', '');

        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('source/a.txt', $entries);
        self::assertContains('source/b.txt', $entries);
        self::assertContains('source/c.txt', $entries);
    }

    public function test_files_at_root_when_subdirectory_disabled(): void
    {
        file_put_contents($this->sourceDir . '/file.txt', '');

        Zip::zipDirectory($this->sourceDir, $this->zipPath, inSubDirectory: false);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('file.txt', $entries);
        self::assertNotContains('source/', $entries);
        self::assertNotContains('source/file.txt', $entries);
    }

    public function test_nested_structure_without_subdirectory(): void
    {
        mkdir($this->sourceDir . '/sub', 0755);
        file_put_contents($this->sourceDir . '/root.txt', '');
        file_put_contents($this->sourceDir . '/sub/nested.txt', '');

        Zip::zipDirectory($this->sourceDir, $this->zipPath, inSubDirectory: false);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('root.txt', $entries);
        self::assertContains('sub/', $entries);
        self::assertContains('sub/nested.txt', $entries);
        self::assertNotContains('source/', $entries);
    }

    public function test_no_leading_slash_in_entry_paths_without_subdirectory(): void
    {
        file_put_contents($this->sourceDir . '/file.txt', '');

        Zip::zipDirectory($this->sourceDir, $this->zipPath, inSubDirectory: false);

        foreach ($this->zipEntries($this->zipPath) as $entry) {
            self::assertStringStartsNotWith('/', $entry);
        }
    }

    public function test_subdirectory_enabled_by_default(): void
    {
        file_put_contents($this->sourceDir . '/file.txt', '');

        Zip::zipDirectory($this->sourceDir, $this->zipPath);

        $entries = $this->zipEntries($this->zipPath);

        self::assertContains('source/', $entries);
        self::assertContains('source/file.txt', $entries);
    }
}
