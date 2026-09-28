<?php

namespace App\Support\Security;

use RuntimeException;

final class ClamAvScanner implements MalwareScanner
{
    public function assertClean(string $path): void
    {
        $endpoint = (string) config('security.uploads.clamd_endpoint');
        $limit = min(52_428_800, max(1, (int) config('security.uploads.max_bytes', 10_485_760)));
        $timeout = min(30, max(1, (int) config('security.uploads.scan_timeout_seconds', 10)));
        if (! preg_match('~^(?:unix:///[^\x00-\x20]+|tcp://(?:127\.0\.0\.1|\[::1\]):[0-9]{1,5})$~D', $endpoint)
            || ! is_file($path) || is_link($path) || filesize($path) > $limit || filesize($path) < 1) {
            throw new RuntimeException('FILE_SCAN_UNAVAILABLE');
        }
        $stream = @stream_socket_client($endpoint, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);
        if (! is_resource($stream)) {
            throw new RuntimeException('FILE_SCAN_UNAVAILABLE');
        }
        $input = null;
        try {
            stream_set_timeout($stream, $timeout);
            $deadline = microtime(true) + $timeout;
            $this->write($stream, "zINSTREAM\0", $deadline);
            $input = fopen($path, 'rb');
            if (! is_resource($input)) {
                throw new RuntimeException('FILE_SCAN_UNAVAILABLE');
            }
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('FILE_SCAN_UNAVAILABLE');
                }
                $bytes += strlen($chunk);
                if ($bytes > $limit) {
                    throw new RuntimeException('FILE_SCAN_LIMIT');
                }
                if ($chunk !== '') {
                    $this->write($stream, pack('N', strlen($chunk)).$chunk, $deadline);
                }
            }
            $this->write($stream, pack('N', 0), $deadline);
            $reply = '';
            while (! str_contains($reply, "\0") && strlen($reply) < 1024 && ! feof($stream) && microtime(true) < $deadline) {
                $this->boundIo($stream, $deadline);
                $chunk = fread($stream, 1024 - strlen($reply));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $reply .= $chunk;
            }
            if ($reply !== "stream: OK\0") {
                throw new RuntimeException(str_contains($reply, ' FOUND') ? 'FILE_SCAN_REJECTED' : 'FILE_SCAN_INCOMPLETE');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            fclose($stream);
        }
    }

    private function write($stream, string $data, float $deadline): void
    {
        while ($data !== '') {
            $this->boundIo($stream, $deadline);
            $written = fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('FILE_SCAN_UNAVAILABLE');
            }
            $data = substr($data, $written);
        }
    }

    private function boundIo($stream, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('FILE_SCAN_TIMEOUT');
        }
        $seconds = (int) $remaining;
        stream_set_timeout($stream, $seconds, max(1, (int) (($remaining - $seconds) * 1_000_000)));
    }
}
