<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sebastka\Domeneshop\DomeneshopClient;

/**
 * The documentation pages inline the spec into a <script> block, which is the
 * kind of thing that breaks quietly: a renamed placeholder, or a description
 * containing "</script>", would produce a file that still looks fine on disk
 * and is blank in the browser. These tests build every page for real and read
 * it back.
 */
final class DocsPageTest extends TestCase
{
    /** @var array<string, string> */
    private static array $built = [];

    /**
     * Which renderers exist, and whether each has a try-it console at all.
     * Redoc's open-source build does not — that is Redocly's paid product.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function rendererProvider(): iterable
    {
        yield 'swagger' => ['swagger', 'swagger-ui.html', true];
        yield 'rapidoc' => ['rapidoc', 'rapidoc.html', true];
        yield 'redoc' => ['redoc', 'redoc.html', false];
    }

    /**
     * Only the renderers that actually have a try-it console.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function interactiveRendererProvider(): iterable
    {
        foreach (self::rendererProvider() as $name => $case) {
            if ($case[2]) {
                yield $name => $case;
            }
        }
    }

    /**
     * Only the renderers that have none.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function nonInteractiveRendererProvider(): iterable
    {
        foreach (self::rendererProvider() as $name => $case) {
            if (! $case[2]) {
                yield $name => $case;
            }
        }
    }

    /** Build a page for real and return its HTML, memoised per variant. */
    private static function html(string $renderer, bool $proxy = false): string
    {
        $key = $renderer . ($proxy ? ':proxy' : ':static');

        if (! isset(self::$built[$key])) {
            $out = tempnam(sys_get_temp_dir(), 'domeneshop-docs-') . '.html';

            exec(\sprintf(
                '%s %s --renderer=%s %s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(__DIR__ . '/../bin/build-docs-page.php'),
                escapeshellarg($renderer),
                $proxy ? '--proxy' : '',
                escapeshellarg($out),
            ), $output, $status);
            self::assertSame(0, $status, "build-docs-page.php failed:\n" . implode("\n", $output));

            $html = file_get_contents($out);
            self::assertIsString($html);
            @unlink($out);

            self::$built[$key] = $html;
        }

        return self::$built[$key];
    }

    /**
     * Every template must carry all three placeholders. The builder refuses to
     * write a page if one goes missing, so a rename cannot silently produce a
     * blank or spec-less page.
     */
    #[DataProvider('rendererProvider')]
    public function testTheTemplateCarriesEveryPlaceholder(string $renderer, string $file, bool $interactive): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../resources/' . $file);

        self::assertStringContainsString('/*__OPENAPI_SPEC__*/ null', $template);
        self::assertStringContainsString('/*__UI_OVERRIDES__*/ {}', $template);
        self::assertStringContainsString('<!--__BANNER__-->', $template);
    }

    #[DataProvider('rendererProvider')]
    public function testTheBuiltPageHasNoPlaceholderLeft(string $renderer, string $file, bool $interactive): void
    {
        foreach ([false, true] as $proxy) {
            $html = self::html($renderer, $proxy);
            self::assertStringNotContainsString('__OPENAPI_SPEC__', $html);
            self::assertStringNotContainsString('__UI_OVERRIDES__', $html);
            self::assertStringNotContainsString('__BANNER__', $html);
        }
    }

    #[DataProvider('rendererProvider')]
    public function testTheInlinedSpecIsValidJsonAndComplete(string $renderer, string $file, bool $interactive): void
    {
        $spec = self::inlinedSpec($renderer);

        // Pinned to swagger-php's own constant: the version the generator emits
        // and the version baked into the page must not drift apart.
        self::assertSame(\OpenApi\Annotations\OpenApi::VERSION_3_2_0, $spec['openapi']);
        self::assertSame('Domeneshop API', $spec['info']['title']);
        self::assertCount(9, $spec['paths']);
        self::assertArrayHasKey('TLSA', $spec['components']['schemas']);
        self::assertArrayHasKey('basicAuth', $spec['components']['securitySchemes']);
    }

    /**
     * A literal `</script>` inside any description would close the block early
     * and leave a blank page, so the builder escapes it. This is the regression
     * test for that.
     */
    #[DataProvider('rendererProvider')]
    public function testNothingClosesTheScriptBlockEarly(string $renderer, string $file, bool $interactive): void
    {
        $html = self::html($renderer);

        $start = strpos($html, 'const spec =');
        self::assertIsInt($start);

        $end = strpos($html, '</script>', $start);
        self::assertIsInt($end);

        self::assertDoesNotMatchRegularExpression('#</script#i', substr($html, $start, $end - $start));
    }

    /**
     * Opened as a plain file, every request would be blocked by CORS. A submit
     * button that always fails is worse than no button.
     */
    #[DataProvider('rendererProvider')]
    public function testTheStaticBuildDisablesTryItAndTargetsTheRealApi(string $renderer, string $file, bool $interactive): void
    {
        $spec = self::inlinedSpec($renderer);
        self::assertSame(DomeneshopClient::DEFAULT_BASE_URI, $spec['servers'][0]['url']);

        // No overrides at all is what keeps each template's own "off" default.
        self::assertSame([], self::overrides($renderer));
    }

    /**
     * The preview's whole purpose: point the spec at the router's same-origin
     * prefix, so requests never leave the browser cross-origin and CORS never
     * comes into it.
     */
    #[DataProvider('interactiveRendererProvider')]
    public function testThePreviewBuildTargetsTheProxyNotTheApi(string $renderer, string $file, bool $interactive): void
    {

        $spec = self::inlinedSpec($renderer, proxy: true);

        self::assertSame('/__proxy', $spec['servers'][0]['url']);

        // No server entry may point at the API directly — that is the one that
        // would be blocked by CORS. (The document's externalDocs link is a
        // different thing and is expected to stay.)
        foreach ($spec['servers'] as $server) {
            self::assertStringStartsNotWith('http', $server['url']);
        }
    }

    #[DataProvider('interactiveRendererProvider')]
    public function testThePreviewEnablesTheTryItConsole(string $renderer, string $file, bool $interactive): void
    {

        $overrides = self::overrides($renderer, proxy: true);

        self::assertNotSame([], $overrides, 'the preview build applied no overrides');

        // Each renderer spells it differently; both must end up switched on.
        $enabled = ($overrides['tryItOutEnabled'] ?? null) === true
            || ($overrides['allow-try'] ?? null) === 'true';

        self::assertTrue($enabled, 'try-it is not enabled in the ' . $renderer . ' preview');
    }

    /**
     * A renderer with no console must never advertise the proxy: displaying an
     * endpoint that nothing on the page can call is worse than useless.
     */
    #[DataProvider('nonInteractiveRendererProvider')]
    public function testANonInteractivePreviewKeepsTheRealServerUrl(string $renderer, string $file, bool $interactive): void
    {

        $spec = self::inlinedSpec($renderer, proxy: true);

        self::assertSame(DomeneshopClient::DEFAULT_BASE_URI, $spec['servers'][0]['url']);
        self::assertSame([], self::overrides($renderer, proxy: true));
    }

    /** The reader should be told why there is nothing to click. */
    #[DataProvider('nonInteractiveRendererProvider')]
    public function testANonInteractivePreviewSaysSo(string $renderer, string $file, bool $interactive): void
    {

        self::assertStringContainsString('no try-it console', self::html($renderer, proxy: true));
    }

    /**
     * Empty overrides must still be an object: a template doing
     * `overrides.foo` against a JSON array would misbehave.
     */
    #[DataProvider('rendererProvider')]
    public function testOverridesAreAlwaysAnObject(string $renderer, string $file, bool $interactive): void
    {
        self::assertStringContainsString('const overrides = {}', self::html($renderer));
    }

    #[DataProvider('rendererProvider')]
    public function testAssetsArePinnedToAnExactVersion(string $renderer, string $file, bool $interactive): void
    {
        // An unpinned CDN URL turns a working page into a time bomb.
        $html = self::html($renderer);
        $package = match ($renderer) {
            'swagger' => 'swagger-ui-dist',
            'rapidoc' => 'rapidoc',
            'redoc' => 'redoc',
        };

        self::assertMatchesRegularExpression('#' . preg_quote($package, '#') . '@\d+\.\d+\.\d+/#', $html);
        self::assertStringNotContainsString($package . '@latest', $html);
    }

    #[DataProvider('rendererProvider')]
    public function testThePageDeclaresItselfUnofficial(string $renderer, string $file, bool $interactive): void
    {
        self::assertStringContainsString('Unofficial', self::html($renderer));
    }

    /** The preview hits the live API: creates and deletes actually happen. */
    #[DataProvider('interactiveRendererProvider')]
    public function testThePreviewWarnsThatCallsAreReal(string $renderer, string $file, bool $interactive): void
    {

        $html = self::html($renderer, proxy: true);

        self::assertStringContainsString('real API', $html);
        self::assertStringContainsString('localhost', $html);
    }

    public function testAnUnknownRendererIsRejected(): void
    {
        exec(\sprintf(
            '%s %s --renderer=nonsense 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../bin/build-docs-page.php'),
        ), $output, $status);

        self::assertNotSame(0, $status);
        self::assertStringContainsString('Unknown renderer', implode("\n", $output));
    }

    /**
     * The builder writes the prefix into the spec and the router reads requests
     * off it. If the two ever disagree, try-it 404s with no clue why.
     */
    public function testTheProxyPrefixMatchesBetweenBuilderAndRouter(): void
    {
        $builder = (string) file_get_contents(__DIR__ . '/../bin/build-docs-page.php');
        $router = (string) file_get_contents(__DIR__ . '/../bin/docs-router.php');

        self::assertSame(1, preg_match("/const PROXY_PREFIX = '([^']+)'/", $builder, $inBuilder));
        self::assertSame(1, preg_match("/const PROXY_PREFIX = '([^']+)'/", $router, $inRouter));

        self::assertSame(
            $inBuilder[1],
            $inRouter[1],
            'build-docs-page.php and docs-router.php disagree on the proxy prefix',
        );
    }

    /** @return array<string, mixed> */
    private static function inlinedSpec(string $renderer, bool $proxy = false): array
    {
        return self::extractJson(self::html($renderer, $proxy), 'spec');
    }

    /** @return array<string, mixed> */
    private static function overrides(string $renderer, bool $proxy = false): array
    {
        return self::extractJson(self::html($renderer, $proxy), 'overrides');
    }

    /** @return array<string, mixed> */
    private static function extractJson(string $html, string $variable): array
    {
        self::assertSame(
            1,
            preg_match('/const ' . $variable . ' = (\{.*?\});?\n/s', $html, $matches),
            "Could not find the inlined \"{$variable}\" in the built page.",
        );

        // Undo the escaping the builder applies before handing it to json_decode.
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(str_replace('<\\/', '</', $matches[1]), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
