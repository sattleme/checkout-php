<?php

declare(strict_types=1);

namespace Sattleme\Checkout\Tests\Support\Net;

/**
 * A certificate authority and a server certificate for the tests' TLS servers, made when the
 * tests run (none is committed: the repository holds no key, even a test one), in a temporary
 * directory removed at the end.
 */
final class TestCertificates
{
    private readonly string $directory;

    /** @param list<string> $hosts */
    public function __construct(array $hosts)
    {
        $directory = sys_get_temp_dir() . '/sattleme-checkout-tls-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot make a temporary directory');
        }
        $this->directory = $directory;
        $config = $directory . '/openssl.cnf';
        file_put_contents($config, implode("\n", [
            '[req]',
            'default_bits = 2048',
            'distinguished_name = dn',
            '[dn]',
            '[ca]',
            'basicConstraints = critical, CA:TRUE',
            'keyUsage = critical, keyCertSign, cRLSign',
            'subjectKeyIdentifier = hash',
            '[server]',
            'basicConstraints = critical, CA:FALSE',
            'keyUsage = critical, digitalSignature, keyEncipherment',
            'extendedKeyUsage = serverAuth',
            'subjectAltName = ' . implode(', ', array_map(static fn(string $h): string => 'DNS:' . $h, $hosts)),
            '',
        ]));
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $caKey = openssl_pkey_new($options);
        $serverKey = openssl_pkey_new($options);
        if (!$caKey instanceof \OpenSSLAsymmetricKey || !$serverKey instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Cannot make test keys: ' . (string) openssl_error_string());
        }
        // openssl_csr_new() takes its key by reference: copies keep the keys' types.
        $caKeyCopy = $caKey;
        $serverKeyCopy = $serverKey;
        $caCsr = openssl_csr_new(['commonName' => 'checkout-php test CA'], $caKeyCopy, $options + ['req_extensions' => 'ca']);
        \assert($caCsr instanceof \OpenSSLCertificateSigningRequest);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 1, $options + ['x509_extensions' => 'ca'], random_int(1, \PHP_INT_MAX));
        $serverCsr = openssl_csr_new(['commonName' => $hosts[0]], $serverKeyCopy, $options + ['req_extensions' => 'server']);
        \assert($serverCsr instanceof \OpenSSLCertificateSigningRequest && $ca instanceof \OpenSSLCertificate);
        $server = openssl_csr_sign($serverCsr, $ca, $caKey, 1, $options + ['x509_extensions' => 'server'], random_int(1, \PHP_INT_MAX));
        \assert($server instanceof \OpenSSLCertificate);
        $caPem = $serverPem = $serverKeyPem = '';
        if (!openssl_x509_export($ca, $caPem) || !openssl_x509_export($server, $serverPem)
            || !openssl_pkey_export($serverKey, $serverKeyPem, null, ['config' => $config])) {
            throw new \RuntimeException('Cannot export test certificates');
        }
        \assert(\is_string($caPem) && \is_string($serverPem) && \is_string($serverKeyPem));
        file_put_contents($directory . '/ca.pem', $caPem);
        file_put_contents($directory . '/server.pem', $serverPem . $serverKeyPem);
        chmod($directory . '/server.pem', 0600);
    }

    public function caFile(): string
    {
        return $this->directory . '/ca.pem';
    }

    /** The server's certificate and key, in one PEM file. */
    public function serverFile(): string
    {
        return $this->directory . '/server.pem';
    }

    public function remove(): void
    {
        foreach (['openssl.cnf', 'ca.pem', 'server.pem'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        @rmdir($this->directory);
    }
}
