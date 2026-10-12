<?php
// Builds one small sprite sheet from the CC0 "Orange Fat Cat" pack (megupets.com): a single row of
// equal cells. Usage:
//   php bin/build-cat-sprite.php <"Orange Fat Cat" dir> public/cat/cat-sprite.png
// Prints the cell size, original image sizes and the first cell + frame count of each animation,
// for the CSS/JS. Loads one image at a time to keep memory low.

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

[$script, $source, $output] = $argv + [null, null, null];
if (!$source || !$output) {
    fwrite(STDERR, "usage: php build-cat-sprite.php <source dir> <output.png>\n");
    exit(1);
}

const CELL_HEIGHT = 128; // shown at 64 px tall, so sharp on 2x screens

$globs = [
    'walk' => '/Walk/CAT WALK*.png',
    'idle' => '/Idle/CAT IDLE*.png',
    'jump' => '/Jump/CAT JUMP*.png',
    'hi' => '/Extra/HI CAT.png',
    'grumpy' => '/Extra/GRUMPYCAT.png',
    'tsk' => '/Extra/TSK CAT.png',
    'happy' => '/Extra/HAPPY CAT.png',
    'zombie' => '/Extra/ZOMBIE CAT.png',
];

// Pass 1: file list and sizes only.
$files = [];
$layout = [];
$sizes = [];
foreach ($globs as $name => $pattern) {
    $found = glob($source.$pattern);
    sort($found);
    if (!$found) {
        fwrite(STDERR, "no files for $name ($pattern)\n");
        exit(1);
    }
    $layout[$name] = ['first' => count($files), 'frames' => count($found)];
    foreach ($found as $file) {
        $size = getimagesize($file);
        if (!$size || IMAGETYPE_PNG !== $size[2]) {
            fwrite(STDERR, "not a PNG: $file\n");
            exit(1);
        }
        $files[] = ['file' => $file, 'width' => $size[0], 'height' => $size[1]];
        $sizes[$name] = $size[0].'x'.$size[1];
    }
}

// One scale for every frame (from the tallest one), so the cat keeps its size between poses.
$scale = CELL_HEIGHT / max(array_column($files, 'height'));
$cellWidth = (int) ceil(max(array_column($files, 'width')) * $scale);

$sheet = imagecreatetruecolor($cellWidth * count($files), CELL_HEIGHT);
imagealphablending($sheet, false);
imagesavealpha($sheet, true);
imagefill($sheet, 0, 0, imagecolorallocatealpha($sheet, 0, 0, 0, 127));
imagealphablending($sheet, true);

// Pass 2: draw one image at a time.
foreach ($files as $i => $frame) {
    $image = imagecreatefrompng($frame['file']);
    if (!$image) {
        fwrite(STDERR, "could not read: {$frame['file']}\n");
        exit(1);
    }
    $width = (int) round($frame['width'] * $scale);
    $height = (int) round($frame['height'] * $scale);
    // Centered horizontally, standing on the bottom of the cell.
    imagecopyresampled($sheet, $image, $i * $cellWidth + intdiv($cellWidth - $width, 2), CELL_HEIGHT - $height, 0, 0, $width, $height, $frame['width'], $frame['height']);
    imagedestroy($image);
}

imagealphablending($sheet, false);
if (!imagepng($sheet, $output, 9)) {
    fwrite(STDERR, "could not write $output\n");
    exit(1);
}

printf("cell %dx%d, %d cells, %d bytes\n", $cellWidth, CELL_HEIGHT, count($files), filesize($output));
echo 'original sizes: '.json_encode($sizes)."\n";
echo 'layout: '.json_encode($layout)."\n";
