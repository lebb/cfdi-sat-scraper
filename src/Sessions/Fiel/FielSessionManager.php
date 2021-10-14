<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Sessions\Fiel;

use LogicException;
use PhpCfdi\CfdiSatScraper\Exceptions\LoginException;
use PhpCfdi\CfdiSatScraper\Exceptions\SatHttpGatewayException;
use PhpCfdi\CfdiSatScraper\Internal\HtmlForm;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\Sessions\AbstractSessionManager;
use PhpCfdi\CfdiSatScraper\Sessions\SessionManager;
use PhpCfdi\CfdiSatScraper\URLS;
use PhpCfdi\Credentials\Credential;

final class FielSessionManager extends AbstractSessionManager implements SessionManager
{
    /** @var FielSessionData */
    private $sessionData;

    /** @var SatHttpGateway|null */
    private $httpGateway;

    public function __construct(FielSessionData $fielSessionData)
    {
        $this->sessionData = $fielSessionData;
    }

    public static function create(Credential $credential): self
    {
        return new self(new FielSessionData($credential));
    }

    public function hasLogin(): bool
    {
        $httpGateway = $this->getHttpGateway();

        // if cookie is empty, then it will not be able to detect a session anyway
        if ($httpGateway->isCookieJarEmpty()) {
            return false;
        }

        try {
            // check is logged in on portal
            $html = $httpGateway->getPortalMainPage();
            if (false === strpos($html, 'RFC Autenticado: ' . $this->getRfc())) {
                return false;
            }
        } catch (SatHttpGatewayException $exception) {
            // if http error, consider without session
            return false;
        }

        return true;
    }

    public function login(): void
    {
        $httpGateway = $this->getHttpGateway();

        try {
            // contact homepage, it will try to redirect to access by password
            $httpGateway->getPortalMainPage();

            // previous page will try to redirect to access by password using post
            $httpGateway->postLoginData(URLS::SAT_URL_CIEC_LOGIN, []);

            // change to fiel login page and get challenge
            $html = $httpGateway->getAuthLoginPage(URLS::SAT_URL_FIEL_LOGIN, URLS::SAT_URL_CIEC_LOGIN);

            // resolve and submit challenge, it returns an autosubmit form
            $inputs = $this->resolveChallengeUsingFiel($html);
            $html = $httpGateway->postFielLoginData(URLS::SAT_URL_FIEL_LOGIN, $inputs);

            // submit login credentials to portalcfdi
            $form = new HtmlForm($html, 'form');
            $inputs = $form->getFormValues(); // wa, weesult, wctx
            $httpGateway->postPortalMainPage($inputs);
        } catch (SatHttpGatewayException $exception) {
            throw FielLoginException::connectionException('try to login using FIEL', $this->sessionData, $exception);
        }
    }

    public function getSessionData(): FielSessionData
    {
        return $this->sessionData;
    }

    public function getHttpGateway(): SatHttpGateway
    {
        if (null === $this->httpGateway) {
            throw new LogicException('Must set http gateway property before use');
        }
        return $this->httpGateway;
    }

    public function setHttpGateway(SatHttpGateway $httpGateway): void
    {
        $this->httpGateway = $httpGateway;
    }

    public function getRfc(): string
    {
        return $this->sessionData->getRfc();
    }

    /**
     * @param string $html
     * @return array<string, string>
     */
    private function resolveChallengeUsingFiel(string $html): array
    {
        // extract the challenge
        $inputs = (new HtmlForm($html, '#certform'))->getFormValues();
        $tokenUuid = ($inputs[''] ?? $inputs['guid'] ?? '') ?: '';
        // build the form data to send
        return [
            'token' => $this->createTokenFromTokenUuid($tokenUuid),
            'credentialsRequired' => 'CERT',
            'guid' => $tokenUuid,
            'ks' => 'null',
            'seeder' => '',
            'arc' => '',
            'tan' => '',
            'placer' => '',
            'secuence' => '',
            'urlApplet' => 'https://cfdiau.sat.gob.mx/nidp/app/login?id=SATx509Custom',
            'fert' => $this->sessionData->getValidTo(),
        ];
    }

    private function createTokenFromTokenUuid(string $tokenuuid): string
    {
        $fiel = $this->sessionData;

        $rfc = $fiel->getRfc();
        $serial = $fiel->getSerialNumber();
        $sourceString = "$tokenuuid|$rfc|$serial";
        $signature = base64_encode(base64_encode($fiel->sign($sourceString, OPENSSL_ALGO_SHA1)));
        return base64_encode(base64_encode($sourceString) . '#' . $signature);
    }

    protected function createExceptionConnection(string $when, SatHttpGatewayException $exception): LoginException
    {
        return FielLoginException::connectionException($when, $this->sessionData, $exception);
    }

    protected function createExceptionNotAuthenticated(string $html): LoginException
    {
        return FielLoginException::notRegisteredAfterLogin($this->sessionData, $html);
    }
}
