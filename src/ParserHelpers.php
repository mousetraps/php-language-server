<?php
declare(strict_types=1);

namespace LanguageServer;

use Microsoft\PhpParser as Tolerant;

class ParserHelpers {
    public static function isConstantFetch(Tolerant\Node $node) : bool {
        $parent = $node->parent;
        return
            (
            $node instanceof Tolerant\Node\QualifiedName &&
            (
//                $node->parent instanceof Tolerant\Node\Statement\ExpressionStatement ||
                $parent instanceof Tolerant\Node\Expression ||
                $parent instanceof Tolerant\Node\DelimitedList\ExpressionList ||
                $parent instanceof Tolerant\Node\ArrayElement ||
                ($parent instanceof Tolerant\Node\Parameter && $node->parent->default === $node) ||
                $parent instanceof Tolerant\Node\StatementNode ||
                $parent instanceof Tolerant\Node\CaseStatementNode
            ) &&
            !(
                $parent instanceof Tolerant\Node\Expression\MemberAccessExpression ||
                $parent instanceof Tolerant\Node\Expression\CallExpression ||
                $parent instanceof Tolerant\Node\Expression\ObjectCreationExpression ||
                $parent instanceof Tolerant\Node\Expression\ScopedPropertyAccessExpression ||
                self::isFunctionLike($parent) ||
                (
                    $parent instanceof Tolerant\Node\Expression\BinaryExpression &&
                    $parent->operator->kind === Tolerant\TokenKind::InstanceOfKeyword
                )
            ));
    }

     public static function getFunctionLikeDeclarationFromParameter(Tolerant\Node\Parameter $node) {
        return $node->parent->parent;
    }

    public static function isFunctionLike(Tolerant\Node $node) {
        return
            $node instanceof Tolerant\Node\Statement\FunctionDeclaration ||
            $node instanceof Tolerant\Node\MethodDeclaration ||
            $node instanceof Tolerant\Node\Expression\AnonymousFunctionCreationExpression;
    }

    public static function isBooleanExpression($expression) : bool {
        if (!($expression instanceof Tolerant\Node\Expression\BinaryExpression)) {
            return false;
        }
        switch ($expression->operator->kind) {
            case Tolerant\TokenKind::InstanceOfKeyword:
            case Tolerant\TokenKind::GreaterThanToken:
            case Tolerant\TokenKind::GreaterThanEqualsToken:
            case Tolerant\TokenKind::LessThanToken:
            case Tolerant\TokenKind::LessThanEqualsToken:
            case Tolerant\TokenKind::AndKeyword:
            case Tolerant\TokenKind::AmpersandAmpersandToken:
            case Tolerant\TokenKind::LessThanEqualsGreaterThanToken:
            case Tolerant\TokenKind::OrKeyword:
            case Tolerant\TokenKind::BarBarToken:
            case Tolerant\TokenKind::XorKeyword:
            case Tolerant\TokenKind::ExclamationEqualsEqualsToken:
            case Tolerant\TokenKind::ExclamationEqualsToken:
            case Tolerant\TokenKind::CaretToken:
            case Tolerant\TokenKind::EqualsEqualsEqualsToken:
            case Tolerant\TokenKind::EqualsToken:
                return true;
        }
        return false;
    }


    /**
     * Tries to get the parent property declaration given a Node
     * @param Tolerant\Node $node
     * @return Tolerant\Node\PropertyDeclaration | null $node
     */
    public static function tryGetPropertyDeclaration(Tolerant\Node $node) {
        if ($node instanceof Tolerant\Node\Expression\Variable &&
            (($propertyDeclaration = $node->parent->parent) instanceof Tolerant\Node\PropertyDeclaration ||
                ($propertyDeclaration = $propertyDeclaration->parent) instanceof Tolerant\Node\PropertyDeclaration)
        ) {
            return $propertyDeclaration;
        }
        return null;
    }

    /**
     * Tries to get the parent ConstDeclaration or ClassConstDeclaration given a Node
     * @param Tolerant\Node $node
     * @return Tolerant\Node\Statement\ConstDeclaration | Tolerant\Node\ClassConstDeclaration | null $node
     */
    public static function tryGetConstOrClassConstDeclaration(Tolerant\Node $node) {
        if (
            $node instanceof Tolerant\Node\ConstElement && (
                ($constDeclaration = $node->parent->parent) instanceof Tolerant\Node\ClassConstDeclaration ||
                $constDeclaration instanceof Tolerant\Node\Statement\ConstDeclaration )
            ) {
            return $constDeclaration;
        }
        return null;
    }
}