<?php
// Draws the demo package's pictures (demo/images/banner.png 344 x 840 = the side of the wizard at 2x, icon.png 128 x 128).
// bin/jumphp php games/installer tools/demo-images.php
$out = __DIR__ . '/../demo/images';
@mkdir($out, 0777, true);

$w = 344; $h = 840;
$im = imagecreatetruecolor($w, $h);
for ($y = 0; $y < $h; $y++) {
    $t = $y / $h;
    $c = imagecolorallocate($im, (int)(40 + 70 * $t), (int)(22 + 30 * $t), (int)(110 + 60 * (1 - $t)));
    imageline($im, 0, $y, $w, $y, $c);
}
mt_srand(7);
for ($i = 0; $i < 140; $i++) {
    $s = mt_rand(1, 3) * 2;
    $a = mt_rand(40, 110);
    imagefilledrectangle($im, $x = mt_rand(0, $w), $y = mt_rand(0, (int)($h * 0.7)), $x + $s, $y + $s, imagecolorallocatealpha($im, 255, 255, 255, $a));
}
// pixel hills
foreach ([[0.78, [70, 50, 160]], [0.86, [50, 34, 120]], [0.93, [30, 20, 80]]] as $k => [$base, $rgb]) {
    $c = imagecolorallocate($im, ...$rgb);
    for ($x = 0; $x < $w; $x += 8) {
        $top = (int)($h * $base + 26 * sin($x / (40 + 20 * $k) + $k * 2));
        imagefilledrectangle($im, $x, $top - $top % 8, $x + 7, $h, $c);
    }
}
// a note sheet, in pixels
$px = 8; $ox = 92; $oy = 220;
$sheet = [
    '..########..',
    '..#......##.',
    '..#.####.#.#',
    '..#......###',
    '..#.######.#',
    '..#........#',
    '..#.######.#',
    '..#........#',
    '..#.####...#',
    '..#........#',
    '..##########',
];
$white = imagecolorallocate($im, 250, 248, 255);
$ink = imagecolorallocate($im, 109, 74, 255);
foreach ($sheet as $r => $row) {
    foreach (str_split($row) as $col => $ch) {
        if ($ch === '#') {
            imagefilledrectangle($im, $ox + $col * $px, $oy + $r * $px, $ox + $col * $px + $px - 1, $oy + $r * $px + $px - 1, $white);
        }
    }
}
for ($r = 2; $r <= 8; $r += 2) {
    imagefilledrectangle($im, $ox + 4 * $px, $oy + $r * $px + 2, $ox + 9 * $px, $oy + $r * $px + 5, $ink);
}
imagepng($im, "$out/banner.png");

$s = 128;
$icon = imagecreatetruecolor($s, $s);
imagesavealpha($icon, true);
imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
imagefilledrectangle($icon, 16, 8, 112, 120, imagecolorallocate($icon, 109, 74, 255));
imagefilledrectangle($icon, 28, 20, 100, 108, imagecolorallocate($icon, 250, 248, 255));
foreach ([36, 52, 68, 84] as $y) {
    imagefilledrectangle($icon, 38, $y, $y === 84 ? 70 : 90, $y + 6, imagecolorallocate($icon, 109, 74, 255));
}
imagepng($icon, "$out/icon.png");
echo "demo/images: banner.png, icon.png\n";
