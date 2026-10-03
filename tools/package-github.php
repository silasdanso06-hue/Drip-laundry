<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_dir($root . '/dist')) mkdir($root . '/dist', 0700, true);
file_put_contents($root . '/dist/.htaccess', "Require all denied\n");
$zip = new ZipArchive();
$path = $root . '/dist/dripclean-github.zip';
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create ZIP.');
$files = [];
foreach (glob($root . '/*') as $file) {
    if (is_file($file) && in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'html', 'md'], true)) $files[] = $file;
}
foreach (['assets', 'lib', 'tests', 'tools'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'html', 'css', 'js', 'mjs', 'json', 'png', 'jpg', 'mp4', 'md', 'txt', 'ps1'], true)) continue;
        $files[] = $file->getPathname();
    }
}
foreach (['.gitignore', '.gitattributes', 'private/.htaccess'] as $file) $files[] = $root . '/' . $file;
foreach ($files as $file) {
    $name = str_replace('\\', '/', substr($file, strlen($root) + 1));
    if (!$zip->addFile($file, $name)) throw new RuntimeException('Could not package ' . $name);
}
$zip->close();
echo 'Created dist/dripclean-github.zip with ' . count($files) . " source files. Private data and generated output excluded.\n";
