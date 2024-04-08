<?php
/*
 * The MIT License
 *
 * Copyright 2023 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');

include __DIR__ . '/vendor/autoload.php';

$baseUrl = getenv('BASEURL') ?: '';
$dataDir = getenv('DATADIR') ?: '/data';
$maxLevel = getenv('MAXLEVEL') ?: 2;
$maxSize = (getenv('MAXSIZE') ?: 1000) * 1048576;
$allowedNmsp = getenv('ALLOWEDNMSP') ?: '';
$allowedNmsp = empty($allowedNmsp) ? [] : explode(',', $allowedNmsp);

$id = $_GET['id'] ?? '';
if (!empty($baseUrl) && str_starts_with($id, $baseUrl)) {
    $id = (int) substr($id, strlen($baseUrl));
    $path = acdhOeaw\arche\core\BinaryPayload::getStorageDir($id, $dataDir, 0, $maxLevel) . '/' . $id;
} else {
    $match = false;
    foreach ($allowedNmsp as $i) {
        if (str_starts_with($id, $i)) {
            $match = true;
            break;
        }
    }
    if (!$match) {
        http_response_code(400);
        echo "Resource $id out of allowed namespaces\n";
        exit();
    }

    $chunk = 1048576; // 1MB
    $remote = fopen($id, 'rb');
    if ($remote === false) {
        http_response_code(400);
        echo "Can't access $id\n";
        exit();
    }
    $path = tempnam(sys_get_temp_dir(), 'exif_cache_');
    $local = fopen($path, 'wb');
    $size = 0;
    while (!feof($remote)) {
        $size += fwrite($local, fread($remote, $chunk));
        if ($size > $maxSize) {
            fclose($remote);
            fclose($local);
            unlink($path);
            http_response_code(413);
            echo "Resource $id is too large\n";
            exit();
        }
    }
    fclose($remote);
    fclose($local);
}
if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    echo "Resource $id not found\n";
    exit();
}
$cmd = sprintf(
    "%s -j %s",
    escapeshellcmd(getenv('EXIFTOOL')),
    escapeshellarg($path)
);
$output = $resCode = null;
exec($cmd, $output, $resCode);
if (str_starts_with($path, sys_get_temp_dir()) && file_exists($path)) {
    unlink($path);
}
$data = json_decode(implode('', $output));
if (!is_array($data)) {
    http_response_code(500);
    echo "Unable to fetch EXIF data for the resource\n";
    exit();
}
header('Content-Type: application/json');
echo json_encode($data[0], JSON_UNESCAPED_SLASHES);
