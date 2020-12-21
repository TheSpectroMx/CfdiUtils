<?php

namespace CfdiUtilsTests\Elements\Nomina12;

use CfdiUtils\Elements\Nomina12\AccionesOTitulos;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CfdiUtils\Elements\Nomina12\AccionesOTitulos
 */
class AccionesOTitulosTest extends TestCase
{
    /** @var AccionesOTitulos */
    public $element;

    protected function setUp(): void
    {
        parent::setUp();
        $this->element = new AccionesOTitulos();
    }

    public function testConstructedObject()
    {
        $this->assertSame('nomina12:AccionesOTitulos', $this->element->getElementName());
    }
}
