<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The document itself: every operation says what it really does, and nothing internal or assumed gets through. */
class OpenApiDocumentationTest extends WebTestCase
{
    private const string GENERATED_TEXT = '/^(Creates|Retrieves|Replaces|Updates|Removes) (a|the collection of) \w+ resources?\.$/';
    private const array GENERATED_RESPONSES = ['Invalid input', 'An error occurred', 'Forbidden', 'Not found', 'Unexpected error'];
    private const array PUBLIC_OPERATIONS = [
        'GET /api/paper_planes',
        'GET /api/paper_planes/{uuid}',
        'GET /api/agent_missions/{uuid}',
        'GET /api/agent_missions/current',
        'POST /api/participants',
        'POST /api/participants/recover',
    ];

    /** @return array<string, mixed> */
    private function document(): array
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, mixed>> by "METHOD path" */
    private static function operations(array $document): array
    {
        $operations = [];
        foreach ($document['paths'] as $path => $methods) {
            foreach (array_intersect_key($methods, array_flip(['get', 'post', 'put', 'patch', 'delete'])) as $method => $operation) {
                $operations[strtoupper($method).' '.$path] = $operation;
            }
        }

        return $operations;
    }

    public function testEveryOperationDescribesItself(): void
    {
        $operations = self::operations($this->document());

        self::assertNotEmpty($operations);
        foreach ($operations as $name => $operation) {
            self::assertDoesNotMatchRegularExpression(self::GENERATED_TEXT, $operation['summary'], $name);
            self::assertDoesNotMatchRegularExpression(self::GENERATED_TEXT, $operation['description'], $name);
            self::assertNotSame($operation['summary'], $operation['description'], $name);
            self::assertMatchesRegularExpression('/^[a-z][a-zA-Z]+$/', $operation['operationId'], $name);
        }

        foreach (['summary', 'operationId'] as $unique) {
            $values = array_column($operations, $unique);
            self::assertSame($values, array_values(array_unique($values)), 'Repeated '.$unique);
        }
    }

    public function testThePaperPlanePostsCanBeToldApart(): void
    {
        $operations = self::operations($this->document());

        self::assertSame('Launch a paper plane', $operations['POST /api/paper_planes']['summary']);
        self::assertSame('Request another AI version', $operations['POST /api/paper_planes/{uuid}/ai-message/retry']['summary']);
        self::assertSame('Approve or reject the AI version', $operations['POST /api/paper_planes/{uuid}/ai-message/decision']['summary']);
    }

    public function testResponsesAreOurOwnAndErrorsAreProblems(): void
    {
        $document = $this->document();

        foreach (self::operations($document) as $name => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                self::assertNotContains($response['description'], self::GENERATED_RESPONSES, $name.' '.$status);
                self::assertStringNotContainsString('resource created', $response['description'], $name.' '.$status);

                if ($status >= 400) {
                    self::assertSame(['application/problem+json'], array_keys($response['content']), $name.' '.$status);
                    $schema = substr($response['content']['application/problem+json']['schema']['$ref'], \strlen('#/components/schemas/'));
                    self::assertArrayHasKey($schema, $document['components']['schemas'], $name.' '.$status);
                }
            }
        }
    }

    public function testResponsesThatReturnTheResourceKeepTheirSchema(): void
    {
        $operations = self::operations($this->document());

        foreach ($operations as $name => $operation) {
            $success = array_filter($operation['responses'], static fn ($status): bool => $status < 400, \ARRAY_FILTER_USE_KEY);
            self::assertCount(1, $success, $name);
            self::assertArrayHasKey('application/ld+json', current($success)['content'], $name);
        }
    }

    public function testProtectedOperationsDeclareBothCredentialsAndPublicOnesNone(): void
    {
        $document = $this->document();

        self::assertEmpty($document['security'] ?? []);
        self::assertSame('bearer', $document['components']['securitySchemes']['bearerAuth']['scheme']);
        self::assertSame('cookie', $document['components']['securitySchemes']['sessionCookie']['in']);

        foreach (self::operations($document) as $name => $operation) {
            $expected = \in_array($name, self::PUBLIC_OPERATIONS, true) ? [] : [['bearerAuth' => []], ['sessionCookie' => []]];
            self::assertSame($expected, $operation['security'] ?? [], $name);
        }
    }

    public function testEveryTagIsDescribedAndUsed(): void
    {
        $document = $this->document();

        $described = array_column($document['tags'], 'description', 'name');
        self::assertContainsOnlyString($described);
        $used = array_merge(...array_column(self::operations($document), 'tags'));
        self::assertEqualsCanonicalizing(array_keys($described), array_values(array_unique($used)));
    }

    public function testIdentifiersAreDocumentedAsUuids(): void
    {
        foreach (self::operations($this->document()) as $name => $operation) {
            foreach ($operation['parameters'] ?? [] as $parameter) {
                if ('uuid' === $parameter['name']) {
                    self::assertSame('uuid', $parameter['schema']['format'], $name);
                    self::assertNotSame('', $parameter['description'], $name);
                    self::assertStringNotContainsString('identifier', $parameter['description'], $name);
                }
            }
        }
    }

    public function testTheGeneralDescriptionExplainsHowToUseTheApi(): void
    {
        $info = $this->document()['info'];

        foreach (['## Authentication', '## CSRF', '## Formats', '## Errors'] as $section) {
            self::assertStringContainsString($section, $info['description']);
        }
        self::assertSame('https://www.linkedin.com/in/juanluisgarciaborrego/', $info['contact']['url']);
        self::assertArrayNotHasKey('email', $info['contact']);
    }

    /** Comments for developers must not reach the public document: they used to, through the class docblocks. */
    public function testNoInternalNotesLeak(): void
    {
        $document = $this->document();
        unset($document['components']['schemas']);
        $text = (string) json_encode($document);

        foreach (['Doctrine', 'Manager', 'Processor', 'Provider', 'VitaminaTech', 'by Sky Pulse report'] as $internal) {
            self::assertStringNotContainsString($internal, $text);
        }
    }
}
