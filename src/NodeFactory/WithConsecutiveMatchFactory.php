<?php

declare(strict_types=1);

namespace Rector\PHPUnit\NodeFactory;

use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\PhpParser\Node\BetterNodeFinder;

final readonly class WithConsecutiveMatchFactory
{
    public function __construct(
        private NodeNameResolver $nodeNameResolver,
        private BetterNodeFinder $betterNodeFinder,
        private BuilderFactory $builderFactory,
    ) {
    }

    /**
     * @param Stmt[] $returnStmts
     */
    public function createClosure(
        MethodCall $expectsMethodCall,
        array $returnStmts,
        Variable|Expr|null $referenceVariable
    ): Closure {
        $byRef = $referenceVariable instanceof Variable;

        $closure = new Closure([
            'byRef' => $byRef,
        ]);

        $matcherVariable = new Variable('matcher');
        $closure->uses[] = new ClosureUse($matcherVariable);

        $usedVariables = $this->resolveUniqueUsedVariables([
            ...$expectsMethodCall->getArgs(),
            ...$this->resolveUniqueUsedVariables($returnStmts),
        ]);
        foreach ($usedVariables as $usedVariable) {
            $closureUse = new ClosureUse($usedVariable);
            if ($byRef && $this->nodeNameResolver->areNamesEqual($usedVariable, $referenceVariable)) {
                $closureUse->byRef = true;
            }

            $closure->uses[] = $closureUse;
        }

        $parametersVariable = new Variable('parameters');

        $match = $this->createParametersMatch($matcherVariable, $expectsMethodCall, $parametersVariable);
        $closure->params[] = new Param($parametersVariable);
        $closure->stmts = [new Expression($match), ...$returnStmts];

        return $closure;
    }

    public function createParametersMatch(
        Variable $matcherVariable,
        MethodCall $expectsMethodCall,
        Variable $parameters
    ): Match_ {
        $numberOfInvocationsMethodCall = new MethodCall($matcherVariable, new Identifier('numberOfInvocations'));

        $matchArms = [];
        foreach ($expectsMethodCall->getArgs() as $key => $arg) {
            $assertEquals = $this->builderFactory->staticCall('self', 'assertEquals', [$arg, $parameters]);
            $matchArms[] = new MatchArm([new LNumber($key + 1)], $assertEquals);
        }

        return new Match_($numberOfInvocationsMethodCall, $matchArms);
    }

    /**
     * @param Node[] $nodes
     * @return Variable[]
     */
    private function resolveUniqueUsedVariables(array $nodes): array
    {
        /** @var Variable[] $usedVariables */
        $usedVariables = $this->betterNodeFinder->findInstancesOfScoped($nodes, Variable::class);

        $uniqueUsedVariables = [];

        foreach ($usedVariables as $usedVariable) {
            if ($this->nodeNameResolver->isNames($usedVariable, ['this', 'matcher', 'parameters'])) {
                continue;
            }

            $usedVariableName = $this->nodeNameResolver->getName($usedVariable);
            $uniqueUsedVariables[$usedVariableName] = $usedVariable;
        }

        return $uniqueUsedVariables;
    }
}