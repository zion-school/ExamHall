<?php
/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 16-Sep-2026
 *
 * Safe evaluator for reward formulas (no eval). Example:
 *   percent >= 90 ? 10 : percent >= 75 ? 5 : percent >= 50 ? 2 : 0
 *
 * Supports numbers, "strings", variables, + - * / %, comparisons (== != < <= > >=),
 * && || !, ternary ? :, parentheses and the functions min max round floor ceil abs clamp.
 */

defined('EXAMS_APP') or exit;

final class ExprError extends RuntimeException
{
}

final class Expr
{
    private array $tokens = [];
    private int $pos = 0;
    private array $vars = [];

    private const FUNCTIONS = ['min', 'max', 'round', 'floor', 'ceil', 'abs', 'clamp'];

    /** Evaluate $source with $vars. Throws ExprError with a teacher-readable message. */
    public static function evaluate(string $source, array $vars)
    {
        $e = new self();
        $e->tokens = self::tokenize($source);
        $e->vars = $vars;
        $e->pos = 0;
        if (!$e->tokens) {
            throw new ExprError('The formula is empty.');
        }
        $value = $e->ternary();
        if ($e->pos < count($e->tokens)) {
            throw new ExprError('Unexpected "' . $e->tokens[$e->pos][1] . '" in the formula.');
        }
        return $value;
    }

    private static function tokenize(string $s): array
    {
        $tokens = [];
        $re = '/\s*(?:(\d+(?:\.\d+)?)|("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')|([A-Za-z_][A-Za-z0-9_]*)|(==|!=|<=|>=|&&|\|\||[-+*\/%<>!?:(),]))/A';
        $offset = 0;
        $len = strlen($s);
        while ($offset < $len) {
            if (trim(substr($s, $offset)) === '') {
                break;
            }
            if (!preg_match($re, $s, $m, 0, $offset)) {
                throw new ExprError('The formula has a character I do not understand near "' . substr($s, $offset, 10) . '".');
            }
            $offset += strlen($m[0]);
            if (($m[1] ?? '') !== '') {
                $tokens[] = ['num', (float)$m[1]];
            } elseif (($m[2] ?? '') !== '') {
                $tokens[] = ['str', stripcslashes(substr($m[2], 1, -1))];
            } elseif (($m[3] ?? '') !== '') {
                $tokens[] = ['id', $m[3]];
            } else {
                $tokens[] = ['op', $m[4]];
            }
        }
        return $tokens;
    }

    private function peek(): ?string
    {
        $t = $this->tokens[$this->pos] ?? null;
        return $t && $t[0] === 'op' ? $t[1] : null;
    }

    private function expect(string $op): void
    {
        if ($this->peek() !== $op) {
            throw new ExprError("Expected \"$op\" in the formula.");
        }
        $this->pos++;
    }

    private function ternary()
    {
        $cond = $this->orExpr();
        if ($this->peek() === '?') {
            $this->pos++;
            $yes = $this->ternary();
            $this->expect(':');
            $no = $this->ternary();
            return self::truthy($cond) ? $yes : $no;
        }
        return $cond;
    }

    private function orExpr()
    {
        $left = $this->andExpr();
        while ($this->peek() === '||') {
            $this->pos++;
            $right = $this->andExpr();
            $left = self::truthy($left) || self::truthy($right) ? 1.0 : 0.0;
        }
        return $left;
    }

    private function andExpr()
    {
        $left = $this->comparison();
        while ($this->peek() === '&&') {
            $this->pos++;
            $right = $this->comparison();
            $left = self::truthy($left) && self::truthy($right) ? 1.0 : 0.0;
        }
        return $left;
    }

    private function comparison()
    {
        $left = $this->additive();
        while (in_array($this->peek(), ['==', '!=', '<', '<=', '>', '>='], true)) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->additive();
            if (is_string($left) || is_string($right)) {
                $l = (string)$left;
                $r = (string)$right;
                $result = match ($op) { '==' => $l === $r, '!=' => $l !== $r, '<' => $l < $r, '<=' => $l <= $r, '>' => $l > $r, '>=' => $l >= $r };
            } else {
                $result = match ($op) { '==' => abs($left - $right) < 1e-9, '!=' => abs($left - $right) >= 1e-9, '<' => $left < $right, '<=' => $left <= $right, '>' => $left > $right, '>=' => $left >= $right };
            }
            $left = $result ? 1.0 : 0.0;
        }
        return $left;
    }

    private function additive()
    {
        $left = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->term();
            $left = $op === '+' ? self::num($left) + self::num($right) : self::num($left) - self::num($right);
        }
        return $left;
    }

    private function term()
    {
        $left = $this->unary();
        while (in_array($this->peek(), ['*', '/', '%'], true)) {
            $op = $this->tokens[$this->pos++][1];
            $right = self::num($this->unary());
            if (($op === '/' || $op === '%') && abs($right) < 1e-12) {
                throw new ExprError('The formula divides by zero.');
            }
            $left = match ($op) { '*' => self::num($left) * $right, '/' => self::num($left) / $right, '%' => fmod(self::num($left), $right) };
        }
        return $left;
    }

    private function unary()
    {
        $op = $this->peek();
        if ($op === '-') {
            $this->pos++;
            return -self::num($this->unary());
        }
        if ($op === '+') {
            $this->pos++;
            return self::num($this->unary());
        }
        if ($op === '!') {
            $this->pos++;
            return self::truthy($this->unary()) ? 0.0 : 1.0;
        }
        return $this->primary();
    }

    private function primary()
    {
        $t = $this->tokens[$this->pos] ?? null;
        if ($t === null) {
            throw new ExprError('The formula ends too early.');
        }
        $this->pos++;
        if ($t[0] === 'num' || $t[0] === 'str') {
            return $t[1];
        }
        if ($t[0] === 'op' && $t[1] === '(') {
            $v = $this->ternary();
            $this->expect(')');
            return $v;
        }
        if ($t[0] === 'id') {
            $name = $t[1];
            if ($this->peek() === '(') {
                $this->pos++;
                $args = [];
                if ($this->peek() !== ')') {
                    do {
                        $args[] = self::num($this->ternary());
                    } while ($this->peek() === ',' && ++$this->pos);
                }
                $this->expect(')');
                return self::call(strtolower($name), $args);
            }
            if (in_array(strtolower($name), ['true', 'false'], true)) {
                return strtolower($name) === 'true' ? 1.0 : 0.0;
            }
            if (!array_key_exists($name, $this->vars)) {
                throw new ExprError("Unknown name \"$name\". You can use: " . implode(', ', array_keys($this->vars)) . '.');
            }
            $v = $this->vars[$name];
            return is_bool($v) ? ($v ? 1.0 : 0.0) : (is_numeric($v) ? (float)$v : (string)$v);
        }
        throw new ExprError('Unexpected "' . $t[1] . '" in the formula.');
    }

    private static function call(string $name, array $args)
    {
        if (!in_array($name, self::FUNCTIONS, true)) {
            throw new ExprError("Unknown function \"$name\". You can use: " . implode(', ', self::FUNCTIONS) . '.');
        }
        $need = ['min' => 1, 'max' => 1, 'round' => 1, 'floor' => 1, 'ceil' => 1, 'abs' => 1, 'clamp' => 3][$name];
        if (count($args) < $need) {
            throw new ExprError("$name() needs " . ($need === 1 ? 'a number' : "$need numbers") . '.');
        }
        return match ($name) {
            'min' => min($args),
            'max' => max($args),
            'round' => round($args[0], (int)($args[1] ?? 0)),
            'floor' => floor($args[0]),
            'ceil' => ceil($args[0]),
            'abs' => abs($args[0]),
            'clamp' => max($args[1], min($args[2], $args[0])),
        };
    }

    private static function num($v): float
    {
        if (is_string($v)) {
            if (!is_numeric($v)) {
                throw new ExprError("\"$v\" is not a number.");
            }
            return (float)$v;
        }
        return (float)$v;
    }

    private static function truthy($v): bool
    {
        return is_string($v) ? $v !== '' : abs((float)$v) > 1e-12;
    }
}
