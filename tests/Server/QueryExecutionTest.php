<?php

declare(strict_types=1);

namespace GraphQL\Tests\Server;

use GraphQL\Error\Debug;
use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\CustomValidationRule;
use GraphQL\Validator\ValidationContext;
use function count;
use function sprintf;

class QueryExecutionTest extends ServerTestCase
{
    /** @var ServerConfig */
    private $config;

    public function setUp()
    {
        $schema       = $this->buildSchema();
        $this->config = ServerConfig::create()
            ->setSchema($schema);
    }

    public function testSimpleQueryExecution() : void
    {
        $query = '{f1}';

        $expected = [
            'data' => ['f1' => 'f1'],
        ];

        $this->assertQueryResultEquals($expected, $query);
    }

    private function assertQueryResultEquals($expected, $query, $variables = null)
    {
        $result = $this->executeQuery($query, $variables);
        $this->assertArraySubset($expected, $result->toArray(true));

        return $result;
    }

    private function executeQuery($query, $variables = null, $readonly = false)
    {
        $op     = OperationParams::create(['query' => $query, 'variables' => $variables], $readonly);
        $helper = new Helper();
        $result = $helper->executeOperation($this->config, $op);
        $this->assertInstanceOf(ExecutionResult::class, $result);

        return $result;
    }

    public function testReturnsSyntaxErrors() : void
    {
        $query = '{f1';

        $result = $this->executeQuery($query);
        $this->assertNull($result->data);
        $this->assertCount(1, $result->errors);
        $this->assertContains(
            'Syntax Error: Expected Name, found <EOF>',
            $result->errors[0]->getMessage()
        );
    }

    public function testDebugExceptions() : void
    {
        $debug = Debug::INCLUDE_DEBUG_MESSAGE | Debug::INCLUDE_TRACE;
        $this->config->setDebug($debug);

        $query = '
        {
            fieldWithSafeException
            f1
        }
        ';

        $expected = [
            'data' => [
                'fieldWithSafeException' => null,
                'f1'                 => 'f1',
            ],
            'errors' => [
                [
                    'message' => 'This is the exception we want',
                    'path' => ['fieldWithSafeException'],
                    'trace' => [],
                ],
            ],
        ];

        $result = $this->executeQuery($query)->toArray();
        $this->assertArraySubset($expected, $result);
    }

    public function testRethrowUnsafeExceptions() : void
    {
        $this->config->setDebug(Debug::RETHROW_UNSAFE_EXCEPTIONS);
        $this->expectException(Unsafe::class);

        $this->executeQuery('
        {
            fieldWithUnsafeException
        }
        ')->toArray();
    }

    public function testPassesRootValueAndContext() : void
    {
        $rootValue = 'myRootValue';
        $context   = new \stdClass();

        $this->config
            ->setContext($context)
            ->setRootValue($rootValue);

        $query = '
        {
            testContextAndRootValue
        }
        ';

        $this->assertTrue(! isset($context->testedRootValue));
        $this->executeQuery($query);
        $this->assertSame($rootValue, $context->testedRootValue);
    }

    public function testPassesVariables() : void
    {
        $variables = ['a' => 'a', 'b' => 'b'];
        $query     = '
            query ($a: String!, $b: String!) {
                a: fieldWithArg(arg: $a)
                b: fieldWithArg(arg: $b)
            }
        ';
        $expected  = [
            'data' => [
                'a' => 'a',
                'b' => 'b',
            ],
        ];
        $this->assertQueryResultEquals($expected, $query, $variables);
    }

    public function testPassesCustomValidationRules() : void
    {
        $query    = '
            {nonExistentField}
        ';
        $expected = [
            'errors' => [
                ['message' => 'Cannot query field "nonExistentField" on type "Query".'],
            ],
        ];

        $this->assertQueryResultEquals($expected, $query);

        $called = false;

        $rules = [
            new CustomValidationRule('SomeRule', function () use (&$called) {
                $called = true;

                return [];
            }),
        ];

        $this->config->setValidationRules($rules);
        $expected = [
            'data' => [],
        ];
        $this->assertQueryResultEquals($expected, $query);
        $this->assertTrue($called);
    }

    public function testAllowsValidationRulesAsClosure() : void
    {
        $called = false;
        $params = $doc = $operationType = null;

        $this->config->setValidationRules(function ($p, $d, $o) use (&$called, &$params, &$doc, &$operationType) {
            $called        = true;
            $params        = $p;
            $doc           = $d;
            $operationType = $o;

            return [];
        });

        $this->assertFalse($called);
        $this->executeQuery('{f1}');
        $this->assertTrue($called);
        $this->assertInstanceOf(OperationParams::class, $params);
        $this->assertInstanceOf(DocumentNode::class, $doc);
        $this->assertEquals('query', $operationType);
    }

    public function testAllowsDifferentValidationRulesDependingOnOperation() : void
    {
        $q1      = '{f1}';
        $q2      = '{invalid}';
        $called1 = false;
        $called2 = false;

        $this->config->setValidationRules(function (OperationParams $params) use ($q1, &$called1, &$called2) {
            if ($params->query === $q1) {
                $called1 = true;

                return DocumentValidator::allRules();
            }

            $called2 = true;

            return [
                new CustomValidationRule('MyRule', function (ValidationContext $context) {
                    $context->reportError(new Error('This is the error we are looking for!'));
                }),
            ];
        });

        $expected = ['data' => ['f1' => 'f1']];
        $this->assertQueryResultEquals($expected, $q1);
        $this->assertTrue($called1);
        $this->assertFalse($called2);

        $called1  = false;
        $called2  = false;
        $expected = ['errors' => [['message' => 'This is the error we are looking for!']]];
        $this->assertQueryResultEquals($expected, $q2);
        $this->assertFalse($called1);
        $this->assertTrue($called2);
    }

    public function testAllowsSkippingValidation() : void
    {
        $this->config->setValidationRules([]);
        $query    = '{nonExistentField}';
        $expected = ['data' => []];
        $this->assertQueryResultEquals($expected, $query);
    }

    public function testPersistedQueriesAreDisabledByDefault() : void
    {
        $result = $this->executePersistedQuery('some-id');

        $expected = [
            'errors' => [
                [
                    'message'  => 'Persisted queries are not supported by this server',
                    'category' => 'request',
                ],
            ],
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    private function executePersistedQuery($queryId, $variables = null)
    {
        $op     = OperationParams::create(['queryId' => $queryId, 'variables' => $variables]);
        $helper = new Helper();
        $result = $helper->executeOperation($this->config, $op);
        $this->assertInstanceOf(ExecutionResult::class, $result);

        return $result;
    }

    public function testBatchedQueriesAreDisabledByDefault() : void
    {
        $batch = [
            ['query' => '{invalid}'],
            ['query' => '{f1,fieldWithSafeException}'],
        ];

        $result = $this->executeBatchedQuery($batch);

        $expected = [
            [
                'errors' => [
                    [
                        'message'  => 'Batched queries are not supported by this server',
                        'category' => 'request',
                    ],
                ],
            ],
            [
                'errors' => [
                    [
                        'message'  => 'Batched queries are not supported by this server',
                        'category' => 'request',
                    ],
                ],
            ],
        ];

        $this->assertEquals($expected[0], $result[0]->toArray());
        $this->assertEquals($expected[1], $result[1]->toArray());
    }

    /**
     * @param mixed[][] $qs
     */
    private function executeBatchedQuery(array $qs)
    {
        $batch = [];
        foreach ($qs as $params) {
            $batch[] = OperationParams::create($params);
        }
        $helper = new Helper();
        $result = $helper->executeBatch($this->config, $batch);
        $this->assertInternalType('array', $result);
        $this->assertCount(count($qs), $result);

        foreach ($result as $index => $entry) {
            $this->assertInstanceOf(
                ExecutionResult::class,
                $entry,
                sprintf('Result at %s is not an instance of %s', $index, ExecutionResult::class)
            );
        }

        return $result;
    }

    public function testMutationsAreNotAllowedInReadonlyMode() : void
    {
        $mutation = 'mutation { a }';

        $expected = [
            'errors' => [
                [
                    'message'  => 'GET supports only query operation',
                    'category' => 'request',
                ],
            ],
        ];

        $result = $this->executeQuery($mutation, null, true);
        $this->assertEquals($expected, $result->toArray());
    }

    public function testAllowsPersistentQueries() : void
    {
        $called = false;
        $this->config->setPersistentQueryLoader(function ($queryId, OperationParams $params) use (&$called) {
            $called = true;
            $this->assertEquals('some-id', $queryId);

            return '{f1}';
        });

        $result = $this->executePersistedQuery('some-id');
        $this->assertTrue($called);

        $expected = [
            'data' => ['f1' => 'f1'],
        ];
        $this->assertEquals($expected, $result->toArray());

        // Make sure it allows returning document node:
        $called = false;
        $this->config->setPersistentQueryLoader(function ($queryId, OperationParams $params) use (&$called) {
            $called = true;
            $this->assertEquals('some-id', $queryId);

            return Parser::parse('{f1}');
        });
        $result = $this->executePersistedQuery('some-id');
        $this->assertTrue($called);
        $this->assertEquals($expected, $result->toArray());
    }

    public function testProhibitsInvalidPersistedQueryLoader() : void
    {
        $this->expectException(InvariantViolation::class);
        $this->expectExceptionMessage(
            'Persistent query loader must return query string or instance of GraphQL\Language\AST\DocumentNode ' .
            'but got: {"err":"err"}'
        );
        $this->config->setPersistentQueryLoader(function () {
            return ['err' => 'err'];
        });
        $this->executePersistedQuery('some-id');
    }

    public function testPersistedQueriesAreStillValidatedByDefault() : void
    {
        $this->config->setPersistentQueryLoader(function () {
            return '{invalid}';
        });
        $result   = $this->executePersistedQuery('some-id');
        $expected = [
            'errors' => [
                [
                    'message'   => 'Cannot query field "invalid" on type "Query".',
                    'locations' => [['line' => 1, 'column' => 2]],
                    'category'  => 'graphql',
                ],
            ],
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    public function testAllowSkippingValidationForPersistedQueries() : void
    {
        $this->config
            ->setPersistentQueryLoader(function ($queryId) {
                if ($queryId === 'some-id') {
                    return '{invalid}';
                }

                return '{invalid2}';
            })
            ->setValidationRules(function (OperationParams $params) {
                if ($params->queryId === 'some-id') {
                    return [];
                }

                return DocumentValidator::allRules();
            });

        $result   = $this->executePersistedQuery('some-id');
        $expected = [
            'data' => [],
        ];
        $this->assertEquals($expected, $result->toArray());

        $result   = $this->executePersistedQuery('some-other-id');
        $expected = [
            'errors' => [
                [
                    'message'   => 'Cannot query field "invalid2" on type "Query".',
                    'locations' => [['line' => 1, 'column' => 2]],
                    'category'  => 'graphql',
                ],
            ],
        ];
        $this->assertEquals($expected, $result->toArray());
    }

    public function testProhibitsUnexpectedValidationRules() : void
    {
        $this->expectException(InvariantViolation::class);
        $this->expectExceptionMessage('Expecting validation rules to be array or callable returning array, but got: instance of stdClass');
        $this->config->setValidationRules(function (OperationParams $params) {
            return new \stdClass();
        });
        $this->executeQuery('{f1}');
    }

    public function testExecutesBatchedQueries() : void
    {
        $this->config->setQueryBatching(true);

        $batch = [
            ['query' => '{invalid}'],
            ['query' => '{f1,fieldWithSafeException}'],
            [
                'query'     => '
                    query ($a: String!, $b: String!) {
                        a: fieldWithArg(arg: $a)
                        b: fieldWithArg(arg: $b)
                    }
                ',
                'variables' => ['a' => 'a', 'b' => 'b'],
            ],
        ];

        $result = $this->executeBatchedQuery($batch);

        $expected = [
            [
                'errors' => [['message' => 'Cannot query field "invalid" on type "Query".']],
            ],
            [
                'data' => [
                    'f1' => 'f1',
                    'fieldWithSafeException' => null,
                ],
                'errors' => [
                    ['message' => 'This is the exception we want'],
                ],
            ],
            [
                'data' => [
                    'a' => 'a',
                    'b' => 'b',
                ],
            ],
        ];

        $this->assertArraySubset($expected[0], $result[0]->toArray());
        $this->assertArraySubset($expected[1], $result[1]->toArray());
        $this->assertArraySubset($expected[2], $result[2]->toArray());
    }

    public function testDeferredsAreSharedAmongAllBatchedQueries() : void
    {
        $batch = [
            ['query' => '{dfd(num: 1)}'],
            ['query' => '{dfd(num: 2)}'],
            ['query' => '{dfd(num: 3)}'],
        ];

        $calls = [];

        $this->config
            ->setQueryBatching(true)
            ->setRootValue('1')
            ->setContext([
                'buffer' => function ($num) use (&$calls) {
                    $calls[] = sprintf('buffer: %d', $num);
                },
                'load'   => function ($num) use (&$calls) {
                    $calls[] = sprintf('load: %d', $num);

                    return sprintf('loaded: %d', $num);
                },
            ]);

        $result = $this->executeBatchedQuery($batch);

        $expectedCalls = [
            'buffer: 1',
            'buffer: 2',
            'buffer: 3',
            'load: 1',
            'load: 2',
            'load: 3',
        ];
        $this->assertEquals($expectedCalls, $calls);

        $expected = [
            [
                'data' => ['dfd' => 'loaded: 1'],
            ],
            [
                'data' => ['dfd' => 'loaded: 2'],
            ],
            [
                'data' => ['dfd' => 'loaded: 3'],
            ],
        ];

        $this->assertEquals($expected[0], $result[0]->toArray());
        $this->assertEquals($expected[1], $result[1]->toArray());
        $this->assertEquals($expected[2], $result[2]->toArray());
    }

    public function testValidatesParamsBeforeExecution() : void
    {
        $op     = OperationParams::create(['queryBad' => '{f1}']);
        $helper = new Helper();
        $result = $helper->executeOperation($this->config, $op);
        $this->assertInstanceOf(ExecutionResult::class, $result);

        $this->assertEquals(null, $result->data);
        $this->assertCount(1, $result->errors);

        $this->assertEquals(
            'GraphQL Request must include at least one of those two parameters: "query" or "queryId"',
            $result->errors[0]->getMessage()
        );

        $this->assertInstanceOf(
            RequestError::class,
            $result->errors[0]->getPrevious()
        );
    }

    public function testAllowsContextAsClosure() : void
    {
        $called = false;
        $params = $doc = $operationType = null;

        $this->config->setContext(function ($p, $d, $o) use (&$called, &$params, &$doc, &$operationType) {
            $called        = true;
            $params        = $p;
            $doc           = $d;
            $operationType = $o;
        });

        $this->assertFalse($called);
        $this->executeQuery('{f1}');
        $this->assertTrue($called);
        $this->assertInstanceOf(OperationParams::class, $params);
        $this->assertInstanceOf(DocumentNode::class, $doc);
        $this->assertEquals('query', $operationType);
    }

    public function testAllowsRootValueAsClosure() : void
    {
        $called = false;
        $params = $doc = $operationType = null;

        $this->config->setRootValue(function ($p, $d, $o) use (&$called, &$params, &$doc, &$operationType) {
            $called        = true;
            $params        = $p;
            $doc           = $d;
            $operationType = $o;
        });

        $this->assertFalse($called);
        $this->executeQuery('{f1}');
        $this->assertTrue($called);
        $this->assertInstanceOf(OperationParams::class, $params);
        $this->assertInstanceOf(DocumentNode::class, $doc);
        $this->assertEquals('query', $operationType);
    }

    public function testAppliesErrorFormatter() : void
    {
        $called = false;
        $error  = null;
        $this->config->setErrorFormatter(function ($e) use (&$called, &$error) {
            $called = true;
            $error  = $e;

            return ['test' => 'formatted'];
        });

        $result = $this->executeQuery('{fieldWithSafeException}');
        $this->assertFalse($called);
        $formatted = $result->toArray();
        $expected  = [
            'errors' => [
                ['test' => 'formatted'],
            ],
        ];
        $this->assertTrue($called);
        $this->assertArraySubset($expected, $formatted);
        $this->assertInstanceOf(Error::class, $error);

        // Assert debugging still works even with custom formatter
        $formatted = $result->toArray(Debug::INCLUDE_TRACE);
        $expected  = [
            'errors' => [
                [
                    'test'  => 'formatted',
                    'trace' => [],
                ],
            ],
        ];
        $this->assertArraySubset($expected, $formatted);
    }

    public function testAppliesErrorsHandler() : void
    {
        $called    = false;
        $errors    = null;
        $formatter = null;
        $this->config->setErrorsHandler(function ($e, $f) use (&$called, &$errors, &$formatter) {
            $called    = true;
            $errors    = $e;
            $formatter = $f;

            return [
                ['test' => 'handled'],
            ];
        });

        $result = $this->executeQuery('{fieldWithSafeException,test: fieldWithSafeException}');

        $this->assertFalse($called);
        $formatted = $result->toArray();
        $expected  = [
            'errors' => [
                ['test' => 'handled'],
            ],
        ];
        $this->assertTrue($called);
        $this->assertArraySubset($expected, $formatted);
        $this->assertInternalType('array', $errors);
        $this->assertCount(2, $errors);
        $this->assertInternalType('callable', $formatter);
        $this->assertArraySubset($expected, $formatted);
    }
}
