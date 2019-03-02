<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\Backend\VM;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPTypes\Type;


class JIT {
    private static int $functionNumber = 0;

    public static int $optimizationLevel = 3;


    private static array $stringConstant = [];
    private static array $intConstant = [];
    private static array $builtIns = [];

    const COMPARE_MAP = [
        OpCode::TYPE_SMALLER => \GCC_JIT_COMPARISON_LT,
        OpCode::TYPE_IDENTICAL => \GCC_JIT_COMPARISON_EQ,
    ];

    const BINARYOP_MAP = [
        OpCode::TYPE_MINUS => \GCC_JIT_BINARY_OP_MINUS,
        OpCode::TYPE_PLUS => \GCC_JIT_BINARY_OP_PLUS,
    ];

    public static function compile(Block $block, ?string $debugfile = null) {
        $context = new JIT\Context(JIT\Builtin::LOAD_TYPE_EMBED);
        // $context->setDebug(true);
        // if (!is_null($debugfile)) {
        //     $context->setDebugFile($debugfile);
        // }
        // $context->setOption(
        //     \GCC_JIT_INT_OPTION_OPTIMIZATION_LEVEL,
        //     self::$optimizationLevel
        // );

        self::compileBlock($context, $block);
        $context->compileInPlace();
    }


    private static function compileBlock(JIT\Context $context, Block $block, ?string $funcName = null): void {
        $internalName = "internal_" . (++self::$functionNumber);
        
        $args = [];
        $argVars = [];
        if (!is_null($block->func)) {
            $callbackType = '';
            if ($block->func->returnType instanceof Op\Type\Literal) {
                switch ($block->func->returnType->name) {
                    case 'void':
                        $callbackType .= 'void';
                        break;
                    case 'int':
                        $callbackType .= 'long long';
                        break;
                    throw new \LogicException("Non-void return types not supported yet");
                }
            } else {
                throw new \LogicException("Non-typed functions not implemented yet");
            }
            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($block->func->params as $idx => $param) {
                $type = $context->getTypeFromType($param->result->type);
                $callbackType .= $callbackSep . $context->getStringFromType($type);
                $callbackSep = ', ';
                $args[] = $arg = \gcc_jit_context_new_param($context->context, $context->location(), $type, 'param_' . $idx);
                $argVars[] = new JIT\Variable(
                    $context,
                    JIT\Variable::getTypeFromType($param->result->type),
                    JIT\Variable::KIND_VARIABLE,
                    $arg->asRValue(),
                    $arg->asLValue()
                );
            }
            $callbackType .= ')';
            assert($block->func->returnType instanceof Op\Type\Literal);
            // ToDo: do this in PHPTypes
            $returnType = $context->getTypeFromType(Type::fromDecl($block->func->returnType->name));
        } else {
            $callbackType = 'void(*)()';
            $returnType = $context->getTypeFromString('void');
        }

        $func = \gcc_jit_context_new_function(
            $context->context, 
            NULL,
            \GCC_JIT_FUNCTION_EXPORTED,
            $returnType,
            $internalName,
            count($args), 
            \gcc_jit_param_ptr_ptr::fromArray(...$args),
            0
        );
        if (!is_null($funcName)) {
            $context->functions[strtolower($funcName)] = $func;
        }

        self::compileBlockInternal($context, $func, $block, ...$argVars);
        if (empty($args)) {
            $context->addExport($internalName, $callbackType, $block);
        }
    }

    private static int $blockNumber = 0;
    private function compileBlockInternal(
        JIT\Context $context, 
        \gcc_jit_function_ptr $func,
        Block $block,
        JIT\Variable ...$args
    ): \gcc_jit_block_ptr {
        if ($context->scope->blockStorage->contains($block)) {
            return $context->scope->blockStorage[$block];
        }
        $context->scope->blockNumber++;
        $gccBlock = \gcc_jit_function_new_block($func, 'block_' . self::$blockNumber);
        $context->scope->blockStorage[$block] = $gccBlock;
        // Handle hoisted variables
        foreach ($block->orig->hoistedOperands as $operand) {
            $var = $context->makeVariableFromOp($func, $gccBlock, $block, $operand);
        }

        foreach ($block->opCodes as $op) {
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $context->helper->assignOperand($gccBlock, $block->getOperand($op->arg1), $args[$op->arg2]);
                    break;
                case OpCode::TYPE_ASSIGN:
                    $value = $context->getVariableFromOp($block->getOperand($op->arg3));
                    $context->helper->assignOperand($gccBlock, $block->getOperand($op->arg2), $value);
                    $context->helper->assignOperand($gccBlock, $block->getOperand($op->arg1), $value);
                    break;  
                case OpCode::TYPE_CONCAT:
                    $result = $context->getVariableFromOp($block->getOperand($op->arg1));
                    $left = $context->getVariableFromOp($block->getOperand($op->arg2));
                    $right = $context->getVariableFromOp($block->getOperand($op->arg3));
                    $context->type->string->concat($gccBlock, $result, $left, $right);
                    break;
                case OpCode::TYPE_ECHO:
                    $arg = $context->getVariableFromOp($block->getOperand($op->arg1));
                    switch ($arg->type) {
                        case JIT\Variable::TYPE_STRING:
                            $context->helper->eval(
                                $gccBlock,
                                $context->helper->call(
                                    'printf',
                                    $context->constantFromString('%.*s'),
                                    $context->type->string->size($arg),
                                    $context->type->string->value($arg)
                                )
                            );
                            break;
                        case JIT\Variable::TYPE_NATIVE_LONG:
                            $context->helper->eval(
                                $gccBlock,
                                $context->helper->call(
                                    'printf',
                                    $context->constantFromString('%lld'),
                                    $arg->rvalue
                                )
                            );
                            break;
                        default: 
                            throw new \LogicException("Echo for type $arg->type not implemented");
                    }
                    
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                    $result = $block->getOperand($op->arg1);
                    $context->makeVariableFromRValueOp(
                        $context->helper->numericBinaryOp(
                            self::BINARYOP_MAP[$op->type], 
                            $result, 
                            $context->getVariableFromOp($block->getOperand($op->arg2)), 
                            $context->getVariableFromOp($block->getOperand($op->arg3))
                        ),
                        $result
                    );
                    break;
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_IDENTICAL:
                    $result = $block->getOperand($op->arg1);
                    $context->makeVariableFromRValueOp(
                        $context->helper->compareOp(
                            self::COMPARE_MAP[$op->type], 
                            $result, 
                            $context->getVariableFromOp($block->getOperand($op->arg2)), 
                            $context->getVariableFromOp($block->getOperand($op->arg3))
                        ),
                        $result
                    );
                    break;
                case OpCode::TYPE_JUMP:
                    $newBlock = self::compileBlockInternal($context, $func, $op->block1, ...$args);
                    $context->freeDeadVariables($func, $gccBlock, $block);
                    \gcc_jit_block_end_with_jump(
                        $gccBlock,
                        $context->location(),
                        $newBlock
                    );
                    return $gccBlock;
                case OpCode::TYPE_JUMPIF:
                    $if = self::compileBlockInternal($context, $func, $op->block1, ...$args);
                    $else = self::compileBlockInternal($context, $func, $op->block2, ...$args);
                    $context->freeDeadVariables($func, $gccBlock, $block);
                    \gcc_jit_block_end_with_conditional(
                        $gccBlock,
                        $context->location(),
                        $context->getVariableFromOp($block->getOperand($op->arg1))->rvalue,
                        $if,
                        $else
                    );
                    return $gccBlock;
                case OpCode::TYPE_RETURN_VOID:
                    $context->freeDeadVariables($func, $gccBlock, $block);
                    goto void_return;
                    break;
                case OpCode::TYPE_RETURN:
                    $return = $context->getVariableFromOp($block->getOperand($op->arg1));
                    $return->addref($gccBlock);
                    $context->freeDeadVariables($func, $gccBlock, $block);
                    \gcc_jit_block_end_with_return(
                        $gccBlock,
                        $context->location(),
                        $return->rvalue
                    );
                    return $gccBlock;
                case OpCode::TYPE_FUNCDEF:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $context->pushScope();
                    self::compileBlock($context, $op->block1, $nameOp->value);
                    $context->popScope();
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $nameOp = $block->getOperand($op->arg1);
                    if (!$nameOp instanceof Operand\Literal) {
                        throw new \LogicException("Variable function calls not yet supported");
                    }
                    $lcname = strtolower($nameOp->value);
                    if (!isset($context->functions[$lcname])) {
                        throw new \RuntimeException("Call to undefined function $lcname");
                    }
                    $context->scope->toCall = $context->functions[$lcname];
                    $context->scope->args = [];
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $context->scope->args[] = $context->getVariableFromOp($block->getOperand($op->arg1))->rvalue;
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    $context->helper->eval($gccBlock,
                        \gcc_jit_context_new_call(
                            $context->context,
                            $context->location(),
                            $context->scope->toCall,
                            count($context->scope->args),
                            \gcc_jit_rvalue_ptr_ptr::fromArray(...$context->scope->args)
                        )
                    );
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    $result = \gcc_jit_context_new_call(
                        $context->context,
                        $context->location(),
                        $context->scope->toCall,
                        count($context->scope->args),
                        \gcc_jit_rvalue_ptr_ptr::fromArray(...$context->scope->args)
                    );
                    $context->helper->assign(
                        $gccBlock,
                        $context->getVariableFromOp($block->getOperand($op->arg1))->lvalue,
                        $result
                    );
                    break;
                default:
                    throw new \LogicException("Unknown JIT opcode: ". $op->getType());
            }
        }
void_return:
        \gcc_jit_block_end_with_void_return($gccBlock, null);
        return $gccBlock;
    }

    private static function compileArg(Operand $op): \gcc_jit_param_ptr {
        throw new \LogicException("Block args not implemented yet");
    }

}