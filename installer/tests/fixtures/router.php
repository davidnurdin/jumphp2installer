<?php
// php -S router for the tests: /bytes/<n> (n bytes, Content-Length), /redirect -> /bytes/1000, /missing (404),
// /pkg/... files of tests/fixtures/pkg, /api/packages/demo (the package API)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/bytes/(\d+)$#', $path, $m)) {
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $m[1]);
    echo str_repeat('ab', intdiv((int)$m[1], 2)) . ((int)$m[1] % 2 ? 'a' : '');
    return true;
}
if ($path === '/redirect') {
    header('Location: /bytes/1000', true, 302);
    return true;
}
if ($path === '/api/packages/demo') {
    header('Content-Type: application/json');
    echo json_encode(['id' => 'demo', 'name' => 'Demo', 'script' => (string)file_get_contents(getenv('PKG_DIR') . '/install.yaml'), 'assets' => '/api/packages/demo/assets/']);
    return true;
}
if (preg_match('#^/api/packages/demo/assets/(.+)$#', $path, $m) && is_file($f = getenv('PKG_DIR') . '/' . $m[1])) {
    header('Content-Length: ' . filesize($f));
    readfile($f);
    return true;
}
http_response_code(404);
echo 'not found';
return true;
