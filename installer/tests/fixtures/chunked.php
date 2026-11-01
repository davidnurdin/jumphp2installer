<?php
// A one-request HTTP server answering a chunked body (php -S does not): php chunked.php <port>
$server = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $errno, $errstr);
fwrite(STDOUT, "ready\n");
$c = stream_socket_accept($server, 10);
while (($line = fgets($c)) !== false && trim($line) !== '');
fwrite($c, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
foreach (['Hello', ', ', str_repeat('x', 5000), ' world'] as $part) {
    fwrite($c, dechex(strlen($part)) . "\r\n" . $part . "\r\n");
    usleep(20000);
}
fwrite($c, "0\r\n\r\n");
fclose($c);
