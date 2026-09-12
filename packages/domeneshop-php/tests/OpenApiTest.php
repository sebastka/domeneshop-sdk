<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use OpenApi\Annotations\OpenApi;
use OpenApi\Generator;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI document is generated from the #[OA\*] attributes on the resource
 * methods, so it cannot drift from the client. These tests hold the generated
 * spec to the things we publish it for — full coverage, and the corrections it
 * makes to the official document.
 */
final class OpenApiTest extends TestCase
{
    private static ?OpenApi $spec = null;

    /** @var array<string, mixed>|null */
    private static ?array $decoded = null;

    private static function spec(): OpenApi
    {
        if (self::$spec === null) {
            $generated = (new Generator())->generate([__DIR__ . '/../src'], validate: false);
            self::assertNotNull($generated, 'No OpenAPI attributes were found under src/.');
            $generated->openapi = OpenApi::VERSION_3_2_0;
            self::$spec = $generated;
        }

        return self::$spec;
    }

    /** @return array<string, mixed> */
    private static function decoded(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = self::$decoded ??= json_decode(self::spec()->toJson(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testTheSpecValidates(): void
    {
        self::assertTrue(self::spec()->validate(), 'The generated spec failed swagger-php validation.');
    }

    public function testEveryOperationIsPresent(): void
    {
        self::assertEqualsCanonicalizing([
            'listDomains', 'getDomain',
            'listDnsRecords', 'getDnsRecord', 'createDnsRecord', 'replaceDnsRecord', 'deleteDnsRecord',
            'listForwards', 'getForward', 'createForward', 'replaceForward', 'deleteForward',
            'listInvoices', 'getInvoice',
            'updateDynDns',
        ], self::operationIds());
    }

    /**
     * The official document leaves operationId off ten of its fifteen
     * operations, which breaks client generators. Ours names every one.
     */
    public function testEveryOperationHasAnOperationId(): void
    {
        $withoutId = 0;
        foreach (self::eachOperation() as $operation) {
            if (! isset($operation['operationId'])) {
                $withoutId++;
            }
        }

        self::assertSame(0, $withoutId);
    }

    /** Every operation needs credentials, so every operation must say so. */
    public function testEveryOperationDeclaresBasicAuth(): void
    {
        foreach (self::eachOperation() as $path => $operation) {
            self::assertSame(
                [['basicAuth' => []]],
                $operation['security'] ?? null,
                "{$path} does not declare basicAuth",
            );
        }

        self::assertArrayHasKey('basicAuth', self::decoded()['components']['securitySchemes']);
    }

    /**
     * Every endpoint needs credentials, so every endpoint can answer 401 — and
     * the published document says so nowhere at all. It is defined once as a
     * shared component and referenced, so it cannot be right on some operations
     * and missing on others.
     */
    public function testEveryOperationDocumentsUnauthorized(): void
    {
        foreach (self::eachOperation() as $path => $operation) {
            self::assertArrayHasKey('401', $operation['responses'] ?? [], "{$path} does not document a 401");
            self::assertSame(
                '#/components/responses/Unauthorized',
                $operation['responses']['401']['$ref'] ?? null,
                "{$path} does not use the shared Unauthorized response",
            );
        }

        self::assertArrayHasKey('Unauthorized', self::decoded()['components']['responses']);
    }

    /**
     * An operation that takes an id in its path can be given one that does not
     * exist. The published document leaves 404 off several of them.
     */
    public function testOperationsWithAPathIdDocumentNotFound(): void
    {
        foreach (self::eachOperation() as $path => $operation) {
            if (! str_contains($path, '{')) {
                continue;
            }

            self::assertArrayHasKey('404', $operation['responses'] ?? [], "{$path} does not document a 404");
        }
    }

    public function testEveryOperationIsTaggedAndDescribed(): void
    {
        foreach (self::eachOperation() as $path => $operation) {
            self::assertNotEmpty($operation['tags'] ?? [], "{$path} has no tag");
            self::assertNotEmpty($operation['summary'] ?? '', "{$path} has no summary");
            self::assertNotEmpty($operation['description'] ?? '', "{$path} has no description");
        }
    }

    /**
     * The official document omits the request body for creating a forward
     * entirely, which makes the operation look like it takes none.
     */
    public function testCreatingAForwardDocumentsItsRequestBody(): void
    {
        $operation = self::decoded()['paths']['/domains/{domainId}/forwards/']['post'];

        self::assertSame(
            '#/components/schemas/HTTPForward',
            $operation['requestBody']['content']['application/json']['schema']['$ref'],
        );
        self::assertTrue($operation['requestBody']['required']);
    }

    /**
     * The official TLSA schema requires only usage/selector/dtype, so a record
     * with no type and no hash would validate against it.
     */
    public function testTlsaRequiresItsTypeAndHashToo(): void
    {
        $required = [];
        foreach (self::decoded()['components']['schemas']['TLSA']['allOf'] as $part) {
            $required = array_merge($required, $part['required'] ?? []);
        }

        self::assertEqualsCanonicalizing(['type', 'data', 'usage', 'selector', 'dtype'], $required);
    }

    public function testEveryRecordTypeHasASchemaAndIsInTheUnion(): void
    {
        $schemas = self::decoded()['components']['schemas'];
        $union = array_column(self::decoded()['components']['schemas']['DNSRecord']['oneOf'], '$ref');

        foreach (['A', 'AAAA', 'CNAME', 'MX', 'SRV', 'TLSA', 'TXT'] as $type) {
            self::assertArrayHasKey($type, $schemas, "No schema for {$type}");
            self::assertContains("#/components/schemas/{$type}", $union, "{$type} is missing from DNSRecord");
        }
    }

    public function testTheServerIsTheRealApiEndpoint(): void
    {
        self::assertSame(
            \Sebastka\Domeneshop\DomeneshopClient::DEFAULT_BASE_URI,
            self::decoded()['servers'][0]['url'],
        );
    }

    /** @return list<string> */
    private static function operationIds(): array
    {
        $ids = [];
        foreach (self::eachOperation() as $operation) {
            if (isset($operation['operationId'])) {
                $ids[] = $operation['operationId'];
            }
        }

        return $ids;
    }

    /** @return iterable<string, array<string, mixed>> */
    private static function eachOperation(): iterable
    {
        foreach (self::decoded()['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'delete', 'patch'] as $method) {
                if (isset($item[$method])) {
                    yield strtoupper($method) . ' ' . $path => $item[$method];
                }
            }
        }
    }
}
