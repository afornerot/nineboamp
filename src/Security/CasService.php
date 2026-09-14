<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

class CasService
{
    private bool $initialized = false;

    public function __construct(
        #[Autowire(param: 'casHost')]
        private string $casHost,
        #[Autowire(param: 'casPort')]
        private string $casPort,
        #[Autowire(param: 'casPath')]
        private string $casPath,
    ) {
    }

    public function init(Request $request): void
    {
        if ($this->initialized) {
            return;
        }

        $url = $request->getScheme().'://'.$request->getHost();

        \phpCAS::client(
            CAS_VERSION_2_0,
            $this->casHost,
            (int) $this->casPort,
            $this->casPath,
            $url,
            false);

        \phpCAS::setNoCasServerValidation();

        $this->initialized = true;
    }

    public function logout(Request $request): void
    {
        $this->init($request);

        $url = $request->getScheme().'://'.$request->getHost().$request->getBaseUrl();
        \phpCAS::logoutWithRedirectService($url);
    }
}
