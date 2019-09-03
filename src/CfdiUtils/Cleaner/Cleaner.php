<?php
namespace CfdiUtils\Cleaner;

use CfdiUtils\Cfdi;
use CfdiUtils\Utils\SchemaLocations;
use CfdiUtils\Utils\Xml;
use DOMAttr;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Class to clean CFDI and avoid bad common practices.
 *
 * Strictly speaking, CFDI must accomplish all XML rules, including that any other
 * XML element must be isolated in its own namespace and follow their own XSD rules.
 *
 * The common practice (allowed by SAT) is that the CFDI is created, signed and
 * some nodes are attached after sign, some of them does not follow the XML standard.
 *
 * This is why it's better to clear Comprobante/Addenda and remove unused namespaces
 */
class Cleaner
{
    /** @var DOMDocument|null */
    protected $dom;

    public function __construct(string $content)
    {
        if ('' !== $content) {
            $this->load($content);
        }
    }

    /**
     * Method to clean content and return the result
     * If an error occurs, an exception is thrown
     * @param string $content
     * @return string
     */
    public static function staticClean($content): string
    {
        $cleaner = new self($content);
        $cleaner->clean();
        return $cleaner->retrieveXml();
    }

    /**
     * Check if the CFDI version is complatible to this class
     * @param string $version
     * @return bool
     */
    public static function isVersionAllowed(string $version): bool
    {
        return in_array($version, ['3.2', '3.3']);
    }

    /**
     * Check if a given namespace is allowed (must not be removed from CFDI)
     *
     * @param string $namespace
     * @return bool
     */
    public static function isNameSpaceAllowed(string $namespace): bool
    {
        return (
            'http://www.w3.org/' === (substr($namespace, 0, 18) ?: '')
            || 'http://www.sat.gob.mx/' === (substr($namespace, 0, 22) ?: '')
        );
    }

    /**
     * Apply all removals (Addenda, Non SAT Nodes and Non SAT namespaces)
     * @return void
     */
    public function clean()
    {
        $this->removeAddenda();
        $this->removeIncompleteSchemaLocations();
        $this->removeNonSatNSNodes();
        $this->removeNonSatNSschemaLocations();
        $this->removeUnusedNamespaces();
        $this->collapseComprobanteComplemento();
    }

    /**
     * Load the string content as a CFDI
     * This is exposed to reuse the current object instead of create a new instance
     *
     * @param string $content
     *
     * @throws CleanerException when the content is not valid xml
     * @throws CleanerException when the document does not use the namespace http://www.sat.gob.mx/cfd/3
     * @throws CleanerException when cannot find a Comprobante version (or Version) attribute
     * @throws CleanerException when the version is not compatible
     *
     * @return void
     */
    public function load(string $content)
    {
        try {
            $cfdi = Cfdi::newFromString($content);
        } catch (\Throwable $exception) {
            throw new CleanerException($exception->getMessage(), $exception->getCode(), $exception->getPrevious());
        }
        $version = $cfdi->getVersion();
        if (! $this->isVersionAllowed($version)) {
            throw new CleanerException("The CFDI version '$version' is not allowed");
        }
        $this->dom = $cfdi->getDocument();
    }

    /**
     * Get the XML content of the CFDI
     *
     * @return string
     */
    public function retrieveXml(): string
    {
        return $this->dom()->saveXML();
    }

    /**
     * Get a clone of the XML DOM Document of the CFDI
     *
     * @return DOMDocument
     */
    public function retrieveDocument(): DOMDocument
    {
        return clone $this->dom();
    }

    /**
     * Procedure to remove the Comprobante/Addenda node
     *
     * @return void
     */
    public function removeAddenda()
    {
        $query = '/cfdi:Comprobante/cfdi:Addenda';
        $addendas = $this->xpathQuery($query);
        foreach ($addendas as $addenda) {
            $addenda->parentNode->removeChild($addenda);
        }
    }

    /**
     * Procedure to drop schemaLocations where second part does not ends with '.xsd'
     *
     * @return void
     */
    public function removeIncompleteSchemaLocations()
    {
        foreach ($this->obtainXsiSchemaLocations() as $element) {
            $element->nodeValue = $this->removeIncompleteSchemaLocation($element->nodeValue);
        }
    }

    public function removeIncompleteSchemaLocation(string $source): string
    {
        $components = array_values(array_filter(array_map('trim', explode(' ', $source))));
        $length = count($components);
        for ($c = 0; $c < $length; $c = $c + 1) {
            $xsd = $components[$c + 1] ?? '';
            if ((0 === strcasecmp('.xsd', substr($xsd, -4, 4)))) {
                $c = $c + 1;
                continue;
            }
            $components[$c] = '';
        }
        return strval(implode(' ', array_filter($components)));
    }

    /**
     * Procedure to drop schemaLocations that are not allowed
     * If the schemaLocation is empty then remove the attribute
     *
     * @return void
     */
    public function removeNonSatNSschemaLocations()
    {
        $schemaLocations = $this->obtainXsiSchemaLocations();
        foreach ($schemaLocations as $element) {
            $this->removeNonSatNSschemaLocation($element);
        }
    }

    private function removeNonSatNSschemaLocation(DOMAttr $schemaLocation)
    {
        $source = $schemaLocation->nodeValue;
        // load locations
        $schemaLocations = SchemaLocations::fromString($source, true);
        if ($schemaLocations->hasAnyNamespaceWithoutLocation()) {
            throw new CleanerException(
                sprintf("The schemaLocation value '%s' must have even number of URIs", $source)
            );
        }
        // filter
        foreach ($schemaLocations as $namespace => $location) {
            if (! $this->isNameSpaceAllowed($namespace)) {
                $schemaLocations->remove($namespace);
            }
        }
        $modified = $schemaLocations->asString();
        if ($source === $modified) {
            return;
        }
        if ($schemaLocations->isEmpty()) { // remove node
            $schemaLocation->ownerElement->removeAttributeNode($schemaLocation);
        } else { // replace node content
            $schemaLocation->nodeValue = $modified;
        }
    }

    /**
     * Procedure to remove all nodes that are not from an allowed namespace
     * @return void
     */
    public function removeNonSatNSNodes()
    {
        $nss = $this->obtainNamespaces();
        foreach ($nss as $namespace) {
            if (! $this->isNameSpaceAllowed($namespace)) {
                $this->removeNonSatNSNode($namespace);
            }
        }
    }

    /**
     * Procedure to remove all nodes from an specific namespace
     * @param string $namespace
     * @return void
     */
    private function removeNonSatNSNode(string $namespace)
    {
        foreach ($this->dom()->getElementsByTagNameNS($namespace, '*') as $children) {
            $children->parentNode->removeChild($children);
        }
    }

    /**
     * Procedure to remove not allowed xmlns definitions
     * @return void
     */
    public function removeUnusedNamespaces()
    {
        $nss = [];
        $dom = $this->dom();
        $namespaces = $this->obtainNamespaces();
        foreach ($namespaces as $namespace) {
            if (! $namespace || $this->isNameSpaceAllowed($namespace)) {
                continue;
            }
            $prefix = $dom->lookupPrefix($namespace);
            $nss[$prefix] = $namespace;
        }
        $documentElement = Xml::documentElement($dom);
        foreach ($nss as $prefix => $namespace) {
            $documentElement->removeAttributeNS($namespace, $prefix);
        }
    }

    /** @return DOMNodeList|DOMAttr[] */
    private function obtainXsiSchemaLocations(): DOMNodeList
    {
        // Do not assume that prefix for http://www.w3.org/2001/XMLSchema-instance is "xsi"
        $xsi = $this->dom()->lookupPrefix('http://www.w3.org/2001/XMLSchema-instance');
        if (! $xsi) {
            return new DOMNodeList();
        }
        return $this->xpathQuery("//@$xsi:schemaLocation");
    }

    /** @return string[] */
    private function obtainNamespaces(): array
    {
        return array_unique(array_column(iterator_to_array($this->xpathQuery('//namespace::*')), 'nodeValue'));
    }

    /**
     * Helper function to perform a XPath query using an element (or root element)
     * @param string $query
     * @param DOMNode|null $element
     * @return DOMNodeList
     */
    private function xpathQuery(string $query, DOMNode $element = null): DOMNodeList
    {
        if (null === $element) {
            $document = $this->dom();
            $element = Xml::documentElement($document);
        } else {
            $document = Xml::ownerDocument($element);
        }
        /** @var DOMNodeList|false $nodelist phpstan does not know that query can return false */
        $nodelist = (new DOMXPath($document))->query($query, $element);
        if (false === $nodelist) {
            $nodelist = new DOMNodeList();
        }
        return $nodelist;
    }

    private function dom(): DOMDocument
    {
        if (null === $this->dom) {
            throw new \LogicException('No document has been loaded');
        }
        return $this->dom;
    }

    public function collapseComprobanteComplemento()
    {
        $comprobante = Xml::documentElement($this->dom());
        $complementos = $this->xpathQuery('./cfdi:Complemento', $comprobante);
        if ($complementos->length < 2) {
            return; // nothing to do, there are less than 2 complemento
        }
        /** @var DOMNode $first */
        $first = $complementos->item(0);
        for ($i = 1; $i < $complementos->length; $i++) { // iterate over all extra children
            /** @var DOMNode $extra */
            $extra = $complementos->item($i);
            $comprobante->removeChild($extra); // remove extra child from parent
            while ($extra->childNodes->length > 0) { // append extra child contents into first child
                /** @var DOMNode $child */
                $child = $extra->childNodes->item(0);
                $extra->removeChild($child);
                $first->appendChild($child);
            }
        }
    }
}
