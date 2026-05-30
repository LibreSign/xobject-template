<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 LibreSign
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace LibreSign\XObjectTemplate\Pdf\Svg;

use InvalidArgumentException;

/**
 * Parses SVG path data and converts it to PDF path command strings.
 *
 * Handles all SVG path commands (M, L, H, V, C, S, Q, T, A, Z) and converts
 * them to equivalent PDF drawing commands in the PDF coordinate system.
 */
final class SvgPathCommandParser
{
    public function __construct(
        private SvgArcConverter $arcConverter = new SvgArcConverter(),
        private SvgTransformResolver $transformResolver = new SvgTransformResolver(),
    ) {
    }

    /**
     * Convert an SVG path `d` attribute value to PDF path commands.
     *
     * @param string $pathData   The SVG path data string
     * @param float  $minX       The viewBox X origin
     * @param float  $maxY       The viewBox bottom Y (for flipping Y axis)
     * @param string $source     Source identifier for error messages
     * @param array{0:float,1:float,2:float,3:float,4:float,5:float} $transformMatrix Cumulative transform matrix
     * @return string PDF path command sequence
     */
    public function convertPathData(
        string $pathData,
        float $minX,
        float $maxY,
        string $source,
        array $transformMatrix,
    ): string {
        preg_match_all(
            '/([A-Za-z])|([-+]?\d*\.?\d+(?:[eE][-+]?\d+)?)/',
            $pathData,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            throw new InvalidArgumentException(sprintf('Unsupported or empty SVG path data in "%s".', $source));
        }

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = $match[1] !== '' ? $match[1] : $match[2];
        }

        $state = new PathParsingState();
        $context = new PathCommandContext($transformMatrix, $minX, $maxY, $source);
        $tokenCount = count($tokens);
        $index = 0;
        $currentCommand = null;

        while ($index < $tokenCount) {
            $token = $tokens[$index];
            if (preg_match('/^[A-Za-z]$/', $token) === 1) {
                $currentCommand = $token;
                ++$index;
            }

            if ($currentCommand === null) {
                throw new InvalidArgumentException(sprintf('Invalid SVG path command sequence in "%s".', $source));
            }

            $isRelative = ctype_lower($currentCommand);
            $command = strtoupper($currentCommand);

            $this->handlePathCommand(
                $command,
                $isRelative,
                $tokens,
                $index,
                $tokenCount,
                $state,
                $context,
            );
        }

        return implode("\n", $state->commands);
    }

    /**
     * Route path command to appropriate handler.
     *
     * @param list<string> $tokens
     */
    private function handlePathCommand(
        string $command,
        bool $isRelative,
        array $tokens,
        int &$index,
        int $tokenCount,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        match ($command) {
            'M' => $this->handleMoveCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'L' => $this->handleLineCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'H' => $this->handleHorizontalCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'V' => $this->handleVerticalCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'C' => $this->handleCubicCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'S' => $this->handleSmoothCubicCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'Q' => $this->handleQuadraticCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'T' => $this->handleSmoothQuadraticCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'A' => $this->handleArcCommand($tokens, $index, $tokenCount, $isRelative, $state, $context),
            'Z' => $this->handleClosePathCommand($state),
            default => throw new InvalidArgumentException(sprintf(
                'SVG path command "%s" is not supported for source "%s".',
                $command,
                $context->source,
            )),
        };
    }

    /**
     * @param list<string> $tokens
     */
    private function handleMoveCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
        $state->currentX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
        $state->currentY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
        [$mX, $mY] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $state->currentX, $state->currentY);
        $state->commands[] = sprintf('%F %F m', $mX - $context->minX, $context->maxY - $mY);
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;

        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
            $nextX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $nextY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $this->appendLineToState($state, $context, $nextX, $nextY);
        }
        $state->prevQuadCpX = null;
        $state->prevQuadCpY = null;
    }

    /**
     * @param list<string> $tokens
     */
    private function handleLineCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
              $nextX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
              $nextY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $this->appendLineToState($state, $context, $nextX, $nextY);
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleHorizontalCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 1, $context->source);
            $state->currentX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            [$lX, $lY] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $state->currentX, $state->currentY);
            $state->commands[] = sprintf('%F %F l', $lX - $context->minX, $context->maxY - $lY);
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleVerticalCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 1, $context->source);
            $state->currentY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[0]);
            [$lX, $lY] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $state->currentX, $state->currentY);
            $state->commands[] = sprintf('%F %F l', $lX - $context->minX, $context->maxY - $lY);
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleCubicCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 6, $context->source);
                $x1 = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
                $y1 = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
                $x2 = $this->resolveCoord($isRelative, $state->currentX, $coordinates[2]);
                $y2 = $this->resolveCoord($isRelative, $state->currentY, $coordinates[3]);
                $x = $this->resolveCoord($isRelative, $state->currentX, $coordinates[4]);
                $y = $this->resolveCoord($isRelative, $state->currentY, $coordinates[5]);

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = $x2;
            $state->lastCubicControlY = $y2;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSmoothCubicCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 4, $context->source);
            $x1 = $this->reflectControlPoint($state->lastCubicControlX, $state->currentX);
            $y1 = $this->reflectControlPoint($state->lastCubicControlY, $state->currentY);
            $x2 = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $y2 = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $x = $this->resolveCoord($isRelative, $state->currentX, $coordinates[2]);
            $y = $this->resolveCoord($isRelative, $state->currentY, $coordinates[3]);

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = $x2;
            $state->lastCubicControlY = $y2;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleQuadraticCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 4, $context->source);
                $qcpX = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
                $qcpY = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
                $x    = $this->resolveCoord($isRelative, $state->currentX, $coordinates[2]);
                $y    = $this->resolveCoord($isRelative, $state->currentY, $coordinates[3]);

            $x1 = $state->currentX + (2.0 / 3.0) * ($qcpX - $state->currentX);
            $y1 = $state->currentY + (2.0 / 3.0) * ($qcpY - $state->currentY);
            $x2 = $x + (2.0 / 3.0) * ($qcpX - $x);
            $y2 = $y + (2.0 / 3.0) * ($qcpY - $y);

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
            $state->prevQuadCpX = $qcpX;
            $state->prevQuadCpY = $qcpY;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleSmoothQuadraticCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 2, $context->source);
            $qcpX = $this->reflectControlPoint($state->prevQuadCpX, $state->currentX);
            $qcpY = $this->reflectControlPoint($state->prevQuadCpY, $state->currentY);
            $x = $this->resolveCoord($isRelative, $state->currentX, $coordinates[0]);
            $y = $this->resolveCoord($isRelative, $state->currentY, $coordinates[1]);
            $x1 = $state->currentX + (2.0 / 3.0) * ($qcpX - $state->currentX);
            $y1 = $state->currentY + (2.0 / 3.0) * ($qcpY - $state->currentY);
            $x2 = $x + (2.0 / 3.0) * ($qcpX - $x);
            $y2 = $y + (2.0 / 3.0) * ($qcpY - $y);

            $state->commands[] = $this->buildCubicCurveCommand($context->transformMatrix, $context->minX, $context->maxY, $x1, $y1, $x2, $y2, $x, $y);
            $state->prevQuadCpX = $qcpX;
            $state->prevQuadCpY = $qcpY;
            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    /**
     * @param list<string> $tokens
     */
    private function handleArcCommand(
        array $tokens,
        int &$index,
        int $tokenCount,
        bool $isRelative,
        PathParsingState $state,
        PathCommandContext $context,
    ): void {
        while ($index < $tokenCount && preg_match('/^[A-Za-z]$/', $tokens[$index]) !== 1) {
            $coordinates = $this->readPathNumbers($tokens, $index, 7, $context->source);
            $rx       = abs($coordinates[0]);
            $ry       = abs($coordinates[1]);
            $rotation = $coordinates[2];
            $largeArc = (int) $coordinates[3];
            $sweep    = (int) $coordinates[4];
            $x        = $this->resolveCoord($isRelative, $state->currentX, $coordinates[5]);
            $y        = $this->resolveCoord($isRelative, $state->currentY, $coordinates[6]);

            $curves = $this->arcConverter->arcToBezierCurves($state->currentX, $state->currentY, $rx, $ry, $rotation, $largeArc, $sweep, $x, $y);

            foreach ($curves as $curve) {
                [$cp1x, $cp1y] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $curve[0], $curve[1]);
                [$cp2x, $cp2y] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $curve[2], $curve[3]);
                [$ex, $ey] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $curve[4], $curve[5]);
                $state->commands[] = sprintf(
                    '%F %F %F %F %F %F c',
                    $cp1x - $context->minX,
                    $context->maxY - $cp1y,
                    $cp2x - $context->minX,
                    $context->maxY - $cp2y,
                    $ex - $context->minX,
                    $context->maxY - $ey,
                );
            }

            $state->currentX = $x;
            $state->currentY = $y;
            $state->lastCubicControlX = null;
            $state->lastCubicControlY = null;
        }
    }

    private function handleClosePathCommand(PathParsingState $state): void
    {
        $state->commands[] = 'h';
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    /**
     * Resolve a coordinate as absolute or relative to current position.
     */
    private function resolveCoord(bool $isRelative, float $current, float $coord): float
    {
        return $isRelative ? $current + $coord : $coord;
    }

    /**
     * Reflect a previous control point over the current position.
     * Returns current position when no prior control point exists (SVG spec default).
     */
    private function reflectControlPoint(?float $prevControl, float $current): float
    {
        return $prevControl === null ? $current : (2.0 * $current) - $prevControl;
    }

    private function appendLineToState(
        PathParsingState $state,
        PathCommandContext $context,
        float $toX,
        float $toY,
    ): void {
        $state->currentX = $toX;
        $state->currentY = $toY;
        [$lX, $lY] = $this->transformResolver->applyTransformToPoint($context->transformMatrix, $toX, $toY);
        $state->commands[] = sprintf('%F %F l', $lX - $context->minX, $context->maxY - $lY);
        $state->lastCubicControlX = null;
        $state->lastCubicControlY = null;
    }

    /**
     * @param list<string> $tokens
     * @return list<float>
     */
    private function readPathNumbers(array $tokens, int &$index, int $count, string $source): array
    {
        $values = [];

        for ($i = 0; $i < $count; ++$i) {
            if ($index >= count($tokens) || preg_match('/^[A-Za-z]$/', $tokens[$index]) === 1) {
                throw new InvalidArgumentException(sprintf(
                    'Malformed SVG path data in "%s".',
                    $source,
                ));
            }

            $values[] = (float) $tokens[$index];
            ++$index;
        }

        return $values;
    }

        private function buildCubicCurveCommand(
        array $transformMatrix,
        float $minX,
        float $maxY,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $x,
        float $y,
    ): string {
        [$tx1, $ty1] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x1, $y1);
        [$tx2, $ty2] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x2, $y2);
        [$tx, $ty] = $this->transformResolver->applyTransformToPoint($transformMatrix, $x, $y);

        return sprintf(
            '%F %F %F %F %F %F c',
            $tx1 - $minX,
            $maxY - $ty1,
            $tx2 - $minX,
            $maxY - $ty2,
            $tx - $minX,
            $maxY - $ty,
        );
    }
}
