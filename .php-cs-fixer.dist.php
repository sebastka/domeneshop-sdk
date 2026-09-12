<?php

declare(strict_types=1);

/**
 * Code style for the monorepo's PHP packages.
 *
 * Deliberately narrow. The rule that earns its keep here is
 * `native_function_invocation`: inside a namespace, an unqualified call like
 * `sprintf()` makes PHP look for `Sebastka\Domeneshop\sprintf()` first and only
 * then fall back to the global one, which blocks the compiler from
 * substituting its specialised handling. Prefixing `\` restores it.
 *
 * The `@compiler_optimized` set is the curated list of functions that actually
 * benefit; we do not prefix every global call, which would be noise for no gain.
 *
 * Class references are left alone on purpose: `\RuntimeException` inline is a
 * deliberate choice in this codebase, and rewriting it to a `use` statement
 * would churn every file for no benefit.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/packages'])
    ->exclude(['vendor', 'build'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
    ])
    ->setFinder($finder);
