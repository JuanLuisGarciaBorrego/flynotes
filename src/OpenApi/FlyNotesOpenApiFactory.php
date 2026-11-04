<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Contact;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\Model\SecurityScheme;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * What the operations' attributes cannot say. Each operation declares its own responses (src/ApiResource); this adds
 * what they all share and removes what API Platform assumes without knowing the application.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class FlyNotesOpenApiFactory implements OpenApiFactoryInterface
{
    /** Responses API Platform adds on its own, so they are not known to happen in that operation. */
    private const array ASSUMED_RESPONSES = ['Invalid input', 'An error occurred', 'Forbidden', 'Not found'];
    /** The `security` of the OpenAPI operation of everything that needs an identity. */
    public const array AUTHENTICATION = [['bearerAuth' => []], ['sessionCookie' => []]];
    /** Here and not in api_platform.yaml: `openapi.tags` makes objects of a container parameter, which cannot be dumped. */
    private const array TAGS = [
        'Paper planes' => 'Launch, discover and stamp planes, and review the AI version of a message before it flies.',
        'My planes' => 'The planes of the authenticated human and their statistics.',
        'Participants' => 'Human identities. Register, recover an identity with its code, and read your profile.',
        'Sky Pulse missions' => 'The research agent, which needs two human approvals. A human authorizes the research tool, then decides whether its report is published.',
    ];
    private const array UUID_DESCRIPTIONS = ['/agent_missions/' => 'UUID of the mission.', '/paper_planes/' => 'UUID of the paper plane.'];

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $paths = $openApi->getPaths();
        foreach ($paths->getPaths() as $path => $item) {
            if (null !== $get = $item->getGet()) {
                $item = $item->withGet($this->complete($get, $path));
            }
            if (null !== $post = $item->getPost()) {
                $item = $item->withPost($this->complete($post, $path));
            }
            $paths->addPath($path, $item);
        }

        // Each protected operation declares it (see AUTHENTICATION): a global requirement would also cover the public ones.
        $tags = array_map(static fn (string $name, string $description): Tag => new Tag($name, $description), array_keys(self::TAGS), self::TAGS);

        // Here and not in api_platform.yaml: `openapi.contact` is dropped without an email, and none is published.
        $contact = new Contact('Juan Luis García', 'https://www.linkedin.com/in/juanluisgarciaborrego/');

        return $openApi->withInfo($openApi->getInfo()->withContact($contact))->withSecurity([])->withTags($tags)->withComponents($openApi->getComponents()->withSecuritySchemes(new \ArrayObject([
            'bearerAuth' => new SecurityScheme(
                type: 'http',
                description: 'The recovery code of a human participant, sent as `Authorization: Bearer <code>`. Exempt from the CSRF check.',
                scheme: 'bearer',
            ),
            'sessionCookie' => new SecurityScheme(
                type: 'apiKey',
                description: 'The web session started by registering or recovering an identity. Writes must also pass the CSRF check: come from the same origin or send the `csrf-token` header.',
                name: 'flynotes_session',
                in: 'cookie',
            ),
        ])));
    }

    private function complete(Operation $operation, string $path): Operation
    {
        $responses = [];
        foreach ($operation->getResponses() ?? [] as $status => $response) {
            if (\in_array($response->getDescription(), self::ASSUMED_RESPONSES, true)) {
                continue;
            }
            $responses[$status] = (int) $status >= 400 ? $this->asProblem($response, (int) $status) : $response;
        }

        return $operation
            ->withResponses($responses)
            ->withParameters(array_map(fn (Parameter $parameter): Parameter => $this->completeParameter($parameter, $path), $operation->getParameters() ?? []));
    }

    /** Errors are RFC 7807 documents whatever the client accepts; validation ones also list their violations. */
    private function asProblem(Response $response, int $status): Response
    {
        $schema = 422 === $status ? 'ConstraintViolation.jsonld' : 'Error.jsonld';

        return $response->withContent(new \ArrayObject([
            'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]],
        ]));
    }

    private function completeParameter(Parameter $parameter, string $path): Parameter
    {
        if ('uuid' !== $parameter->getName()) {
            return $parameter;
        }

        foreach (self::UUID_DESCRIPTIONS as $prefix => $description) {
            if (str_contains($path, $prefix)) {
                $parameter = $parameter->withDescription($description);
            }
        }

        return $parameter->withSchema(['type' => 'string', 'format' => 'uuid']);
    }
}
