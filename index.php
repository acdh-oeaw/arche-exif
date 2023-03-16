<?php
include __DIR__ . '/vendor/autoload.php';
$id = (int) ($_GET['id'] ?? '');
$dataDir = getenv('DATADIR') ?: '/data';
$path = acdhOeaw\arche\core\BinaryPayload::getStorageDir($id, $dataDir, 0, 0) . '/' . $id;
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
$data = json_decode(implode('', $output));
if (!is_array($data)) {
    http_response_code(500);
    echo "Unable to fetch EXIF data for the resource\n";
    exit();
}
echo json_encode($data[0], JSON_UNESCAPED_SLASHES);
