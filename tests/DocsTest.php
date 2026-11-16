<?php

declare(strict_types=1);

namespace BarkaPay\Tests;

use BarkaPay\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the documentation honest: every PHP sample must at least parse, and
 * must only name classes, methods and properties that exist.
 */
final class DocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * @return list<string> the ```php blocks of the README
     */
    private static function readmeSnippets(): array
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        preg_match_all('/```php\n(.*?)```/s', $readme, $matches);

        return $matches[1];
    }

    private static function lint(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sdkdoc');
        self::assertIsString($file);
        file_put_contents($file, str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n" . $code);

        try {
            exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
        } finally {
            unlink($file);
        }

        return $exitCode === 0 ? '' : implode("\n", $output);
    }

    public function testReadmeHasCodeSamples(): void
    {
        self::assertGreaterThanOrEqual(10, \count(self::readmeSnippets()));
    }

    public function testEveryReadmeSampleIsValidPhp(): void
    {
        foreach (self::readmeSnippets() as $i => $snippet) {
            self::assertSame('', self::lint($snippet), 'README sample #' . ($i + 1) . " does not parse:\n" . $snippet);
        }
    }

    public function testReadmeOnlyNamesClassesThatExist(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        preg_match_all('/\bBarkaPay\\\\[A-Za-z\\\\]+/', $readme, $matches);

        $names = array_unique($matches[0]);
        self::assertNotEmpty($names);

        foreach ($names as $name) {
            $name = rtrim($name, '\\');
            self::assertTrue(
                class_exists($name) || interface_exists($name) || enum_exists($name) || is_dir(self::ROOT . '/src/' . str_replace(['BarkaPay\\', '\\'], ['', '/'], $name)),
                'README mentions ' . $name . ', which does not exist.',
            );
        }
    }

    public function testReadmeOnlyCallsMethodsThatExist(): void
    {
        $client = new \ReflectionClass(Client::class);
        $readme = implode("\n", self::readmeSnippets());

        // $barkapay->resource->method(…)
        preg_match_all('/\$barkapay->(\w+)->(\w+)\(/', $readme, $calls, PREG_SET_ORDER);
        self::assertNotEmpty($calls);
        foreach ($calls as [, $resource, $method]) {
            self::assertTrue($client->hasProperty($resource), 'Unknown resource $barkapay->' . $resource);
            $type = $client->getProperty($resource)->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertTrue(
                method_exists($type->getName(), $method),
                'README calls ' . $resource . '->' . $method . '(), which does not exist.',
            );
        }

        // $barkapay->method(…)
        preg_match_all('/\$barkapay->(\w+)\(/', $readme, $direct);
        foreach (array_unique($direct[1]) as $method) {
            self::assertTrue($client->hasMethod($method), 'README calls $barkapay->' . $method . '(), which does not exist.');
        }

        // Constructor arguments used as named parameters.
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $client->getConstructor()?->getParameters() ?? [],
        );
        preg_match_all('/new Client\((.*?)\);/s', $readme, $constructions);
        foreach ($constructions[1] as $arguments) {
            preg_match_all('/^\s*(\w+):/m', $arguments, $named);
            foreach ($named[1] as $name) {
                self::assertContains($name, $parameters, 'README passes an unknown Client option: ' . $name);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        foreach (glob(self::ROOT . '/examples/*.php') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('examples')]
    public function testEveryExampleIsValidPhp(string $file): void
    {
        self::assertSame('', self::lint((string) file_get_contents($file)));
    }

    public function testVersionConstantMatchesTheChangelog(): void
    {
        $changelog = (string) file_get_contents(self::ROOT . '/CHANGELOG.md');

        // The first released section of the changelog is the current version.
        self::assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\] - \d{4}-\d{2}-\d{2}$/m', $changelog, $m));
        self::assertSame(Client::VERSION, $m[1] ?? null, 'Client::VERSION and the latest CHANGELOG section disagree.');
        self::assertStringContainsString('[' . Client::VERSION . ']: https://github.com/BarkaPay/barkapay-php/compare/', $changelog);
    }

    public function testDocumentationUsesTheBrandInLowercase(): void
    {
        foreach (['README.md', 'SECURITY.md', 'CONTRIBUTING.md'] as $file) {
            $text = (string) file_get_contents(self::ROOT . '/' . $file);

            // Drop what legitimately spells it otherwise: code, URLs, package / org / header names.
            $prose = (string) preg_replace('/```.*?```|`[^`]*`|\]\([^)]*\)|https?:\/\/\S+|\S+@\S+/s', '', $text);

            self::assertDoesNotMatchRegularExpression('/\bpayhub\b/i', $prose, $file . ' shows the internal name in prose.');
            self::assertDoesNotMatchRegularExpression('/\b(BarkaPay|Barkapay|BARKAPAY)\b/', $prose, $file . ' must write the brand in lowercase.');
        }
    }
}
