<?php

namespace App\Tests;

use App\Security\FileVoter;
use Bnine\FilesBundle\Security\AbstractFileVoter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class UploadServerTest extends WebTestCase
{
    public function testRemovedOneupMappingsReturn404(): void
    {
        $client = static::createClient();

        foreach (['avatar', 'logo', 'media'] as $mapping) {
            $client->request('POST', '/_uploader/'.$mapping.'/uploads');

            $this->assertResponseStatusCodeSame(404, 'Le mapping oneup "'.$mapping.'" a été supprimé.');
        }
    }

    public function testMediaDomainIsNoLongerPublic(): void
    {
        $this->assertVoteDenied('media');
    }

    public function testAvatarDomainStaysPublic(): void
    {
        $this->assertVoteGranted('avatar');
    }

    private function assertVoteDenied(string $domain): void
    {
        $this->assertSame(-1, $this->voteView($domain), 'Le domaine "'.$domain.'" doit être refusé à l\'anonyme.');
    }

    private function assertVoteGranted(string $domain): void
    {
        $this->assertSame(1, $this->voteView($domain), 'Le domaine "'.$domain.'" doit rester public.');
    }

    private function voteView(string $domain): int
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        return (new FileVoter())->vote($token, [$domain, 1], [AbstractFileVoter::VIEW]);
    }
}
