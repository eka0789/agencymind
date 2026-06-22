<?php

namespace App\Services;

use phpseclib3\Net\SFTP;

class SftpClientFactory
{
    /**
     * Create a new SFTP connection instance.
     */
    public function make(string $host, int $port = 22): SFTP
    {
        return new SFTP($host, $port);
    }
}
