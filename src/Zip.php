<?php

declare(strict_types=1);

namespace GNfsys\Helpers;

use GNfsys\Helpers\Exceptions\ArchiveException;
use GNfsys\Helpers\Exceptions\IOException;
use Throwable;
use ZipArchive;

class Zip
{
    /**
     * Add files and subdirectories in a folder to a zip file.
     *
     * @throws IOException
     * @throws ArchiveException
     */
    private static function addFolderToZip(string $folder, ZipArchive $archive, string $relativePath): void
    {
        $handle = opendir($folder);

        if ($handle === false) {
            throw new IOException('Cannot open folder: ' . $folder);
        }

        try {
            while (false !== $file = readdir($handle)) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $fullPath = $folder . '/' . $file;
                $entryPath = $relativePath === '' ? $file : $relativePath . '/' . $file;

                if (is_file($fullPath)) {
                    if (!$archive->addFile($fullPath, $entryPath)) {
                        throw new ArchiveException('Cannot add file: ' . $entryPath);
                    }
                } elseif (is_dir($fullPath)) {
                    // Add sub-directory.
                    if (!$archive->addEmptyDir($entryPath)) {
                        throw new ArchiveException('Cannot add folder: ' . $entryPath);
                    }

                    self::addFolderToZip($fullPath, $archive, $entryPath);
                }
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * Zip a folder, throw on failure.
     *
     * The destination file will be deleted on failure, if possible.
     *
     * @param string $source         Path of the directory to be zipped.
     * @param string $destination    Path of the output zip file.
     * @param bool   $inSubDirectory Use the directory source name to create a subfolder inside the archive.
     * @param bool   $overwrite      Replace the destination file if present.
     *
     * @throws ArchiveException
     * @throws IOException
     * @throws Throwable
     */
    public static function zipDirectoryOrFail(string $source, string $destination, bool $inSubDirectory = true, bool $overwrite = false): void
    {
        if (!is_dir($source) || !is_readable($source)) {
            throw new IOException('Cannot read source directory: ' . $source);
        }

        if (file_exists($destination) && !$overwrite) {
            throw new IOException('Destination file already exists and overwriting is disabled: ' . $destination);
        }

        $directory = basename($source);

        $archive = new ZipArchive();

        if (!$overwrite) {
            $flags = ZipArchive::CREATE;
        } else {
            $flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
        }

        if (true !== $code = $archive->open($destination, $flags)) {
            throw new ArchiveException("[ERR: $code] Destination file cannot be opened for writing: $destination");
        }

        if ($inSubDirectory && !$archive->addEmptyDir($directory)) {
            throw new ArchiveException('Cannot add root subfolder');
        }

        try {
            self::addFolderToZip($source, $archive, $inSubDirectory ? $directory : '');
        } catch (Throwable $exception) {
            $archive->close();

            @unlink($destination);

            throw $exception;
        }

        if (!$archive->close()) {
            @unlink($destination);

            throw new ArchiveException('Cannot close zip archive');
        }
    }

    /**
     * Zip a folder.
     *
     * The destination file will be deleted on failure, if possible.
     *
     * @param string $source         Path of the directory to be zipped.
     * @param string $destination    Path of the output zip file.
     * @param bool   $inSubDirectory Use the directory source name to create a subfolder inside the archive.
     * @param bool   $overwrite      Replace the destination file if present.
     */
    public static function zipDirectory(string $source, string $destination, bool $inSubDirectory = true, bool $overwrite = false): bool
    {
        try {
            self::zipDirectoryOrFail($source, $destination, $inSubDirectory, $overwrite);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
