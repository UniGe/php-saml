<?php

/**
 * Determine if the SAML response is valid using a provided x509 certificate.
 */
class OneLogin_Saml_XmlSec
{
    /**
     * Acceptable skew between SP and IdP clocks.
     * See SAML Version 2.0 Errata 05, Errata E92 
     */
    const CLOCK_SKEW_SECONDS = 180; // 3 minutes
    
    /**
     * A SamlResponse class provided to the constructor.
     * @var OneLogin_Saml_Settings
     */
    protected $_settings;

    /**
     * The document to be tested.
     * @var DomDocument
     */
    protected $_document;

    /**
     * Construct the SamlXmlSec object.
     *
     * @param OneLogin_Saml_Settings $settings A SamlResponse settings object containing the necessary
     *                                          x509 certicate to test the document.
     * @param OneLogin_Saml_Response $response The document to test.
     */
    public function __construct(OneLogin_Saml_Settings $settings, OneLogin_Saml_Response $response)
    {
        $this->_settings = $settings;
        $this->_document = clone $response->document;
    }

    /**
     * Verify that the document only contains a single Assertion
     * 
     * According Interoperable SAML 2.0 Web Browser SSO Deployment Profile,
     * par. 9.2, the response MUST contain exactly one assertion.
     *
     * @return bool TRUE if the document passes.
     */
    public function validateNumAssertions()
    {
        $rootNode = $this->_document;
        $assertionNodes = $rootNode->getElementsByTagName('Assertion');
        return ($assertionNodes->length == 1);
    }

    /**
     * Verify that the document is still valid according
     *
     * @return bool
     */
    public function validateTimestamps()
    {
        $rootNode = $this->_document;
        $timestampNodes = $rootNode->getElementsByTagName('Conditions');
        for ($i = 0; $i < $timestampNodes->length; $i++) {
            $nbAttribute = $timestampNodes->item($i)->attributes->getNamedItem("NotBefore");
            $naAttribute = $timestampNodes->item($i)->attributes->getNamedItem("NotOnOrAfter");
            if ($nbAttribute && strtotime($nbAttribute->textContent) > time() + self::CLOCK_SKEW_SECONDS) {
                return FALSE;
            }
            if ($naAttribute && strtotime($naAttribute->textContent) <= time() - self::CLOCK_SKEW_SECONDS) {
                return FALSE;
            }
        }
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function isValid()
    {
        $objXMLSecDSig = new XMLSecurityDSig();

        $objDSig = $objXMLSecDSig->locateSignature($this->_document);
        if (!$objDSig) {
            throw new Exception('Cannot locate Signature Node');
        }
        $objXMLSecDSig->canonicalizeSignedInfo();
        $objXMLSecDSig->idKeys = array('ID');

        $retVal = $objXMLSecDSig->validateReference();
        if (!$retVal) {
            throw new Exception('Reference Validation Failed');
        }

        $singleAssertion = $this->validateNumAssertions();
        if (!$singleAssertion) {
            throw new Exception('Multiple assertions are not supported');
        }

        $validTimestamps = $this->validateTimestamps();
        if (!$validTimestamps) {
            throw new Exception('Timing issues (please check your clock settings)
            ');
        }

        $objKey = $objXMLSecDSig->locateKey();
        if (!$objKey) {
            throw new Exception('We have no idea about the key');
        }

        XMLSecEnc::staticLocateKeyInfo($objKey, $objDSig);

        $objKey->loadKey($this->_settings->idpPublicCertificate, FALSE, TRUE);

        return ($objXMLSecDSig->verify($objKey) === 1);
    }
}
