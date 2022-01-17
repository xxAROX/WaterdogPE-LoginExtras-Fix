<?php
/*
 *    Copyright 2022 Jan Sohn / xxAROX
 *
 *    Licensed under the Apache License, Version 2.0 (the "License");
 *    you may not use this file except in compliance with the License.
 *    You may obtain a copy of the License at
 *
 *        http://www.apache.org/licenses/LICENSE-2.0
 *
 *    Unless required by applicable law or agreed to in writing, software
 *    distributed under the License is distributed on an "AS IS" BASIS,
 *    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *    See the License for the specific language governing permissions and
 *    limitations under the License.
 *
 */
declare(strict_types=1);
/**
 * Build script
 */
$startTime = microtime(true);
// Input & Output directory...
$from = getcwd() . DIRECTORY_SEPARATOR;
$description = yaml_parse_file($from . "plugin.yml");
$to = __DIR__ . DIRECTORY_SEPARATOR . "out" . DIRECTORY_SEPARATOR . $description["name"] . DIRECTORY_SEPARATOR;
$outputPath = $from . "out" . DIRECTORY_SEPARATOR . $description["name"];
@mkdir($to, 0777, true);
// Clean output directory...
cleanDirectory($to);
// Copying new files...
if (is_dir($from . "src")) {
	copyDirectory($from . "src", $to . "src/xxAROX/WDFix");
}
if (is_dir($from . "resources")) {
	copyDirectory($from . "resources", $to . "resources");
}
yaml_emit_file($to . "plugin.yml", $description);
// Defining output path...
@unlink($outputPath . ".phar");
// Generate phar
$phar = new Phar($outputPath . ".phar");
$phar->buildFromDirectory($to);
$phar->compressFiles(Phar::GZ);
printf("Built in %s seconds! Output path: %s\n", round(microtime(true) - $startTime, 3), $outputPath);
# Functions:
function copyDirectory(string $from, string $to, array $ignoredFiles = []): void{
	@mkdir($to, 0777, true);
	$ignoredFiles = array_map(fn(string $path) => str_replace("/", "\\", $path), $ignoredFiles);
	$files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), function (SplFileInfo $fileInfo, $key, $iterator) use ($from, $ignoredFiles): bool{
		if (!empty($ignoredFiles)) {
			$path = str_replace("/", "\\", $fileInfo->getPathname());
			foreach ($ignoredFiles as $ignoredFile) {
				if (str_starts_with($path, $ignoredFile)) {
					return false;
				}
			}
		}
		return true;
	}), RecursiveIteratorIterator::SELF_FIRST);
	/** @var SplFileInfo $fileInfo */
	foreach ($files as $fileInfo) {
		$target = str_replace($from, $to, $fileInfo->getPathname());
		if ($fileInfo->isDir()) {
			@mkdir($target, 0777, true);
		} else {
			$contents = file_get_contents($fileInfo->getPathname());
			file_put_contents($target, $contents);
		}
	}
}

/**
 * Function cleanDirectory
 * @param string $directory
 * @return void
 */
function cleanDirectory(string $directory): void{
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	/** @var SplFileInfo $fileInfo */
	foreach ($files as $fileInfo) {
		if ($fileInfo->isDir()) {
			rmdir($fileInfo->getPathname());
		} else {
			unlink($fileInfo->getPathname());
		}
	}
}