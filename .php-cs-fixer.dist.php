<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude([
        'vendor',
        'var',
        'bin',
    ])
    ->name('*.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'concat_space' => ['spacing' => 'none'],
        'array_syntax' => ['syntax' => 'short'],
        'combine_consecutive_issets' => true,
        'explicit_indirect_variable' => true,
        'no_useless_return' => true,
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'no_spaces_after_function_name' => true,
        'no_spaces_inside_parenthesis' => true,
        'ternary_operator_spaces' => true,
        'class_definition' => ['single_line' => true],
        'whitespace_after_comma_in_array' => true,

        // phpdoc
        'phpdoc_add_missing_param_annotation' => ['only_untyped' => true],
        'phpdoc_order' => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm' => 'alpha',
        ],
        'phpdoc_no_empty_return' => false,
        'phpdoc_summary' => false,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'expectedExceptionMessageRegExp',
                'expectedException',
                'expectedExceptionMessage',
                'author',
            ],
        ],
    ])
    ->setFinder($finder)
;
