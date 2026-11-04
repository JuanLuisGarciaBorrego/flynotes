<?php

declare(strict_types=1);

namespace App\Mcp\Helper;

use Mcp\Exception\ToolCallException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates MCP tool arguments against the constraints of the matching API Platform resource, so both
 * channels share one source of rules. Symfony constraints cannot target parameters, so each argument
 * is checked as the value of the resource property with the same name.
 */
final readonly class ToolValidationHelper
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param class-string         $resource
     * @param array<string, mixed> $arguments
     * @param string[]|null        $groups    null runs the Default group
     *
     * @throws ToolCallException listing every violation, as "Invalid arguments. <property>: <message> ..."
     */
    public function validate(string $resource, array $arguments, ?array $groups = null): void
    {
        $messages = [];
        foreach ($arguments as $property => $value) {
            foreach ($this->validator->validatePropertyValue($resource, $property, $value, $groups) as $violation) {
                $messages[] = $property.': '.$violation->getMessage();
            }
        }

        if ([] !== $messages) {
            throw new ToolCallException('Invalid arguments. '.implode(' ', $messages));
        }
    }
}
