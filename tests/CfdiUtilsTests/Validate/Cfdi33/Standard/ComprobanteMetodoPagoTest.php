<?php

namespace CfdiUtilsTests\Validate\Cfdi33\Standard;

use CfdiUtils\Validate\Cfdi33\Standard\ComprobanteMetodoPago;
use CfdiUtils\Validate\Status;
use CfdiUtilsTests\Validate\ValidateTestCase;

class ComprobanteMetodoPagoTest extends ValidateTestCase
{
    /** @var  ComprobanteMetodoPago */
    protected $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ComprobanteMetodoPago();
    }

    public function providerValidCases(): array
    {
        return[
            ['T', null, 'METPAG01'],
            ['P', null, 'METPAG01'],
            ['N', null, 'METPAG01'],
            ['I', 'PUE', 'METPAG02'],
            ['I', 'PPD', 'METPAG02'],
            ['E', 'PUE', 'METPAG02'],
            ['I', 'PPD', 'METPAG02'],
        ];
    }

    /**
     * @param string $tipoDeComprobante
     * @param string|null $metodoDePago
     * @param string $ok
     * @dataProvider providerValidCases
     */
    public function testValidCases(string $tipoDeComprobante, ?string $metodoDePago, string $ok)
    {
        $this->comprobante->addAttributes([
            'TipoDeComprobante' => $tipoDeComprobante,
            'MetodoPago' => $metodoDePago,
        ]);
        $this->runValidate();
        $this->assertFalse($this->asserts->hasErrors());
        $this->assertStatusEqualsCode(Status::ok(), $ok);
    }

    public function providerInvalidCases(): array
    {
        return[
            ['T', 'PUE', 'METPAG01'],
            ['T', '', 'METPAG01'],
            ['P', 'PUE', 'METPAG01'],
            ['P', '', 'METPAG01'],
            ['N', 'PUE', 'METPAG01'],
            ['N', '', 'METPAG01'],
            ['I', null, 'METPAG02'],
            ['I', null, 'METPAG02'],
            ['E', 'XXX', 'METPAG02'],
            ['I', 'XXX', 'METPAG02'],
        ];
    }

    /**
     * @param string $tipoDeComprobante
     * @param string|null $metodoDePago
     * @param string $error
     * @dataProvider providerInvalidCases
     */
    public function testInvalidCases(string $tipoDeComprobante, ?string $metodoDePago, string $error)
    {
        $this->comprobante->addAttributes([
            'TipoDeComprobante' => $tipoDeComprobante,
            'MetodoPago' => $metodoDePago,
        ]);
        $this->runValidate();
        $this->assertTrue($this->asserts->hasErrors());
        $this->assertStatusEqualsCode(Status::error(), $error);
    }

    public function providerNoneCases(): array
    {
        return [
            [null, ''],
            ['', ''],
            ['X', ''],
        ];
    }

    /**
     * @param string|null $tipoDeComprobante
     * @param string $metodoDePago
     * @dataProvider providerNoneCases
     */
    public function testNoneCases(?string $tipoDeComprobante, string $metodoDePago)
    {
        $this->comprobante->addAttributes([
            'TipoDeComprobante' => $tipoDeComprobante,
            'MetodoPago' => $metodoDePago,
        ]);
        $this->runValidate();
        $this->assertFalse($this->asserts->hasErrors());
        $this->assertCount(2, $this->asserts->byStatus(Status::none()));
    }
}
