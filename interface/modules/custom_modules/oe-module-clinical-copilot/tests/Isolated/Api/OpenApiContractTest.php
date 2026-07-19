<?php

/**
 * OpenAPI contract tests: ops/api/openapi.yaml vs the real endpoint files.
 *
 * The Week 2 API spec is hand-maintained, so nothing stops it from drifting
 * away from the implementation. These tests bind the two together with
 * static-analysis-style assertions over the endpoint sources: every declared
 * path/method/parameter/status must be visible in the PHP that serves it, and
 * every input/status the PHP uses must be declared back in the spec.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Api;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Failure mode guarded: ops/api/openapi.yaml silently drifting from the
 * endpoints under public/ — a documented operation whose file was renamed or
 * deleted, a declared parameter the code never reads (or an input the code
 * reads that the spec hides), an action enum missing a handled action, a
 * status code the page can emit but the spec never mentions, or a brand-new
 * public endpoint shipped without any spec entry. Consumers of the spec
 * (the Bruno collection, integrators) would build against fiction.
 */
final class OpenApiContractTest extends TestCase
{
    private const SPEC_PATH_PREFIX = '/interface/modules/custom_modules/oe-module-clinical-copilot/public/';

    /**
     * public/ endpoints intentionally OUTSIDE the Week 2 ingestion spec
     * (Week 1 / ops surfaces). A new public/*.php must either be documented
     * in openapi.yaml or consciously added here — never silently unspecified.
     */
    private const SPEC_SCOPE_EXEMPT = [
        'chat.php',            // Week 1 chat surface (U11)
        'dashboard.php',       // observability dashboard (ARCHITECTURE.md §3.3)
        'doc.php',             // Week 1 pre-visit synthesis page (U8)
        'event.php',           // over-reliance indicator ping (ARCHITECTURE.md §2.5)
        'intake_form_pdf.php', // static blank-form PDF download feeding intake_upload.php
        'status.php',          // Week 1 chat/synthesis polling fallback (ARCHITECTURE.md §1.3)
    ];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /**
     * Failure mode: a spec path pointing at a file that was renamed/deleted,
     * or at anything outside the module's public/ surface.
     */
    public function testEveryDeclaredPathMapsToARealEndpointFile(): void
    {
        foreach (array_keys(self::specPaths()) as $specPath) {
            self::assertStringStartsWith(
                self::SPEC_PATH_PREFIX,
                $specPath,
                "Spec path '{$specPath}' is outside the module's public/ endpoint surface."
            );
            self::assertFileExists(
                self::endpointFile($specPath),
                "Spec path '{$specPath}' has no matching endpoint file under public/."
            );
        }
    }

    /**
     * Failure mode: the spec declaring a method the page does not handle
     * (POST on a read-only page) or omitting one it does (a page that reads
     * \$_POST but is documented GET-only). Every module page answers GET
     * (plain PHP file), so a spec path without a get operation is also drift.
     */
    public function testDeclaredMethodsMatchImplementationHandling(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $ops = self::operations($pathItem);
            $src = self::source($specPath);
            $handlesPost = str_contains($src, "\$_POST") || preg_match("/REQUEST_METHOD'?\]\s*\?\?\s*'GET'\)\s*===\s*'POST'/", $src) === 1;

            self::assertArrayHasKey('get', $ops, "'{$specPath}': every public/*.php page serves GET, but the spec declares no get operation.");

            if (isset($ops['post'])) {
                self::assertTrue($handlesPost, "'{$specPath}': spec declares POST but the implementation never reads the POST request.");
            } else {
                self::assertFalse($handlesPost, "'{$specPath}': implementation handles POST input but the spec declares no post operation.");
            }

            $extra = array_diff(array_keys($ops), ['get', 'post']);
            self::assertSame([], array_values($extra), "'{$specPath}': spec declares methods the implementation cannot dispatch: " . implode(', ', $extra));
        }
    }

    /**
     * Failure mode: a documented parameter the code never reads — the classic
     * "required in the spec, ignored by the page" lie. Query parameters must
     * appear as \$_GET reads; request-body properties as \$_POST reads (binary
     * ones as \$_FILES); csrf_token_form is consumed by CsrfUtils, so its
     * contract is the checkCsrfInput call.
     */
    public function testDeclaredParametersAreReadByImplementation(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $src = self::source($specPath);
            $getKeys = self::superglobalKeys($src, '_GET');
            $postKeys = self::superglobalKeys($src, '_POST');
            $fileKeys = self::superglobalKeys($src, '_FILES');

            foreach (self::queryParameters($pathItem) as $name) {
                self::assertContains($name, $getKeys, "'{$specPath}': declared query parameter '{$name}' is never read via \$_GET.");
            }

            foreach (self::requestBodyProperties($pathItem) as $name => $property) {
                if ($name === 'csrf_token_form') {
                    self::assertStringContainsString('CsrfUtils::checkCsrfInput', $src, "'{$specPath}': spec requires csrf_token_form but the implementation never checks CSRF.");
                    continue;
                }
                if (($property['format'] ?? null) === 'binary') {
                    self::assertContains($name, $fileKeys, "'{$specPath}': declared file-upload property '{$name}' is never read via \$_FILES.");
                    continue;
                }
                self::assertContains($name, $postKeys, "'{$specPath}': declared body property '{$name}' is never read via \$_POST.");
            }
        }
    }

    /**
     * Failure mode (reverse direction): the implementation reading an input
     * the spec does not document — an undocumented knob a spec consumer can
     * never discover (this is exactly how collection_date and the intake
     * save-path fields went missing before these tests existed).
     */
    public function testImplementationInputsAreAllDeclared(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $src = self::source($specPath);
            $declaredQuery = self::queryParameters($pathItem);
            $declaredBody = array_keys(self::requestBodyProperties($pathItem));

            foreach (self::superglobalKeys($src, '_GET') as $key) {
                self::assertContains($key, $declaredQuery, "'{$specPath}': implementation reads \$_GET['{$key}'] but the spec declares no such query parameter.");
            }
            foreach (self::superglobalKeys($src, '_POST') as $key) {
                self::assertContains($key, $declaredBody, "'{$specPath}': implementation reads \$_POST['{$key}'] but the spec declares no such body property.");
            }
            foreach (self::superglobalKeys($src, '_FILES') as $key) {
                self::assertContains($key, $declaredBody, "'{$specPath}': implementation reads \$_FILES['{$key}'] but the spec declares no such body property.");
            }
        }
    }

    /**
     * Failure mode: the spec's action enum and the page's dispatched actions
     * diverging — a handled action missing from the enum (undiscoverable
     * operation) or an enum value the page silently ignores (documented
     * operation that does nothing).
     */
    public function testActionEnumMatchesHandledActions(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $properties = self::requestBodyProperties($pathItem);
            if (!isset($properties['action']['enum'])) {
                continue;
            }
            $declared = $properties['action']['enum'];
            self::assertIsArray($declared);
            sort($declared);

            $handled = self::handledActions(self::source($specPath));
            sort($handled);

            self::assertSame(
                $declared,
                $handled,
                "'{$specPath}': spec action enum [" . implode(', ', $declared) . '] != actions dispatched by the implementation [' . implode(', ', $handled) . '].'
            );
        }
    }

    /**
     * Failure mode: a declared status the page cannot produce (a documented
     * 302 with no redirect, a documented 4xx with no http_response_code), so
     * a spec consumer handles branches that never happen.
     */
    public function testDeclaredStatusCodesAreEmittedByImplementation(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $src = self::source($specPath);
            $emitted = self::emittedStatusCodes($src);

            foreach (self::operations($pathItem) as $method => $op) {
                foreach (array_keys($op['responses'] ?? []) as $status) {
                    $status = (string)$status;
                    if ($status === '200') {
                        continue; // the default render path; every page has one
                    }
                    if ($status === '302') {
                        self::assertMatchesRegularExpression(
                            "/header\(\s*'Location:/",
                            $src,
                            "'{$specPath}' {$method}: spec declares 302 but the implementation never sends a Location header."
                        );
                        continue;
                    }
                    self::assertContains(
                        (int)$status,
                        $emitted,
                        "'{$specPath}' {$method}: spec declares {$status} but the implementation never calls http_response_code({$status})."
                    );
                }
            }
        }
    }

    /**
     * Failure mode (reverse direction): the page emitting a status the spec
     * never mentions (this is how intake_upload's 413 oversize guard went
     * undocumented before these tests existed).
     */
    public function testEmittedStatusCodesAreDeclared(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $declared = [];
            foreach (self::operations($pathItem) as $op) {
                foreach (array_keys($op['responses'] ?? []) as $status) {
                    $declared[] = (int)$status;
                }
            }
            foreach (self::emittedStatusCodes(self::source($specPath)) as $code) {
                self::assertContains($code, $declared, "'{$specPath}': implementation emits http_response_code({$code}) but no operation on this path declares a {$code} response.");
            }
        }
    }

    /**
     * Failure mode: content-type drift. A page that starts emitting JSON (or
     * any explicit Content-Type) while the spec still documents the implicit
     * HTML page — or a spec that promises a response/request content type the
     * code does not honour (multipart declared with no \$_FILES read;
     * urlencoded declared while the page actually consumes file uploads).
     */
    public function testContentTypesMatchImplementation(): void
    {
        foreach (self::specPaths() as $specPath => $pathItem) {
            $src = self::source($specPath);

            // Explicit Content-Type headers emitted by the page must be
            // declared on some response; these pages emit implicit HTML today.
            preg_match_all("/header\(\s*['\"]Content-Type:\s*([^;'\"]+)/i", $src, $m);
            $emittedTypes = array_map(trim(...), $m[1]);

            $declaredResponseTypes = [];
            foreach (self::operations($pathItem) as $op) {
                foreach (($op['responses'] ?? []) as $response) {
                    $declaredResponseTypes = array_merge($declaredResponseTypes, array_keys($response['content'] ?? []));
                }
            }
            foreach ($emittedTypes as $type) {
                self::assertContains($type, $declaredResponseTypes, "'{$specPath}': implementation emits Content-Type '{$type}' but no declared response documents it.");
            }
            foreach ($declaredResponseTypes as $type) {
                if ($type === 'text/html') {
                    continue; // implicit PHP output; no explicit header expected
                }
                self::assertContains($type, $emittedTypes, "'{$specPath}': spec declares response content type '{$type}' the implementation never emits.");
            }

            // Request side: multipart implies file-upload handling and vice versa.
            $requestTypes = [];
            foreach (self::operations($pathItem) as $op) {
                $requestTypes = array_merge($requestTypes, array_keys($op['requestBody']['content'] ?? []));
            }
            $readsFiles = self::superglobalKeys($src, '_FILES') !== [];
            if (in_array('multipart/form-data', $requestTypes, true)) {
                self::assertTrue($readsFiles, "'{$specPath}': spec declares a multipart/form-data body but the implementation never reads \$_FILES.");
            } elseif ($requestTypes !== []) {
                self::assertFalse($readsFiles, "'{$specPath}': implementation reads \$_FILES but the spec declares a non-multipart request body.");
            }
        }
    }

    /**
     * Failure mode (completeness direction): a new public endpoint shipped
     * with no spec entry and no conscious exemption — the spec quietly
     * becoming a partial map of the module's HTTP surface. The exempt list is
     * cross-checked both ways so it cannot rot either.
     */
    public function testEveryPublicEndpointIsSpecifiedOrKnownExempt(): void
    {
        $specified = array_map(basename(...), array_keys(self::specPaths()));

        $files = glob(self::moduleRoot() . '/public/*.php');
        self::assertNotFalse($files);
        self::assertNotSame([], $files, 'No endpoint files found under public/ — wrong module root?');

        foreach ($files as $file) {
            $name = basename($file);
            self::assertTrue(
                in_array($name, $specified, true) || in_array($name, self::SPEC_SCOPE_EXEMPT, true),
                "public/{$name} is neither documented in ops/api/openapi.yaml nor listed in SPEC_SCOPE_EXEMPT — document it or consciously exempt it."
            );
        }

        foreach (self::SPEC_SCOPE_EXEMPT as $name) {
            self::assertFileExists(self::moduleRoot() . '/public/' . $name, "SPEC_SCOPE_EXEMPT lists public/{$name}, which no longer exists — prune the exemption.");
            self::assertNotContains($name, $specified, "public/{$name} is both documented in the spec and listed in SPEC_SCOPE_EXEMPT — remove the exemption.");
        }
    }

    // ---------------------------------------------------------------- helpers

    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, array<string, mixed>> spec path => path item */
    private static function specPaths(): array
    {
        if (self::$spec === null) {
            $file = self::moduleRoot() . '/ops/api/openapi.yaml';
            self::assertFileExists($file);
            $parsed = Yaml::parseFile($file);
            self::assertIsArray($parsed, 'openapi.yaml did not parse to a mapping.');
            self::$spec = $parsed;
        }
        $paths = self::$spec['paths'] ?? null;
        self::assertIsArray($paths, 'openapi.yaml declares no paths.');
        self::assertNotSame([], $paths, 'openapi.yaml declares no paths.');

        return $paths;
    }

    private static function endpointFile(string $specPath): string
    {
        return self::moduleRoot() . '/public/' . basename($specPath);
    }

    private static function source(string $specPath): string
    {
        $src = file_get_contents(self::endpointFile($specPath));
        self::assertIsString($src);

        return $src;
    }

    /**
     * @param array<string, mixed> $pathItem
     *
     * @return array<string, array<string, mixed>> lowercase HTTP method => operation
     */
    private static function operations(array $pathItem): array
    {
        $ops = [];
        foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
            if (isset($pathItem[$method]) && is_array($pathItem[$method])) {
                $ops[$method] = $pathItem[$method];
            }
        }

        return $ops;
    }

    /**
     * @param array<string, mixed> $pathItem
     *
     * @return list<string> declared in:query parameter names across operations
     */
    private static function queryParameters(array $pathItem): array
    {
        $names = [];
        foreach (self::operations($pathItem) as $op) {
            foreach (($op['parameters'] ?? []) as $parameter) {
                if (is_array($parameter) && ($parameter['in'] ?? '') === 'query' && is_string($parameter['name'] ?? null)) {
                    $names[] = $parameter['name'];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, mixed> $pathItem
     *
     * @return array<string, array<string, mixed>> requestBody schema property name => schema, across operations
     */
    private static function requestBodyProperties(array $pathItem): array
    {
        $properties = [];
        foreach (self::operations($pathItem) as $op) {
            foreach (($op['requestBody']['content'] ?? []) as $media) {
                foreach (($media['schema']['properties'] ?? []) as $name => $schema) {
                    if (is_string($name) && is_array($schema)) {
                        $properties[$name] = $schema;
                    }
                }
            }
        }

        return $properties;
    }

    /** @return list<string> literal string keys read from the given superglobal */
    private static function superglobalKeys(string $src, string $superglobal): array
    {
        preg_match_all('/\$' . $superglobal . "\['([A-Za-z0-9_]+)'\]/", $src, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Actions the page dispatches on: `$action === 'x'` comparisons plus, when
     * the page switches on $action, its `case 'x':` labels.
     *
     * @return list<string>
     */
    private static function handledActions(string $src): array
    {
        preg_match_all("/\\\$action\s*===\s*'([A-Za-z0-9_]+)'/", $src, $m);
        $actions = $m[1];
        if (preg_match('/switch\s*\(\s*\$action\s*\)/', $src) === 1) {
            preg_match_all("/case\s+'([A-Za-z0-9_]+)':/", $src, $m);
            $actions = array_merge($actions, $m[1]);
        }

        return array_values(array_unique($actions));
    }

    /** @return list<int> literal http_response_code(...) statuses */
    private static function emittedStatusCodes(string $src): array
    {
        preg_match_all('/http_response_code\((\d{3})\)/', $src, $m);

        return array_values(array_unique(array_map(intval(...), $m[1])));
    }
}
