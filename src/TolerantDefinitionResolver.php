<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Protocol\TolerantSymbolInformation;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use phpDocumentor\Reflection\{
    DocBlockFactory, Types, Type, Fqsen, TypeResolver
};
use LanguageServer\Protocol\SymbolInformation;
use LanguageServer\Index\ReadableIndex;
use Microsoft\PhpParser as Tolerant;

class TolerantDefinitionResolver implements DefinitionResolverInterface
{
    /**
     * @var \LanguageServer\Index\ReadableIndex
     */
    private $index;

    /**
     * @var \phpDocumentor\Reflection\TypeResolver
     */
    private $typeResolver;

    /**
     * @var \PhpParser\PrettyPrinterAbstract
     */
    private $prettyPrinter;

    /**
     * @param ReadableIndex $index
     */
    public function __construct(ReadableIndex $index)
    {
        $this->index = $index;
        $this->typeResolver = new TypeResolver;
        $this->prettyPrinter = new PrettyPrinter;
    }

    /**
     * Builds the declaration line for a given node.
     *
     *
     * @param Tolerant\Node $node
     * @return string
     */
    public function getDeclarationLineFromNode($node): string
    {
        // TODO Tolerant\Node\Statement\FunctionStaticDeclaration::class

        // we should have a better way of determining whether something is a property or constant
        // If part of a declaration list -> get the parent declaration
        if (
            // PropertyDeclaration // public $a, $b, $c;
            $node instanceof Tolerant\Node\Expression\Variable &&
            ($propertyDeclaration = $node->getFirstAncestor(Tolerant\Node\PropertyDeclaration::class)) !== null
        ) {
            $defLine = $propertyDeclaration->getText();
            $defLineStart = $propertyDeclaration->getStart();

            $defLine = \substr_replace(
                $defLine,
                $node->getFullText(),
                $propertyDeclaration->propertyElements->getFullStart() - $defLineStart,
                $propertyDeclaration->propertyElements->getWidth()
            );
        } elseif (
            // ClassConstDeclaration or ConstDeclaration // const A = 1, B = 2;
            $node instanceof Tolerant\Node\ConstElement &&
            ($constDeclaration = $node->getFirstAncestor(Tolerant\Node\Statement\ConstDeclaration::class, Tolerant\Node\ClassConstDeclaration::class))
        ) {
            $defLine = $constDeclaration->getText();
            $defLineStart = $constDeclaration->getStart();

            $defLine = \substr_replace(
                $defLine,
                $node->getFullText(),
                $constDeclaration->constElements->getFullStart() - $defLineStart,
                $constDeclaration->constElements->getWidth()
            );
        }

        // Get the current node
        else {
            $defLine = $node->getText();
        }

        $defLine = \strtok($defLine, "\n");

        return $defLine;
    }

    /**
     * Gets the documentation string for a node, if it has one
     *
     * @param Tolerant\Node $node
     * @return string|null
     */
    public function getDocumentationFromNode($node)
    {
        // For properties and constants, set the node to the declaration node, rather than the individual property.
        // This is because they get defined as part of a list.
        $constOrPropertyDeclaration = $node->getFirstAncestor(
            Tolerant\Node\PropertyDeclaration::class,
            Tolerant\Node\Statement\ConstDeclaration::class,
            Tolerant\Node\ClassConstDeclaration::class
        );
        if ($constOrPropertyDeclaration !== null) {
            $node = $constOrPropertyDeclaration;
        }

        // For parameters, parse the documentation to get the parameter tag.
        if ($node instanceof Tolerant\Node\Parameter) {
            $functionLikeDeclaration = $this->getFunctionLikeDeclarationFromParameter($node);
            $variableName = $node->variableName->getText($node->getFileContents());
            $docBlock = $this->getDocBlock($functionLikeDeclaration);

            if ($docBlock !== null) {
                $parameterDocBlockTag = $this->getDocBlockTagForParameter($docBlock, $variableName);
                return $parameterDocBlockTag !== null ? $parameterDocBlockTag->getDescription()->render() : null;

            }
        }
        // for everything else, get the doc block summary corresponding to the current node.
        else {
            $docBlock = $this->getDocBlock($node);
            if ($docBlock !== null) {
                return $docBlock->getSummary();
            }
        }
    }

    function getDocBlock(Tolerant\Node $node) {
        // TODO context information
        static $docBlockFactory;
        $docBlockFactory = $docBlockFactory ?? DocBlockFactory::createInstance();
        $docCommentText = $node->getDocCommentText();
        return $docCommentText !== null ? $docBlockFactory->create($docCommentText) : null;
    }

    /**
     * Create a Definition for a definition node
     *
     * @param Tolerant\Node $node
     * @param string $fqn
     * @return Definition
     */
    public function createDefinitionFromNode($node, string $fqn = null): Definition
    {
        $def = new Definition;

        // this determines whether the suggestion will show after "new"
        $def->isClass = $node instanceof Tolerant\Node\Statement\ClassDeclaration;

        $def->isGlobal = (
            $node instanceof Tolerant\Node\Statement\InterfaceDeclaration
            || $node instanceof Tolerant\Node\Statement\ClassDeclaration
            || $node instanceof Tolerant\Node\Statement\TraitDeclaration

            || $node instanceof Tolerant\Node\Statement\NamespaceDefinition && $node->name !== null

            || $node instanceof Tolerant\Node\Statement\FunctionDeclaration

            || $node instanceof Tolerant\Node\Statement\ConstDeclaration
            || $node instanceof Tolerant\Node\ClassConstDeclaration
        );

        $def->isStatic = (
            ($node instanceof Tolerant\Node\MethodDeclaration && $node->isStatic())
            || ($node instanceof Tolerant\Node\Expression\Variable &&
                ($propertyDeclaration = $node->getFirstAncestor(Tolerant\Node\PropertyDeclaration::class)) !== null &&
                $propertyDeclaration->isStatic())
        );
        $def->fqn = $fqn;
        if ($node instanceof Tolerant\Node\Statement\ClassDeclaration) {
            $def->extends = [];
            if ($node->classBaseClause !== null && $node->classBaseClause->baseClass !== null) {
                $def->extends[] = (string)$node->classBaseClause->baseClass;
            }
            // TODO what about class interfaces
        } else if ($node instanceof Tolerant\Node\Statement\InterfaceDeclaration) {
            $def->extends = [];
            if ($node->interfaceBaseClause !== null && $node->interfaceBaseClause->interfaceNameList !== null) {
                foreach ($node->interfaceBaseClause->interfaceNameList->getChildNodes() as $n) {
                    $def->extends[] = (string)$n;
                }
            }
        }
        $def->symbolInformation = TolerantSymbolInformation::fromNode($node, $fqn);
//        $def->type = $this->getTypeFromNode($node); //TODO
        $def->type = new Types\Mixed;
        $def->declarationLine = $this->getDeclarationLineFromNode($node);
        $def->documentation = $this->getDocumentationFromNode($node);
        return $def;
    }

    /**
     * Given any node, returns the Definition object of the symbol that is referenced
     *
     * @param Node $node Any reference node
     * @return Definition|null
     */
    public function resolveReferenceNodeToDefinition($node)
    {
        // Variables are not indexed globally, as they stay in the file scope anyway
        if ($node instanceof Node\Expr\Variable) {
            // Resolve $this
            if ($node->name === 'this' && $fqn = $this->getContainingClassFqn($node)) {
                return $this->index->getDefinition($fqn, false);
            }
            // Resolve the variable to a definition node (assignment, param or closure use)
            $defNode = self::resolveVariableToNode($node);
            if ($defNode === null) {
                return null;
            }
            return $this->createDefinitionFromNode($defNode);
        }
        // Other references are references to a global symbol that have an FQN
        // Find out the FQN
        $fqn = $this->resolveReferenceNodeToFqn($node);
        if ($fqn === null) {
            return null;
        }
        // If the node is a function or constant, it could be namespaced, but PHP falls back to global
        // http://php.net/manual/en/language.namespaces.fallback.php
        $parent = $node->getAttribute('parentNode');
        $globalFallback = $parent instanceof Node\Expr\ConstFetch || $parent instanceof Node\Expr\FuncCall;
        // Return the Definition object from the index index
        return $this->index->getDefinition($fqn, $globalFallback);
    }

    /**
     * Given any node, returns the FQN of the symbol that is referenced
     * Returns null if the FQN could not be resolved or the reference node references a variable
     *
     * @param Node $node
     * @return string|null
     */
    public function resolveReferenceNodeToFqn($node)
    {
        $parent = $node->getAttribute('parentNode');

        if (
            $node instanceof Node\Name && (
                $parent instanceof Node\Stmt\ClassLike
                || $parent instanceof Node\Param
                || $parent instanceof Node\FunctionLike
                || $parent instanceof Node\Stmt\GroupUse
                || $parent instanceof Node\Expr\New_
                || $parent instanceof Node\Expr\StaticCall
                || $parent instanceof Node\Expr\ClassConstFetch
                || $parent instanceof Node\Expr\StaticPropertyFetch
                || $parent instanceof Node\Expr\Instanceof_
            )
        ) {
            // For extends, implements, type hints and classes of classes of static calls use the name directly
            $name = (string)$node;
        // Only the name node should be considered a reference, not the UseUse node itself
        } else if ($parent instanceof Node\Stmt\UseUse) {
            $name = (string)$parent->name;
            $grandParent = $parent->getAttribute('parentNode');
            if ($grandParent instanceof Node\Stmt\GroupUse) {
                $name = $grandParent->prefix . '\\' . $name;
            } else if ($grandParent instanceof Node\Stmt\Use_ && $grandParent->type === Node\Stmt\Use_::TYPE_FUNCTION) {
                $name .= '()';
            }
        } else if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\PropertyFetch) {
            if ($node->name instanceof Node\Expr) {
                // Cannot get definition if right-hand side is expression
                return null;
            }
            // Get the type of the left-hand expression
            $varType = $this->resolveExpressionNodeToType($node->var);
            if ($varType instanceof Types\Compound) {
                // For compound types, use the first FQN we find
                // (popular use case is ClassName|null)
                for ($i = 0; $t = $varType->get($i); $i++) {
                    if (
                        $t instanceof Types\This
                        || $t instanceof Types\Object_
                        || $t instanceof Types\Static_
                        || $t instanceof Types\Self_
                    ) {
                        $varType = $t;
                        break;
                    }
                }
            }
            if (
                $varType instanceof Types\This
                || $varType instanceof Types\Static_
                || $varType instanceof Types\Self_
            ) {
                // $this/static/self is resolved to the containing class
                $classFqn = self::getContainingClassFqn($node);
            } else if (!($varType instanceof Types\Object_) || $varType->getFqsen() === null) {
                // Left-hand expression could not be resolved to a class
                return null;
            } else {
                $classFqn = substr((string)$varType->getFqsen(), 1);
            }
            $memberSuffix = '->' . (string)$node->name;
            if ($node instanceof Node\Expr\MethodCall) {
                $memberSuffix .= '()';
            }
            // Find the right class that implements the member
            $implementorFqns = [$classFqn];
            while ($implementorFqn = array_shift($implementorFqns)) {
                // If the member FQN exists, return it
                if ($this->index->getDefinition($implementorFqn . $memberSuffix)) {
                    return $implementorFqn . $memberSuffix;
                }
                // Get Definition of implementor class
                $implementorDef = $this->index->getDefinition($implementorFqn);
                // If it doesn't exist, return the initial guess
                if ($implementorDef === null) {
                    break;
                }
                // Repeat for parent class
                if ($implementorDef->extends) {
                    foreach ($implementorDef->extends as $extends) {
                        $implementorFqns[] = $extends;
                    }
                }
            }
            return $classFqn . $memberSuffix;
        } else if ($parent instanceof Node\Expr\FuncCall && $node instanceof Node\Name) {
            if ($parent->name instanceof Node\Expr) {
                return null;
            }
            $name = (string)($node->getAttribute('namespacedName') ?? $parent->name);
        } else if ($parent instanceof Node\Expr\ConstFetch && $node instanceof Node\Name) {
            $name = (string)($node->getAttribute('namespacedName') ?? $parent->name);
        } else if (
            $node instanceof Node\Expr\ClassConstFetch
            || $node instanceof Node\Expr\StaticPropertyFetch
            || $node instanceof Node\Expr\StaticCall
        ) {
            if ($node->class instanceof Node\Expr || $node->name instanceof Node\Expr) {
                // Cannot get definition of dynamic names
                return null;
            }
            $className = (string)$node->class;
            if ($className === 'self' || $className === 'static' || $className === 'parent') {
                // self and static are resolved to the containing class
                $classNode = getClosestNode($node, Node\Stmt\Class_::class);
                if ($classNode === null) {
                    return null;
                }
                if ($className === 'parent') {
                    // parent is resolved to the parent class
                    if (!isset($n->extends)) {
                        return null;
                    }
                    $className = (string)$classNode->extends;
                } else {
                    $className = (string)$classNode->namespacedName;
                }
            }
            if ($node instanceof Node\Expr\StaticPropertyFetch) {
                $name = (string)$className . '::$' . $node->name;
            } else {
                $name = (string)$className . '::' . $node->name;
            }
        } else {
            return null;
        }
        if (!isset($name)) {
            return null;
        }
        if (
            $node instanceof Node\Expr\MethodCall
            || $node instanceof Node\Expr\StaticCall
            || $parent instanceof Node\Expr\FuncCall
        ) {
            $name .= '()';
        }
        return $name;
    }

    /**
     * Returns FQN of the class a node is contained in
     * Returns null if the class is anonymous or the node is not contained in a class
     *
     * @param Node $node
     * @return string|null
     */
    private static function getContainingClassFqn(Node $node)
    {
        $classNode = getClosestNode($node, Node\Stmt\Class_::class);
        if ($classNode === null || $classNode->isAnonymous()) {
            return null;
        }
        return (string)$classNode->namespacedName;
    }

    /**
     * Returns the assignment or parameter node where a variable was defined
     *
     * @param Node\Expr\Variable|Node\Expr\ClosureUse $var The variable access
     * @return Node\Expr\Assign|Node\Expr\AssignOp|Node\Param|Node\Expr\ClosureUse|null
     */
    public static function resolveVariableToNode(Node\Expr $var)
    {
        $n = $var;
        // When a use is passed, start outside the closure to not return immediatly
        if ($var instanceof Node\Expr\ClosureUse) {
            $n = $var->getAttribute('parentNode')->getAttribute('parentNode');
            $name = $var->var;
        } else if ($var instanceof Node\Expr\Variable || $var instanceof Node\Param) {
            $name = $var->name;
        } else {
            throw new \InvalidArgumentException('$var must be Variable, Param or ClosureUse, not ' . get_class($var));
        }
        // Traverse the AST up
        do {
            // If a function is met, check the parameters and use statements
            if ($n instanceof Node\FunctionLike) {
                foreach ($n->getParams() as $param) {
                    if ($param->name === $name) {
                        return $param;
                    }
                }
                // If it is a closure, also check use statements
                if ($n instanceof Node\Expr\Closure) {
                    foreach ($n->uses as $use) {
                        if ($use->var === $name) {
                            return $use;
                        }
                    }
                }
                break;
            }
            // Check each previous sibling node for a variable assignment to that variable
            while ($n->getAttribute('previousSibling') && $n = $n->getAttribute('previousSibling')) {
                if (
                    ($n instanceof Node\Expr\Assign || $n instanceof Node\Expr\AssignOp)
                    && $n->var instanceof Node\Expr\Variable && $n->var->name === $name
                ) {
                    return $n;
                }
            }
        } while (isset($n) && $n = $n->getAttribute('parentNode'));
        // Return null if nothing was found
        return null;
    }

    /**
     * Given an expression node, resolves that expression recursively to a type.
     * If the type could not be resolved, returns Types\Mixed.
     *
     * @param \PhpParser\Node\Expr $expr
     * @return \phpDocumentor\Reflection\Type
     */
    public function resolveExpressionNodeToType($expr): Type
    {
        if ($expr instanceof Node\Expr\Variable || $expr instanceof Node\Expr\ClosureUse) {
            if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
                return new Types\This;
            }
            // Find variable definition
            $defNode = $this->resolveVariableToNode($expr);
            if ($defNode instanceof Node\Expr) {
                return $this->resolveExpressionNodeToType($defNode);
            }
            if ($defNode instanceof Node\Param) {
                return $this->getTypeFromNode($defNode);
            }
        }
        if ($expr instanceof Node\Expr\FuncCall) {
            // Find the function definition
            if ($expr->name instanceof Node\Expr) {
                // Cannot get type for dynamic function call
                return new Types\Mixed;
            }
            $fqn = (string)($expr->getAttribute('namespacedName') ?? $expr->name);
            $def = $this->index->getDefinition($fqn, true);
            if ($def !== null) {
                return $def->type;
            }
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            if (strtolower((string)$expr->name) === 'true' || strtolower((string)$expr->name) === 'false') {
                return new Types\Boolean;
            }
            if (strtolower((string)$expr->name) === 'null') {
                return new Types\Null_;
            }
            // Resolve constant
            $fqn = (string)($expr->getAttribute('namespacedName') ?? $expr->name);
            $def = $this->index->getDefinition($fqn, true);
            if ($def !== null) {
                return $def->type;
            }
        }
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\PropertyFetch) {
            if ($expr->name instanceof Node\Expr) {
                return new Types\Mixed;
            }
            // Resolve object
            $objType = $this->resolveExpressionNodeToType($expr->var);
            if (!($objType instanceof Types\Compound)) {
                $objType = new Types\Compound([$objType]);
            }
            for ($i = 0; $t = $objType->get($i); $i++) {
                if ($t instanceof Types\This) {
                    $classFqn = self::getContainingClassFqn($expr);
                    if ($classFqn === null) {
                        return new Types\Mixed;
                    }
                } else if (!($t instanceof Types\Object_) || $t->getFqsen() === null) {
                    return new Types\Mixed;
                } else {
                    $classFqn = substr((string)$t->getFqsen(), 1);
                }
                $fqn = $classFqn . '->' . $expr->name;
                if ($expr instanceof Node\Expr\MethodCall) {
                    $fqn .= '()';
                }
                $def = $this->index->getDefinition($fqn);
                if ($def !== null) {
                    return $def->type;
                }
            }
        }
        if (
            $expr instanceof Node\Expr\StaticCall
            || $expr instanceof Node\Expr\StaticPropertyFetch
            || $expr instanceof Node\Expr\ClassConstFetch
        ) {
            $classType = self::resolveClassNameToType($expr->class);
            if (!($classType instanceof Types\Object_) || $classType->getFqsen() === null || $expr->name instanceof Node\Expr) {
                return new Types\Mixed;
            }
            $fqn = substr((string)$classType->getFqsen(), 1) . '::';
            if ($expr instanceof Node\Expr\StaticPropertyFetch) {
                $fqn .= '$';
            }
            $fqn .= $expr->name;
            if ($expr instanceof Node\Expr\StaticCall) {
                $fqn .= '()';
            }
            $def = $this->index->getDefinition($fqn);
            if ($def === null) {
                return new Types\Mixed;
            }
            return $def->type;
        }
        if ($expr instanceof Node\Expr\New_) {
            return self::resolveClassNameToType($expr->class);
        }
        if ($expr instanceof Node\Expr\Clone_ || $expr instanceof Node\Expr\Assign) {
            return $this->resolveExpressionNodeToType($expr->expr);
        }
        if ($expr instanceof Node\Expr\Ternary) {
            // ?:
            if ($expr->if === null) {
                return new Types\Compound([
                    $this->resolveExpressionNodeToType($expr->cond),
                    $this->resolveExpressionNodeToType($expr->else)
                ]);
            }
            // Ternary is a compound of the two possible values
            return new Types\Compound([
                $this->resolveExpressionNodeToType($expr->if),
                $this->resolveExpressionNodeToType($expr->else)
            ]);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            // ?? operator
            return new Types\Compound([
                $this->resolveExpressionNodeToType($expr->left),
                $this->resolveExpressionNodeToType($expr->right)
            ]);
        }
        if (
            $expr instanceof Node\Expr\Instanceof_
            || $expr instanceof Node\Expr\Cast\Bool_
            || $expr instanceof Node\Expr\BooleanNot
            || $expr instanceof Node\Expr\Empty_
            || $expr instanceof Node\Expr\Isset_
            || $expr instanceof Node\Expr\BinaryOp\Greater
            || $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual
            || $expr instanceof Node\Expr\BinaryOp\Smaller
            || $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual
            || $expr instanceof Node\Expr\BinaryOp\BooleanAnd
            || $expr instanceof Node\Expr\BinaryOp\BooleanOr
            || $expr instanceof Node\Expr\BinaryOp\LogicalAnd
            || $expr instanceof Node\Expr\BinaryOp\LogicalOr
            || $expr instanceof Node\Expr\BinaryOp\LogicalXor
            || $expr instanceof Node\Expr\BinaryOp\NotEqual
            || $expr instanceof Node\Expr\BinaryOp\NotIdentical
        ) {
            return new Types\Boolean;
        }
        if (
            $expr instanceof Node\Expr\Cast\String_
            || $expr instanceof Node\Expr\BinaryOp\Concat
            || $expr instanceof Node\Expr\AssignOp\Concat
            || $expr instanceof Node\Scalar\String_
            || $expr instanceof Node\Scalar\Encapsed
            || $expr instanceof Node\Scalar\EncapsedStringPart
            || $expr instanceof Node\Scalar\MagicConst\Class_
            || $expr instanceof Node\Scalar\MagicConst\Dir
            || $expr instanceof Node\Scalar\MagicConst\Function_
            || $expr instanceof Node\Scalar\MagicConst\Method
            || $expr instanceof Node\Scalar\MagicConst\Namespace_
            || $expr instanceof Node\Scalar\MagicConst\Trait_
        ) {
            return new Types\String_;
        }
        if (
            $expr instanceof Node\Expr\BinaryOp\Minus
            || $expr instanceof Node\Expr\BinaryOp\Plus
            || $expr instanceof Node\Expr\BinaryOp\Pow
            || $expr instanceof Node\Expr\BinaryOp\Mul
        ) {
            if (
                $this->resolveExpressionNodeToType($expr->left) instanceof Types\Integer
                && $this->resolveExpressionNodeToType($expr->right) instanceof Types\Integer
            ) {
                return new Types\Integer;
            }
            return new Types\Float_;
        }

        if (
            $expr instanceof Node\Expr\AssignOp\Minus
            || $expr instanceof Node\Expr\AssignOp\Plus
            || $expr instanceof Node\Expr\AssignOp\Pow
            || $expr instanceof Node\Expr\AssignOp\Mul
        ) {
            if (
                $this->resolveExpressionNodeToType($expr->var) instanceof Types\Integer
                && $this->resolveExpressionNodeToType($expr->expr) instanceof Types\Integer
            ) {
                return new Types\Integer;
            }
            return new Types\Float_;
        }

        if (
            $expr instanceof Node\Scalar\LNumber
            || $expr instanceof Node\Expr\Cast\Int_
            || $expr instanceof Node\Scalar\MagicConst\Line
            || $expr instanceof Node\Expr\BinaryOp\Spaceship
            || $expr instanceof Node\Expr\BinaryOp\BitwiseAnd
            || $expr instanceof Node\Expr\BinaryOp\BitwiseOr
            || $expr instanceof Node\Expr\BinaryOp\BitwiseXor
        ) {
            return new Types\Integer;
        }
        if (
            $expr instanceof Node\Expr\BinaryOp\Div
            || $expr instanceof Node\Scalar\DNumber
            || $expr instanceof Node\Expr\Cast\Double
        ) {
            return new Types\Float_;
        }
        if ($expr instanceof Node\Expr\Array_) {
            $valueTypes = [];
            $keyTypes = [];
            foreach ($expr->items as $item) {
                $valueTypes[] = $this->resolveExpressionNodeToType($item->value);
                $keyTypes[] = $item->key ? $this->resolveExpressionNodeToType($item->key) : new Types\Integer;
            }
            $valueTypes = array_unique($keyTypes);
            $keyTypes = array_unique($keyTypes);
            if (empty($valueTypes)) {
                $valueType = null;
            } else if (count($valueTypes) === 1) {
                $valueType = $valueTypes[0];
            } else {
                $valueType = new Types\Compound($valueTypes);
            }
            if (empty($keyTypes)) {
                $keyType = null;
            } else if (count($keyTypes) === 1) {
                $keyType = $keyTypes[0];
            } else {
                $keyType = new Types\Compound($keyTypes);
            }
            return new Types\Array_($valueType, $keyType);
        }
        if ($expr instanceof Node\Expr\ArrayDimFetch) {
            $varType = $this->resolveExpressionNodeToType($expr->var);
            if (!($varType instanceof Types\Array_)) {
                return new Types\Mixed;
            }
            return $varType->getValueType();
        }
        if ($expr instanceof Node\Expr\Include_) {
            // TODO: resolve path to PhpDocument and find return statement
            return new Types\Mixed;
        }
        return new Types\Mixed;
    }

    /**
     * Takes any class name node (from a static method call, or new node) and returns a Type object
     * Resolves keywords like self, static and parent
     *
     * @param Node $class
     * @return Type
     */
    private static function resolveClassNameToType(Node $class): Type
    {
        if ($class instanceof Node\Expr) {
            return new Types\Mixed;
        }
        if ($class instanceof Node\Stmt\Class_) {
            // Anonymous class
            return new Types\Object_;
        }
        $className = (string)$class;
        if ($className === 'static') {
            return new Types\Static_;
        }
        if ($className === 'self' || $className === 'parent') {
            $classNode = getClosestNode($class, Node\Stmt\Class_::class);
            if ($className === 'parent') {
                if ($classNode === null || $classNode->extends === null) {
                    return new Types\Object_;
                }
                // parent is resolved to the parent class
                $classFqn = (string)$classNode->extends;
            } else {
                if ($classNode === null) {
                    return new Types\Self_;
                }
                // self is resolved to the containing class
                $classFqn = (string)$classNode->namespacedName;
            }
            return new Types\Object_(new Fqsen('\\' . $classFqn));
        }
        return new Types\Object_(new Fqsen('\\' . $className));
    }

    /**
     * Returns the type a reference to this symbol will resolve to.
     * For properties and constants, this is the type of the property/constant.
     * For functions and methods, this is the return type.
     * For parameters, this is the type of the parameter.
     * For classes and interfaces, this is the class type (object).
     * For variables / assignments, this is the documented type or type the assignment resolves to.
     * Can also be a compound type.
     * If it is unknown, will be Types\Mixed.
     * Returns null if the node does not have a type.
     *
     * @param Node $node
     * @return \phpDocumentor\Reflection\Type|null
     */
    public function getTypeFromNode($node)
    {
        if ($node instanceof Node\Param) {
            // Parameters
            $docBlock = $node->getAttribute('parentNode')->getAttribute('docBlock');
            if ($docBlock !== null) {
                // Use @param tag
                foreach ($docBlock->getTagsByName('param') as $paramTag) {
                    if ($paramTag->getVariableName() === $node->name) {
                        if ($paramTag->getType() === null) {
                            break;
                        }
                        return $paramTag->getType();
                    }
                }
            }
            $type = null;
            if ($node->type !== null) {
                // Use PHP7 return type hint
                if (is_string($node->type)) {
                    // Resolve a string like "bool" to a type object
                    $type = $this->typeResolver->resolve($node->type);
                } else {
                    $type = new Types\Object_(new Fqsen('\\' . (string)$node->type));
                }
            }
            if ($node->default !== null) {
                $defaultType = $this->resolveExpressionNodeToType($node->default);
                if (isset($type) && !is_a($type, get_class($defaultType))) {
                    $type = new Types\Compound([$type, $defaultType]);
                } else {
                    $type = $defaultType;
                }
            }
            return $type ?? new Types\Mixed;
        }
        if ($node instanceof Node\FunctionLike) {
            // Functions/methods
            $docBlock = $node->getAttribute('docBlock');
            if (
                $docBlock !== null
                && !empty($returnTags = $docBlock->getTagsByName('return'))
                && $returnTags[0]->getType() !== null
            ) {
                // Use @return tag
                return $returnTags[0]->getType();
            }
            if ($node->returnType !== null) {
                // Use PHP7 return type hint
                if (is_string($node->returnType)) {
                    // Resolve a string like "bool" to a type object
                    return $this->typeResolver->resolve($node->returnType);
                }
                return new Types\Object_(new Fqsen('\\' . (string)$node->returnType));
            }
            // Unknown return type
            return new Types\Mixed;
        }
        if ($node instanceof Node\Expr\Variable) {
            $node = $node->getAttribute('parentNode');
        }
        if (
            $node instanceof Node\Stmt\PropertyProperty
            || $node instanceof Node\Const_
            || $node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignOp
        ) {
            if ($node instanceof Node\Stmt\PropertyProperty || $node instanceof Node\Const_) {
                $docBlockHolder = $node->getAttribute('parentNode');
            } else {
                $docBlockHolder = $node;
            }
            // Property, constant or variable
            // Use @var tag
            if (
                isset($docBlockHolder)
                && ($docBlock = $docBlockHolder->getAttribute('docBlock'))
                && !empty($varTags = $docBlock->getTagsByName('var'))
                && ($type = $varTags[0]->getType())
            ) {
                return $type;
            }
            // Resolve the expression
            if ($node instanceof Node\Stmt\PropertyProperty) {
                if ($node->default) {
                    return $this->resolveExpressionNodeToType($node->default);
                }
            } else if ($node instanceof Node\Const_) {
                return $this->resolveExpressionNodeToType($node->value);
            } else {
                return $this->resolveExpressionNodeToType($node);
            }
            // TODO: read @property tags of class
            // TODO: Try to infer the type from default value / constant value
            // Unknown
            return new Types\Mixed;
        }
        return null;
    }

    /**
     * Returns the fully qualified name (FQN) that is defined by a node
     * Returns null if the node does not declare any symbol that can be referenced by an FQN
     *
     * @param Tolerant\Node $node
     * @return string|null
     */
    public static function getDefinedFqn($node)
    {
        $parent = $node->getParent();
        // Anonymous classes don't count as a definition
        // INPUT                    OUTPUT:
        // namespace A\B;
        // class C { }              A\B\C
        // interface C { }          A\B\C
        // trait C { }              A\B\C
        if (
            $node instanceof Tolerant\Node\Statement\ClassDeclaration ||
            $node instanceof Tolerant\Node\Statement\InterfaceDeclaration ||
            $node instanceof Tolerant\Node\Statement\TraitDeclaration
        ) {
            return (string) $node->getNamespacedName();
        }

        // INPUT                   OUTPUT:
        // namespace A\B;           A\B
        else if ($node instanceof Tolerant\Node\Statement\NamespaceDefinition && $node->name instanceof Tolerant\Node\QualifiedName) {
            return (string) $node->name;
        }
        // INPUT                   OUTPUT:
        // namespace A\B;
        // function a();           A\B\a();
        else if ($node instanceof Tolerant\Node\Statement\FunctionDeclaration) {
            // Function: use functionName() as the name
            return (string)$node->getNamespacedName() . '()';
        }
        // INPUT                        OUTPUT
        // namespace A\B;
        // class C {
        //   function a () {}           A\B\C::a()
        //   static function b() {}     A\B\C->b()
        // }
        else if ($node instanceof Tolerant\Node\MethodDeclaration) {
            // Class method: use ClassName->methodName() as name
            $class = $node->getFirstAncestor(Tolerant\Node\Statement\ClassDeclaration::class);
            if (!isset($class->name)) {
                // Ignore anonymous classes
                return null;
            }
            if ($node->isStatic()) {
                return (string)$class->getNamespacedName() . '::' . $node->getName() . '()';
            } else {
                return (string)$class->getNamespacedName() . '->' . $node->getName() . '()';
            }
        }

        // INPUT                        OUTPUT
        // namespace A\B;
        // class C {
        //   static $a = 4, $b = 4      A\B\C::$a, A\B\C::$b
        //   $a = 4, $b = 4             A\B\C->$a, A\B\C->$b
        // }
        else if (
            $node instanceof Tolerant\Node\Expression\Variable &&
            ($propertyDeclaration = $node->getFirstAncestor(Tolerant\Node\PropertyDeclaration::class)) !== null &&
            ($classDeclaration = $node->getFirstAncestor(Tolerant\Node\Statement\ClassDeclaration::class)) !== null)
        {
            if ($propertyDeclaration->isStatic()) {
                // Static Property: use ClassName::$propertyName as name
                return (string)$classDeclaration->getNamespacedName() . '::$' . (string)$node->getName();
            } elseif (($name = $node->getName()) !== null) {
                // Instance Property: use ClassName->propertyName as name
                return (string)$classDeclaration->getNamespacedName() . '->' . $name;
            }
        }

        // INPUT                        OUTPUT
        // namespace A\B;
        // const FOO = 5;               A\B\FOO
        // class C {
        //   const $a, $b = 4           A\B\C::$a(), A\B\C::$b
        // }
        else if ($node instanceof Tolerant\Node\ConstElement) {
            $constDeclaration = $node->getFirstAncestor(Tolerant\Node\Statement\ConstDeclaration::class, Tolerant\Node\ClassConstDeclaration::class);
            if ($constDeclaration instanceof Tolerant\Node\Statement\ConstDeclaration) {
                // Basic constant: use CONSTANT_NAME as name
                return (string)$node->getNamespacedName();
            }
            if ($constDeclaration instanceof Tolerant\Node\ClassConstDeclaration) {
                // Class constant: use ClassName::CONSTANT_NAME as name
                $classDeclaration = $constDeclaration->getFirstAncestor(Tolerant\Node\Statement\ClassDeclaration::class);
                if (!isset($classDeclaration->name)) {
                    return null;
                }
                return (string)$classDeclaration->getNamespacedName() . '::' . $node->getName();
            }
        }
    }
}