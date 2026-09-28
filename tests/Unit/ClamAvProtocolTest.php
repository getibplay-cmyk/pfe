<?php

namespace Tests\Unit;

use App\Support\Security\ClamAvScanner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClamAvProtocolTest extends TestCase
{
    public static function verdicts(): array
    {
        return [["stream: OK\0", true], ["stream: Synthetic-Test FOUND\0", false],
            ["INSTREAM size limit exceeded. ERROR\0", false], ['stream: OK', false], ["stream: OK\0", false, true]];
    }

    #[DataProvider('verdicts')]
    public function test_only_a_complete_clean_verdict_accepts_the_exact_stream(string $reply, bool $clean, bool $stall = false): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The local protocol fixture requires pcntl; real scanner qualification remains separate.');
        }
        $fixture = 'Synthetic bounded bytes, not a malware signature.';
        $path = tempnam(sys_get_temp_dir(), 'rf-scan-test-');
        file_put_contents($path, $fixture);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        $this->assertIsResource($server);
        $endpoint = 'tcp://'.stream_socket_get_name($server, false);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            pcntl_alarm(4);
            $client = stream_socket_accept($server, 3);
            if (! is_resource($client)) {
                exit(2);
            }
            stream_set_timeout($client, 2);
            $read = static function (int $size) use ($client): string {
                $data = '';
                while (strlen($data) < $size && ! feof($client)) {
                    $chunk = fread($client, $size - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }

                return $data;
            };
            if ($read(10) !== "zINSTREAM\0") {
                exit(3);
            }
            $received = '';
            while (true) {
                $length = $read(4);
                if (strlen($length) !== 4) {
                    exit(4);
                }
                $size = unpack('N', $length)[1];
                if ($size === 0) {
                    break;
                }
                if ($size > 65536) {
                    exit(5);
                }
                $received .= $read($size);
            }
            if ($stall) {
                usleep(700000);
                fwrite($client, 'stream:');
                usleep(1500000);
            } else {
                fwrite($client, $reply);
            }
            fclose($client);
            fclose($server);
            exit($received === $fixture ? 0 : 6);
        }
        fclose($server);
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['security' => ['uploads' => [
            'clamd_endpoint' => $endpoint, 'max_bytes' => 1024, 'scan_timeout_seconds' => $stall ? 1 : 2,
        ]]]));
        Container::setInstance($container);
        try {
            $started = microtime(true);
            $accepted = true;
            try {
                (new ClamAvScanner)->assertClean($path);
            } catch (\RuntimeException) {
                $accepted = false;
            }
            $this->assertSame($clean, $accepted);
            if ($stall) {
                $this->assertLessThan(1.6, microtime(true) - $started, 'A partial reply must not restart the scanner time budget.');
            }
        } finally {
            Container::setInstance($previous);
            unlink($path);
            pcntl_waitpid($pid, $status);
        }
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }
}
