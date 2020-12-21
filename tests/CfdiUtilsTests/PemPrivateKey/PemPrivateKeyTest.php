<?php

namespace CfdiUtilsTests\PemPrivateKey;

use CfdiUtils\Certificado\Certificado;
use CfdiUtils\PemPrivateKey\PemPrivateKey;
use CfdiUtilsTests\TestCase;

class PemPrivateKeyTest extends TestCase
{
    public function providerConstructWithBadArgument(): array
    {
        return [
            'empty' => [''],
            'random content' => ['foo bar'],
            'file://' => ['file://'],
            'file without prefix' => [__FILE__],
            'non-existent-file' => ['file://' . __DIR__ . '/non-existent-file'],
            'existent but is a directory' => ['file://' . __DIR__],
            'existent but invalid file' => ['file://' . __FILE__],
            'cer file' => ['file://' . static::utilAsset('certs/CSD01_AAA010101AAA.cer')],
            'key not pem file' => ['file://' . static::utilAsset('certs/CSD01_AAA010101AAA.key')],
            'no footer' => ['-----BEGIN PRIVATE KEY-----XXXXX'],
            'hidden url' => ['file://https://cdn.kernel.org/pub/linux/kernel/v4.x/linux-4.13.9.tar.xz'],
        ];
    }

    /**
     * @param string $key
     * @dataProvider providerConstructWithBadArgument
     */
    public function testConstructWithBadArgument(string $key)
    {
        $this->expectException(\UnexpectedValueException::class);
        new PemPrivateKey($key);
    }

    public function testConstructWithKeyFile()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey('file://' . $keyfile);
        $this->assertInstanceOf(PemPrivateKey::class, $privateKey);
    }

    public function testConstructWithKeyContents()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $this->assertInstanceOf(PemPrivateKey::class, $privateKey);
    }

    public function testOpenAndClose()
    {
        $passPhrase = '';
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $this->assertFalse($privateKey->isOpen());
        $this->assertTrue($privateKey->open($passPhrase));
        $this->assertTrue($privateKey->isOpen());
        $privateKey->close();
        $this->assertFalse($privateKey->isOpen());
    }

    public function testOpenWithBadKey()
    {
        $keyContents = "-----BEGIN PRIVATE KEY-----\nXXXXX\n-----END PRIVATE KEY-----";
        $privateKey = new PemPrivateKey($keyContents);
        $this->assertFalse($privateKey->open(''));
    }

    public function testOpenWithIncorrectPassPhrase()
    {
        $passPhrase = 'dummy password';
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA_password.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $this->assertFalse($privateKey->open($passPhrase));
        $this->assertFalse($privateKey->isOpen());
    }

    public function testOpenWithCorrectPassPhrase()
    {
        $passPhrase = '12345678a';
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA_password.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $this->assertTrue($privateKey->open($passPhrase));
        $this->assertTrue($privateKey->isOpen());
    }

    public function testCloneOpenKey()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $this->assertTrue($privateKey->open(''));

        $cloned = clone $privateKey;
        $this->assertFalse($cloned->isOpen());
        $this->assertTrue($cloned->open(''));
    }

    public function testSerializeOpenKey()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $this->assertTrue($privateKey->open(''));

        /** @var PemPrivateKey $serialized */
        $serialized = unserialize(serialize($privateKey));
        $this->assertFalse($serialized->isOpen());
        $this->assertTrue($serialized->open(''));
    }

    public function testSignWithClosedKey()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The private key is not open');
        $privateKey->sign('');
    }

    public function testSign()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $privateKey->open('');

        $content = 'lorem ipsum';
        $expectedSign = <<< EOC
VzOXVBIpeuOSUrZ3bxXPOkAhNepeu9u9y0PKt7eXX5yp7Do8r/rrRFxTuIcVfYoqcj2zGv/366hnhJJm
duiejZMJ/4En5VvjGaJoBltjEe4ANBzdCyqm49KlwRiGvOZGGeBBBFM1ks6N3zHDhPnzT17TgqiZMMY6
3thdR+kAsrxmN9o8ItWhudtS59bnmj84fkb/CJIsGHOVu4IvhVmUlsnEjdaB0vcCE8h+cPmDqTMhuunx
UB0oPiKxPEhhXFw18T2omx/oFfDI/gmD0LgRQfJ+fxYoKoPZL/g9ushPxq9vzQiik6DpJqY6uVWbBxmZ
NbLxnrVqMcDx4CpFhIaMKQ==
EOC;
        $sign = chunk_split(base64_encode($privateKey->sign($content, OPENSSL_ALGO_MD5)), 80, "\n");
        $this->assertEquals($expectedSign, rtrim($sign));
    }

    public function testBelongsToWithClosedKey()
    {
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The private key is not open');
        $privateKey->belongsTo('');
    }

    public function testBelongsTo()
    {
        $cerfile = $this->utilAsset('certs/CSD01_AAA010101AAA.cer');
        $keyfile = $this->utilAsset('certs/CSD01_AAA010101AAA.key.pem');
        $privateKey = new PemPrivateKey(strval(file_get_contents($keyfile)));
        $privateKey->open('');
        $certificado = new Certificado($cerfile);
        $this->assertTrue($privateKey->belongsTo($certificado->getPemContents()));
    }
}
